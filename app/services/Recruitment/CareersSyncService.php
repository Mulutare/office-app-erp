<?php
declare(strict_types=1);
namespace App\Services\Recruitment;

final class CareersSyncService
{
    public function __construct(private RecruitmentService $service,private CareersIntegrationClient $client) {}
    public function run(): array
    {
        $r=$this->service->repo; $published=0; $imported=0; $failed=0;
        $lock='officeapp:careers:'.$r->company;
        if((int)$r->query('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1) return ['published'=>0,'imported'=>0,'failed'=>0,'busy'=>true];
        try {
            // Closing in the normal vacancy editor also queues an explicit withdrawal.
            $closed=$r->query("SELECT id FROM recruitment_vacancies WHERE company_id=? AND public_status='published' AND status<>'open'",[$r->company])->fetchAll(\PDO::FETCH_COLUMN);
            foreach($closed as $id) (new CareersPublicationService($r))->publish((int)$id,'closed',null);
            $states=$r->query('SELECT s.*,r.payload FROM recruitment_publication_state s JOIN recruitment_publication_revisions r ON r.company_id=s.company_id AND r.vacancy_id=s.vacancy_id AND r.revision=s.desired_revision WHERE s.company_id=? AND (s.published_revision IS NULL OR s.published_revision<s.desired_revision) ORDER BY s.id LIMIT 50',[$r->company])->fetchAll(\PDO::FETCH_ASSOC);
            foreach($states as $state) {
                try {
                    $r->update('publication_state',(int)$state['id'],['last_attempt_at'=>gmdate('Y-m-d H:i:s')]);
                    $response=$this->client->request('publication',CareersContract::json($state['payload']));
                    if(($response['revision']??null)!==(int)$state['desired_revision']) throw new \RuntimeException('Unexpected publication acknowledgement.');
                    $r->update('publication_state',(int)$state['id'],['published_revision'=>$state['desired_revision'],'last_success_at'=>gmdate('Y-m-d H:i:s'),'last_error'=>null]);
                    $r->audit('careers_publication_synced',(int)$state['vacancy_id']); $published++;
                } catch(\Throwable $e) { $r->update('publication_state',(int)$state['id'],['last_error'=>'Publication failed; retry scheduled.']); $r->audit('careers_publication_failed',(int)$state['vacancy_id']); $failed++; }
            }
            try {
                $list=$this->client->request('pending',['limit'=>20]);
                if(!is_array($list['ids']??null)||count($list['ids'])>20) throw new \RuntimeException('Invalid pending response.');
                foreach($list['ids'] as $id) {
                    try {
                        if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new \RuntimeException('Invalid submission reference.');
                        $payload=$this->client->request('submission',['id'=>$id]);
                        if(($payload['id']??null)!==$id) throw new \RuntimeException('Submission reference mismatch.');
                        (new ExternalSubmissionImporter($this->service))->import($payload);
                        $response=$this->client->request('ack',['id'=>$id,'checksum'=>hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR))]);
                        if(($response['acknowledged']??false)!==true) throw new \RuntimeException('Acknowledgement failed.');
                        $imported++;
                    } catch(\Throwable $e) { $r->audit('careers_sync_failed',0,null,'code=submission_or_ack'); $failed++; }
                }
            } catch(\Throwable $e) { $r->audit('careers_sync_failed',0,null,'code=pending_list'); $failed++; }
        } finally { $r->query('SELECT RELEASE_LOCK(?)',[$lock]); }
        return compact('published','imported','failed');
    }
}
