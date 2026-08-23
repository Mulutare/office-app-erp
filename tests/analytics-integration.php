<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/bootstrap.php';
use App\Repositories\MySql\PowerBiConfigurationRepository;
use App\Services\CompanyModuleService;
use App\Services\PowerBiConfigurationService;

$passed=0;$failed=0;$check=static function(bool $ok,string $label)use(&$passed,&$failed):void{echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;$ok?$passed++:$failed++;};
try{
 $sample=db()->query("SELECT company_id FROM companies WHERE code='sample-company'")->fetchColumn();$unlicensed=db()->query("SELECT company_id FROM companies WHERE code='default'")->fetchColumn();$user=db()->query("SELECT user_id FROM company_users WHERE company_id=".(int)$sample." AND active=TRUE ORDER BY user_id LIMIT 1")->fetchColumn();$moduleId=(int)db()->query("SELECT module_id FROM erp_modules WHERE code='analytics'")->fetchColumn();
 db()->prepare("INSERT INTO company_modules(company_id,module_id,license_status,enabled,licensed_at) VALUES(:c,:m,'active',TRUE,NOW()) ON DUPLICATE KEY UPDATE license_status='active',enabled=TRUE")->execute(['c'=>$sample,'m'=>$moduleId]);
 db()->prepare("INSERT INTO company_power_bi_configurations(company_id,enabled,authentication_mode,microsoft_tenant_id,report_id,report_name,configuration_status,last_successful_validation_at) VALUES(:c,TRUE,'user_owns_data','a7aab42f-0a27-4f54-967b-32c5b0ae2271','0d5b5815-9d8f-4542-b3bc-38498f382ba4','Passion Sales Report','ready',NOW()) ON DUPLICATE KEY UPDATE company_id=VALUES(company_id)")->execute(['c'=>$sample]);
 $repo=new PowerBiConfigurationRepository();$config=$repo->findForCompany((int)$sample);
 $_SESSION['auth']=['company'=>['company_id'=>(int)$unlicensed]];$modules=new CompanyModuleService();
 $check(!$modules->isLicensed('analytics')&&!$modules->isEnabled('analytics'),'Unlicensed company cannot enable or use Analytics');
 $check($repo->findForCompany((int)$unlicensed)===null,'Unlicensed company has no inherited Power BI configuration');
 $_SESSION['auth']=['company'=>['company_id'=>(int)$sample]];$modules=new CompanyModuleService();
 $check($modules->isLicensed('analytics')&&$modules->isEnabled('analytics'),'Development company has a valid enabled Analytics entitlement');
 $check(is_array($config)&&$config['report_id']==='0d5b5815-9d8f-4542-b3bc-38498f382ba4','Existing development Power BI report migrated to its intended company');
 $otherLeak=$repo->findForCompany((int)$unlicensed);$check($otherLeak===null||$otherLeak['report_id']!==$config['report_id'],'Company A cannot read Company B report mapping');
 db()->prepare('UPDATE company_modules SET enabled=FALSE WHERE company_id=:c AND module_id=:m')->execute(['c'=>$sample,'m'=>$moduleId]);
 $check(!(new CompanyModuleService())->isEnabled('analytics'),'Licensed but disabled Analytics is not usable');db()->prepare('UPDATE company_modules SET enabled=TRUE WHERE company_id=:c AND module_id=:m')->execute(['c'=>$sample,'m'=>$moduleId]);
 db()->prepare('DELETE FROM company_power_bi_configurations WHERE company_id=:c')->execute(['c'=>$sample]);$state=(new PowerBiConfigurationService())->pageState();$check($state['state']==='enabled_not_configured'&&$state['embedUrl']===null,'Licensed and enabled company without configuration gets setup-required state');
 db()->prepare("INSERT INTO company_power_bi_configurations(company_id,enabled,authentication_mode,microsoft_tenant_id,report_id,report_name,configuration_status,last_successful_validation_at) VALUES(:c,TRUE,'user_owns_data',:tenant,:report,:name,'ready',NOW())")->execute(['c'=>$sample,'tenant'=>$config['microsoft_tenant_id'],'report'=>$config['report_id'],'name'=>$config['report_name']]);
 $state=(new PowerBiConfigurationService())->pageState();$check($state['state']==='ready'&&str_contains((string)$state['embedUrl'],rawurlencode($config['report_id'])),'Enabled configured company resolves only its own embed mapping');
 $input=['enabled'=>'1','authentication_mode'=>'company_managed','microsoft_tenant_id'=>$config['microsoft_tenant_id'],'workspace_id'=>'','report_id'=>$config['report_id'],'dataset_id'=>'','report_name'=>'Secure Test','client_id'=>'11111111-1111-4111-8111-111111111111','client_secret'=>'TopSecret-Analytics-Test','credential_reference'=>''];$saved=(new PowerBiConfigurationService())->save($input,(int)$user);$cipher=$repo->secretCiphertext((int)$sample);
 $check(!empty($saved['successful'])&&is_string($cipher)&&!str_contains($cipher,'TopSecret-Analytics-Test'),'Company-managed secret is encrypted and never stored as plaintext');
 $view=(string)file_get_contents(__DIR__.'/../resources/views/administration/analytics.php');$check(!str_contains($view,'client_secret_ciphertext')&&str_contains($view,'value=""'),'Analytics configuration HTML never renders stored secret material');
 $input['client_secret']='';(new PowerBiConfigurationService())->save($input,(int)$user);$check($repo->secretCiphertext((int)$sample)===$cipher,'Blank client secret preserves the existing encrypted secret');
 $validation=(new PowerBiConfigurationService())->validateConfiguration((int)$user);$validated=$repo->findForCompany((int)$sample);$check(empty($validation['successful'])&&($validated['configuration_status']??'')==='configuration_invalid'&&isset($validation['errors']['connection']),'Unsupported service-principal mode cannot be marked ready without the Microsoft connector');
 $repo->save((int)$sample,['enabled'=>true,'authentication_mode'=>'user_owns_data','microsoft_tenant_id'=>$config['microsoft_tenant_id'],'workspace_id'=>null,'report_id'=>$config['report_id'],'dataset_id'=>null,'report_name'=>$config['report_name'],'client_id'=>null,'credential_reference'=>null,'configuration_status'=>'ready','last_successful_validation_at'=>date('Y-m-d H:i:s')],null,(int)$user);
}catch(Throwable $e){echo 'FAIL unexpected: '.$e->getMessage().PHP_EOL;$failed++;}
echo PHP_EOL.($passed+$failed).' analytics checks, '.$failed.' failures'.PHP_EOL;exit($failed===0?0:1);
