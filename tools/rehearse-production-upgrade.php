<?php

declare(strict_types=1);
if(getenv('OFFICEAPP_REHEARSAL_ISOLATED')!=='1'||getenv('DB_HOST')!=='db'||getenv('DB_DATABASE')!=='passiontech_officeapp'||getenv('DB_USERNAME')!=='root'||(string)getenv('DB_PASSWORD')!==''||preg_match('/^officeapp-rehearsal-[a-f0-9]{32}-php$/',(string)getenv('OFFICEAPP_REHEARSAL_CONTAINER'))!==1){fwrite(STDERR,'Rehearsal isolation environment rejected before opening any connection'.PHP_EOL);exit(1);}
require_once __DIR__ . '/../app/helpers/bootstrap.php';
require_once __DIR__ . '/rehearsal-baseline-audit.php';
use App\Database\MigrationRunner;
use App\Database\ReferenceDataSynchronizer;
$report=['result'=>'FAIL','database'=>'passiontech_officeapp','migration_events'=>[]];
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$tableSnapshot=static function(PDO $pdo,string $table):array{
    $q=$pdo->prepare("SELECT column_name FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name='PRIMARY' ORDER BY seq_in_index");$q->execute([$table]);$keys=$q->fetchAll(PDO::FETCH_COLUMN);
    if($keys===[])throw new RuntimeException('Stable snapshot requires a primary key: '.$table);
    $quote=static fn(string $name):string=>'`'.str_replace('`','``',$name).'`';
    $rows=$pdo->query('SELECT * FROM '.$quote($table).' ORDER BY '.implode(',',array_map($quote,$keys)))->fetchAll(PDO::FETCH_ASSOC);
    return ['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
};
try {
    $pdo=db();
    $assert(!(bool)$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),'Normal application PDO must retain native prepares');
    $report['initial_connection_collation']=$pdo->query('SELECT @@collation_connection')->fetchColumn();
    $assert($report['initial_connection_collation']==='utf8mb4_unicode_ci','Application PDO must establish the verified Unicode connection before rehearsal initialization');
    $assert(getenv('OFFICEAPP_REHEARSAL_ISOLATED')==='1'&&getenv('DB_HOST')==='db'&&getenv('DB_DATABASE')==='passiontech_officeapp'&&preg_match('/^officeapp-rehearsal-[a-f0-9]{32}-php$/',(string)getenv('OFFICEAPP_REHEARSAL_CONTAINER'))===1&&$pdo->query('SELECT DATABASE()')->fetchColumn()==='passiontech_officeapp','Rehearsal isolation environment/database mismatch');
    $report['container']=getenv('OFFICEAPP_REHEARSAL_CONTAINER');
    echo 'Isolated database: passiontech_officeapp; container: ',$report['container'],PHP_EOL;
    $diagnostic=getenv('OFFICEAPP_REHEARSAL_DIAGNOSTIC_ONLY')==='1';
    $expectedPath=__DIR__.'/rehearsal-expected-environment.json';
    $expected=$diagnostic?null:json_decode(ltrim((string)file_get_contents($expectedPath),"\xef\xbb\xbf"),true,512,JSON_THROW_ON_ERROR);
    if(!$diagnostic){
        $assert(is_array($expected)&&$expected!==[],'Complete proven production environment metadata is required');
        require_once __DIR__.'/../deployment/powerbi-upgrade-validation.php';
        $report['deployment_session']=\OfficeApp\Deployment\initializePowerBiUpgradeSession($pdo);
        initializeRehearsalSession($pdo,$expected);
    }
    $requiredViews=['vw_powerbi_bi_employees','vw_powerbi_cash_deposits','vw_powerbi_employees','vw_powerbi_fulfilled_sales','vw_powerbi_history_date_detail','vw_powerbi_history_export_rows','vw_powerbi_inventory_balance_reconciliation','vw_powerbi_inventory_daily','vw_powerbi_inventory_movements','vw_powerbi_locations','vw_powerbi_products','vw_powerbi_receipts','vw_powerbi_sales_agents','vw_powerbi_sales_order_lines','vw_powerbi_sales_orders','vw_powerbi_sales_payments','vw_powerbi_shop_hierarchy','vw_powerbi_warehouse_cluster_bridge','vw_powerbi_warehouse_territory_bridge','vw_powerbi_warehouses','vw_sales_user_authorized_orders','vw_sales_user_warehouse_location_scope','vw_user_warehouse_location_scope'];
    $report['baseline_view_health']=auditRehearsalBaseline($pdo,$diagnostic,$expected,$requiredViews);
    $report['production_environment']=$expected;
    if(getenv('OFFICEAPP_REHEARSAL_DIAGNOSTIC_ONLY')==='1'){
        $report['result']='DIAGNOSTIC_ONLY';
        $report['upgrade_attempted']=false;
        file_put_contents('/rehearsal-report/upgrade.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        echo 'Diagnostic view-health result: ',$report['result'],PHP_EOL;
        exit(1);
    }
    $assert($report['baseline_view_health']['result']==='PASS','Restored baseline view-health guard failed; migrations prohibited');
    require_once __DIR__.'/../deployment/powerbi-upgrade-validation.php';
    $report['deployment_session']=\OfficeApp\Deployment\initializePowerBiUpgradeSession($pdo);
    $report['baseline_deployment_health']=\OfficeApp\Deployment\auditPowerBiUpgradeViews($pdo,false);
    $viewMetadata=$report['baseline_view_health']['views']['vw_powerbi_fulfilled_sales'];
    $report['restored_fulfilled_sales_metadata']=['character_set_client'=>$viewMetadata['character_set_client'],'collation_connection'=>$viewMetadata['collation_connection']];
    $assert($viewMetadata['character_set_client']==='utf8mb4'&&$viewMetadata['collation_connection']==='utf8mb4_unicode_ci','Fulfilled-sales view creation metadata differs from production');
    $report['baseline_fulfilled_sales_count']=(int)$pdo->query('SELECT COUNT(*) FROM passiontech_officeapp.vw_powerbi_fulfilled_sales')->fetchColumn();
    $report['server_version']=$pdo->query('SELECT VERSION()')->fetchColumn();
    $report['baseline_062_checksum']=$pdo->query("SELECT checksum FROM schema_migrations WHERE version='062'")->fetchColumn();
    $assert($report['baseline_062_checksum']==='c7afbf6e450702ed1c512c5ace9e41045402660c50b23e2ebab7a1a3faff5550','Production 062 checksum differs from the explicitly permitted legacy checksum');
    $runner=new MigrationRunner($pdo,'mysql');$directory=__DIR__.'/../database/migrations/mysql';
    $audit=$runner->auditAppliedMigrations($directory);
    $report['restored_baseline']=end($audit['applied_versions']);
    $report['checksum_audit']=$audit;
    $pending=array_values(array_filter(array_map(static fn(string $file):string=>(string)(require $file)['version'],glob($directory.'/*.php')),static fn(string $version):bool=>!in_array($version,$audit['applied_versions'],true)));
    $assert($pending===array_map('strval',range(100,109)),'Pending catalog must contain exactly 100-109 before any mutation');
    $assert($report['restored_baseline']==='099'&&$audit['first_unapplied']==='100','Restored backup must end at 099 with first unapplied 100');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM schema_migration_steps')->fetchColumn()===0,'Restored migration step ledger is not empty');
    $report['baseline_counts']=[];
    foreach($pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN) as $table){$report['baseline_counts'][$table]=(int)$pdo->query('SELECT COUNT(*) FROM `'.str_replace('`','``',$table).'`')->fetchColumn();}
    $protectedTables=['bi_powerbi_history_rows','bi_powerbi_history_import_batches','sales_incentive_claims','sales_incentive_settlements','sales_incentive_events','sales_quick_sales','sales_quick_sale_reports'];
    $report['protected_baseline_data']=[];foreach($protectedTables as $table){$report['protected_baseline_data'][$table]=$tableSnapshot($pdo,$table);}
    $report['baseline_explicit_ids']=$pdo->query("SELECT external_id,entity_id FROM data_external_ids WHERE company_id=2 AND entity_type='warehouses' AND external_id REGEXP '^PBI-SHOP-[0-9]{3}$' ORDER BY external_id")->fetchAll(PDO::FETCH_ASSOC);
    $beforeViews=$report['baseline_view_health']['views'];
    $report['preexisting_definer_objects']=[];
    foreach($beforeViews as $name=>$view){$report['current_object']=$name;$pdo->query('SELECT * FROM `'.str_replace('`','``',$name).'` LIMIT 1')->fetchAll();$report['preexisting_definer_objects'][$name]='read-only SELECT resolved';}
    unset($report['current_object']);
    foreach($pdo->query('SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN) as $trigger){$report['preexisting_definer_objects'][$trigger]='Restored metadata; invocation cannot be validated without business mutation';}
    $report['first_preflight']=$runner->auditFirstUnappliedPreflight($directory);
    $assert($report['first_preflight']==='apply','Migration 100 must be apply');
    $warehouses=$pdo->query('SELECT * FROM inventory_warehouses WHERE company_id=2 AND warehouse_id IN(25,26) ORDER BY warehouse_id')->fetchAll(PDO::FETCH_ASSOC);
    $report['baseline_warehouse_25_26']=array_map(static fn(array $w):array=>['warehouse_id'=>$w['warehouse_id'],'company_id'=>$w['company_id'],'row_sha256'=>hash('sha256',json_encode($w,JSON_THROW_ON_ERROR))],$warehouses);
    $generic=$pdo->query("SELECT * FROM data_external_ids WHERE company_id=2 AND entity_type='warehouses' AND external_id NOT REGEXP '^PBI-SHOP-[0-9]{3}$' ORDER BY entity_id,external_id")->fetchAll(PDO::FETCH_ASSOC);
    $expectedMigrations=array_map('strval',range(100,109));
    $report['applied_sequence']=[];$report['per_migration_boundaries']=[];
    foreach($expectedMigrations as $version){
        $report['migration_events'][]=['version'=>$version,'event'=>'begin'];echo 'Migration ',$version,' begin',PHP_EOL;
        $step=$runner->runNext($directory,$version);
        $assert(($step['result']??null)==='applied'&&($step['version']??null)===$version,'Bounded migration did not apply exact expected version: '.$version);
        $current=(string)$pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
        $residueStatement=$pdo->prepare('SELECT COUNT(*) FROM schema_migration_steps WHERE version=?');$residueStatement->execute([$version]);$residue=(int)$residueStatement->fetchColumn();
        $assert($current===$version&&$residue===0,'Per-migration boundary failed: '.$version);
        $report['applied_sequence'][]=$version;$report['per_migration_boundaries'][]=['version'=>$version,'migration_after'=>$current,'step_residue'=>$residue];
        $report['migration_events'][]=['version'=>$version,'event'=>'end'];echo 'Migration ',$version,' end',PHP_EOL;
    }
    $assert($report['applied_sequence']===$expectedMigrations,'Upgrade must apply exactly 100-109 one migration per boundary');
    $report['reference_sync']=(new ReferenceDataSynchronizer($pdo,'mysql'))->run(__DIR__.'/../database/seeds');
    $ledgerBeforeAgain=$pdo->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
    $stepsBeforeAgain=$pdo->query('SELECT * FROM schema_migration_steps ORDER BY version,statement_number')->fetchAll(PDO::FETCH_ASSOC);
    $again=$runner->run($directory);
    $report['second_run']=$again;
    $assert($again['applied']===[]&&$again['baselined']===[]&&count($again['skipped'])===count(glob($directory.'/*.php')),'Second migration run must skip the entire catalog');
    $assert($pdo->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC)===$ledgerBeforeAgain&&$pdo->query('SELECT * FROM schema_migration_steps ORDER BY version,statement_number')->fetchAll(PDO::FETCH_ASSOC)===$stepsBeforeAgain,'Second run mutated a migration ledger');
    $report['idempotent_skip_count']=count($again['skipped']);
    $report['final_migration']=$pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
    $report['step_residue']=(int)$pdo->query('SELECT COUNT(*) FROM schema_migration_steps')->fetchColumn();
    $assert($report['final_migration']==='109'&&$report['step_residue']===0,'Final ledger/step residue invariant failed');
    $report['current_sql_modes']=$pdo->query('SELECT @@GLOBAL.sql_mode AS global_sql_mode,@@SESSION.sql_mode AS session_sql_mode')->fetch(PDO::FETCH_ASSOC);
    foreach($report['current_sql_modes'] as $mode)$assert(in_array('ONLY_FULL_GROUP_BY',explode(',',(string)$mode),true),'ONLY_FULL_GROUP_BY must remain enabled');
    $report['deployment_release_health']=\OfficeApp\Deployment\auditPowerBiUpgradeViews($pdo,true);
    $report['mysql84_readiness_audit']=$report['deployment_release_health']['readiness_audit'];
    $assert((int)$report['mysql84_readiness_audit']['company_id']===2&&$report['mysql84_readiness_audit']['reporting_mode']==='HISTORY_ONLY'&&$report['mysql84_readiness_audit']['live_cutover_date']===null&&(int)$report['mysql84_readiness_audit']['explicit_pbi_shop_count']===22&&(int)$report['mysql84_readiness_audit']['unexpected_scoped_external_ids']===0,'Final migration 109 runtime audit failed');
    $report['protected_data_after']=[];foreach($protectedTables as $table){$after=$tableSnapshot($pdo,$table);$report['protected_data_after'][$table]=$after;$assert($after===$report['protected_baseline_data'][$table],'History or native operational rows were changed/invented: '.$table);}
    $report['reporting_control']=$pdo->query('SELECT reporting_mode,live_cutover_date FROM bi_powerbi_reporting_control WHERE company_id=2')->fetch(PDO::FETCH_ASSOC);
    $assert($report['reporting_control']===['reporting_mode'=>'HISTORY_ONLY','live_cutover_date'=>null],'Reporting mode/cutover changed');
    $report['shop_scope']=$pdo->query('SELECT * FROM vw_powerbi_109_explicit_shop_scope_audit WHERE company_id=2')->fetch(PDO::FETCH_ASSOC);
    $assert((int)$report['shop_scope']['explicit_pbi_shop_count']===22&&(int)$report['shop_scope']['unexpected_scoped_external_ids']===0&&(int)$report['shop_scope']['current_shop_scope_rows']===22,'Explicit shop scope must be exactly 22');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM vw_powerbi_warehouses WHERE pbi_shop_id IS NOT NULL AND pbi_shop_id NOT REGEXP '^PBI-SHOP-[0-9]{3}$'")->fetchColumn()===0,'Unexpected scoped shop ID');
    $genericAfter=$pdo->query("SELECT * FROM data_external_ids WHERE company_id=2 AND entity_type='warehouses' AND external_id NOT REGEXP '^PBI-SHOP-[0-9]{3}$' ORDER BY entity_id,external_id")->fetchAll(PDO::FETCH_ASSOC);
    $assert($genericAfter===$generic,'Generic external IDs were deleted/rewritten');
    $assert($pdo->query('SELECT * FROM inventory_warehouses WHERE company_id=2 AND warehouse_id IN(25,26) ORDER BY warehouse_id')->fetchAll(PDO::FETCH_ASSOC)===$warehouses,'Warehouses 25/26 changed');
    $report['preserved_warehouse_25_26_count']=count($warehouses);$report['generic_ids_preserved']=count($generic);
    $report['generic_ids_scoped']=(int)$pdo->query("SELECT COUNT(*) FROM data_external_ids x INNER JOIN vw_powerbi_warehouses w ON w.company_id=x.company_id AND w.warehouse_id=x.entity_id WHERE x.entity_type='warehouses' AND x.external_id NOT REGEXP '^PBI-SHOP-[0-9]{3}$' AND w.pbi_shop_id=x.external_id")->fetchColumn();
    $assert($report['generic_ids_scoped']===0,'Generic external IDs must never become PBI shop IDs');
    $report['manager_counts']=$pdo->query('SELECT mapping_status,COUNT(*) shop_count FROM vw_powerbi_current_shop_manager_scope WHERE company_id=2 GROUP BY mapping_status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $assert((int)($report['manager_counts']['CONFIRMED_REPORTING_ASSIGNMENT']??0)===21&&(int)($report['manager_counts']['EXPLICIT_WAREHOUSE_MANAGER']??0)===1,'Manager governance must yield 21 + 1');
    $report['explicit_adi_hageray_manager']=$pdo->query("SELECT warehouse_id,manager_employee_id,mapping_status FROM vw_powerbi_current_shop_manager_scope WHERE pbi_shop_id='PBI-SHOP-022'")->fetch(PDO::FETCH_ASSOC);
    $assert((int)$report['explicit_adi_hageray_manager']['warehouse_id']===23&&(int)$report['explicit_adi_hageray_manager']['manager_employee_id']===105&&$report['explicit_adi_hageray_manager']['mapping_status']==='EXPLICIT_WAREHOUSE_MANAGER','Explicit Adi Hageray manager differs');
    $confirmedPairs=[2=>90,3=>101,4=>36,6=>104,5=>97,7=>106,8=>77,9=>102,10=>92,11=>96,12=>109,13=>103,14=>95,15=>107,16=>99,17=>98,18=>108,19=>93,20=>100,21=>94,22=>91];
    $report['governed_manager_assignments']=$pdo->query('SELECT warehouse_id,employee_id,status,mapping_source,effective_from,effective_to FROM bi_powerbi_shop_manager_assignments WHERE company_id=2 ORDER BY warehouse_id')->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($report['governed_manager_assignments'])===21,'Unexpected governed manager assignment count');
    foreach($report['governed_manager_assignments'] as $assignment){$assert(($confirmedPairs[(int)$assignment['warehouse_id']]??null)===(int)$assignment['employee_id']&&$assignment['status']==='confirmed'&&$assignment['mapping_source']==='BUSINESS_CONFIRMED_CURRENT_MANAGER_2026_09_30'&&$assignment['effective_from']==='2026-08-01'&&$assignment['effective_to']===null,'Unexpected governed manager mapping/effective dates');}
    $tables=$pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND table_name LIKE 'bi_powerbi_legacy_%'")->fetchAll(PDO::FETCH_COLUMN);
    $report['legacy_history_counts']=[];
    foreach($tables as $table){$count=(int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();$report['legacy_history_counts'][$table]=$count;$assert($count===0,'Migration invented legacy history: '.$table);}
    $report['current_object']='vw_powerbi_cutover_blockers';
    $report['current_query']='SELECT * FROM vw_powerbi_cutover_blockers';
    $report['cutover_blockers']=$pdo->query($report['current_query'])->fetchAll(PDO::FETCH_ASSOC);
    unset($report['current_object'],$report['current_query']);
    $report['object_validation']=[];
    $objects=$pdo->query('SELECT table_name,table_type FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);
    $report['required_upgrade_objects']=[];
    foreach(glob($directory.'/*.php') as $migrationFile){$definition=require $migrationFile;if((int)$definition['version']<100||(int)$definition['version']>109)continue;foreach($definition['statements'] as $sql){if(preg_match('/CREATE\s+(?:OR\s+REPLACE\s+)?(TABLE|VIEW)\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)/i',$sql,$match)){$name=$match[2];$kind=strtoupper($match[1])==='TABLE'?'BASE TABLE':'VIEW';$assert(($objects[$name]??null)===$kind,'Required upgrade object missing/wrong type: '.$name);$report['required_upgrade_objects'][$name]=$kind;}}}
    foreach($pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND table_name LIKE 'bi_powerbi_%'")->fetchAll(PDO::FETCH_COLUMN) as $table){
        $checked=$pdo->query('CHECK TABLE `'.str_replace('`','``',$table).'`')->fetchAll(PDO::FETCH_ASSOC);
        $report['object_validation'][$table]=$checked;
        $assert(count(array_filter($checked,static fn(array $r):bool=>$r['Msg_type']==='status'&&$r['Msg_text']==='OK'))>0,'CHECK TABLE failed: '.$table);
    }
    $report['changed_views']=[];
    foreach(array_map(static fn(array $row):array=>array_change_key_case($row,CASE_LOWER),$pdo->query('SELECT table_name,view_definition,definer FROM information_schema.views WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_ASSOC)) as $view){
        $name=$view['table_name'];$report['current_object']=$name;$report['current_query']='SELECT * FROM `'.str_replace('`','``',$name).'` LIMIT 1';$pdo->query($report['current_query'])->fetchAll();
        $report['object_validation'][$name]='SELECT resolved';
        if(!isset($beforeViews[$name])||$beforeViews[$name]['view_definition']!==$view['view_definition'])$report['changed_views'][]=$name;
    }
    unset($report['current_object'],$report['current_query']);
    $report['safaricom_contract_counts']=$pdo->query("SELECT mapping_status,COUNT(*) contract_count FROM bi_powerbi_live_source_contracts WHERE company_id=2 AND capability_code IN ('FLOAT_INCENTIVE_TO_SAFARICOM','FLOAT_INCENTIVE_REFUND_FROM_SAFARICOM') GROUP BY mapping_status")->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($report['safaricom_contract_counts'])===1&&$report['safaricom_contract_counts'][0]['mapping_status']==='NATIVE_READY'&&(int)$report['safaricom_contract_counts'][0]['contract_count']===2,'Safaricom semantic contracts must both be NATIVE_READY');
    $report['safaricom_contracts']=$pdo->query("SELECT capability_code,mapping_status,cutover_blocking,preferred_source_object FROM bi_powerbi_live_source_contracts WHERE company_id=2 AND capability_code IN ('FLOAT_INCENTIVE_TO_SAFARICOM','FLOAT_INCENTIVE_REFUND_FROM_SAFARICOM') ORDER BY capability_code")->fetchAll(PDO::FETCH_ASSOC);
    foreach($report['safaricom_contracts'] as $contract){$expectedSource=$contract['capability_code']==='FLOAT_INCENTIVE_TO_SAFARICOM'?'vw_powerbi_safaricom_claim_daily':'vw_powerbi_safaricom_refund_daily';$assert((int)$contract['cutover_blocking']===0&&$contract['preferred_source_object']===$expectedSource,'Safaricom contract source/blocking state differs');}
    $report['safaricom_semantic_audit']=$pdo->query('SELECT * FROM vw_powerbi_108_safaricom_semantic_audit WHERE company_id=2')->fetch(PDO::FETCH_ASSOC);
    $report['mussie_confirmed_overrides']=(int)$pdo->query("SELECT COUNT(*) FROM bi_powerbi_history_role_overrides WHERE company_id=2 AND status='confirmed' AND (LOWER(TRIM(legacy_employee_name))='mussie yohannes tsegay' OR LOWER(TRIM(canonical_employee_name))='mussie yohannes tsegay')")->fetchColumn();
    $report['mussie_fabricated_resolved_history']=(int)$pdo->query("SELECT COUNT(*) FROM vw_powerbi_compat_stock_detail WHERE company_id=2 AND SourceSystem='POWERBI_HISTORY' AND LOWER(TRIM(employee_name))='mussie yohannes tsegay' AND (role IS NOT NULL OR role_group IS NOT NULL OR role_mapping_status<>'UNRESOLVED_HISTORY_ROLE')")->fetchColumn();
    $assert($report['mussie_confirmed_overrides']===0&&$report['mussie_fabricated_resolved_history']===0,'Unresolved Mussie history was fabricated');
    $report['unresolved_history_rows']=(int)$pdo->query("SELECT COUNT(*) FROM vw_powerbi_compat_stock_detail WHERE company_id=2 AND CONVERT(SourceSystem USING utf8mb4) COLLATE utf8mb4_unicode_ci=_utf8mb4'POWERBI_HISTORY' COLLATE utf8mb4_unicode_ci AND CONVERT(role_mapping_status USING utf8mb4) COLLATE utf8mb4_unicode_ci=_utf8mb4'UNRESOLVED_HISTORY_ROLE' COLLATE utf8mb4_unicode_ci")->fetchColumn();
    $assert($report['unresolved_history_rows']===0,'Original zero-history backup must remain unchanged; 99 rows are validated only in the rollback fixture');
    $report['result']='PASS';
} catch(Throwable $e){
    $report['error']=$e->getMessage();
    if($e instanceof PDOException){$report['sql_error']=['sqlstate'=>$e->errorInfo[0]??null,'driver_code'=>$e->errorInfo[1]??null];}
    $events=$report['migration_events'];$lastEvent=$events===[]?null:end($events);if($lastEvent!==null&&$lastEvent['event']==='begin')$report['failed_migration']=$lastEvent['version'];
    if (isset($pdo)) {
        try {
            $report['applied_sequence']=$pdo->query("SELECT version FROM schema_migrations WHERE version>='100' ORDER BY version")->fetchAll(PDO::FETCH_COLUMN);
            $report['final_migration']=$pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
            $report['step_residue']=(int)$pdo->query('SELECT COUNT(*) FROM schema_migration_steps')->fetchColumn();
        } catch(Throwable $ignored) {}
    }
}
file_put_contents('/rehearsal-report/upgrade.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($report['result']==='PASS'?0:1);
