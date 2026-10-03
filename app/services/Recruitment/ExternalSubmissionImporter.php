<?php
declare(strict_types=1);
namespace App\Services\Recruitment;

final class ExternalSubmissionImporter
{
    public function __construct(private RecruitmentService $service) {}
    public function import(array $payload): int
    {
        $r=$this->service->repo;
        $id=$payload['id']??''; $reference=$payload['reference']??'';
        if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||!is_string($reference)||strlen($reference)>190) throw new \InvalidArgumentException('Invalid submission identity.');
        $encoded=json_encode($payload,JSON_THROW_ON_ERROR);
        if(strlen($encoded)>CareersContract::MAX_BODY) throw new \InvalidArgumentException('Submission exceeds limit.');
        $checksum=hash('sha256',$encoded);
        $keys=[];
        try {
            return $r->transaction(function()use($r,$payload,$id,$reference,$checksum,&$keys) {
                // Vacancy lock serializes imports and publication changes for this tenant/vacancy.
                $v=$r->query('SELECT * FROM recruitment_vacancies WHERE company_id=? AND public_slug=? FOR UPDATE',[$r->company,$reference])->fetch(\PDO::FETCH_ASSOC);
                if(!$v) throw new \InvalidArgumentException('Unknown public vacancy.');
                $prior=$r->query("SELECT * FROM recruitment_external_submissions WHERE company_id=? AND source='careers_website' AND source_submission_id=? FOR UPDATE",[$r->company,$id])->fetch(\PDO::FETCH_ASSOC);
                if($prior) {
                    if(!hash_equals($prior['payload_checksum'],$checksum)) throw new \InvalidArgumentException('Submission identity has changed content.');
                    return (int)$prior['application_id'];
                }
                if($v['status']!=='open'||!CareersContract::open(['state'=>$v['public_status']]+$v)) throw new \InvalidArgumentException('Vacancy is closed or unpublished; import requires attention.');
                if(!is_int($payload['revision']??null)||$payload['revision']<1) throw new \InvalidArgumentException('Invalid publication revision.');
                $json=$r->query('SELECT payload FROM recruitment_publication_revisions WHERE company_id=? AND vacancy_id=? AND revision=?',[$r->company,$v['id'],$payload['revision']])->fetchColumn();
                if(!$json) throw new \InvalidArgumentException('Unknown vacancy revision.');
                $snapshot=CareersContract::json($json);
                $submitted=$payload['submitted_at']??'';
                $date=is_string($submitted)?\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$submitted,new \DateTimeZone('UTC')):false;
                if(!$date || $date->format('Y-m-d\TH:i:s\Z')!==$submitted || $date->getTimestamp()>time()+300 || !CareersContract::open($snapshot,$date->format('Y-m-d'))) throw new \InvalidArgumentException('Invalid submission date.');
                $person=$payload['person']??null; $answers=$payload['answers']??null; $documents=$payload['documents']??null;
                foreach(['name','email','phone'] as $field) if(!is_array($person)||!is_string($person[$field]??null)) throw new \InvalidArgumentException('Invalid applicant details.');
                if(!is_array($person)||!Rules::email((string)($person['email']??''))||strlen((string)($person['phone']??''))>80||trim((string)($person['phone']??''))===''||($payload['consent']??false)!==true||!is_array($answers)||!is_array($documents)||count($documents)!==2) throw new \InvalidArgumentException('Incomplete application.');
                $codes=array_column($snapshot['questions'],'code');
                if(array_diff(array_keys($answers),$codes)) throw new \InvalidArgumentException('Unknown screening answer.');
                foreach($answers as $answer) if(strlen(json_encode($answer,JSON_THROW_ON_ERROR))>4000) throw new \InvalidArgumentException('Answer exceeds limit.');
                $files=[];
                foreach(['cv','letter'] as $role) {
                    $doc=$documents[$role]??[];
                    if(!is_string($doc['name']??null)||!is_string($doc['bytes']??null)) throw new \InvalidArgumentException('Required document missing.');
                    $bytes=base64_decode($doc['bytes'],true);
                    if($bytes===false) throw new \InvalidArgumentException('Invalid document encoding.');
                    $meta=CareersContract::document($doc['name'],$bytes);
                    if(!is_string($doc['checksum']??null)||!hash_equals($meta['checksum'],$doc['checksum'])) throw new \InvalidArgumentException('Document integrity check failed.');
                    $files[$role]=['name'=>$doc['name'],'bytes'=>$bytes];
                }
                $result=(new ScreeningService())->evaluate($snapshot['questions'],$answers);
                $duplicate=(int)$r->query('SELECT COUNT(*) FROM recruitment_applications a JOIN recruitment_applicants p ON p.company_id=a.company_id AND p.id=a.applicant_id WHERE a.company_id=? AND a.vacancy_id=? AND p.email_normalized=?',[$r->company,$v['id'],Rules::email($person['email'])])->fetchColumn();
                $applicant=$this->service->applicant(['name'=>$person['name']??'','email'=>$person['email'],'phone'=>$person['phone']]);
                $application=$r->insert('applications',['applicant_id'=>$applicant,'vacancy_id'=>$v['id'],'received_at'=>$date->format('Y-m-d H:i:s'),'source'=>'website','needs_review'=>1,'notes'=>$duplicate?'Possible repeat application for this vacancy/email; HR must review.':'']);
                foreach($files as $role=>$file) {
                    $key=$this->service->store->put($r->company,$file['bytes']); $keys[]=$key;
                    $name=mb_substr(preg_replace('/[\x00-\x1f\x7f]/','_',basename(str_replace('\\','/',$file['name']))),0,254);
                    $r->insert('attachments',CareersContract::document($name,$file['bytes'])+['application_id'=>$application,'part_key'=>$role,'original_name'=>$name,'storage_key'=>$key,'scan_status'=>'quarantine']);
                }
                foreach($result['answers'] as $answer) {
                    $criterion=$r->query('SELECT id FROM recruitment_vacancy_criteria WHERE company_id=? AND vacancy_id=? AND code=?',[$r->company,$v['id'],$answer['code']])->fetchColumn();
                    if(!$criterion) throw new \InvalidArgumentException('Screening definition is unavailable.');
                    $r->insert('application_answers',['application_id'=>$application,'criterion_id'=>$criterion,'question_snapshot'=>json_encode($answer,JSON_THROW_ON_ERROR),'raw_value'=>json_encode($answer['answer'],JSON_THROW_ON_ERROR),'normalized_value'=>json_encode($answer['normalized'],JSON_THROW_ON_ERROR),'evaluation'=>$answer['evaluation'],'evaluation_reason'=>$answer['reason']]);
                }
                $summary=$result; unset($summary['answers']);
                $r->insert('screening_results',$summary+['application_id'=>$application,'explanation'=>json_encode($result['answers'],JSON_THROW_ON_ERROR),'evaluated_at'=>gmdate('Y-m-d H:i:s')]);
                $r->insert('external_submissions',['source_submission_id'=>$id,'source_vacancy_reference'=>$reference,'application_id'=>$application,'submitted_at'=>$date->format('Y-m-d H:i:s'),'imported_at'=>gmdate('Y-m-d H:i:s'),'import_status'=>'imported','payload_checksum'=>$checksum]);
                $r->insert('history',['application_id'=>$application,'to_status'=>'New']);
                $r->audit('website_application_imported',$application); $r->audit('screening_evaluated',$application,null,'outcome='.$result['outcome']);
                return $application;
            });
        } catch(\Throwable $e) {
            foreach($keys as $key) { $path=$this->service->store->path($r->company,$key); if(is_file($path)) unlink($path); }
            $r->audit('careers_import_failed',0,null,'submission='.substr($id,0,32).'; code=validation_or_storage');
            throw $e;
        }
    }
}
