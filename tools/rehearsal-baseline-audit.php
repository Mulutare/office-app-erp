<?php
declare(strict_types=1);

/** Read-only metadata and view-health gate. Never exposes business rows. */
function auditRehearsalBaseline(PDO $pdo, bool $diagnosticProbes = false, ?array $expectedEnvironment = null, array $requiredViews = []): array
{
    if ($pdo->inTransaction()) throw new RuntimeException('Baseline audit requires its own read-only transaction.');
    $audit=['result'=>'FAIL','failures'=>[],'views'=>[]];
    $pdo->exec('START TRANSACTION READ ONLY');
    try {
        $audit['environment']=$pdo->query("SELECT VERSION() AS version,@@version_comment AS version_comment,
            @@character_set_server AS character_set_server,@@collation_server AS collation_server,
            @@character_set_database AS character_set_database,@@collation_database AS collation_database,
            @@character_set_client AS character_set_client,@@character_set_connection AS character_set_connection,
            @@character_set_results AS character_set_results,@@collation_connection AS collation_connection,
            @@sql_mode AS sql_mode,@@GLOBAL.sql_mode AS global_sql_mode,DATABASE() AS database_name")->fetch(PDO::FETCH_ASSOC);
        $audit['environment_mismatches']=[];
        if($expectedEnvironment!==null){
            foreach(['version_comment','character_set_server','collation_server','character_set_database','collation_database','character_set_client','character_set_connection','character_set_results','collation_connection'] as $field){
                if(!array_key_exists($field,$expectedEnvironment)||$expectedEnvironment[$field]!==$audit['environment'][$field]){
                    $audit['environment_mismatches'][$field]=['expected'=>$expectedEnvironment[$field]??null,'actual'=>$audit['environment'][$field]];
                }
            }
            if(isset($expectedEnvironment['sql_mode'])&&$expectedEnvironment['sql_mode']!==$audit['environment']['sql_mode'])$audit['environment_mismatches']['sql_mode']=['expected'=>$expectedEnvironment['sql_mode'],'actual'=>$audit['environment']['sql_mode']];
            foreach($expectedEnvironment['required_sql_modes']??[] as $requiredMode){
                foreach(['sql_mode','global_sql_mode'] as $field){
                    if(!in_array($requiredMode,explode(',',(string)$audit['environment'][$field]),true))$audit['environment_mismatches'][$field]=['required_mode'=>$requiredMode,'actual'=>$audit['environment'][$field]];
                }
            }
            if(preg_replace('/-cll-lve$/','',(string)($expectedEnvironment['version']??''))!==$audit['environment']['version'])$audit['environment_mismatches']['version']=['expected'=>$expectedEnvironment['version']??null,'actual'=>$audit['environment']['version']];
        }
        $audit['table_collations']=$pdo->query('SELECT table_schema,table_name,table_type,table_collation FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY table_name')->fetchAll(PDO::FETCH_ASSOC);
        $audit['string_columns']=$pdo->query("SELECT table_name,column_name,character_set_name,collation_name,data_type,column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND data_type IN ('varchar','char','tinytext','text','mediumtext','longtext','enum','set') ORDER BY table_name,ordinal_position")->fetchAll(PDO::FETCH_ASSOC);
        $views=array_map(static fn(array $row):array=>array_change_key_case($row,CASE_LOWER),$pdo->query('SELECT table_name,view_definition,definer,security_type,character_set_client,collation_connection FROM information_schema.views WHERE table_schema=DATABASE() ORDER BY table_name')->fetchAll(PDO::FETCH_ASSOC));
        foreach ($views as $view) {
            $name=$view['table_name'];$quoted='`'.str_replace('`','``',$name).'`';
            $query='SELECT * FROM '.$quoted.' LIMIT 1';
            $item=$view+['query'=>$query,'result'=>'FAIL'];
            try {
                $item['show_create']=$pdo->query('SHOW CREATE VIEW '.$quoted)->fetch(PDO::FETCH_ASSOC);
                // Store the complete dependency graph as metadata, including recursively referenced views.
                $statement=$pdo->prepare('SELECT table_schema,table_name FROM information_schema.view_table_usage WHERE view_schema=DATABASE() AND view_name=? ORDER BY table_name');
                $statement->execute([$name]);$item['references']=$statement->fetchAll(PDO::FETCH_ASSOC);
                $result=$pdo->query($query);while($result->fetch(PDO::FETCH_NUM)!==false){};$result->closeCursor();
                $item['result']='PASS';
            } catch (Throwable $error) {
                $item['error']=$error->getMessage();
                $audit['failures'][]=['view'=>$name,'query'=>$query,'error'=>$error->getMessage()];
            }
            $audit['views'][$name]=$item;
        }
        foreach(array_diff($requiredViews,array_keys($audit['views'])) as $missing){
            $audit['failures'][]=['view'=>$missing,'query'=>'SELECT * FROM `'.str_replace('`','``',$missing).'` LIMIT 1','error'=>'Required baseline view is absent'];
        }
        $audit['migration_maximum']=$pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
        // Compare session behavior using metadata expressions only; never change stored view definitions.
        $audit['collation_probes']=[];
        foreach($diagnosticProbes ? ['utf8mb4_general_ci','utf8mb4_unicode_ci','utf8mb4_0900_ai_ci'] : [] as $collation){
            $pdo->exec('SET NAMES utf8mb4 COLLATE '.$collation);
            $probe=['session_collation'=>$collation];
            try{$probe['flow_type']=$pdo->query('SELECT DISTINCT CHARSET(flow_type) AS charset_name,COLLATION(flow_type) AS collation_name,COERCIBILITY(flow_type) AS coercibility FROM vw_powerbi_inventory_movements')->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){$probe['flow_type_error']=$e->getMessage();}
            $probe['comparison_literal']=$pdo->query("SELECT CHARSET('FULFILMENT_SOLD_OUT') AS charset_name,COLLATION('FULFILMENT_SOLD_OUT') AS collation_name,COERCIBILITY('FULFILMENT_SOLD_OUT') AS coercibility")->fetch(PDO::FETCH_ASSOC);
            try{$r=$pdo->query('SELECT * FROM vw_powerbi_fulfilled_sales LIMIT 1');$r->closeCursor();$probe['fulfilled_sales']='PASS';}catch(Throwable $e){$probe['fulfilled_sales_error']=$e->getMessage();}
            $audit['collation_probes'][]=$probe;
        }
        $audit['versions_100_or_greater']=(int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version>='100'")->fetchColumn();
        $audit['step_residue']=(int)$pdo->query('SELECT COUNT(*) FROM schema_migration_steps')->fetchColumn();
        $audit['result']=$audit['failures']===[]&&$audit['environment_mismatches']===[]?'PASS':'FAIL';
        return $audit;
    } finally {
        if($pdo->inTransaction())$pdo->rollBack();
        if($diagnosticProbes && isset($audit['environment'])){
            foreach(['character_set_client','character_set_connection','character_set_results','collation_connection'] as $setting){
                $value=$audit['environment'][$setting];
                if($value===null){$pdo->exec('SET SESSION '.$setting.'=NULL');}
                elseif(preg_match('/^[a-zA-Z0-9_]+$/',$value)===1){$pdo->exec('SET SESSION '.$setting.'='.$pdo->quote($value));}
                else{throw new RuntimeException('Unsafe session metadata value');}
            }
        }
    }
}

function initializeRehearsalSession(PDO $pdo, array $expected): void
{
    foreach(['character_set_connection','collation_connection'] as $setting){
        if(!isset($expected[$setting])||!is_string($expected[$setting])||preg_match('/^[a-zA-Z0-9_]+$/',$expected[$setting])!==1)throw new RuntimeException('Invalid proven session metadata: '.$setting);
    }
    $pdo->exec('SET NAMES '.$expected['character_set_connection'].' COLLATE '.$expected['collation_connection']);
    $pdo->exec('SET collation_connection='.$pdo->quote($expected['collation_connection']));
}
