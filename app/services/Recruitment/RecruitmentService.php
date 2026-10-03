<?php
declare(strict_types=1);
namespace App\Services\Recruitment;

final class RecruitmentService
{
    public function __construct(public readonly Repository $repo,public readonly DocumentStore $store) {}
    public function vacancy(array $input,?int $actor): int
    {
        $id=(int)($input['id']??0);
        $values=[];
        foreach(['reference','title','department','location','description'] as $key) $values[$key]=trim((string)($input[$key]??''));
        if($values['reference']==='' || $values['title']==='' || strlen($values['reference'])>80 || strlen($values['title'])>190) throw new \InvalidArgumentException('Vacancy reference and title are required and must fit their fields.');
        $values['status']=$input['status']??'draft';
        if(!in_array($values['status'],['draft','open','closed'],true)) throw new \InvalidArgumentException('Invalid vacancy status.');
        foreach(['opens_on','closes_on'] as $key) $values[$key]=!empty($input[$key])?Rules::date($input[$key]):null;
        if($values['opens_on'] && $values['closes_on'] && $values['closes_on']<$values['opens_on']) throw new \InvalidArgumentException('Closing date must follow opening date.');
        return $this->repo->transaction(function()use($id,$values,$actor) {
            if($id) { $this->repo->find('vacancies',$id,true); $this->repo->update('vacancies',$id,$values); }
            else $id=$this->repo->insert('vacancies',$values);
            $this->repo->audit('vacancy_saved',$id,$actor); return $id;
        });
    }
    public function applicant(array $input): int
    {
        $id=(int)($input['applicant_id']??0);
        if($id) { $p=$this->repo->find('applicants',$id); if($p['merged_into']) throw new \InvalidArgumentException('Select the retained applicant.'); return $id; }
        $name=trim((string)($input['name']??'')); $email=trim((string)($input['email']??''));
        if($name===''||mb_strlen($name)>190) throw new \InvalidArgumentException('Applicant name is required (maximum 190 characters).');
        if($email!=='' && !Rules::email($email)) throw new \InvalidArgumentException('Enter a valid email or leave it blank for review.');
        return $this->repo->insert('applicants',['name'=>$name,'email_original'=>$email?:null,'email_normalized'=>Rules::email($email),'phone'=>trim((string)($input['phone']??''))?:null,'contact_details'=>trim((string)($input['contact_details']??''))?:null]);
    }
    public function create(array $input,?int $actor,?array $upload=null): int
    {
        return $this->repo->transaction(function()use($input,$actor,$upload) {
            $vacancy=(int)($input['vacancy_id']??0); if($vacancy) $this->repo->find('vacancies',$vacancy);
            $id=$this->repo->insert('applications',['applicant_id'=>$this->applicant($input),'vacancy_id'=>$vacancy?:null,'received_at'=>gmdate('Y-m-d H:i:s'),'source'=>'manual','notes'=>trim((string)($input['notes']??''))]);
            if($upload) $this->attachment($id,null,$upload);
            $this->repo->insert('history',['application_id'=>$id,'to_status'=>'New','actor_id'=>$actor]);
            $this->repo->audit('application_created',$id,$actor); return $id;
        });
    }
    public function attachment(?int $application,?int $email,array $file): int
    {
        if($application) $this->repo->find('applications',$application);
        if($email) $this->repo->find('emails',$email);
        $name=mb_substr(basename(str_replace('\\','/',(string)$file['name'])),0,254);
        $name=preg_replace('/[\x00-\x1f\x7f]/','_',$name);
        $meta=Rules::file($name,$file['bytes'],(int)(getenv('RECRUITMENT_MAX_ATTACHMENT_BYTES')?:10485760));
        if(!$email && $meta['validation_status']!=='accepted') throw new \InvalidArgumentException('Upload rejected: invalid file type or file exceeds attachment limit.');
        // Imported invalid/oversized parts are preserved but never released from quarantine.
        $key=$this->store->put($this->repo->company,$file['bytes']);
        return $this->repo->insert('attachments',$meta+['application_id'=>$application,'email_id'=>$email,'part_key'=>$file['part_key']??null,'original_name'=>$name,'storage_key'=>$key,'scan_status'=>'quarantine']);
    }
    public function update(int $id,array $input,int $actor,bool $canHire): void
    {
        $this->repo->transaction(function()use($id,$input,$actor,$canHire) {
            $old=$this->repo->find('applications',$id,true);
            if($old['deleted_at']) throw new \InvalidArgumentException('Application archived.');
            $status=$input['status']??$old['status'];
            if(!in_array($status,Rules::STATUSES,true)) throw new \InvalidArgumentException('Invalid application status.');
            if(($status==='Hired'||$old['status']==='Hired') && $status!==$old['status'] && !$canHire) throw new \InvalidArgumentException('Hiring permission is required.');
            $reviewer=(int)($input['reviewer_id']??0); $vacancy=(int)($input['vacancy_id']??0);
            if($old['source']==='website' && $vacancy!==(int)$old['vacancy_id']) throw new \InvalidArgumentException('Website answers belong to their submitted vacancy. Create a separate application for another vacancy.');
            if($reviewer && !in_array($reviewer,array_map('intval',array_column($this->repo->reviewers(),'user_id')),true)) throw new \InvalidArgumentException('Select an active HR reviewer from this company.');
            if($vacancy) $this->repo->find('vacancies',$vacancy);
            $p=$this->repo->find('applicants',(int)$old['applicant_id'],true);
            $email=trim((string)($input['email']??$p['email_original'])); $name=trim((string)($input['name']??$p['name']));
            if($name==='' || mb_strlen($name)>190 || ($email!==''&&!Rules::email($email))) throw new \InvalidArgumentException('Review applicant name and email.');
            $verified=!empty($input['identity_verified']);
            if($status==='Hired' && (!$verified || !$vacancy)) throw new \InvalidArgumentException('Hiring requires a verified identity and matched vacancy.');
            $this->repo->update('applicants',(int)$p['id'],['name'=>$name,'email_original'=>$email?:null,'email_normalized'=>Rules::email($email),'phone'=>trim((string)($input['phone']??$p['phone']))?:null,'contact_details'=>trim((string)($input['contact_details']??$p['contact_details'])),'identity_verified'=>(int)$verified,'email_verified'=>(int)!empty($input['email_verified'])]);
            $this->repo->update('applications',$id,['status'=>$status,'reviewer_id'=>$reviewer?:null,'vacancy_id'=>$vacancy?:null,'notes'=>trim((string)($input['notes']??'')),'needs_review'=>(int)!empty($input['needs_review'])]);
            if($old['status']!==$status) $this->repo->insert('history',['application_id'=>$id,'from_status'=>$old['status'],'to_status'=>$status,'actor_id'=>$actor]);
            $this->repo->audit('application_updated',$id,$actor,'status='.$status);
        });
    }
    public function details(int $id): array
    {
        $app=$this->repo->find('applications',$id); if($app['deleted_at']) throw new \InvalidArgumentException('Application archived.');
        $p=$this->repo->find('applicants',(int)$app['applicant_id']);
        $company=$this->repo->company;
        $attachments=$this->repo->query('SELECT * FROM recruitment_attachments WHERE company_id=? AND application_id=?',[$company,$id])->fetchAll(\PDO::FETCH_ASSOC);
        $emails=$this->repo->query('SELECT id,sender,recipients,subject,received_at,body_text,processing_status,error_code FROM recruitment_emails WHERE company_id=? AND application_id=?',[$company,$id])->fetchAll(\PDO::FETCH_ASSOC);
        $history=$this->repo->query('SELECT h.*,u.display_name FROM recruitment_history h LEFT JOIN users u ON u.user_id=h.actor_id WHERE h.company_id=? AND h.application_id=? ORDER BY h.id',[$company,$id])->fetchAll(\PDO::FETCH_ASSOC);
        $duplicates=$this->repo->query('SELECT DISTINCT p.id,p.name,p.email_original,p.phone FROM recruitment_applicants p WHERE p.company_id=? AND p.id<>? AND p.merged_into IS NULL AND ((? IS NOT NULL AND p.email_normalized=?) OR (? IS NOT NULL AND p.phone=?) OR p.id IN (SELECT a.applicant_id FROM recruitment_applications a JOIN recruitment_attachments d ON d.company_id=a.company_id AND d.application_id=a.id WHERE a.company_id=? AND d.checksum IN(SELECT checksum FROM recruitment_attachments WHERE company_id=? AND application_id=?)))',[$company,$p['id'],$p['email_normalized'],$p['email_normalized'],$p['phone'],$p['phone'],$company,$company,$id])->fetchAll(\PDO::FETCH_ASSOC);
        $screening=$this->repo->query('SELECT * FROM recruitment_screening_results WHERE company_id=? AND application_id=?',[$company,$id])->fetch(\PDO::FETCH_ASSOC);
        return compact('app','p','attachments','emails','history','duplicates','screening');
    }
    public function merge(int $from,int $into,int $actor,string $reason): void
    {
        if($from===$into || trim($reason)==='') throw new \InvalidArgumentException('Select two applicants and give a merge reason.');
        $this->repo->transaction(function()use($from,$into,$actor,$reason) {
            // Deterministic lock order avoids reciprocal merge deadlocks.
            foreach([min($from,$into),max($from,$into)] as $id) { $p=$this->repo->find('applicants',$id,true); if($p['merged_into']) throw new \InvalidArgumentException('Applicant was already merged.'); }
            $this->repo->query('UPDATE recruitment_applications SET applicant_id=? WHERE company_id=? AND applicant_id=?',[$into,$this->repo->company,$from]);
            $this->repo->update('applicants',$from,['merged_into'=>$into]);
            $this->repo->audit('applicant_merged',$from,$actor,'retained_applicant='.$into.'; human_confirmation=1; reason='.mb_substr(trim($reason),0,200));
        });
    }
    public function archive(int $id,int $actor): void
    {
        $this->repo->transaction(function()use($id,$actor) { $this->repo->find('applications',$id,true); $this->repo->update('applications',$id,['deleted_at'=>gmdate('Y-m-d H:i:s')]); $this->repo->audit('application_archived',$id,$actor,'Documents retained'); });
    }
    public function linkEmail(int $email,int $application,int $actor): void
    {
        $this->repo->transaction(function()use($email,$application,$actor) {
            $e=$this->repo->find('emails',$email,true); $old=$e['application_id']?(int)$e['application_id']:null;
            $target=$this->repo->find('applications',$application,true);
            if($target['deleted_at']) throw new \InvalidArgumentException('Target is archived.');
            $this->repo->update('emails',$email,['application_id'=>$application]);
            $this->repo->query('UPDATE recruitment_attachments SET application_id=? WHERE company_id=? AND email_id=?',[$application,$this->repo->company,$email]);
            // Keep the originally generated application visible until HR deliberately archives it.
            if($old && $old!==$application) $this->repo->update('applications',$old,['needs_review'=>1]);
            $this->repo->audit('email_linked',$email,$actor,'application='.$application);
        });
    }
    public function export(array $filters,string $timezone,string $base): string
    {
        $headers=['Application ID','Received Date','Applicant Name','Email','Phone','Vacancy Reference','Vacancy Title','Department','Location','Status','Assigned HR','Source','CV Link','Notes','Last Updated'];
        $rows=[]; $tz=new \DateTimeZone($timezone);
        foreach($this->repo->applications($filters,null) as $app) {
            $attachment=$this->repo->query("SELECT id FROM recruitment_attachments WHERE company_id=? AND application_id=? AND validation_status='accepted' ORDER BY id LIMIT 1",[$this->repo->company,$app['id']])->fetchColumn();
            $date=fn($v)=>(new \DateTimeImmutable($v,new \DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d H:i:s');
            $rows[]=[(string)$app['id'],$date($app['received_at']),$app['name'],$app['email_original'],$app['phone'],$app['reference'],$app['title'],$app['department'],$app['location'],$app['status'],$app['reviewer_name'],$app['source'],$attachment?$base.'/hr/recruitment/download?id='.$attachment:'',$app['notes'],$date($app['updated_at'])];
        }
        $bytes=(new \App\Services\DataExchange\SpreadsheetCodec())->write($headers,$rows);
        $this->repo->audit('export',0,(int)($_SESSION['auth']['user_id']??0)?:null,'rows='.count($rows));
        return $bytes;
    }
}
