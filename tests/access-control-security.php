<?php
require __DIR__.'/../app/helpers/bootstrap.php';set_exception_handler(function($e){fwrite(STDERR,$e->getMessage()."\n");exit(1);});if(getenv('DB_DATABASE')!=='office_app_test')throw new RuntimeException('Isolated database required');
use App\Services\UserPermissionOverrideService;use App\Models\CompanyMembership;
$m=new CompanyMembership();$_SESSION['auth']=['user_id'=>121,'company'=>(new App\Models\CompanyModule())->companyById(2),'is_platform_admin'=>false];$s=new UserPermissionOverrideService();$initial=$s->formData(122);$ids=array_column($initial['permissions'],'permission_id','code');$b=(int)db()->query("SELECT user_id FROM users WHERE username='access.manager.b.local'")->fetchColumn();$passed=0;$sid=0;
$check=function($ok,$label)use(&$passed){if(!$ok)throw new RuntimeException('FAIL '.$label);$passed++;echo 'PASS '.$label."\n";};$reject=function($call){try{$call();return false;}catch(RuntimeException $e){return true;}};
try{
 $s->update(122,[$ids['administration.module.enabled']=>'allow',$ids['administration.roles.manage']=>'allow'],$initial['version'],121);
 $f=$s->formData($b);$check($reject(fn()=>$s->update($b,[$ids['finance.records.view']=>'allow'],$f['version'],122)),'Access administrator cannot grant a capability they lack');
 $check($reject(fn()=>$s->update($b,[999999=>'allow'],$f['version'],121)),'Unknown permission injection rejected');
 $platform=(int)db()->query('SELECT user_id FROM users WHERE is_platform_admin=TRUE LIMIT 1')->fetchColumn();$check($platform>0&&$reject(fn()=>$s->formData($platform)),'Company editor cannot edit platform administrator');
 $company=$_SESSION['auth']['company'];$_SESSION['auth']['company']=(new App\Models\CompanyModule())->companyById(1);$check($reject(fn()=>$s->formData(122)),'Company editor cannot inspect a foreign membership');$_SESSION['auth']['company']=$company;
 $scope=new App\Services\SalesHierarchyScope();$check($scope->canReadSalesRow(2,115,['company_id'=>2,'created_by'=>122]),'District can read records in authoritative child shop');$check(!$scope->canReadSalesRow(2,115,['company_id'=>2,'created_by'=>121]),'District cannot read records outside its authoritative hierarchy');$check(!$scope->hasCompanyWideAccess(2,115),'District role does not imply company-wide Sales scope');
 $q=db()->prepare("INSERT INTO sales_settlements(company_id,settlement_number,bank_account_id,currency,expected_amount,variance_amount,remaining_amount,workflow_status,created_by,submitted_by) SELECT company_id,?,bank_account_id,currency,1,-1,1,'submitted',122,122 FROM sales_settlements WHERE settlement_id=1");$q->execute(['ACL-CHECK-'.bin2hex(random_bytes(4))]);$sid=(int)db()->lastInsertId();
 $result=(new App\Services\SettlementService())->transition($sid,'review','Test maker/checker',122);$check(!$result['successful']&&str_contains($result['errors']['form'],'cannot review their own'),'User with review permission cannot review their own settlement');
 $result=(new App\Services\SettlementService())->transition($sid,'submit','Test state',122);$check(!$result['successful']&&str_contains($result['errors']['form'],'current status'),'User permission does not bypass workflow state');
 $check((new App\Models\User())->permissionCodes(2,122)===$m->permissionCodes(122,2),'User profile and session use identical effective resolver');
}finally{
 $_SESSION['auth']['company']=(new App\Models\CompanyModule())->companyById(2);$now=$s->formData(122);$restore=[];foreach($now['permissions'] as $p)$restore[$p['permission_id']]=$initial['overrides'][$p['permission_id']]??'inherit';$s->update(122,$restore,$now['version'],121);
 if($sid){db()->prepare('DELETE FROM sales_settlement_events WHERE company_id=2 AND settlement_id=?')->execute([$sid]);db()->prepare('DELETE FROM sales_settlements WHERE company_id=2 AND settlement_id=?')->execute([$sid]);}
}
echo "$passed security and hierarchy checks passed\n";
