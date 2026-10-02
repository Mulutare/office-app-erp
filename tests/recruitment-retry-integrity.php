<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/bootstrap.php';
use App\Services\Recruitment\{Repository,DocumentStore,RecruitmentService,ImportService,MailProvider,MimeParser};
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage()."\n");exit(1);});
if(getenv('APP_ENV')!=='testing'||getenv('DB_DATABASE')!=='office_app_test')throw new RuntimeException('Disposable synthetic fixtures required');
final class PreservedRetryProvider implements MailProvider {
 public function connect(array $m):void{}
 public function inventory(string $since):array{return ['validity'=>701,'uids'=>[]];}
 public function raw(int $uid):string{throw new RuntimeException('Completed raw mail must not be refetched');}
 public function arrival(int $uid):string{throw new RuntimeException('Completed arrival must not be refetched');}
 public function parse(string $raw):array{return (new MimeParser())->parse($raw);}
 public function close():void{}
}
$passed=0;$check=function($ok,$label)use(&$passed){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";$passed++;};
$p=db();$company=(int)$p->query("SELECT company_id FROM companies WHERE code='default'")->fetchColumn();$r=new Repository($p,$company);$s=new RecruitmentService($r,new DocumentStore());
$email=$r->query("SELECT * FROM recruitment_emails WHERE company_id=? AND uidvalidity=701 AND processing_status='complete' AND application_id IS NOT NULL ORDER BY id LIMIT 1",[$company])->fetch(PDO::FETCH_ASSOC);
$snapshot=function()use($s,$r){$apps=$r->query('SELECT * FROM recruitment_applications WHERE company_id=? ORDER BY id',[$r->company])->fetchAll(PDO::FETCH_ASSOC);$files=$r->query('SELECT * FROM recruitment_attachments WHERE company_id=? ORDER BY id',[$r->company])->fetchAll(PDO::FETCH_ASSOC);foreach($files as $f)$s->store->read($r->company,$f['storage_key'],$f['checksum']);return [$apps,$files];};
$before=$snapshot();$r->update('emails',(int)$email['id'],['processing_status'=>'stored']);
$worker=new ImportService($s,new PreservedRetryProvider());
$p->exec("CREATE TRIGGER recruitment_fixture_email_commit_failure BEFORE UPDATE ON recruitment_emails FOR EACH ROW BEGIN IF NEW.id=".(int)$email['id']." AND NEW.processing_status='complete' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic final commit interruption'; END IF; END");
try{$worker->sync((int)$email['mailbox_id']);$check($r->find('emails',(int)$email['id'])['processing_status']==='failed','Interrupted final persistence records a failed retry');$check($snapshot()===$before,'Interrupted final persistence preserves all applications and attachments');}
finally{$p->exec('DROP TRIGGER recruitment_fixture_email_commit_failure');}
$worker->sync((int)$email['mailbox_id']);$check($r->find('emails',(int)$email['id'])['processing_status']==='complete','Preserved raw message retry completes without refetch');
$check($snapshot()===$before,'Completed retry cannot duplicate applications or attachments and verifies every document checksum');
$check($s->store->read($company,$email['raw_storage'],$email['raw_checksum'])!==''&&$r->find('emails',(int)$email['id'])['raw_storage']===$email['raw_storage'],'Retry preserves the exact original raw storage identity and bytes');
echo "$passed retry integrity checks passed\n";
