<?php
declare(strict_types=1);
require_once __DIR__.'/rehearsal-test-session.php';
$report=['result'=>'FAIL'];
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
try {
    $before=json_decode((string)file_get_contents('/rehearsal-report/upgrade.json'),true,512,JSON_THROW_ON_ERROR);
    $assert($before['result']==='PASS','Successful upgrade evidence required before post-test audit');
    $pdo=db();
    $report['view_health']=auditRehearsalBaseline($pdo,false,$profile);
    $assert($report['view_health']['result']==='PASS','Post-test view health or environment check failed');
    $pdo->exec('START TRANSACTION READ ONLY');
    try {
        $report['ledger_audit']=(new App\Database\MigrationRunner($pdo,'mysql'))->auditAppliedMigrations(__DIR__.'/../database/migrations/mysql');
        $report['final_migration']=$pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
        $report['step_residue']=(int)$pdo->query('SELECT COUNT(*) FROM schema_migration_steps')->fetchColumn();
        $assert($report['ledger_audit']['first_unapplied']===null&&$report['final_migration']==='109'&&$report['step_residue']===0,'Post-test migration ledger changed');
        $report['protected_data']=[];
        foreach($before['protected_baseline_data'] as $table=>$expected){
            $q=$pdo->prepare("SELECT column_name FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name='PRIMARY' ORDER BY seq_in_index");$q->execute([$table]);$keys=$q->fetchAll(PDO::FETCH_COLUMN);
            $assert($keys!==[],'Primary key required for post-test row snapshot');
            $quote=static fn(string $name):string=>'`'.str_replace('`','``',$name).'`';
            $rows=$pdo->query('SELECT * FROM '.$quote($table).' ORDER BY '.implode(',',array_map($quote,$keys)))->fetchAll(PDO::FETCH_ASSOC);
            $actual=['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
            $report['protected_data'][$table]=$actual;$assert($actual===$expected,'Post-test protected rows changed: '.$table);
        }
        $report['readiness_audit']=$pdo->query('SELECT r.company_id,r.reporting_mode,r.live_cutover_date,s.explicit_pbi_shop_count,s.unexpected_scoped_external_ids,(SELECT COUNT(*) FROM vw_powerbi_cutover_blockers b WHERE b.company_id=r.company_id) AS cutover_blocker_rows FROM vw_powerbi_reporting_readiness r INNER JOIN vw_powerbi_109_explicit_shop_scope_audit s ON s.company_id=r.company_id WHERE r.company_id=2')->fetch(PDO::FETCH_ASSOC);
        $audit=$report['readiness_audit'];
        $assert(is_array($audit)&&(int)$audit['company_id']===2&&$audit['reporting_mode']==='HISTORY_ONLY'&&$audit['live_cutover_date']===null&&(int)$audit['explicit_pbi_shop_count']===22&&(int)$audit['unexpected_scoped_external_ids']===0,'Post-test readiness/shop invariants failed');
        $report['unresolved_history_rows']=(int)$pdo->query('SELECT unresolved_history_role_rows FROM vw_powerbi_reporting_readiness WHERE company_id=2')->fetchColumn();
        $assert($report['unresolved_history_rows']===0,'Synthetic history fixture was retained');
        $report['result']='PASS';
    } finally {if($pdo->inTransaction())$pdo->rollBack();}
} catch(Throwable $e){$report['error']=$e->getMessage();}
file_put_contents('/rehearsal-report/post-tests-audit.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
echo 'Post-test audit: ',$report['result'],isset($report['error'])?'; '.$report['error']:'',PHP_EOL;
exit($report['result']==='PASS'?0:1);
