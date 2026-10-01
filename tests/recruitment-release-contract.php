<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/bootstrap.php';
require __DIR__.'/../deployment/powerbi-upgrade-validation.php';
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage()."\n");exit(1);});
if(getenv('APP_ENV')!=='testing'||getenv('DB_DATABASE')!=='office_app_test')throw new RuntimeException('Disposable fixtures required');
$p=db();$passed=0;$check=function($ok,$label)use(&$passed){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";$passed++;};
$reject=function($call){try{$call();return false;}catch(RuntimeException){return true;}};
$audit=fn()=>OfficeApp\Deployment\auditPowerBiUpgradeLedger($p,true);
$check($audit()['target']==='111','Release ledger audit accepts reviewed recruitment successor with exact Power BI prefix');
foreach(['old110','gap','future','checksum','baseline099'] as $case){
 $p->beginTransaction();
 try{
  if($case==='old110'){$p->exec("DELETE FROM schema_migrations WHERE version='111'");$check($audit()['target']==='110','Existing focused 110 validation remains accepted');}
  if($case==='gap'){$p->exec("DELETE FROM schema_migrations WHERE version='098'");$check($reject($audit),'Release audit rejects missing historical prefix version');}
  if($case==='future'){$p->exec("INSERT INTO schema_migrations(version,description,checksum) VALUES('112','Synthetic future migration',REPEAT('a',64))");$check($reject($audit),'Release audit rejects unknown future migrations');}
  if($case==='checksum'){$p->exec("UPDATE schema_migrations SET checksum=REPEAT('a',64) WHERE version='111'");$check($reject($audit),'Release audit rejects modified recruitment checksum');}
  if($case==='baseline099'){$p->exec("DELETE FROM schema_migrations WHERE version>'099'");$check(OfficeApp\Deployment\auditPowerBiUpgradeLedger($p,false)['target']==='099','Read-only staged baseline remains exactly 099');}
 }finally{$p->rollBack();}
}
$check($audit()['target']==='111'&&!$p->inTransaction(),'All forged-ledger fixtures rolled back without residue');
echo "$passed recruitment release contract checks passed\n";
