<?php

declare(strict_types=1);
require __DIR__ . '/../app/database/MigrationRunner.php';
require_once __DIR__ . '/../deployment/powerbi-upgrade-validation.php';
use App\Database\MigrationRunner;
class AuditRows extends PDOStatement {
    public function __construct(private array $rows) {}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {return $this->rows;}
}
class AuditConnection extends PDO {
    public bool $transaction=false;
    public int $writes=0;
    public function __construct(public array $rows) {}
    public function query(string $sql,?int $fetchMode=null,mixed ...$args): PDOStatement|false {
        if ($sql!=='SELECT version, checksum FROM schema_migrations ORDER BY version') throw new RuntimeException('Unexpected query');
        return new AuditRows($this->rows);
    }
    public function exec(string $sql): int|false {
        if ($sql==='START TRANSACTION READ ONLY') {$this->transaction=true;return 0;}
        $this->writes++;
        throw new RuntimeException('Read-only transaction blocks writes');
    }
    public function inTransaction(): bool {return $this->transaction;}
    public function rollBack(): bool {$this->transaction=false;return true;}
}
class UpgradeProtocolConnection extends PDO {
    public bool $emulated=false;
    public bool $accept=true;
    public bool $retain=true;
    public bool $transaction=false;
    public int $sqlCalls=0;
    public function __construct() {}
    public function getAttribute(int $attribute): mixed {
        return $attribute===PDO::ATTR_DRIVER_NAME?'mysql':$this->emulated;
    }
    public function setAttribute(int $attribute,mixed $value): bool {
        if($attribute!==PDO::ATTR_EMULATE_PREPARES||$value!==true)throw new RuntimeException('Unexpected attribute change');
        if($this->accept&&$this->retain)$this->emulated=true;
        return $this->accept;
    }
    public function query(string $sql,?int $fetchMode=null,mixed ...$args): PDOStatement|false {
        ++$this->sqlCalls;
        throw new RuntimeException('SQL error sentinel 1615');
    }
    public function exec(string $sql): int|false {
        if($sql==='START TRANSACTION READ ONLY'){$this->transaction=true;return 0;}
        ++$this->sqlCalls;throw new RuntimeException('Unexpected SQL');
    }
    public function inTransaction(): bool {return $this->transaction;}
    public function rollBack(): bool {$this->transaction=false;return true;}
}
$passed=0;$failed=0;
$check=static function(bool $ok,string $name)use(&$passed,&$failed){echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;$ok?$passed++:$failed++;};
$reject=static function(callable $action):bool{try{$action();return false;}catch(RuntimeException){return true;}};
$temp=sys_get_temp_dir().'/officeapp-audit-'.bin2hex(random_bytes(8));
try {
    foreach([false,true] as $release){
        $protocol=new UpgradeProtocolConnection();
        $check($reject(fn()=>OfficeApp\Deployment\auditPowerBiUpgradeViews($protocol,$release))&&$protocol->sqlCalls===0,'Native prepares rejected before any SQL for target '.($release?'109':'099'));
    }
    foreach(['accept','retain'] as $failure){
        $protocol=new UpgradeProtocolConnection();$protocol->$failure=false;
        $check($reject(fn()=>OfficeApp\Deployment\initializePowerBiUpgradeSession($protocol))&&$protocol->sqlCalls===0,'Initializer fails closed before SQL when emulation '.$failure.' fails');
    }
    $protocol=new UpgradeProtocolConnection();
    $errorMessage='';
    try{OfficeApp\Deployment\initializePowerBiUpgradeSession($protocol);}catch(RuntimeException $error){$errorMessage=$error->getMessage();}
    $check($protocol->emulated&&$protocol->sqlCalls===1&&$errorMessage==='SQL error sentinel 1615','Initializer enables text protocol before querying and propagates SQL errors without retries');
    $protocol=new UpgradeProtocolConnection();$protocol->emulated=true;$errorMessage='';
    try{OfficeApp\Deployment\auditPowerBiUpgradeViews($protocol);}catch(RuntimeException $error){$errorMessage=$error->getMessage();}
    $check($protocol->sqlCalls===1&&$errorMessage==='SQL error sentinel 1615'&&!$protocol->transaction,'Text-protocol audit propagates SQL errors without retries and closes its read-only transaction');
    $driver=(string)file_get_contents(__DIR__.'/../app/database/MySqlDriver.php');
    $check(str_contains($driver,'PDO::ATTR_EMULATE_PREPARES => false'),'Normal application driver retains native prepares');
    mkdir($temp,0700);
    $first="<?php return ['version'=>'001','description'=>'Applied fixture','statements'=>['INVALID SQL MUST NEVER RUN'],'preflight'=>static function(PDO \$p):string{throw new RuntimeException('Applied preflight must never run');}];\n";
    $next="<?php return ['version'=>'002','description'=>'Next fixture','statements'=>['INVALID SQL MUST NEVER RUN'],'preflight'=>static function(PDO \$p):string{return 'apply';}];\n";
    file_put_contents($temp.'/001_fixture.php',$first);file_put_contents($temp.'/002_fixture.php',$next);
    $pdo=new AuditConnection([['version'=>'001','checksum'=>hash('sha256',$first)]]);
    $runner=new MigrationRunner($pdo,'mysql');
    $audit=$runner->auditAppliedMigrations($temp);
    $check($audit===['applied_versions'=>['001'],'first_unapplied'=>'002'],'Audit validates ledger and first unapplied without SQL/preflight execution');
    $check($runner->auditFirstUnappliedPreflight($temp)==='apply'&&!$pdo->transaction&&$pdo->writes===0,'Only first preflight evaluates in a rolled-back read-only transaction');
    $pdo->rows=[['version'=>'002','checksum'=>hash('sha256',$next)]];
    $check($reject(fn()=>$runner->auditAppliedMigrations($temp)),'Ledger gaps are rejected');
    $pdo->rows=[['version'=>'003','checksum'=>str_repeat('f',64)]];
    $check($reject(fn()=>$runner->auditAppliedMigrations($temp)),'Unknown applied catalog versions are rejected');
    $pdo->rows=[['version'=>'001','checksum'=>str_repeat('f',64)]];
    $check($reject(fn()=>$runner->auditAppliedMigrations($temp)),'Modified applied checksums are rejected');
    $pdo->rows=[['version'=>'001','checksum'=>hash('sha256',$first)]];
    file_put_contents($temp.'/001_duplicate.php',$first);
    $check($reject(fn()=>$runner->auditAppliedMigrations($temp)),'Duplicate catalog versions are rejected');
    unlink($temp.'/001_duplicate.php');
    file_put_contents($temp.'/001_fixture.php',str_replace("\n","\r\n",$first));
    $check($runner->auditAppliedMigrations($temp)['first_unapplied']==='002','Audit uses normalized LF/CRLF checksums');
    file_put_contents($temp.'/002_fixture.php',str_replace("return 'apply';","\$p->exec('UPDATE unsafe SET x=1');return 'apply';",$next));
    $check($reject(fn()=>$runner->auditFirstUnappliedPreflight($temp))&&!$pdo->transaction,'Mutating preflight is refused and transaction is cleaned');
    $method=new ReflectionMethod(MigrationRunner::class,'checksumsMatchVersion');
    $check($method->invoke($runner,'062','c7afbf6e450702ed1c512c5ace9e41045402660c50b23e2ebab7a1a3faff5550','1d85d826ec2d6fb1255e0e36ec6b6390e445788afbc3b72e15d1c61e13e0699e'),'Read-only audit honors exact 062 compatibility');
    $source=(string)file_get_contents(__DIR__.'/../deployment/production-runner.php');
    $check(str_contains($source,"['runner-status','staged-migration-audit','migration-status','preflight'],true"),'Identity and staged audit actions bypass nonce/log mutations');
    $check(str_contains($source,"require_once LIVE_ROOT.'/app/helpers/autoload.php';require_once LIVE_ROOT.'/app/helpers/database.php';")&&!str_contains($source,"app/helpers/bootstrap.php"),'All deployment actions bypass the session-writing application bootstrap');
    $identity=explode("elseif(\$action==='staged-migration-audit')",explode("if(\$action==='runner-status')",$source)[1])[0];
    $check(str_contains($identity,'normalizedSourceSha(__FILE__)')&&str_contains($identity,'RUNNER_PROTOCOL_VERSION')&&str_contains($identity,'RUNNER_BUILD_ID')&&str_contains($identity,'PHP_VERSION'),'Identity returns compiled protocol/build and normalized executed file SHA/PHP');
    $check(str_contains($identity,'MigrationRunner::class,false')&&str_contains($identity,'ReferenceDataSynchronizer::class,false'),'Identity stale-class flags never trigger autoload');
    $branch=explode("elseif(\$action==='migration-status')",explode("elseif(\$action==='staged-migration-audit')",$source)[1])[0];
    $check(str_contains($branch,"\$state['state']!=='staged'")&&str_contains($branch,'$headerRoot!==$root')&&str_contains($branch,"require\$root.'/app/database/MigrationRunner.php'"),'Staged audit requires validated staged state/root and explicit staged runner');
    $check(str_contains($branch,'MigrationRunner::class,false')&&str_contains($branch,'auditAppliedMigrations')&&str_contains($branch,'auditFirstUnappliedPreflight')&&!str_contains($branch,'->run(')&&!str_contains($branch,'saveMetadata('),'Staged audit never executes migrations or writes deployment state');
    $check(str_contains($branch,'initializePowerBiUpgradeSession($pdo)')&&str_contains($branch,'auditPowerBiUpgradeViews($pdo,false)'),'Staged audit proves the actual Unicode session and baseline view health');
    $runnerSource=(string)file_get_contents(__DIR__.'/../app/database/MigrationRunner.php');
    $check(str_contains($runnerSource,'public function runNext(string $directory, string $expectedVersion)')&&str_contains($runnerSource,'$this->auditAppliedMigrations($directory)'),'MigrationRunner exposes bounded exact-next execution backed by ledger audit');
    $check(str_contains($source,"'migrate-next'")&&!str_contains($source,"elseif(\$action==='migrate')"),'Runner removes monolithic migrate action');
    $migrateNext=explode("elseif(\$action==='sync-reference-data')",explode("elseif(\$action==='migrate-next')",$source)[1])[0];
    $check(str_contains($migrateNext,'runNext($directory,$expectedVersion)')&&str_contains($migrateNext,'step_residue_for_version'),'Runner advances exactly one clean migration boundary');
    $check(str_contains($source,'JSON_INVALID_UTF8_SUBSTITUTE')&&str_contains($source,'deployment_fatal_error')&&str_contains($source,'php_execution_timeout')&&str_contains($source,'php_memory_exhausted'),'Runner protects and classifies fatal JSON responses');
    $check(str_contains($source,"\$longActions=['preflight','migrate-next','sync-reference-data','release-health']")&&str_contains($source,"set_time_limit(0)")&&str_contains($source,"\$runtimeLimitAfter!=='0'"),'Long deployment actions disable the PHP request timeout or fail before database mutation');
    $syncBranch=explode("elseif(\$action==='release-health')",explode("elseif(\$action==='sync-reference-data')",$source)[1])[0];
    $check(str_contains($syncBranch,"if(\$current!=='109')")&&str_contains($syncBranch,'Reference sync requires completed migration 109.'),'Reference sync is impossible before migration 109 is complete');
    $health=explode("elseif(\$action==='cutover')",explode("elseif(\$action==='release-health')",$source)[1])[0];
    $check(str_contains($health,'initializePowerBiUpgradeSession($pdo)')&&str_contains($health,'auditPowerBiUpgradeViews($pdo,true)')&&str_contains($health,"\$result['release_target']='109'"),'Release health queries completed target-109 Power BI views before cutover');
    $validation=(string)file_get_contents(__DIR__.'/../deployment/powerbi-upgrade-validation.php');
    $check(str_contains($validation,'bool $requireRelease109 = false')&&str_contains($validation,'range(15, $requireRelease109 ? 109 : 99)'),'Shared validator requires exact 015-109 release or 015-099 baseline ledgers');
    $check(str_contains($validation,"\$report['step_residue'] !== 0"),'Shared validator rejects migration step residue');
    $check(str_contains($validation,"'vw_powerbi_live_stock_detail', 'vw_powerbi_cutover_blockers'")&&str_contains($validation,"'vw_powerbi_reporting_readiness', 'vw_powerbi_109_explicit_shop_scope_audit'")&&!str_contains($validation,'vw_powerbi_110_'),'Shared validator requires repaired stock, blockers, readiness and 109 audit without a 110 sentinel');
    $check(str_contains($validation,'while ($statement->fetch(PDO::FETCH_NUM) !== false)')&&str_contains($validation,'if ($pdo->inTransaction()) $pdo->rollBack();'),'Shared validator consumes every view and closes its owned read-only transaction');
    $check(str_contains($validation,'FROM bi_powerbi_reporting_control c')&&str_contains($validation,'INNER JOIN vw_powerbi_109_explicit_shop_scope_audit a')&&str_contains($validation,'validatePowerBiUpgradeReadinessMetadata($audit);'),'Actual release helper validates direct governed readiness metadata and counts');
    $metadata=['company_id'=>2,'cutover_blocker_rows'=>0,'reporting_mode'=>'HISTORY_ONLY','live_cutover_date'=>null,'explicit_pbi_shop_count'=>22,'unexpected_scoped_external_ids'=>0];
    $check(!$reject(fn()=>OfficeApp\Deployment\validatePowerBiUpgradeReadinessMetadata($metadata)),'Target-109 readiness accepts the unchanged zero-history backup state');
    $withBlockers=$metadata;$withBlockers['cutover_blocker_rows']=99;
    $check(!$reject(fn()=>OfficeApp\Deployment\validatePowerBiUpgradeReadinessMetadata($withBlockers)),'Target-109 readiness permits nonzero governed blockers');
    foreach(['company_id'=>1,'reporting_mode'=>'HISTORY_THEN_LIVE','live_cutover_date'=>'2026-08-01','explicit_pbi_shop_count'=>21,'unexpected_scoped_external_ids'=>1,'cutover_blocker_rows'=>-1] as $field=>$value){
        $invalid=$metadata;$invalid[$field]=$value;
        $check($reject(fn()=>OfficeApp\Deployment\validatePowerBiUpgradeReadinessMetadata($invalid)),'Target-109 readiness rejects invalid '.$field);
    }
    $invalid=$metadata;unset($invalid['live_cutover_date']);
    $check($reject(fn()=>OfficeApp\Deployment\validatePowerBiUpgradeReadinessMetadata($invalid)),'Target-109 readiness requires explicit null cutover metadata');
} catch(Throwable $e){echo 'FAIL '.$e->getMessage().PHP_EOL;$failed++;}
finally {foreach(glob($temp.'/*')?:[] as $file)unlink($file);if(is_dir($temp))rmdir($temp);}
echo ($passed+$failed).' deployment audit checks, '.$failed.' failures'.PHP_EOL;
exit($failed?1:0);
