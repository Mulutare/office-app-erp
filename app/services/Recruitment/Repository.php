<?php
declare(strict_types=1);
namespace App\Services\Recruitment;

/** Recruitment persistence uses an explicit immutable company scope, including CLI imports. */
final class Repository
{
    public function __construct(public readonly \PDO $pdo,public readonly int $company)
    {
        if($company<1) throw new \RuntimeException('Active company required.');
        if($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='mysql') throw new \RuntimeException('Recruitment currently requires the MySQL ERP driver.');
    }
    public function query(string $sql,array $args=[]): \PDOStatement
    {
        $s=$this->pdo->prepare($sql); $s->execute($args); return $s;
    }
    private function table(string $name): string
    {
        if(!in_array($name,['vacancies','applicants','applications','emails','attachments','history','mailboxes','runs','events'],true)) throw new \LogicException('Unknown recruitment table.');
        return 'recruitment_'.$name;
    }
    public function find(string $table,int $id,bool $lock=false): array
    {
        $row=$this->query('SELECT * FROM '.$this->table($table).' WHERE company_id=? AND id=?'.($lock?' FOR UPDATE':''),[$this->company,$id])->fetch(\PDO::FETCH_ASSOC);
        if(!$row) throw new \InvalidArgumentException('Record not found in this company.');
        return $row;
    }
    public function all(string $table): array
    {
        $columns=$table==='mailboxes'?'id,host,port,username,folder,initial_date,interval_minutes,enabled,last_sync,last_error,uidvalidity,last_uid,retention_days':'*';
        return $this->query('SELECT '.$columns.' FROM '.$this->table($table).' WHERE company_id=? ORDER BY id DESC',[$this->company])->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function insert(string $table,array $values): int
    {
        $values=['company_id'=>$this->company,'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')]+$values;
        $keys=array_keys($values);
        foreach($keys as $key) if(!preg_match('/^[a-z_]+$/D',$key)) throw new \LogicException('Invalid field.');
        $this->query('INSERT INTO '.$this->table($table).' ('.implode(',',$keys).') VALUES ('.implode(',',array_fill(0,count($values),'?')).')',array_values($values));
        return (int)$this->pdo->lastInsertId();
    }
    public function update(string $table,int $id,array $values): void
    {
        $values['updated_at']=gmdate('Y-m-d H:i:s');
        $keys=array_keys($values);
        foreach($keys as $key) if(!preg_match('/^[a-z_]+$/D',$key)||$key==='company_id'||$key==='id') throw new \LogicException('Invalid field.');
        $this->query('UPDATE '.$this->table($table).' SET '.implode(',',array_map(fn($k)=>$k.'=?',$keys)).' WHERE company_id=? AND id=?',[...array_values($values),$this->company,$id]);
    }
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try { $result=$callback(); $this->pdo->commit(); return $result; }
        catch(\Throwable $e) { if($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
    public function mailboxLock(int $id,callable $callback): mixed
    {
        $this->find('mailboxes',$id);
        $lock='officeapp:recruitment:'.$this->company.':'.$id;
        if((int)$this->query('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1) throw new \InvalidArgumentException('Mailbox is syncing. Retry after the current run completes.');
        try { return $callback(); }
        finally { $this->query('SELECT RELEASE_LOCK(?)',[$lock]); }
    }
    public function audit(string $action,int $record,?int $actor=null,string $detail=''): void
    {
        $this->insert('events',['actor_id'=>$actor,'action'=>$action,'record_id'=>$record,'detail'=>$detail]);
        // Existing ERP audit stream, with identifiers only and no applicant content.
        \App\Repositories\RepositoryFactory::auditLogs()->record($actor,'recruitment.'.$action,'hr','recruitment',$record?(string)$record:null,null,null,$this->company);
    }
    public function applications(array $filters=[],?int $limit=200,int $offset=0): array
    {
        $where=['a.company_id=?','a.deleted_at IS NULL']; $args=[$this->company];
        foreach(['status'=>'a.status','vacancy'=>'a.vacancy_id','source'=>'a.source','reviewer'=>'a.reviewer_id','applicant'=>'a.applicant_id'] as $key=>$column) if(isset($filters[$key]) && $filters[$key]!=='') { $where[]=$column.'=?'; $args[]=$filters[$key]; }
        foreach(['from'=>'>=','to'=>'<'] as $key=>$operator) if(!empty($filters[$key])) {
            $tz=(string)$this->query('SELECT timezone FROM companies WHERE company_id=?',[$this->company])->fetchColumn();
            $date=new \DateTimeImmutable(Rules::date($filters[$key]),new \DateTimeZone($tz?:'UTC'));
            if($key==='to') $date=$date->modify('+1 day');
            $where[]='a.received_at'.$operator.'?'; $args[]=$date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if(!empty($filters['q'])) {
            $where[]='(p.name LIKE ? OR p.email_normalized LIKE ? OR p.phone LIKE ?)';
            for($i=0;$i<3;$i++) $args[]='%'.substr((string)$filters['q'],0,190).'%';
        }
        if(!empty($filters['queue'])) $where[]="(a.needs_review=1 OR a.vacancy_id IS NULL OR p.identity_verified=0 OR NOT EXISTS(SELECT 1 FROM recruitment_attachments d WHERE d.company_id=a.company_id AND d.application_id=a.id AND d.validation_status='accepted') OR EXISTS(SELECT 1 FROM recruitment_attachments own JOIN recruitment_attachments otherdoc ON otherdoc.company_id=own.company_id AND otherdoc.checksum=own.checksum JOIN recruitment_applications otherapp ON otherapp.company_id=otherdoc.company_id AND otherapp.id=otherdoc.application_id WHERE own.company_id=a.company_id AND own.application_id=a.id AND otherapp.applicant_id<>a.applicant_id AND otherapp.deleted_at IS NULL) OR EXISTS(SELECT 1 FROM recruitment_applicants dup WHERE dup.company_id=p.company_id AND dup.id<>p.id AND dup.merged_into IS NULL AND ((p.email_normalized IS NOT NULL AND dup.email_normalized=p.email_normalized) OR (p.phone IS NOT NULL AND p.phone<>'' AND dup.phone=p.phone))))";
        $sql='SELECT a.*,p.name,p.email_original,p.email_normalized,p.phone,p.contact_details,p.identity_verified,p.email_verified,v.reference,v.title,v.department,v.location,u.display_name reviewer_name FROM recruitment_applications a JOIN recruitment_applicants p ON p.company_id=a.company_id AND p.id=a.applicant_id LEFT JOIN recruitment_vacancies v ON v.company_id=a.company_id AND v.id=a.vacancy_id LEFT JOIN users u ON u.user_id=a.reviewer_id WHERE '.implode(' AND ',$where).' ORDER BY a.received_at DESC,a.id DESC';
        if($limit!==null) $sql.=' LIMIT '.max(1,min($limit,10000)).' OFFSET '.max(0,$offset);
        return $this->query($sql,$args)->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function reviewers(): array
    {
        $rows=$this->query('SELECT u.user_id,u.display_name FROM users u JOIN company_users cu ON cu.user_id=u.user_id WHERE cu.company_id=? AND cu.active=1 AND u.active=1 AND u.deleted_at IS NULL',[$this->company])->fetchAll(\PDO::FETCH_ASSOC);
        return array_values(array_filter($rows,fn($u)=>(new \App\Services\ModuleRoleService())->permissionAllowed($this->company,(int)$u['user_id'],'hr.recruitment.edit')));
    }
}
