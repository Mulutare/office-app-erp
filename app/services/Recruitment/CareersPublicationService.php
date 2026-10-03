<?php
declare(strict_types=1);
namespace App\Services\Recruitment;

final class CareersPublicationService
{
    public function __construct(private Repository $r) {}
    public function criteria(int $vacancy, bool $active=true): array
    {
        $this->r->find('vacancies',$vacancy);
        $rows=$this->r->query('SELECT * FROM recruitment_vacancy_criteria WHERE company_id=? AND vacancy_id=?'.($active?' AND active=1':'').' ORDER BY sort_order,id',[$this->r->company,$vacancy])->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(static function($c) { $c['options']=json_decode($c['options_json']??'[]',true,32,JSON_THROW_ON_ERROR); $c['expected']=json_decode($c['expected_value']??'null',true,32,JSON_THROW_ON_ERROR); return $c; },$rows);
    }
    public function saveCriterion(int $vacancy,array $input,?int $actor): int
    {
        return $this->r->transaction(function()use($vacancy,$input,$actor) {
            $this->r->find('vacancies',$vacancy,true);
            $id=(int)($input['criterion_id']??0);
            if ($id && (int)$this->r->find('vacancy_criteria',$id,true)['vacancy_id']!==$vacancy) throw new \InvalidArgumentException('Question belongs to another vacancy.');
            $c=CareersContract::criterion($input);
            $values=$c; unset($values['options'],$values['expected']);
            $values['options_json']=json_encode($c['options'],JSON_THROW_ON_ERROR); $values['expected_value']=json_encode($c['expected'],JSON_THROW_ON_ERROR);
            $values['required']=(int)$values['required']; $values['active']=!empty($input['active'])?1:0;
            if($values['active'] && count(array_filter($this->criteria($vacancy),fn($row)=>(int)$row['id']!==$id))>=12) throw new \InvalidArgumentException('Keep the application short: at most 12 active questions.');
            if ($id) $this->r->update('vacancy_criteria',$id,$values);
            else {
                if(count($this->criteria($vacancy))>=12) throw new \InvalidArgumentException('Keep the application short: at most 12 active questions.');
                $id=$this->r->insert('vacancy_criteria',$values+['vacancy_id'=>$vacancy,'code'=>bin2hex(random_bytes(12))]);
            }
            $this->r->audit($values['active']?'criterion_saved':'criterion_deactivated',$id,$actor);
            return $id;
        });
    }
    public function publish(int $id,string $state,?int $actor): int
    {
        if (!in_array($state,['published','closed','private'],true)) throw new \InvalidArgumentException('Invalid publication state.');
        return $this->r->transaction(function()use($id,$state,$actor) {
            $v=$this->r->find('vacancies',$id,true);
            if ($state==='published' && ($v['status']!=='open' || ($v['closes_on'] && $v['closes_on']<gmdate('Y-m-d')))) throw new \InvalidArgumentException('Only an open, unexpired vacancy can be published.');
            $slug=$v['public_slug']?:trim(substr(preg_replace('/[^a-z0-9]+/','-',strtolower($v['title'])),0,90),'-').'-'.bin2hex(random_bytes(8));
            $revision=(int)$v['public_revision']+1;
            $questions=[];
            foreach($this->criteria($id) as $c) $questions[]=CareersContract::criterion($c)+['code'=>$c['code']];
            $payload=['reference'=>$slug,'revision'=>$revision,'state'=>$state];
            if($state==='published') $payload+=array_intersect_key($v,array_flip(['title','department','location','description','opens_on','closes_on']))+['questions'=>$questions,'documents'=>['cv','letter']];
            $this->r->update('vacancies',$id,['public_slug'=>$slug,'public_status'=>$state,'public_revision'=>$revision,'published_at'=>gmdate('Y-m-d H:i:s')]);
            $this->r->insert('publication_revisions',['vacancy_id'=>$id,'revision'=>$revision,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)]);
            $this->r->query('INSERT INTO recruitment_publication_state(company_id,vacancy_id,desired_state,desired_revision) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE desired_state=VALUES(desired_state),desired_revision=VALUES(desired_revision)',[$this->r->company,$id,$state,$revision]);
            $this->r->audit('vacancy_publication_'.$state,$id,$actor,'revision='.$revision);
            return $revision;
        });
    }
    public function review(int $application,int $actor): void
    {
        $this->r->transaction(function()use($application,$actor) {
            $this->r->find('applications',$application,true);
            $this->r->query('UPDATE recruitment_screening_results SET reviewed_by=?,reviewed_at=UTC_TIMESTAMP() WHERE company_id=? AND application_id=?',[$actor,$this->r->company,$application]);
            $this->r->audit('screening_reviewed',$application,$actor,'Original objective results retained');
        });
    }
}
