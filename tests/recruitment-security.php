<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/bootstrap.php';
use App\Services\Recruitment\{Repository,RecruitmentService,DocumentStore,Scheduler};
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage()."\n");exit(1);});
if(getenv('APP_ENV')!=='testing'||getenv('DB_DATABASE')!=='office_app_test')throw new RuntimeException('Disposable synthetic database required');
$passed=0;$check=function($ok,$label)use(&$passed){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";$passed++;};
$reject=function($call){try{$call();return false;}catch(Throwable){return true;}};
$p=db();$company=(int)$p->query("SELECT company_id FROM companies WHERE code='default'")->fetchColumn();
$s=new RecruitmentService(new Repository($p,$company),new DocumentStore());
$file=$s->repo->all('attachments')[0];
$check($reject(fn()=>$s->store->path($company,'../../public/cv')),'Private storage rejects traversal identifiers');
$check($reject(fn()=>$s->store->read($company,$file['storage_key'],str_repeat('0',64))),'Document integrity mismatch fails closed');
$check($reject(fn()=>$s->store->read(999999,$file['storage_key'],$file['checksum'])),'Foreign company cannot read same document key');
$public=__DIR__.'/../public/recruitment-fixture-storage';
try{$check($reject(fn()=>new DocumentStore($public)),'Public document storage is refused');}finally{if(is_dir($public))rmdir($public);}
$cipher=new App\Services\PowerBiSecretCipher();$encrypted=$cipher->encrypt('synthetic secret');$binary=base64_decode($encrypted);$binary[16]=chr(ord($binary[16])^1);
$check($reject(fn()=>$cipher->decrypt(base64_encode($binary))),'Tampered credential authentication tag fails closed');
$key=getenv('POWER_BI_ENCRYPTION_KEY');putenv('POWER_BI_ENCRYPTION_KEY=');
try{$check($reject(fn()=>$cipher->encrypt('synthetic secret')),'Missing encryption key prevents credential persistence');}finally{putenv('POWER_BI_ENCRYPTION_KEY='.$key);}
$app=$s->repo->applications(['status'=>'Shortlisted'])[0];$actor=(int)$p->query("SELECT user_id FROM users WHERE username='rec_fixture_owner'")->fetchColumn();
$foreign=(int)$p->query("SELECT user_id FROM users WHERE username='test_tenant_b_user'")->fetchColumn();
if(!$foreign)$foreign=999999;
$check($reject(fn()=>$s->update((int)$app['id'],['reviewer_id'=>$foreign,'vacancy_id'=>$app['vacancy_id']],$actor,false)),'Reviewer must have effective Recruitment editing permission in the current company');
$p->beginTransaction();
try {
 $p->prepare('UPDATE recruitment_mailboxes SET enabled=0 WHERE company_id<>?')->execute([$company]);
 $p->prepare('UPDATE recruitment_mailboxes SET enabled=1,last_sync=NULL WHERE company_id=?')->execute([$company]);
 $p->prepare("UPDATE company_modules cm JOIN erp_modules m ON m.module_id=cm.module_id SET cm.enabled=0 WHERE cm.company_id=? AND m.code='recruitment'")->execute([$company]);
 $before=(int)$p->query('SELECT COUNT(*) FROM recruitment_runs')->fetchColumn();
 $result=(new Scheduler())->run();
 $check($result===['processed'=>0,'failed'=>0]&&(int)$p->query('SELECT COUNT(*) FROM recruitment_runs')->fetchColumn()===$before,'Scheduler skips disabled Recruitment without opening a provider or creating import runs');
 $p->prepare("UPDATE company_modules cm JOIN erp_modules m ON m.module_id=cm.module_id SET cm.enabled=1 WHERE cm.company_id=? AND m.code='recruitment'")->execute([$company]);
 $p->prepare('UPDATE companies SET active=0 WHERE company_id=?')->execute([$company]);
 $check((new Scheduler())->run()===['processed'=>0,'failed'=>0],'Scheduler skips inactive companies');
 $p->prepare("UPDATE companies SET active=1,subscription_status='suspended' WHERE company_id=?")->execute([$company]);
 $check((new Scheduler())->run()===['processed'=>0,'failed'=>0],'Scheduler skips suspended subscriptions');
}finally{$p->rollBack();}
$p->beginTransaction();
try {
 foreach(['=HYPERLINK("https://evil.test")','+SUM(1,1)','-1+1','@SUM(1,1)',"\t=1+1","\r=1+1","\n=1+1","  =1+1"] as $value){
  $p->prepare('UPDATE recruitment_applicants SET name=? WHERE company_id=? AND id=?')->execute([$value,$company,$app['applicant_id']]);
  $p->prepare('UPDATE recruitment_applications SET notes=? WHERE company_id=? AND id=?')->execute([$value,$company,$app['id']]);
  $bytes=$s->export(['status'=>'Shortlisted','reviewer'=>$app['reviewer_id']],'Africa/Nairobi','https://erp.example.test');
  $path=sys_get_temp_dir().'/recruitment-injection.xlsx';file_put_contents($path,$bytes);
  $book=PhpOffice\PhpSpreadsheet\IOFactory::load($path);$sheet=$book->getActiveSheet();
  $check($sheet->getCell('C2')->getDataType()==='s'&&$sheet->getCell('N2')->getDataType()==='s','Export keeps formula/control-prefix payload as literal text: '.json_encode($value));
  $book->disconnectWorksheets();
 }
}finally{$p->rollBack();}
echo "$passed recruitment security checks passed\n";
