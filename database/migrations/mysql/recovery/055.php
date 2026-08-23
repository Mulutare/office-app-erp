<?php
declare(strict_types=1);
return static function(PDO $c):string{
 $tableExists=(int)$c->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='company_power_bi_configurations'")->fetchColumn()===1;
 $effects=[
  1=>(int)$c->query("SELECT COUNT(*) FROM erp_modules WHERE code='analytics' AND available=TRUE AND active=TRUE AND release_status='released'")->fetchColumn()===1,
  2=>$tableExists,
  3=>(int)$c->query("SELECT COUNT(*) FROM permissions WHERE code IN('analytics.view','analytics.configure')")->fetchColumn()===2,
  4=>(int)$c->query("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id WHERE p.code='analytics.view'")->fetchColumn()>0,
  5=>(int)$c->query("SELECT COUNT(*) FROM company_modules cm JOIN companies c ON c.company_id=cm.company_id JOIN erp_modules m ON m.module_id=cm.module_id WHERE c.code='sample-company' AND m.code='analytics' AND cm.license_status='active' AND cm.enabled=TRUE")->fetchColumn()===1,
  6=>$tableExists&&(int)$c->query("SELECT COUNT(*) FROM company_power_bi_configurations p JOIN companies c ON c.company_id=p.company_id WHERE c.code='sample-company' AND p.report_id='0d5b5815-9d8f-4542-b3bc-38498f382ba4'")->fetchColumn()===1,
 ];
 $done=array_map('intval',$c->query("SELECT statement_number FROM schema_migration_steps WHERE version='055' ORDER BY statement_number")->fetchAll(PDO::FETCH_COLUMN));
 if($done===[]){if(!in_array(true,$effects,true))return 'apply';if(!in_array(false,$effects,true))return 'baseline';throw new RuntimeException('Migration 055 found an untracked partial Analytics schema.');}
 if($done!==range(1,max($done)))throw new RuntimeException('Migration 055 recovery steps are not a valid completed prefix.');
 foreach($done as $n)if(empty($effects[$n]))throw new RuntimeException('Migration 055 recovery metadata does not match the database structure.');
 foreach(array_keys($effects) as $n)if($n>max($done)&&$effects[$n])throw new RuntimeException('Migration 055 contains an unrecorded out-of-order effect.');
 return 'apply';
};
