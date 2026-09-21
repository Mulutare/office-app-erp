<?php
require __DIR__.'/../app/helpers/bootstrap.php';
set_exception_handler(static function(Throwable $error): void { fwrite(STDERR,$error->getMessage()."\n"); exit(1); });
if(getenv('DB_DATABASE')!=='office_app_test')throw new RuntimeException('Isolated database required');
use App\Models\CompanyMembership;
use App\Services\UserPermissionOverrideService;
$memberships=new CompanyMembership();
$_SESSION['auth']=['user_id'=>121,'company'=>(new App\Models\CompanyModule())->companyById(2),'roles'=>['company_owner'],'is_platform_admin'=>false,'permissions'=>$memberships->permissionCodes(121,2)];
$service=new UserPermissionOverrideService();$initial=$service->formData(122);$roles=$memberships->roleCodes(122,2);$ids=array_column($initial['permissions'],'permission_id','code');$passed=0;
$snapshot=static function():array {$out=[];foreach(['company_user_roles','company_role_permissions','role_permissions'] as $table){$rows=db()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);$encoded=array_map(fn($row)=>json_encode($row),$rows);sort($encoded);$out[$table]=hash('sha256',implode("\n",$encoded));}return $out;};$baseline=$snapshot();
$auditCount=static function():int {return (int)db()->query("SELECT COUNT(*) FROM audit_logs WHERE company_id=2 AND user_id=121 AND action='USER_PERMISSION_OVERRIDE' AND table_name='users' AND record_id='122'")->fetchColumn();};$auditBefore=$auditCount();
$check=function(bool $ok,string $message)use(&$passed){if(!$ok)throw new RuntimeException($message);$passed++;echo "PASS $message\n";};
$set=function(array $choices)use($service,$ids){$form=$service->formData(122);$post=[];foreach($choices as $code=>$value)$post[$ids[$code]]=$value;$service->update(122,$post,$form['version'],121);};
try{
 $set(['sales.incentive.view'=>'deny']);
 $check(!in_array('sales.incentive.view',$memberships->permissionCodes(122,2),true),'User deny overrides role grant');
 $check(!(new App\Services\ModuleRoleService())->permissionAllowed(2,122,'sales.incentive.view'),'Database-backed service observes user deny');
 $set(['sales.orders.view'=>'allow']);
 $check(in_array('sales.orders.view',$memberships->permissionCodes(122,2),true),'User allow adds a function absent from role');
 $check($roles===$memberships->roleCodes(122,2),'Role assignments remain unchanged');
 $set(['sales.module.enabled'=>'deny']);
 $check(!in_array('sales.orders.view',$memberships->permissionCodes(122,2),true),'User module deny blocks explicit user function allow');
 $set(['sales.module.enabled'=>'inherit']);
 $check(in_array('sales.orders.view',$memberships->permissionCodes(122,2),true),'Re-enable restores stored user function choice');
 $set(['sales.incentive.view'=>'inherit']);
 $check(in_array('sales.incentive.view',$memberships->permissionCodes(122,2),true),'Use role setting restores inherited permission');
 $before=$service->formData(122);$set(['sales.incentive.view'=>'deny']);$rejected=false;
 try{$service->update(122,[$ids['sales.incentive.view']=>'allow'],$before['version'],121);}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'changed since');}
 $check($rejected,'Stale editor cannot overwrite concurrent permission change');
 $q=db()->prepare("SELECT COUNT(*) FROM audit_logs WHERE company_id=2 AND user_id=121 AND action='USER_PERMISSION_OVERRIDE' AND table_name='users' AND record_id='122'");$q->execute();
 $check($auditCount()-$auditBefore===6,'All six changes audited against target user and company');
 $check($snapshot()===$baseline,'All three role tables unchanged byte-for-byte');
}finally{
 $now=$service->formData(122);$restore=[];foreach($now['permissions'] as $p)$restore[$p['permission_id']]=$initial['overrides'][$p['permission_id']]??'inherit';
 $service->update(122,$restore,$now['version'],121);
}
echo "$passed user override checks passed\n";
