<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Services\Recruitment\{Repository,DocumentStore,RecruitmentService,ImportService,ImapProvider,Rules};
use App\Services\{AuthorizationService,TenantContext,PowerBiSecretCipher};

final class RecruitmentController
{
    private function require(string $capability): RecruitmentService
    {
        (new AuthorizationService())->requireModulePermission('recruitment','recruitment.'.$capability);
        return new RecruitmentService(new Repository(\db(),(new TenantContext())->companyId()),new DocumentStore());
    }
    private function can(string $capability): bool { return in_array('recruitment.'.$capability,$_SESSION['auth']['permissions']??[],true); }
    private function csrf(): void
    {
        if(!\verifyCsrfToken(\postString('_token'))) { http_response_code(419); echo 'Form session expired. Reload and retry.'; exit; }
    }
    private function id(array $input): int { return max(0,(int)($input['id']??0)); }
    private function render(string $screen,array $values): void
    {
        \view('layouts.app',['pageTitle'=>'Recruitment','pageDescription'=>'Company recruitment register','contentView'=>'hr.recruitment.index','moduleContext'=>['module'=>'recruitment','section'=>$screen==='mailboxes'?'recruitment_mailboxes':'recruitment'],'user'=>$_SESSION['auth'],'applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'screen'=>$screen,'notice'=>\getFlash('recruitment_notice'),'preview'=>\getFlash('recruitment_preview'),'permissions'=>array_filter(['view','edit','export','hire','mailboxes','merge','delete','quarantine','publish'],fn($p)=>$this->can($p))]+$values);
    }
    public function index(): void
    {
        $s=$this->require('view');
        $page=max(1,(int)($_GET['page']??1));
        try {
            $filters=$_GET; if(!$filters) { $filters['view']='attention'; $_GET['view']='attention'; } $applications=$s->repo->applications($filters,50,($page-1)*50);
            $summary=$s->repo->query('SELECT status,COUNT(*) total FROM recruitment_applications WHERE company_id=? AND deleted_at IS NULL GROUP BY status',[$s->repo->company])->fetchAll(\PDO::FETCH_KEY_PAIR);
            $unreviewed=$s->repo->query('SELECT COUNT(*) FROM recruitment_applications WHERE company_id=? AND deleted_at IS NULL AND needs_review=1',[$s->repo->company])->fetchColumn();
            $screeningCounts=$s->repo->query("SELECT COALESCE(s.outcome,'pending') outcome,COUNT(*) total FROM recruitment_applications a LEFT JOIN recruitment_screening_results s ON s.company_id=a.company_id AND s.application_id=a.id WHERE a.company_id=? AND a.deleted_at IS NULL GROUP BY COALESCE(s.outcome,'pending')",[$s->repo->company])->fetchAll(\PDO::FETCH_KEY_PAIR);
            $failed=$s->repo->query("SELECT id,mailbox_id,uid,processing_status,error_code FROM recruitment_emails WHERE company_id=? AND processing_status<>'complete' ORDER BY id DESC LIMIT 100",[$s->repo->company])->fetchAll(\PDO::FETCH_ASSOC);
            $this->render('list',compact('applications','summary','unreviewed','screeningCounts','failed','page')+['vacancies'=>$s->repo->all('vacancies'),'reviewers'=>$s->repo->reviewers()]);
        } catch(\InvalidArgumentException $e) { http_response_code(422); echo \e($e->getMessage()); }
    }
    public function show(): void
    {
        $s=$this->require('view');
        try { $id=$this->id($_GET); $details=$s->details($id); $s->repo->audit('application_viewed',$id,(int)$_SESSION['auth']['user_id']); $this->render('details',$details+['vacancies'=>$s->repo->all('vacancies'),'reviewers'=>$s->repo->reviewers()]); }
        catch(\InvalidArgumentException $e) { http_response_code(404); echo 'Application not found.'; }
    }
    public function vacancies(): void
    {
        $s=$this->require('view'); $this->render('vacancies',['vacancies'=>$s->repo->all('vacancies'),'careers'=>new \App\Services\Recruitment\CareersPublicationService($s->repo),'publicationStates'=>$s->repo->query('SELECT vacancy_id,s.* FROM recruitment_publication_state s WHERE company_id=?',[$s->repo->company])->fetchAll(\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC)]);
    }
    public function applicants(): void
    {
        $s=$this->require('view'); $search='%'.mb_substr(trim((string)($_GET['q']??'')),0,190).'%';
        $page=max(1,(int)($_GET['page']??1));
        $applicants=$s->repo->query('SELECT p.*,(SELECT COUNT(*) FROM recruitment_applications a WHERE a.company_id=p.company_id AND a.applicant_id=p.id AND a.deleted_at IS NULL) application_count FROM recruitment_applicants p WHERE p.company_id=? AND p.merged_into IS NULL AND (p.name LIKE ? OR p.email_normalized LIKE ? OR p.phone LIKE ?) ORDER BY p.name,p.id LIMIT 50 OFFSET '.(($page-1)*50),[$s->repo->company,$search,$search,$search])->fetchAll(\PDO::FETCH_ASSOC);
        $this->render('applicants',compact('applicants','page'));
    }
    public function create(): void
    {
        $s=$this->require('edit'); $this->render('create',['vacancies'=>$s->repo->all('vacancies'),'applicants'=>$s->repo->all('applicants')]);
    }
    public function mailboxes(): void
    {
        $s=$this->require('mailboxes');
        $this->render('mailboxes',['mailboxes'=>$s->repo->all('mailboxes'),'runs'=>$s->repo->all('runs')]);
    }
    private function upload(): ?array
    {
        $file=$_FILES['document']??null;
        if(!$file || (int)$file['error']===UPLOAD_ERR_NO_FILE) return null;
        if((int)$file['error']!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new \InvalidArgumentException('Upload failed or exceeds the server upload limit.');
        if((int)$file['size']>(int)(getenv('RECRUITMENT_MAX_ATTACHMENT_BYTES')?:10485760)) throw new \InvalidArgumentException('Document exceeds the attachment size limit.');
        $bytes=file_get_contents($file['tmp_name']); if(!is_string($bytes)) throw new \InvalidArgumentException('Document could not be read.');
        return ['name'=>$file['name'],'bytes'=>$bytes];
    }
    public function save(): void
    {
        $action=(string)($_POST['action']??'');
        $capability=match($action) {'mailbox','test','preview','sync'=>'mailboxes','merge'=>'merge','archive'=>'delete','publication'=>'publish',default=>'edit'};
        $s=$this->require($capability); $this->csrf(); $actor=(int)$_SESSION['auth']['user_id'];
        $redirect='/hr/recruitment';
        $notice='Saved successfully.';
        try {
            $id=$this->id($_POST);
            switch($action) {
                case 'create': $id=$s->create($_POST,$actor,$this->upload()); $redirect.='/show?id='.$id; break;
                case 'update': $s->update($id,$_POST,$actor,$this->can('hire')); $redirect.='/show?id='.$id; break;
                case 'upload': $file=$this->upload(); if(!$file) throw new \InvalidArgumentException('Select a document.'); $s->repo->transaction(function()use($s,$id,$file,$actor) { $app=$s->repo->find('applications',$id); if($app['deleted_at']) throw new \InvalidArgumentException('Application archived.'); $s->attachment($id,null,$file); $s->repo->audit('document_uploaded',$id,$actor); }); $redirect.='/show?id='.$id; break;
                case 'publication': (new \App\Services\Recruitment\CareersPublicationService($s->repo))->publish($id,(string)($_POST['state']??''),$actor); $redirect.='/vacancies'; break;
                case 'criterion':
                    $options=array_values(array_filter(array_map('trim',explode("\n",(string)($_POST['choices']??''))),fn($v)=>$v!==''));
                    $expected=in_array($_POST['comparison']??'eq',['in','contains'],true)?array_values(array_filter(array_map('trim',explode("\n",(string)($_POST['accepted']??''))),fn($v)=>$v!=='')):(string)($_POST['accepted']??'');
                    (new \App\Services\Recruitment\CareersPublicationService($s->repo))->saveCriterion($id,$_POST+['options'=>$options,'expected'=>$expected,'operator'=>$_POST['comparison']??'eq'],$actor); $redirect.='/vacancies'; break;
                case 'screening_review': (new \App\Services\Recruitment\CareersPublicationService($s->repo))->review($id,$actor); $redirect.='/show?id='.$id; break;
                case 'vacancy': $s->vacancy($_POST,$actor); $redirect.='/vacancies'; break;
                case 'merge': if(($_POST['confirm']??'')!=='MERGE') throw new \InvalidArgumentException('Type MERGE to confirm.'); $s->merge((int)$_POST['from'],(int)$_POST['into'],$actor,(string)$_POST['reason']); break;
                case 'archive': $s->archive($id,$actor); break;
                case 'link': $s->linkEmail($id,(int)$_POST['application_id'],$actor); break;
                case 'mailbox': $this->mailbox($s,$actor); $redirect.='/mailboxes'; break;
                case 'test':
                    $provider=new ImapProvider();
                    try { $provider->connect($s->repo->find('mailboxes',$id)); $provider->inventory($s->repo->find('mailboxes',$id)['initial_date']); }
                    finally { $provider->close(); }
                    $s->repo->audit('mailbox_tested',$id,$actor); $redirect.='/mailboxes'; $notice='Verified TLS connection and folder access succeeded. Preview the historical range before enabling sync.'; break;
                case 'preview': \flash('recruitment_preview',(new ImportService($s,new ImapProvider()))->preview($id,$actor)+['id'=>$id]); $redirect.='/mailboxes'; break;
                case 'sync': $result=(new ImportService($s,new ImapProvider()))->sync($id,!empty($_POST['preview_token'])?(string)$_POST['preview_token']:null,$actor); $redirect.='/mailboxes'; $notice='Sync '.$result['status'].': '.$result['processed'].' processed, '.$result['failed'].' failed. Paused mailboxes require a confirmed preview; busy mailboxes already have a running import.'; break;
                default: throw new \InvalidArgumentException('Unknown recruitment action.');
            }
            \flash('recruitment_notice',$notice);
        } catch(\InvalidArgumentException $e) { \flash('recruitment_notice',$e->getMessage()); }
        catch(\Throwable $e) { \flash('recruitment_notice','Operation failed. Check required fields, private storage, encryption key, IMAP extension, allowed host, TLS certificate and credentials. No stored secrets are displayed.'); }
        \redirect($redirect);
    }
    private function mailbox(RecruitmentService $s,int $actor): void
    {
        $id=$this->id($_POST); $old=$id?$s->repo->find('mailboxes',$id):null;
        $host=strtolower(trim((string)($_POST['host']??''))); $folder=trim((string)($_POST['folder']??'INBOX')); $user=trim((string)($_POST['username']??'')); $port=(int)($_POST['port']??993);
        if(!preg_match('/^[a-z0-9][a-z0-9.-]*$/i',$host)||preg_match('/[{}\r\n]/',$folder)||$folder===''||$user===''||!in_array($port,[993,143],true)) throw new \InvalidArgumentException('Provide an IMAP hostname, username, folder and TLS port 993 or 143.');
        $initial=Rules::date((string)($_POST['initial_date']??''));
        $interval=(int)($_POST['interval_minutes']??5); if($interval<1||$interval>1440) throw new \InvalidArgumentException('Sync interval must be 1–1440 minutes.');
        $retention=trim((string)($_POST['retention_days']??''));
        if($retention!=='' && (!ctype_digit($retention)||(int)$retention<1)) throw new \InvalidArgumentException('Retention must be blank or a positive number of days.');
        $secret=(string)($_POST['password']??'');
        if(!$old && $secret==='') throw new \InvalidArgumentException('A mailbox password is required.');
        $values=['host'=>$host,'port'=>$port,'username'=>$user,'folder'=>$folder,'initial_date'=>$initial,'interval_minutes'=>$interval,'retention_days'=>$retention===''?null:(int)$retention];
        if($secret!=='') $values['secret']=(new PowerBiSecretCipher())->encrypt($secret);
        $changed=!$old || $old['host']!==$host || (int)$old['port']!==$port || $old['username']!==$user || $old['folder']!==$folder || $old['initial_date']!==$initial;
        if($old && ($old['host']!==$host || $old['username']!==$user || $old['folder']!==$folder) && (int)$s->repo->query('SELECT COUNT(*) FROM recruitment_emails WHERE company_id=? AND mailbox_id=?',[$s->repo->company,$id])->fetchColumn()>0) throw new \InvalidArgumentException('This mailbox has preserved imports. Add a new mailbox record to change its account or folder.');
        if($changed) $values+=['enabled'=>0,'uidvalidity'=>null,'last_uid'=>0,'preview_token'=>null];
        elseif(empty($_POST['enabled'])) $values['enabled']=0;
        // Enabling a newly configured historical range always requires a fresh preview token.
        $save=fn()=>$s->repo->transaction(function()use($s,$id,$values,$actor) { if($id) $s->repo->update('mailboxes',$id,$values); else $id=$s->repo->insert('mailboxes',$values); $s->repo->audit('mailbox_configured',$id,$actor); });
        if($id) $s->repo->mailboxLock($id,$save); else $save();
    }
    public function export(): void
    {
        $s=$this->require('export'); (new AuthorizationService())->requireModulePermission('recruitment','recruitment.view');
        $origin=rtrim((string)(getenv('RECRUITMENT_APP_ORIGIN')?:''),'/');
        if(!preg_match('~^https?://[a-z0-9.-]+(?::[0-9]+)?$~i',$origin)) { http_response_code(503); echo 'Set RECRUITMENT_APP_ORIGIN to the ERP HTTPS origin before exporting document links.'; return; }
        try { $bytes=$s->export($_GET,(string)((new TenantContext())->company()['timezone']??'UTC'),$origin.\appBasePath()); }
        catch(\InvalidArgumentException $e) { http_response_code(422); echo \e($e->getMessage()); return; }
        header('Cache-Control: no-store'); header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="recruitment.xlsx"'); echo $bytes;
    }
    public function download(): void
    {
        $s=$this->require('view');
        try {
            $file=$s->repo->find('attachments',$this->id($_GET));
            if($file['scan_status']!=='clean' || $file['validation_status']!=='accepted') {
                // Explicit separate permission and acknowledgement for quarantined material.
                (new AuthorizationService())->requireModulePermission('recruitment','recruitment.quarantine');
                if(($_GET['acknowledge']??'')!=='quarantine') { http_response_code(409); echo 'This file is unscanned or rejected. Use the quarantine review action to acknowledge the risk.'; return; }
            }
            $bytes=$s->store->read($s->repo->company,$file['storage_key'],$file['checksum']);
            $s->repo->audit('document_downloaded',(int)$file['id'],(int)$_SESSION['auth']['user_id'],'scan_status='.$file['scan_status']);
            header('Content-Type: application/octet-stream'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store');
            header("Content-Disposition: attachment; filename=\"document\"; filename*=UTF-8''".rawurlencode($file['original_name'])); echo $bytes;
        } catch(\InvalidArgumentException $e) { http_response_code(404); echo 'Document not found.'; }
    }
    public function raw(): void
    {
        $s=$this->require('mailboxes'); (new AuthorizationService())->requireModulePermission('recruitment','recruitment.quarantine');
        try { $mail=$s->repo->find('emails',$this->id($_GET)); if(!$mail['raw_storage']) throw new \InvalidArgumentException('Raw message unavailable.'); $raw=$s->store->read($s->repo->company,$mail['raw_storage'],$mail['raw_checksum']); $s->repo->audit('raw_email_downloaded',(int)$mail['id'],(int)$_SESSION['auth']['user_id']); header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="quarantined-message.eml"'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store'); echo $raw; }
        catch(\InvalidArgumentException $e) { http_response_code(404); echo 'Message not found.'; }
    }
}
