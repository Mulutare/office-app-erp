<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/bootstrap.php';
if(getenv('APP_ENV')!=='testing'||getenv('DB_DATABASE')!=='office_app_test') throw new RuntimeException('Synthetic isolated database required.');
set_exception_handler(function($e) { http_response_code(500); fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n"); exit(1); });
if(($argv[1]??'')==='child') {
    $input=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
    $_SESSION['auth']=['user_id'=>(int)$input['user'],'company'=>['company_id'=>(int)$input['company']]];
    $_SESSION['_csrf_token']='fixture-csrf'; $_GET=$input['get']??[]; $_POST=$input['post']??[];
    $_SERVER['REQUEST_URI']='/hr/recruitment'; http_response_code(200);
    register_shutdown_function(static function() { echo "\n__HTTP__".http_response_code(); });
    require __DIR__.'/../app/helpers/router.php'; require __DIR__.'/../routes/web.php';
    $controller=new App\Controllers\RecruitmentController();
    $controller->{$input['method']}(); exit;
}
$pdo=db(); $company=(int)$pdo->query("SELECT company_id FROM companies WHERE code='default'")->fetchColumn();
$pdo->prepare("UPDATE companies SET approval_status='approved',subscription_status='active',active=1,subscription_expires_at=NULL WHERE company_id=?")->execute([$company]);
$pdo->prepare("INSERT INTO company_modules(company_id,module_id,enabled,license_status) SELECT ?,module_id,1,'active' FROM erp_modules WHERE code IN('hr','recruitment') ON DUPLICATE KEY UPDATE enabled=1,license_status='active',expires_at=NULL")->execute([$company]);
$users=[];
$users['anonymous']=0;
foreach(['owner'=>['view','edit','export','hire','mailboxes','merge','delete','quarantine'],'view'=>['view'],'editor'=>['view','edit'],'mailbox'=>['mailboxes'],'none'=>[]] as $role=>$capabilities) {
    $pdo->prepare('INSERT INTO roles(name,code) VALUES(?,?) ON DUPLICATE KEY UPDATE role_id=LAST_INSERT_ID(role_id)')->execute(['Synthetic recruitment '.$role,'rec_fixture_'.$role]); $rid=(int)$pdo->lastInsertId();
    $codes=array_merge(['recruitment.module.enabled'],array_map(fn($c)=>'recruitment.'.$c,$capabilities));
    foreach($codes as $code) {
        $pdo->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT ?,permission_id FROM permissions WHERE code=?')->execute([$rid,$code]);
        $pdo->prepare('INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id) SELECT ?,?,permission_id FROM permissions WHERE code=?')->execute([$company,$rid,$code]);
    }
    $pdo->prepare('INSERT INTO users(username,email,password_hash,display_name,must_change_password) VALUES(?,?,?,?,0) ON DUPLICATE KEY UPDATE user_id=LAST_INSERT_ID(user_id)')->execute(['rec_fixture_'.$role,'rec_fixture_'.$role.'@example.test','disabled-synthetic-login','Fixture '.$role]);
    $uid=(int)$pdo->lastInsertId(); $users[$role]=$uid;
    $pdo->prepare('INSERT IGNORE INTO company_users(company_id,user_id,active,is_default) VALUES(?,?,1,1)')->execute([$company,$uid]);
    $pdo->prepare('INSERT IGNORE INTO company_user_roles(company_id,user_id,role_id) VALUES(?,?,?)')->execute([$company,$uid,$rid]);
}
$passed=0; $check=function($ok,$label)use(&$passed) { if(!$ok) throw new RuntimeException('FAIL '.$label); $passed++; echo 'PASS '.$label."\n"; };
$call=function(string $role,string $method,array $get=[],array $post=[],?int $tenant=null)use($users,$company) {
    $args=['user'=>$users[$role],'company'=>$tenant??$company,'method'=>$method,'get'=>$get,'post'=>$post];
    $process=proc_open([PHP_BINARY,__FILE__,'child',json_encode($args,JSON_THROW_ON_ERROR)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
    preg_match('/__HTTP__(\d+)$/',$output,$m);
    if($error) echo 'CHILD ERROR '.$error;
    return ['status'=>(int)($m[1]??0),'body'=>$output,'error'=>$error,'exit'=>$exit];
};
$app=(int)$pdo->query("SELECT id FROM recruitment_applications WHERE company_id=$company AND deleted_at IS NULL LIMIT 1")->fetchColumn();
$file=(int)$pdo->query("SELECT id FROM recruitment_attachments WHERE company_id=$company LIMIT 1")->fetchColumn();
$email=(int)$pdo->query("SELECT id FROM recruitment_emails WHERE company_id=$company LIMIT 1")->fetchColumn();
foreach(['download'=>['id'=>$file,'acknowledge'=>'quarantine'],'raw'=>['id'=>$email],'export'=>[]] as $method=>$get) {
    $response=$call('anonymous',$method,$get);
    $check($response['status']!==200 && !str_starts_with($response['body'],'PK'),'Anonymous request cannot access '.$method);
}
foreach(['index','show','vacancies','applicants','create','mailboxes','export','download','raw','save'] as $method) {
    $response=$call('none',$method,['id'=>$app],['_token'=>'fixture-csrf','action'=>'update','id'=>$app]);
    if($response['status']!==403) echo json_encode($response,JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    $check($response['status']===403,'Unauthorized endpoint denies '.$method);
}
$check($call('view','save',[],['_token'=>'fixture-csrf','action'=>'update','id'=>$app])['status']===403,'Viewing permission cannot update applications');
$check($call('view','export')['status']===403,'Viewing permission cannot export');
$check($call('view','mailboxes')['status']===403,'Viewing permission cannot administer mailbox credentials');
$check($call('view','download',['id'=>$file,'acknowledge'=>'quarantine'])['status']===403,'Viewing permission cannot bypass quarantine');
$response=$call('owner','download',['id'=>$file]);
if($response['status']!==409) echo 'DOWNLOAD RESPONSE '.json_encode($response,JSON_INVALID_UTF8_SUBSTITUTE)."\n";
$check($response['status']===409,'Quarantine download requires explicit acknowledgement');
$check($call('owner','download',['id'=>$file,'acknowledge'=>'quarantine'])['status']===200,'Privileged acknowledged quarantine download succeeds');
$check($call('editor','save',[],['_token'=>'wrong','action'=>'update','id'=>$app])['status']===419,'Recruitment mutations require valid CSRF token');
$response=$call('owner','export',['status'=>'Under Review']);
$check($response['status']===200 && str_starts_with($response['body'],'PK'),'Authorized export endpoint produces XLSX');
foreach(['index'=>'Apply filters','show'=>'Status history','vacancies'=>'Create vacancy','applicants'=>'Search applicants','create'=>'Manual application','mailboxes'=>'Mailbox administration'] as $method=>$expected) {
    $response=$call('owner',$method,['id'=>$app]);
    $check($response['status']===200 && str_contains($response['body'],$expected),'Rendered HR screen '.$method);
    if($response['status']!==200) echo $response['error'];
}
$check($call('mailbox','index')['status']===403,'Mailbox administration does not imply applicant viewing');
$service=new App\Services\Recruitment\RecruitmentService(new App\Services\Recruitment\Repository($pdo,$company),new App\Services\Recruitment\DocumentStore());
$application=$service->repo->find('applications',$app);
$response=$call('editor','save',[],['_token'=>'fixture-csrf','action'=>'update','id'=>$app,'status'=>'Shortlisted','vacancy_id'=>$application['vacancy_id'],'reviewer_id'=>$users['editor'],'identity_verified'=>1]);
$updated=$service->repo->find('applications',$app);
$check($updated['status']==='Shortlisted' && (int)$updated['reviewer_id']===$users['editor'],'HR assigns reviewer and updates imported application through controller');
$filters=['status'=>'Shortlisted','vacancy'=>$application['vacancy_id'],'reviewer'=>$users['editor'],'source'=>'email','from'=>'2026-09-30','to'=>'2026-09-30'];
$response=$call('owner','export',$filters);
$path=sys_get_temp_dir().'/recruitment-workflow.xlsx';file_put_contents($path,substr($response['body'],0,strrpos($response['body'],"\n__HTTP__")));
$book=PhpOffice\PhpSpreadsheet\IOFactory::load($path);$sheet=$book->getActiveSheet();
$check($response['status']===200 && $sheet->getHighestDataRow()===2 && $sheet->getCell('K2')->getValue()==='Fixture editor' && $sheet->getCell('J2')->getValue()==='Shortlisted','Filtered endpoint export contains exactly assigned shortlisted application');
$book->disconnectWorksheets();
$response=$call('editor','save',[],['_token'=>'fixture-csrf','action'=>'update','id'=>$app,'status'=>'Hired','vacancy_id'=>$application['vacancy_id'],'reviewer_id'=>$users['editor'],'identity_verified'=>1]);
$check($service->repo->find('applications',$app)['status']==='Shortlisted','Server rejects hiring transition from editor without hire permission');
$pdo->prepare("UPDATE company_modules cm JOIN erp_modules m ON m.module_id=cm.module_id SET cm.enabled=0 WHERE cm.company_id=? AND m.code='recruitment'")->execute([$company]);
$check($call('owner','download',['id'=>$file,'acknowledge'=>'quarantine'])['status']===403,'Disabled Recruitment module denies even privileged document download');
$pdo->prepare("UPDATE company_modules cm JOIN erp_modules m ON m.module_id=cm.module_id SET cm.enabled=1 WHERE cm.company_id=? AND m.code='recruitment'")->execute([$company]);
$response=$call('owner','save',[],['_token'=>'fixture-csrf','action'=>'mailbox','host'=>'imap.fixture.test','port'=>993,'username'=>'fixture-config','folder'=>'INBOX','initial_date'=>'2026-09-01','interval_minutes'=>5,'password'=>' synthetic secret with spaces ']);
$configured=$pdo->query("SELECT * FROM recruitment_mailboxes WHERE company_id=$company AND username='fixture-config' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$check($response['exit']===0 && $configured && (new App\Services\PowerBiSecretCipher())->decrypt($configured['secret'])===' synthetic secret with spaces ','Mailbox configuration stores encrypted secrets without altering password whitespace');
$check(!$configured['enabled'],'Saving mailbox credentials does not start an unpreviewed backfill');
$response=$call('owner','mailboxes');
$check(!str_contains($response['body'],'synthetic secret with spaces') && !str_contains($response['body'],$configured['secret']),'Mailbox screen never exposes stored plaintext or ciphertext');
$pdo->exec("INSERT INTO companies(code,name) VALUES('rec_fixture_other','Dedicated recruitment fixture tenant') ON DUPLICATE KEY UPDATE company_id=LAST_INSERT_ID(company_id)");
$other=(int)$pdo->lastInsertId();
$pdo->prepare("UPDATE companies SET approval_status='approved',subscription_status='active',active=1,subscription_expires_at=NULL WHERE company_id=?")->execute([$other]);
$pdo->prepare('INSERT IGNORE INTO company_users(company_id,user_id,active) VALUES(?,?,1)')->execute([$other,$users['owner']]);
$rid=(int)$pdo->query("SELECT role_id FROM roles WHERE code='rec_fixture_owner'")->fetchColumn();
$pdo->prepare('INSERT IGNORE INTO company_user_roles(company_id,user_id,role_id) VALUES(?,?,?)')->execute([$other,$users['owner'],$rid]);
$pdo->prepare('INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id) SELECT ?,role_id,permission_id FROM role_permissions WHERE role_id=?')->execute([$other,$rid]);
$pdo->prepare("INSERT INTO company_modules(company_id,module_id,enabled,license_status) SELECT ?,module_id,1,'active' FROM erp_modules WHERE code IN('hr','recruitment') ON DUPLICATE KEY UPDATE enabled=1,license_status='active',expires_at=NULL")->execute([$other]);
$check($call('owner','show',['id'=>$app],[],$other)['status']===404,'Authorized foreign tenant cannot inspect application');
$check($call('owner','download',['id'=>$file,'acknowledge'=>'quarantine'],[],$other)['status']===404,'Authorized foreign tenant cannot download document');
$check($call('owner','raw',['id'=>$email],[],$other)['status']===404,'Authorized foreign tenant cannot download original mail');
$response=$call('owner','export',[],[],$other);
$check($response['status']===200 && str_starts_with($response['body'],'PK'),'Foreign tenant export uses its own scoped register');
echo "$passed endpoint and screen checks passed\n";
