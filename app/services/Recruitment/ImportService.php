<?php
declare(strict_types=1);
namespace App\Services\Recruitment;

/** Serial mailbox worker. Raw mail is committed independently before parsing or document extraction. */
final class ImportService
{
    public function __construct(private RecruitmentService $service,private MailProvider $provider) {}
    private function retry(callable $call): mixed
    {
        for($attempt=0;$attempt<3;$attempt++) {
            try { return $call(); } catch(\Throwable $e) { if($attempt===2) throw $e; usleep(250000*(2**$attempt)); }
        }
        throw new \LogicException('Unreachable');
    }
    public function preview(int $id,?int $actor=null): array
    {
        return $this->service->repo->mailboxLock($id,fn()=>$this->previewLocked($id,$actor));
    }
    private function previewLocked(int $id,?int $actor): array
    {
        $r=$this->service->repo; $m=$r->find('mailboxes',$id);
        try {
            $this->provider->connect($m); $inventory=$this->provider->inventory($m['initial_date']);
            $token=bin2hex(random_bytes(32));
            $r->update('mailboxes',$id,['preview_token'=>hash('sha256',$token),'preview_at'=>gmdate('Y-m-d H:i:s'),'preview_validity'=>$inventory['validity'],'preview_max_uid'=>max($inventory['uids']?:[0]),'preview_count'=>count($inventory['uids'])]);
            $r->audit('backfill_preview',$id,$actor,'messages='.count($inventory['uids']));
            return ['count'=>count($inventory['uids']),'since'=>$m['initial_date'],'folder'=>$m['folder'],'token'=>$token];
        } finally { $this->provider->close(); }
    }
    public function sync(int $id,?string $backfillToken=null,?int $actor=null): array
    {
        $r=$this->service->repo; $lock='officeapp:recruitment:'.$r->company.':'.$id;
        if((int)$r->query('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1) return ['status'=>'busy','processed'=>0,'failed'=>0];
        $run=0; $processed=0; $failed=0;
        try {
            $m=$r->find('mailboxes',$id);
            if($backfillToken!==null) {
                if(!$m['preview_token'] || !hash_equals($m['preview_token'],hash('sha256',$backfillToken)) || strtotime($m['preview_at'].' UTC')<time()-900) throw new \InvalidArgumentException('Preview expired; preview this range again.');
            } elseif(!$m['enabled']) return ['status'=>'disabled','processed'=>0,'failed'=>0];
            $run=$r->insert('runs',['mailbox_id'=>$id,'status'=>'running']);
            // Mark abandoned runs after acquiring the same connection-scoped lock.
            $r->query("UPDATE recruitment_runs SET status='interrupted',finished_at=UTC_TIMESTAMP(),error_code='worker_interrupted' WHERE company_id=? AND mailbox_id=? AND id<>? AND status='running'",[$r->company,$id,$run]);
            $this->retry(fn()=>$this->provider->connect($m));
            $inventory=$this->retry(fn()=>$this->provider->inventory($m['initial_date']));
            $validity=$inventory['validity'];
            if($backfillToken!==null && $validity!==(int)$m['preview_validity']) throw new \InvalidArgumentException('Mailbox UIDVALIDITY changed. Preview the range again.');
            if((int)$m['uidvalidity']!==$validity) {
                $r->update('mailboxes',$id,['uidvalidity'=>$validity,'last_uid'=>0]);
                $r->audit('uidvalidity_changed',$id,$actor,'Historical range will be re-evaluated; HR must review retransmissions.');
            }
            $uids=$inventory['uids'];
            if($backfillToken!==null) $uids=array_values(array_filter($uids,fn($uid)=>$uid<=(int)$m['preview_max_uid']));
            // Full inventory plus durable completed rows handles UID gaps and interrupted fetches.
            // Limit work per invocation; cursor is informational, not a reason to skip failed records.
            $pending=$r->query("SELECT * FROM recruitment_emails WHERE company_id=? AND mailbox_id=? AND processing_status<>'complete' AND raw_storage IS NOT NULL ORDER BY updated_at,id LIMIT 100",[$r->company,$id])->fetchAll(\PDO::FETCH_ASSOC);
            foreach($pending as $email) {
                if($this->process($email)) $processed++; else $failed++;
            }
            $budget=max(1,min(500,(int)(getenv('RECRUITMENT_SYNC_BATCH')?:100)));
            $worked=0;
            foreach($uids as $uid) {
                $identity=Rules::identity($id,$m['folder'],$validity,(int)$uid);
                $email=$r->query('SELECT * FROM recruitment_emails WHERE company_id=? AND mailbox_id=? AND provider_identity=?',[$r->company,$id,$identity])->fetch(\PDO::FETCH_ASSOC);
                if($email && ($email['processing_status']==='complete'||$email['raw_storage'])) continue;
                if($worked++ >= $budget) break;
                if(!$email) { $emailId=$r->insert('emails',['mailbox_id'=>$id,'provider_identity'=>$identity,'uidvalidity'=>$validity,'uid'=>$uid]); $email=$r->find('emails',$emailId); }
                try {
                    $raw=$this->retry(function()use($m,$uid) {
                        try { return $this->provider->raw((int)$uid); }
                        catch(\Throwable $e) { $this->provider->close(); $this->provider->connect($m); throw $e; }
                    });
                    $key=$this->service->store->put($r->company,$raw);
                    $checksum=hash('sha256',$raw);
                    $values=['raw_storage'=>$key,'raw_checksum'=>$checksum,'processing_status'=>'stored','error_code'=>null];
                    // UIDVALIDITY resets may renumber the same immutable messages. Preserve the new
                    // provider identity, but reuse a single exact-byte match from a previous epoch.
                    // A new message within the current epoch always gets its own application.
                    $prior=$r->query("SELECT application_id FROM recruitment_emails WHERE company_id=? AND mailbox_id=? AND uidvalidity<>? AND raw_checksum=? AND processing_status='complete' AND application_id IS NOT NULL",[$r->company,$id,$validity,$checksum])->fetchAll(\PDO::FETCH_COLUMN);
                    $prior=array_unique($prior);
                    if(count($prior)===1) $values['application_id']=(int)array_values($prior)[0];
                    $r->update('emails',(int)$email['id'],$values);
                    try { $arrival=$this->retry(fn()=>$this->provider->arrival((int)$uid)); $r->update('emails',(int)$email['id'],['received_at'=>$arrival]); }
                    catch(\Throwable $e) { $r->update('emails',(int)$email['id'],['received_at'=>$email['created_at'],'error_code'=>'arrival_date_unavailable']); }
                    $email=$r->find('emails',(int)$email['id']);
                    if($this->process($email)) { $processed++; $r->update('mailboxes',$id,['last_uid'=>$uid]); } else $failed++;
                } catch(\Throwable $e) { $failed++; $r->update('emails',(int)$email['id'],['processing_status'=>'failed','error_code'=>'fetch_or_storage_failed']); }
            }
            $remaining=(int)$r->query("SELECT COUNT(*) FROM recruitment_emails WHERE company_id=? AND mailbox_id=? AND processing_status<>'complete'",[$r->company,$id])->fetchColumn();
            $failed=max($failed,$remaining);
            $r->update('runs',$run,['status'=>$failed?'partial':'complete','processed'=>$processed,'failed'=>$failed,'finished_at'=>gmdate('Y-m-d H:i:s')]);
            $values=['last_sync'=>gmdate('Y-m-d H:i:s'),'last_error'=>$failed?'Some messages need review or retry. Check the failed import queue.':null];
            if($backfillToken!==null) $values+=['enabled'=>1,'preview_token'=>null];
            $r->update('mailboxes',$id,$values); $r->audit('mailbox_sync',$id,$actor,'processed='.$processed.';failed='.$failed);
            return ['status'=>$failed?'partial':'complete','processed'=>$processed,'failed'=>$failed];
        } catch(\Throwable $e) {
            if($run) $r->update('runs',$run,['status'=>'failed','failed'=>$failed+1,'error_code'=>'connection_or_configuration_failed','finished_at'=>gmdate('Y-m-d H:i:s')]);
            $r->update('mailboxes',$id,['last_error'=>'Connection/configuration failed. Verify IMAP extension, allowed host, TLS certificate and credentials; then preview or retry.']);
            // Never propagate native credentials/content in exceptions or logs.
            throw new \RuntimeException('Mailbox operation failed. Verify IMAP extension, allowed host, TLS certificate, credentials, and preview expiry.');
        } finally { $this->provider->close(); $r->query('SELECT RELEASE_LOCK(?)',[$lock]); }
    }
    private function process(array $email): bool
    {
        $r=$this->service->repo; $id=(int)$email['id'];
        try {
            $raw=$this->service->store->read($r->company,$email['raw_storage'],$email['raw_checksum']);
            $message=$this->provider->parse($raw);
            // Provider arrival time takes precedence over sender-controlled Date headers.
            $message['received_at']=$email['received_at'] ?: $email['created_at'];
            $r->transaction(function()use($id,$email,$message) {
                $r=$this->service->repo; $app=$email['application_id']?(int)$email['application_id']:0;
                if(!$app) {
                    $candidate=Rules::extract($message); $normalized=Rules::email($candidate['email']);
                    // Email is a candidate identity, never proof. Forwarded messages remain unmatched.
                    $matches=$normalized?$r->query('SELECT id FROM recruitment_applicants WHERE company_id=? AND email_normalized=? AND merged_into IS NULL',[$r->company,$normalized])->fetchAll(\PDO::FETCH_COLUMN):[];
                    $applicant=count($matches)===1?(int)$matches[0]:$this->service->applicant($candidate);
                    $vacancies=[];
                    foreach($r->all('vacancies') as $v) if(preg_match('/(?<![\w-])'.preg_quote($v['reference'],'/').'(?![\w-])/i',($message['subject']??'').' '.($message['text']??''))) $vacancies[]=$v['id'];
                    $app=$r->insert('applications',['applicant_id'=>$applicant,'vacancy_id'=>count($vacancies)===1?$vacancies[0]:null,'received_at'=>$message['received_at'],'source'=>'email','needs_review'=>1]);
                    $r->insert('history',['application_id'=>$app,'to_status'=>'New']);
                }
                foreach($message['attachments'] as $file) {
                    $existing=$r->query('SELECT id FROM recruitment_attachments WHERE company_id=? AND email_id=? AND part_key=?',[$r->company,$id,$file['part_key']])->fetchColumn();
                    if(!$existing) $this->service->attachment($app,$id,$file);
                }
                $r->update('emails',$id,['application_id'=>$app,'sender'=>mb_substr($message['sender'],0,998),'recipients'=>$message['recipients'],'subject'=>$message['subject'],'message_id'=>mb_substr((string)$message['message_id'],0,998),'received_at'=>$message['received_at'],'body_text'=>$message['text'],'processing_status'=>'complete','error_code'=>$email['error_code']==='arrival_date_unavailable'?'arrival_date_unavailable':null]);
                $r->audit('email_imported',$id,null,'application='.$app);
            });
            return true;
        } catch(\Throwable $e) {
            $r->update('emails',$id,['processing_status'=>'failed','error_code'=>'parse_or_persist_failed']);
            return false;
        }
    }
}
