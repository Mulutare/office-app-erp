<?php
require __DIR__.'/../app/helpers/bootstrap.php';
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage()."\n");exit(1);});
if(getenv('DB_DATABASE')!=='office_app_test')throw new RuntimeException('Isolated database required');
use App\Models\CompanyMembership;use App\Services\UserPermissionOverrideService;use App\Services\WorkspaceAccessService;use App\Services\SalesHierarchyScope;
$b=(int)db()->query("SELECT user_id FROM users WHERE username='access.manager.b.local'")->fetchColumn();if($b<1)throw new RuntimeException('Manager B local fixture is required');$m=new CompanyMembership();$svc=new UserPermissionOverrideService();
$_SESSION['auth']=['user_id'=>121,'company'=>(new App\Models\CompanyModule())->companyById(2),'roles'=>['company_owner'],'is_platform_admin'=>false,'permissions'=>$m->permissionCodes(121,2)];
$initial=[];foreach([122,$b] as $id)$initial[$id]=$svc->formData($id);
$ids=array_column($initial[122]['permissions'],'permission_id','code');$checks=0;
$reportBefore=db()->query('SELECT status FROM sales_quick_sale_reports WHERE report_id=1')->fetchColumn();$quickBefore=db()->query('SELECT status FROM sales_quick_sales WHERE quick_sale_id=11')->fetchColumn();
$check=function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label."\n";};
$set=function($user,$choices)use($svc,$ids){$f=$svc->formData($user);$post=[];foreach($choices as $code=>$value)$post[$ids[$code]]=$value;$svc->update($user,$post,$f['version'],121);};
$snapshot=static function(){ $out=[];foreach(['company_user_roles','company_role_permissions','role_permissions'] as $t){$rows=array_map('json_encode',db()->query('SELECT * FROM '.$t)->fetchAll(PDO::FETCH_ASSOC));sort($rows);$out[$t]=hash('sha256',implode("\n",$rows));}return $out;};$before=$snapshot();
$jars=[];$request=function($user,$path,$post=null)use(&$jars){$jars[$user]??=tempnam(sys_get_temp_dir(),'acl');$c=curl_init('http://127.0.0.1:8080/office_app/public'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$jars[$user],CURLOPT_COOKIEFILE=>$jars[$user],CURLOPT_TIMEOUT=>30]);if($post!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);$body=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$status,$body];};
$token=static function($html){preg_match('/name="_token"\s+value="([^"]+)"/',$html,$v);return html_entity_decode($v[1]??'');};
$moduleId=(int)db()->query("SELECT module_id FROM erp_modules WHERE code='sales'")->fetchColumn();$enabled=(int)db()->query("SELECT enabled FROM company_modules WHERE company_id=2 AND module_id=$moduleId")->fetchColumn();
try{
 $set(122,['sales.report.review'=>'allow','sales.settlements.view'=>'allow','sales.incentive.view'=>'inherit']);
 $set($b,['sales.report.review'=>'deny','sales.settlements.view'=>'deny','sales.incentive.view'=>'deny']);
 $check($m->roleCodes(122,2)===$m->roleCodes($b,2),'A and B share identical company role assignment');
 foreach([122=>'sales.manager.verify.local',$b=>'access.manager.b.local'] as $id=>$login){[, $html]=$request($id,'/login');[$status]=$request($id,'/login',['_token'=>$token($html),'login'=>$login,'password'=>'Access-Test!2026']);$check($status===302,'Login test user '.$id);}
 foreach(['dsa_dsp_report','settlements'] as $key){$item=WorkspaceAccessService::definitions()['sales'][$key];$check(WorkspaceAccessService::allowed($item,$m->permissionCodes(122,2))&&!WorkspaceAccessService::allowed($item,$m->permissionCodes($b,2)),'Same role different effective taskbar '.$key);[$a]=$request(122,$item[1]);[$status]=$request($b,$item[1]);$check($a===200&&$status===403,'Same role HTTP Allow 200 / Deny 403 '.$key);}
 [$status]=$request(122,'/sales/settlements/1');$check($status===200,'Manager A own settlement detail opens');
 [$status]=$request(122,'/sales/quick-sale/11');$check($status===200,'Manager A authorized Quick Sale detail opens');
 $set($b,['sales.report.review'=>'allow','sales.settlements.view'=>'allow']);
 [$status]=$request($b,'/sales/settlements/1');$check(in_array($status,[403,404],true),'Manager B Allow does not open another shop settlement');
 [$status]=$request($b,'/sales/quick-sale/11');$check(in_array($status,[403,404],true),'Manager B Allow does not open another shop Quick Sale');
 $scope=new SalesHierarchyScope();$check($scope->canReadSalesRow(2,122,['company_id'=>2,'created_by'=>123])&&!$scope->canReadSalesRow(2,122,['company_id'=>2,'created_by'=>$b]),'Manager A own hierarchy allowed; other shop denied');
 $check(!$scope->canReadSalesRow(3,122,['company_id'=>3,'created_by'=>122]),'User permission cannot cross tenant');
 $set($b,['sales.report.review'=>'deny','sales.settlements.view'=>'deny']);
 db()->exec("UPDATE sales_quick_sales SET status='reported' WHERE quick_sale_id=11");db()->exec("UPDATE sales_quick_sale_reports SET status='submitted' WHERE report_id=1");
 $tasks=new App\Services\ActionRequiredCountService();
 $reviewItems=$tasks->itemsFor(2,122,$m->permissionCodes(122,2),'sales','quick_sale');$check(count(array_filter($reviewItems,fn($row)=>$row['next_action']==='Confirm Sales Report'))===1,'Allowed manager receives scoped Sales Report review task');
 $set(122,['sales.report.review'=>'deny']);$deniedItems=$tasks->itemsFor(2,122,$m->permissionCodes(122,2),'sales','quick_sale');$check(count(array_filter($deniedItems,fn($row)=>$row['next_action']==='Confirm Sales Report'))===0,'User Deny removes existing Sales Report review task');
 $deniedCounts=$tasks->counts(2,122,$m->permissionCodes(122,2));$check($deniedCounts['sales']['quick_sale']===count($deniedItems),'Badge equals authorized tasks after user Deny');$set(122,['sales.report.review'=>'allow']);
$check($tasks->itemsFor(2,$b,$m->permissionCodes($b,2),'sales','dsa_dsp_report')===[],'Denied Sales Report has no actionable task');$counts=$tasks->counts(2,$b,$m->permissionCodes($b,2));$check(($counts['sales']['dsa_dsp_report']['action_required']??0)===0,'Denied Sales Report has no action badge');
 [$status,$html]=$request(122,'/sales/incentives');$check($status===200&&str_contains($html,'/sales/incentives'),'Incentives Inherit visible and 200');
 [$status]=$request(122,'/sales/incentives/1/decision',['_token'=>$token($html),'decision'=>'approve','approved_amount'=>'1']);$check($status===403,'View-only Incentives crafted approval POST denied');
 $set($b,['sales.incentive.view'=>'allow']);[$status,$html]=$request($b,'/sales/incentives');$check($status===200&&str_contains($html,'No claims match the filters.'),'Incentives ON without scoped records is 200 and empty');
 $set(122,['sales.incentive.view'=>'deny']);[$status,$html]=$request(122,'/sales/quick-sale');$check(!str_contains($html,'href="/office_app/public/sales/incentives"'),'Incentives user Deny hides taskbar');[$status]=$request(122,'/sales/incentives');$check($status===403,'Incentives user Deny direct URL is 403');
 $notifications=new App\Services\UserNotificationService();$notifications->notify(2,122,'test.override','Historical incentive','Historical text remains','sales_incentive_claim',1,'/sales/incentives/1','stabilization:override:122');$nid=(int)db()->query("SELECT notification_id FROM user_notifications WHERE event_key='stabilization:override:122' AND user_id=122")->fetchColumn();$path=$notifications->markRead(2,122,$nid);[$status]=$request(122,$path);$check($status===403,'Old notification destination rechecks user Deny');
 $set(122,['sales.module.enabled'=>'allow','sales.quick_sale.use'=>'allow']);db()->exec("UPDATE company_modules SET enabled=0 WHERE company_id=2 AND module_id=$moduleId");$check(!in_array('sales.quick_sale.use',$m->permissionCodes(122,2),true),'Company disabled module defeats user Allow');[$status]=$request(122,'/sales/quick-sale');$check($status===403,'Company disabled module blocks direct HTTP');[$status,$html]=$request(122,'/dashboard');$check(!str_contains($html,'href="/office_app/public/sales/'),'Company disabled module hides Sales navigation');db()->exec("UPDATE company_modules SET enabled=$enabled WHERE company_id=2 AND module_id=$moduleId");$check(in_array('sales.quick_sale.use',$m->permissionCodes(122,2),true),'Company re-enable restores preserved user Allow');
 foreach(['/finance','/finance/settlements','/finance/settlements/1','/finance/bank-reconciliation'] as $path){[$status]=$request(122,$path);$check($status===403,'Shop Settlement Allow does not grant '.$path);}
 $rejected=function($user,$actor,$choices=[])use($svc){try{$f=$svc->formData($user);$svc->update($user,$choices,$f['version'],$actor);return false;}catch(RuntimeException $e){return true;}};
 $check($rejected(121,121),'Self access edit rejected');$check($rejected(122,$b),'Non-administrator override edit rejected');
 $check($before===$snapshot(),'All three role tables unchanged after overrides and HTTP tests');
}finally{
 db()->prepare('UPDATE sales_quick_sale_reports SET status=? WHERE report_id=1')->execute([$reportBefore]);db()->prepare('UPDATE sales_quick_sales SET status=? WHERE quick_sale_id=11')->execute([$quickBefore]);
 db()->exec("UPDATE company_modules SET enabled=$enabled WHERE company_id=2 AND module_id=$moduleId");
 foreach($initial as $id=>$form){$now=$svc->formData($id);$restore=[];foreach($now['permissions'] as $p)$restore[$p['permission_id']]=$form['overrides'][$p['permission_id']]??'inherit';$svc->update($id,$restore,$now['version'],121);}
 db()->exec("DELETE FROM user_notifications WHERE event_key='stabilization:override:122' AND user_id=122");foreach($jars as $jar)unlink($jar);
}
echo "$checks stabilization checks passed\n";
