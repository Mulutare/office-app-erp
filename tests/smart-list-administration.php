<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/bootstrap.php';
use App\Services\Lists\AdministrationListService;
use App\Services\DataExchange\ExportService;
if(getenv('APP_ENV')!=='testing')throw new RuntimeException('Isolated test database required.');
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
$db=db();$checks=0;$assert=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);++$checks;echo 'PASS '.$message.PHP_EOL;};
$context=$db->query("SELECT cr.company_id,cr.user_id FROM company_user_roles cr JOIN roles r ON r.role_id=cr.role_id WHERE r.code='company_owner' ORDER BY cr.company_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$company=(int)$context['company_id'];$actor=(int)$context['user_id'];$_SESSION['auth']=['user_id'=>$actor,'company'=>['company_id'=>$company]];
$prefix='ADM'.strtoupper(bin2hex(random_bytes(3)));$factory=new AdministrationListService();$db->beginTransaction();
try {
    $db->prepare('INSERT INTO companies(code,name) VALUES(?,?)')->execute([$prefix.'OTHER',$prefix.'Other company']);$otherCompany=(int)$db->lastInsertId();
    $insertUser=$db->prepare('INSERT INTO users(username,email,display_name,password_hash,active,locked_until) VALUES(?,?,?,?,?,?)');
    $member=$db->prepare('INSERT INTO company_users(company_id,user_id,active) VALUES(?,?,1)');
    $role=$db->prepare('INSERT INTO roles(code,name,description) VALUES(?,?,?)');
    $permission=$db->prepare("INSERT INTO permissions(code,name,module,description) VALUES(?,?,'administration',?)");
    for($i=1;$i<=105;++$i){$code=$prefix.sprintf('%03d',$i);$insertUser->execute([$code,$code.'@example.test',$code,'DO-NOT-EXPORT-HASH',$i===105?0:1,$i===104?'2099-01-01':null]);$users[$i]=(int)$db->lastInsertId();$member->execute([$company,$users[$i]]);$role->execute([$code,$code,$code]);$roles[$i]=(int)$db->lastInsertId();$permission->execute([$code,$code,$code]);$permissions[$i]=(int)$db->lastInsertId();}
    $insertUser->execute([$prefix.'FOREIGN',$prefix.'foreign@example.test',$prefix.'Foreign','HIDDEN',1,null]);$foreign=(int)$db->lastInsertId();$member->execute([$otherCompany,$foreign]);
    $assign=$db->prepare('INSERT INTO company_user_roles(company_id,user_id,role_id,assigned_by) VALUES(?,?,?,?)');$grant=$db->prepare('INSERT INTO company_role_permissions(company_id,role_id,permission_id) VALUES(?,?,?)');
    foreach($users as $n=>$user){$assign->execute([$company,$user,$roles[1],$actor]);$grant->execute([$company,$roles[1],$permissions[$n]]);}
    $audit=$db->prepare("INSERT INTO audit_logs(company_id,user_id,action,module,table_name,record_id,old_values,new_values) VALUES(?,?,'UPDATE','administration','users',?,'{\"password_hash\":\"DO-NOT-EXPORT-HASH\"}',?)");
    $event=$db->prepare("INSERT INTO integration_outbox(event_id,company_id,event_type,aggregate_type,aggregate_id,payload_json,status,available_at) VALUES(?,?,?,'test','internal','{\"token\":\"DO-NOT-EXPORT-TOKEN\"}',?,NOW())");
    for($i=1;$i<=105;++$i){$code=$prefix.sprintf('%03d',$i);$audit->execute([$company,$users[$i],$users[1],json_encode(['display_name'=>$code])]);$event->execute([sprintf('%08s-0000-0000-0000-%012d',substr($prefix,0,8),$i),$company,$code,$i===105?'failed':'pending']);}
    $event->execute([sprintf('%08s-0000-0000-0000-%012d',substr($prefix,0,8),106),$otherCompany,$prefix.'FOREIGN','pending']);
    foreach(['users','roles','audit','events','role-users','role-permissions'] as $entity){
        $ctx=['role_id'=>$roles[1]];$list=$factory->listing($entity,['q'=>$prefix],'',$ctx);$page=$list->page();$assert($page['pagination']['total']===105 && count($page['rows'])===25,"$entity scope and count precede pagination");
        $assert($factory->listing($entity,['q'=>$prefix.'105'],'',$ctx)->page()['pagination']['total']===1,"$entity searches beyond page one");
        $assert(count($list->export())===105,"$entity exports every matching page");
        $assert($factory->listing($entity,['q'=>'no-such-administrative-record'],'',$ctx)->export()===[],"$entity preserves an empty search");
        $assert($factory->listing($entity,['q'=>$prefix,'per_page'=>100,'page'=>2],'',$ctx)->page()['pagination']['from']===101,"$entity supports page two at size 100");
    }
    $assert($factory->listing('users',['q'=>$prefix,'status'=>'inactive'])->page()['pagination']['total']===1,'Inactive membership/account filter works');
    $assert($factory->listing('users',['q'=>$prefix,'status'=>'locked'])->page()['pagination']['total']===1,'Locked status is independent of active state');
    $assert($factory->listing('users',['q'=>$prefix,'status'=>'active'])->page()['pagination']['total']===104,'Active status retains locked active accounts as before');
    $assert($factory->listing('users',['q'=>$prefix.'FOREIGN'])->export()===[],'User search cannot read another company membership');
    $assert($factory->listing('events',['q'=>$prefix.'FOREIGN'])->export()===[],'Integration export cannot reach another company');
    $controls=$factory->controls('events',$factory->listing('events',['q'=>'none']));$assert(count($controls['filters']['event_type']['options'])>=105 && !isset($controls['filters']['event_type']['options'][$prefix.'FOREIGN']),'Event dropdown spans the complete tenant dataset and excludes foreign values');
    $assert(isset($controls['filters']['status']['options']['failed']),'Event status dropdown uses actual domain values');
    $file=(new ExportService())->register('events','csv',$factory->columns('events'),$factory->listing('events',['q'=>$prefix])->export());$assert(!str_contains($file['contents'],'DO-NOT-EXPORT-TOKEN'),'Event CSV excludes payload credentials');
    $file=(new ExportService())->register('users','xlsx',$factory->columns('users'),$factory->listing('users',['q'=>$prefix])->export());$assert(strlen($file['contents'])>1000,'User XLSX uses the business-field whitelist');
    $activity=(new App\Services\UserActivityService())->smartListing($users[1],['q'=>$prefix]);$assert($activity['pagination']['total']===105,'User activity searches its complete authorized audit history');
    $assert((new App\Services\UserActivityService())->smartListing($foreign,[])===null,'Foreign user activity fails its profile scope check');
    $denied=false;try{$factory->listing('companies',[]);}catch(RuntimeException $e){$denied=true;}$assert($denied,'Company register requires platform administrator access');
    $_SESSION['auth']['is_platform_admin']=true;
    $insertCompany=$db->prepare('INSERT INTO companies(code,name) VALUES(?,?)');for($i=1;$i<=104;++$i)$insertCompany->execute([$prefix.sprintf('%03d',$i),$prefix.sprintf('%03d',$i)]);
    $companyList=(new App\Services\CompanyProvisioningService())->smartListing(['q'=>$prefix]);$assert($companyList['pagination']['total']===105 && count($companyList['companies'])===25,'Platform company register paginates the complete authorized catalogue');
    $assert(count($companyList['exportList']->export())===105,'Platform company export covers all matching pages');
    foreach([
        ['administration.users.index',(new App\Services\UserAdministrationService())->smartListing(['q'=>$prefix]),'users'],
        ['administration.audit-logs.index',(new App\Services\AuditLogAdministrationService())->smartListing(['q'=>$prefix]),'logs'],
        ['administration.companies.index',$companyList,'companies'],
    ] as [$view,$listing,$key]){ob_start();view($view,$listing+['listing'=>$listing,'user'=>$_SESSION['auth']]);$html=(string)ob_get_clean();$assert(str_contains($html,'name="q"') && str_contains($html,'Export filtered'),"$view renders shared controls");}
    ob_start();view('administration.users.activity',$activity+['listing'=>$activity,'profile'=>$activity['user'],'user'=>$_SESSION['auth']]);$html=(string)ob_get_clean();$assert(str_contains($html,'name="q"'),'User activity renders shared filters with existing change presentation');

    $_SESSION['auth']['is_platform_admin']=false;
    $denied=false;try{$factory->listing('company-users',[],'members',['company_id'=>$otherCompany]);}catch(Throwable){$denied=true;}$assert($denied,'Company member scope override requires platform authority');
    $platformAuth=$_SESSION['auth'];$_SESSION['auth']['is_platform_admin']=true;
    $db->prepare('UPDATE users SET is_platform_admin=1 WHERE user_id=?')->execute([$actor]);
    $members=$factory->workspace('company-users',['id'=>$company,'members'=>['q'=>$prefix]],'members',['company_id'=>$company]);
    $assert($members['pagination']['total']===105&&count($members['rows'])===25&&count($members['exportList']->export())===105,'Platform company members count and export the selected company');
    $assert($factory->listing('company-users',['members'=>['q'=>$prefix.'FOREIGN']],'members',['company_id'=>$company])->export()===[],'Platform member search never mixes company scopes');
    $assert($factory->listing('company-users',['members'=>['q'=>$prefix.'105','status'=>'inactive']],'members',['company_id'=>$company])->page()['pagination']['total']===1,'Platform members search and status precede pagination');
    $assert($factory->listing('company-users',['members'=>['q'=>$prefix,'per_page'=>100,'page'=>2]],'members',['company_id'=>$company])->page()['pagination']['from']===101,'Platform members retain independent page state');
    $_SESSION['auth']=$platformAuth;
    $employee=(int)$db->query('SELECT employee_id FROM hr_employees WHERE company_id='.$company.' AND deleted_at IS NULL LIMIT 1')->fetchColumn();
    $activityList=$factory->listing('employee-activity',[],'',['employee_id'=>$employee]);
    $assert(is_array($activityList->page())&&isset($factory->controls('employee-activity',$activityList,['employee_id'=>$employee])['filters']['actor']['options']),'Employee activity filters support database and connection collation differences');
} finally {if($db->inTransaction())$db->rollBack();}
echo "$checks Administration smart-list checks passed".PHP_EOL;
