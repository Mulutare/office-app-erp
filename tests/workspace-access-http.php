<?php
/** Run only against the explicitly isolated access-control fixture database. */
require __DIR__.'/../app/helpers/bootstrap.php';
set_exception_handler(static function(Throwable $error):void{fwrite(STDERR,$error->getMessage()."\n");exit(1);});
if(getenv('DB_DATABASE')!=='office_app_test')throw new RuntimeException('Isolated database required');
use App\Services\WorkspaceAccessService as Workspace;
$base='http://127.0.0.1:8080/office_app/public';$jar=tempnam(sys_get_temp_dir(),'access093');
function request(string $path,?array $form=null):array{global $base,$jar;$c=curl_init($base.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar,CURLOPT_TIMEOUT=>25]);if($form!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($form)]);$body=curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$code,$body];}
function token(string $html):string{preg_match('/name="_token"\s+value="([^"]+)"/',$html,$m);return html_entity_decode($m[1]??'');}
[$status,$html]=request('/login');[$status]=request('/login',['_token'=>token($html),'login'=>'sales.manager.verify.local','password'=>'Access-Test!2026']);
if($status!==302)throw new RuntimeException('Login failed: '.$status);
$_SESSION['auth']=['user_id'=>121,'company'=>(new App\Models\CompanyModule())->companyById(2),'roles'=>['company_owner'],'is_platform_admin'=>false,'permissions'=>(new App\Models\CompanyMembership())->permissionCodes(121,2)];
$role=(int)db()->query("SELECT role_id FROM roles WHERE code='access_test_manager_093'")->fetchColumn();
$model=new App\Models\Role();$original=$model->permissionIds(2,$role);$editor=new App\Services\RolePermissionUpdateService();
$all=[];foreach($model->activePermissions(false,2) as $p)if(in_array($p['code'],$_SESSION['auth']['permissions'],true))$all[]=(int)$p['permission_id'];
$results=[];
try{
 $result=$editor->update($role,$all,121);if(!$result['successful'])throw new RuntimeException(json_encode($result));
 if (($argv[1] ?? '') === 'individual') {
  foreach(Workspace::definitions() as $module=>$items)foreach($items as $key=>$item){
   if(!Workspace::allowed($item))continue;
   $remove=[];foreach($model->activePermissions(false,2) as $p)if(in_array($p['code'],(array)$item[2],true))$remove[]=(int)$p['permission_id'];
   $result=$editor->update($role,array_values(array_diff($all,$remove)),121);
   if(!$result['successful'])throw new RuntimeException(json_encode($result));
   [$code,$body]=request($item[1]);
   $effective=(new App\Models\CompanyMembership())->permissionCodes(122,2);
   $hidden=!Workspace::allowed($item,$effective);
   $results[]=['case'=>'individual revoke','path'=>$item[1],'status'=>$code,'hidden'=>$hidden,'passed'=>$code===403&&$hidden];
   echo ($code===403&&$hidden?'PASS':'FAIL')." individual revoke $item[1] $code\n";
  }
 } else {
 foreach(Workspace::definitions() as $module=>$items)foreach($items as $key=>$item){if(!Workspace::allowed($item))continue;[$code,$body]=request($item[1]);$results[]=['case'=>'enabled','module'=>$module,'function'=>$key,'path'=>$item[1],'status'=>$code,'passed'=>$code===200];echo ($code===200?'PASS':'FAIL')." enabled $item[1] $code\n";}
 $result=$editor->update($role,[],121);if(!$result['successful'])throw new RuntimeException(json_encode($result));
 foreach($results as $row){[$code]=request($row['path']);$results[]=['case'=>'disabled','path'=>$row['path'],'status'=>$code,'passed'=>$code===403];echo ($code===403?'PASS':'FAIL')." disabled {$row['path']} $code\n";}
 }
}finally{$restore=$editor->update($role,$original,121);echo 'Restored fixture: '.json_encode($restore)."\n";unlink($jar);}
file_put_contents(sys_get_temp_dir().'/officeapp-http-matrix'.(($argv[1] ?? '')==='individual'?'-individual':'').'.json',json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
exit(count(array_filter($results,fn($r)=>!$r['passed']))?1:0);
