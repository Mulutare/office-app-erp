<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/bootstrap.php';
use App\Services\Recruitment\{Repository,DocumentStore,RecruitmentService,ImportService,MailProvider,MimeParser,Rules};
set_exception_handler(function($e) { fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n"); exit(1); });
if(getenv('APP_ENV')!=='testing' || getenv('DB_DATABASE')!=='office_app_test') throw new RuntimeException('Synthetic isolated test database required.');
final class FixtureMailbox implements MailProvider
{
    public int $validity=700; public array $messages=[]; public array $fetches=[]; public bool $interrupted=false; public bool $connected=false;
    public function connect(array $m): void { $this->connected=true; }
    public function inventory(string $since): array { return ['validity'=>$this->validity,'uids'=>array_keys($this->messages)]; }
    public function raw(int $uid): string { $this->fetches[]=$uid; if($this->interrupted) throw new RuntimeException('Synthetic transient outage'); return $this->messages[$uid]; }
    public function parse(string $raw): array { return (new MimeParser())->parse($raw); }
    public function arrival(int $uid): string { return '2026-09-30 06:00:00'; }
    public function close(): void { $this->connected=false; }
}
$passed=0; $check=function($ok,$label)use(&$passed) { if(!$ok) throw new RuntimeException('FAIL '.$label); $passed++; echo 'PASS '.$label."\n"; };
$reject=fn($call)=> (function()use($call) { try { $call(); return false; } catch(InvalidArgumentException $e) { return true; } })();
$pdo=db();
$company=(int)$pdo->query("SELECT company_id FROM companies WHERE code='default'")->fetchColumn();
$other=(int)$pdo->query("SELECT company_id FROM companies WHERE company_id<>$company LIMIT 1")->fetchColumn();
if(!$other) { $pdo->exec("INSERT INTO companies(code,name) VALUES('recruitment-other','Other Synthetic Company')"); $other=(int)$pdo->lastInsertId(); }
$actor=(int)$pdo->query('SELECT user_id FROM users WHERE is_platform_admin=FALSE AND active=TRUE LIMIT 1')->fetchColumn();
$_SESSION['auth']=['user_id'=>$actor,'company'=>['company_id'=>$company,'timezone'=>'Africa/Nairobi']];
$r=new Repository($pdo,$company); $s=new RecruitmentService($r,new DocumentStore());
// Reset only synthetic recruitment records for this fixture company. Physical files are retained.
$r->query('UPDATE recruitment_applicants SET merged_into=NULL WHERE company_id=?',[$company]);
foreach(['history','attachments','emails','applications','applicants','runs','mailboxes','vacancies','events'] as $table) $r->query('DELETE FROM recruitment_'.$table.' WHERE company_id=?',[$company]);
$v=$s->vacancy(['reference'=>'VAC-001','title'=>'Fixture analyst','status'=>'open'], $actor);
$v2=$s->vacancy(['reference'=>'VAC-002','title'=>'Fixture officer','status'=>'open'], $actor);
$mailbox=$r->insert('mailboxes',['host'=>'fixture.example.test','username'=>'fixture','secret'=>'not-a-live-secret','folder'=>'INBOX','initial_date'=>'2026-01-01']);
$provider=new FixtureMailbox(); $provider->messages=[1=>file_get_contents(__DIR__.'/fixtures/recruitment/normal.eml')];
$worker=new ImportService($s,$provider); $preview=$worker->preview($mailbox,$actor);
$check($preview['count']===1,'Historical range preview counts messages before import');
$check($worker->sync($mailbox)['status']==='disabled','Mailbox cannot run before backfill confirmation');
$result=$worker->sync($mailbox,$preview['token'],$actor);
$check($result['processed']===1 && !$result['failed'],'Normal application with CV imports');
$apps=$r->applications(); $app=$apps[0]; $details=$s->details((int)$app['id']);
$check($app['vacancy_id']==$v && $app['email_original']==='Jane@example.test','Conservative extraction matches explicit vacancy reference');
$check(count($details['attachments'])===1 && $details['attachments'][0]['scan_status']==='quarantine','CV is preserved and never labelled safe without a scan');
$check($details['attachments'][0]['validation_status']==='accepted','Detected PDF matches extension');
$firstCount=count($apps); $worker->sync($mailbox);
$check(count($r->applications())===$firstCount && count($provider->fetches)===1,'Reimport is idempotent without re-fetch or duplicate application');
$provider->messages[2]=str_replace(['VAC-001','normal-fixture'],['VAC-002','second-fixture'],$provider->messages[1]); $worker->sync($mailbox);
$apps=$r->applications();
$check(count($apps)===2 && count(array_unique(array_column($apps,'applicant_id')))===1,'Same applicant can apply to two vacancies');
$provider->messages[3]=file_get_contents(__DIR__.'/fixtures/recruitment/forwarded.eml'); $worker->sync($mailbox);
$forward=array_values(array_filter($r->applications(),fn($a)=>$a['vacancy_id']===null))[0];
$check(!$forward['email_original'] && !$forward['identity_verified'],'Forwarded application leaves identity and vacancy for HR review');
$check(count($s->details((int)$forward['id'])['attachments'])===0,'Missing CV is accepted and retained in review queue');
$check(count($r->applications(['queue'=>1]))===3,'Review queue includes missing information and uncertain identities');
$email=$r->all('emails')[0]; $check(!str_contains($email['body_text'],'alert(1)') && !str_contains($email['body_text'],'https://tracking'),'Email display contains no scripts or remote tracking markup');
$provider->messages[4]=str_replace('normal-fixture','interrupted-fixture',$provider->messages[1]); $provider->interrupted=true; $worker->sync($mailbox);
$check((int)$r->query("SELECT COUNT(*) FROM recruitment_emails WHERE company_id=? AND uid=4 AND processing_status='failed'",[$company])->fetchColumn()===1,'Interrupted fetch records a durable failed import');
$provider->interrupted=false; $worker->sync($mailbox);
$check((int)$r->query("SELECT COUNT(*) FROM recruitment_emails WHERE company_id=? AND uid=4 AND processing_status='complete'",[$company])->fetchColumn()===1,'Retry resumes incomplete import');
$provider->messages[5]="From: broken@example.test\nSubject: bad\nContent-Type: multipart/mixed; boundary=missing\n\nbad"; $worker->sync($mailbox);
$broken=$r->query('SELECT * FROM recruitment_emails WHERE company_id=? AND uid=5',[$company])->fetch(PDO::FETCH_ASSOC);
$check($broken['processing_status']==='failed' && $broken['raw_storage']!==null,'Malformed MIME preserves raw message before extraction failure');
$check($s->store->read($company,$broken['raw_storage'],$broken['raw_checksum'])===$provider->messages[5],'Preserved message has verified checksum');
$bad=Rules::file('CV.pdf',"MZexecutable",1024); $check($bad['validation_status']==='rejected','Executable payload masquerading as CV is rejected');
$check(Rules::file('CV.txt',str_repeat('a',1025),1024)['validation_status']==='oversized','Oversized attachment is explicitly classified');
$check($reject(fn()=>$s->create(['name'=>'Bad upload'],$actor,['name'=>'malware.exe','bytes'=>'MZ...'])),'Manual executable upload rolls back application');
$foreign=new RecruitmentService(new Repository($pdo,$other),$s->store);
$check($reject(fn()=>$foreign->details((int)$app['id'])),'Cross-company application lookup is denied');
$check($reject(fn()=>$foreign->create(['name'=>'Cross company','vacancy_id'=>$v],$actor)),'Cross-company vacancy association is denied');
$check($reject(fn()=>$foreign->repo->find('attachments',(int)$details['attachments'][0]['id'])),'Cross-company download lookup is denied');
$check($reject(fn()=>$s->update((int)$app['id'],['status'=>'Hired','vacancy_id'=>$v,'identity_verified'=>1],$actor,false)),'Hiring permission is separate from editing');
$s->update((int)$app['id'],['status'=>'Under Review','vacancy_id'=>$v,'name'=>'=HYPERLINK("bad")','email'=>'Jane@example.test','identity_verified'=>1,'notes'=>'+SUM(1,1)'],$actor,false);
$check(count($s->details((int)$app['id'])['history'])===2,'Status changes have an actor and durable history');
$filtered=$r->applications(['status'=>'Under Review','vacancy'=>$v,'source'=>'email','from'=>'2026-09-30','to'=>'2026-09-30']);
$check(count($filtered)===1,'Export query respects combined filters');
$bytes=$s->export(['status'=>'Under Review'],'Africa/Nairobi','https://erp.example.test');
$path=sys_get_temp_dir().'/recruitment-export-fixture.xlsx'; file_put_contents($path,$bytes);
$book=\PhpOffice\PhpSpreadsheet\IOFactory::load($path); $sheet=$book->getActiveSheet();
$check($sheet->getHighestDataRow()===2 && $sheet->getHighestDataColumn()==='O','Excel has one row per filtered application and all 15 required columns');
$check($sheet->getCell('C2')->getDataType()==='s' && str_starts_with($sheet->getCell('C2')->getValue(),"'="),'Applicant formula injection is stored as text');
$check($sheet->getCell('N2')->getDataType()==='s' && str_starts_with($sheet->getCell('N2')->getValue(),"'+"),'Notes formula injection is stored as text');
$check($sheet->getCell('B2')->getValue()==='2026-09-30 09:00:00','Export dates use company timezone');
$check(str_starts_with($sheet->getCell('M2')->getValue(),'https://erp.example.test/hr/recruitment/download?id='),'Export links point to protected ERP downloads');
$check($sheet->getAutoFilter()->getRange()!=='' && $sheet->getFreezePane()==='A2','Excel has readable headers, filters and frozen heading');
$book->disconnectWorksheets();
$employeeCount=(int)$r->query('SELECT COUNT(*) FROM hr_employees WHERE company_id=?',[$company])->fetchColumn();
$s->update((int)$app['id'],['status'=>'Hired','vacancy_id'=>$v,'identity_verified'=>1,'name'=>'Verified applicant','email'=>'Jane@example.test'],$actor,true);
$check($r->find('applications',(int)$app['id'])['status']==='Hired','Deliberate validated hiring transition succeeds with hiring permission');
$check((int)$r->query('SELECT COUNT(*) FROM hr_employees WHERE company_id=?',[$company])->fetchColumn()===$employeeCount,'Hiring never automatically creates an employee');
$s->update((int)$app['id'],['status'=>'Under Review','vacancy_id'=>$v,'identity_verified'=>1],$actor,true);
$second=new PDO('mysql:host='.getenv('DB_HOST').';dbname=office_app_test',getenv('DB_USERNAME'),getenv('DB_PASSWORD'));
$lock='officeapp:recruitment:'.$company.':'.$mailbox;
$statement=$second->prepare('SELECT GET_LOCK(?,0)'); $statement->execute([$lock]);
$check($worker->sync($mailbox)['status']==='busy','Overlapping workers cannot acquire the same mailbox lock');
$statement=$second->prepare('SELECT RELEASE_LOCK(?)'); $statement->execute([$lock]);
$provider->messages[6]=str_replace(['normal-fixture','CV.pdf'],['attachment-retry-fixture','retry.pdf'],$provider->messages[1]);
$pdo->exec("CREATE TRIGGER recruitment_fixture_attachment_failure BEFORE INSERT ON recruitment_attachments FOR EACH ROW BEGIN IF NEW.original_name='retry.pdf' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic interrupted attachment persistence'; END IF; END");
$worker->sync($mailbox);
$retryMail=$r->query('SELECT * FROM recruitment_emails WHERE company_id=? AND uid=6',[$company])->fetch(PDO::FETCH_ASSOC);
$check($retryMail['processing_status']==='failed' && $retryMail['raw_storage'] && !$retryMail['application_id'],'Attachment persistence failure keeps raw mail and rolls back incomplete application');
$pdo->exec('DROP TRIGGER recruitment_fixture_attachment_failure'); $worker->sync($mailbox);
$retryMail=$r->query('SELECT * FROM recruitment_emails WHERE company_id=? AND uid=6',[$company])->fetch(PDO::FETCH_ASSOC);
$check($retryMail['processing_status']==='complete' && $retryMail['application_id'],'Interrupted attachment import retries to completion');
putenv('RECRUITMENT_MAX_ATTACHMENT_BYTES=16');
$provider->messages[7]=str_replace('normal-fixture','oversized-fixture',$provider->messages[1]); $worker->sync($mailbox);
$oversized=$r->query("SELECT d.* FROM recruitment_attachments d JOIN recruitment_emails e ON e.company_id=d.company_id AND e.id=d.email_id WHERE d.company_id=? AND e.uid=7",[$company])->fetch(PDO::FETCH_ASSOC);
$check($oversized['validation_status']==='oversized' && $oversized['scan_status']==='quarantine','Oversized emailed CV remains preserved in quarantine with explicit classification');
putenv('RECRUITMENT_MAX_ATTACHMENT_BYTES');
$before=count($r->all('emails')); $appCount=count($r->applications()); $provider->validity=701; $worker->sync($mailbox);
$check((int)$r->find('mailboxes',$mailbox)['uidvalidity']===701 && count($r->all('emails'))>$before,'UIDVALIDITY change uses a new durable provider identity');
$check(count($r->applications())===$appCount,'Exact preserved messages across UIDVALIDITY epochs reuse their reviewed applications');
$check(Rules::identity(1,'INBOX',700,1)!==Rules::identity(1,'INBOX',701,1),'Provider identity includes UIDVALIDITY');
$check(Rules::email('a.b+tag@EXAMPLE.test')==='a.b+tag@example.test','Email normalization preserves local part dots and plus suffixes');
$pid=$s->create(['name'=>'Deliberate duplicate','email'=>'Jane@example.test'],$actor); $duplicate=$r->find('applications',$pid);
$s->merge((int)$duplicate['applicant_id'],(int)$app['applicant_id'],$actor,'HR confirmed same identity');
$check($r->find('applications',$pid)['applicant_id']==$app['applicant_id'],'Audited human-confirmed merge reassigns applications');
$s->archive($pid,$actor); $check($reject(fn()=>$s->details($pid)),'Archived application leaves active register');
$check(count($r->all('attachments'))>0,'Archive preserves imported documents');
echo "$passed recruitment integration checks passed\n";
