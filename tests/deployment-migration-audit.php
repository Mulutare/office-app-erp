<?php

declare(strict_types=1);
require __DIR__ . '/../app/database/MigrationRunner.php';
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
$passed=0;$failed=0;
$check=static function(bool $ok,string $name)use(&$passed,&$failed){echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;$ok?$passed++:$failed++;};
$reject=static function(callable $action):bool{try{$action();return false;}catch(RuntimeException){return true;}};
$temp=sys_get_temp_dir().'/officeapp-audit-'.bin2hex(random_bytes(8));
try {
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
    $check(str_contains($source,"if(in_array(\$action,['runner-status','staged-migration-audit'],true)){require_once LIVE_ROOT.'/app/helpers/autoload.php';require_once LIVE_ROOT.'/app/helpers/database.php';}else{require_once LIVE_ROOT.'/app/helpers/bootstrap.php';}"),'Read-only actions bypass the session-writing application bootstrap');
    $identity=explode("elseif(\$action==='staged-migration-audit')",explode("if(\$action==='runner-status')",$source)[1])[0];
    $check(str_contains($identity,'normalizedSourceSha(__FILE__)')&&str_contains($identity,'RUNNER_PROTOCOL_VERSION')&&str_contains($identity,'RUNNER_BUILD_ID')&&str_contains($identity,'PHP_VERSION'),'Identity returns compiled protocol/build and normalized executed file SHA/PHP');
    $check(str_contains($identity,'MigrationRunner::class,false')&&str_contains($identity,'ReferenceDataSynchronizer::class,false'),'Identity stale-class flags never trigger autoload');
    $branch=explode("elseif(\$action==='migration-status')",explode("elseif(\$action==='staged-migration-audit')",$source)[1])[0];
    $check(str_contains($branch,"\$state['state']!=='staged'")&&str_contains($branch,'$headerRoot!==$root')&&str_contains($branch,"require\$root.'/app/database/MigrationRunner.php'"),'Staged audit requires validated staged state/root and explicit staged runner');
    $check(str_contains($branch,'MigrationRunner::class,false')&&str_contains($branch,'auditAppliedMigrations')&&str_contains($branch,'auditFirstUnappliedPreflight')&&!str_contains($branch,'->run(')&&!str_contains($branch,'saveMetadata('),'Staged audit never executes migrations or writes deployment state');
    $check(str_contains($branch,'initializePowerBiUpgradeSession($pdo)')&&str_contains($branch,'auditPowerBiUpgradeViews($pdo,false)'),'Staged audit proves the actual Unicode session and baseline view health');
    $health=explode("elseif(\$action==='cutover')",explode("elseif(\$action==='release-health')",$source)[1])[0];
    $check(str_contains($health,'initializePowerBiUpgradeSession($pdo)')&&str_contains($health,'auditPowerBiUpgradeViews($pdo,true)'),'Release health queries completed Power BI views before cutover');
} catch(Throwable $e){echo 'FAIL '.$e->getMessage().PHP_EOL;$failed++;}
finally {foreach(glob($temp.'/*')?:[] as $file)unlink($file);if(is_dir($temp))rmdir($temp);}
echo ($passed+$failed).' deployment audit checks, '.$failed.' failures'.PHP_EOL;
exit($failed?1:0);
