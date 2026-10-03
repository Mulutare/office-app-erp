<?php
declare(strict_types=1);
namespace Careers;
use App\Services\Recruitment\{CareersContract as Contract,CareersRequestSigner,Rules};

/** This database and storage contain public-site records only, with no ERP access. */
final class Portal
{
    public function __construct(private \PDO $db,private string $storage,private string $origin,private string $key)
    {
        Contract::origin($origin); new CareersRequestSigner($key);
        $root=realpath($storage); $public=realpath(dirname(__DIR__).'/public');
        $documentRoot=!empty($_SERVER['DOCUMENT_ROOT'])?realpath($_SERVER['DOCUMENT_ROOT']):false;
        if(!$root || !$public || str_starts_with(str_replace('\\','/',$root).'/',str_replace('\\','/',$public).'/') || ($documentRoot && str_starts_with(str_replace('\\','/',$root).'/',str_replace('\\','/',$documentRoot).'/')) || !is_writable($root)) throw new \RuntimeException('Private Careers storage unavailable.');
        $this->storage=$root;
    }
    public function query(string $sql,array $args=[]): \PDOStatement { $s=$this->db->prepare($sql); $s->execute($args); return $s; }
    public function origin(): string { return $this->origin; }
    public function limit(string $ip): void
    {
        $window=intdiv(time(),3600); $bucket=hash_hmac('sha256',$ip.':'.$window,$this->key);
        $this->query('INSERT INTO careers_limits(bucket,attempts,expires) VALUES(?,1,?) ON DUPLICATE KEY UPDATE attempts=attempts+1',[$bucket,($window+2)*3600]);
        if((int)$this->query('SELECT attempts FROM careers_limits WHERE bucket=?',[$bucket])->fetchColumn()>10) throw new \InvalidArgumentException('Too many attempts. Please try again later.');
    }
    public function integration(string $path,string $body,array $headers): array
    {
        (new CareersRequestSigner($this->key))->verify('POST',$path,$headers['time']??'',$headers['nonce']??'',$body,$headers['signature']??'',function($nonce,$expires) {
            try { $this->query('INSERT INTO careers_nonces(nonce,expires) VALUES(?,?)',[$nonce,$expires]); return true; }
            catch(\PDOException $e) { if($e->getCode()==='23000') return false; throw $e; }
        });
        $input=Contract::json($body,100000);
        return match($path) {
            '/careers/integration/publication'=>$this->publication($input),
            '/careers/integration/pending'=>$this->pending(),
            '/careers/integration/submission'=>$this->submission($input),
            '/careers/integration/ack'=>$this->ack($input),
            default=>throw new \InvalidArgumentException('Unknown integration operation.'),
        };
    }
    private function publication(array $v): array
    {
        if(!is_string($v['reference']??null)||!preg_match('/^[a-z0-9-]{1,190}$/D',$v['reference'])||!is_int($v['revision']??null)||$v['revision']<1||!in_array($v['state']??'',['private','published','closed'],true)) throw new \InvalidArgumentException('Invalid vacancy.');
        if($v['state']==='published') {
            foreach(['title','description','department','location'] as $field) if(isset($v[$field]) && (!is_string($v[$field]) || mb_strlen($v[$field])>20000)) throw new \InvalidArgumentException('Invalid vacancy content.');
            if(empty($v['title'])||!is_array($v['questions']??null)||count($v['questions'])>12||($v['documents']??null)!==['cv','letter']) throw new \InvalidArgumentException('Invalid vacancy configuration.');
            $codes=[];
            foreach($v['questions'] as $c) { Contract::criterion($c); if(!preg_match('/^[a-f0-9]{24}$/D',$c['code']??'')||in_array($c['code'],$codes,true)) throw new \InvalidArgumentException('Invalid question identity.'); $codes[]=$c['code']; }
            foreach(['opens_on','closes_on'] as $field) if(!empty($v[$field])) Rules::date($v[$field]);
        }
        $payload=json_encode($v,JSON_THROW_ON_ERROR);
        $this->db->beginTransaction();
        try {
            $prior=$this->query('SELECT * FROM careers_vacancies WHERE reference=? FOR UPDATE',[$v['reference']])->fetch(\PDO::FETCH_ASSOC);
            if($prior && ((int)$prior['revision']>$v['revision'] || ((int)$prior['revision']===$v['revision'] && $prior['payload']!==$payload))) throw new \InvalidArgumentException('Publication revision conflict.');
            $this->query('INSERT INTO careers_vacancies(reference,revision,state,payload) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE revision=VALUES(revision),state=VALUES(state),payload=VALUES(payload)',[$v['reference'],$v['revision'],$v['state'],$payload]);
            $this->db->commit(); return ['revision'=>$v['revision']];
        } catch(\Throwable $e) { $this->db->rollBack(); throw $e; }
    }
    private function pending(): array
    {
        // Least recently served first prevents one invalid submission starving the queue.
        return ['ids'=>$this->query('SELECT id FROM careers_submissions WHERE acknowledged=0 ORDER BY last_served_at,created_at,id LIMIT 20')->fetchAll(\PDO::FETCH_COLUMN)];
    }
    private function submission(array $input): array
    {
        $id=$input['id']??'';
        if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new \InvalidArgumentException('Invalid reference.');
        $row=$this->query('SELECT * FROM careers_submissions WHERE id=?',[$id])->fetch(\PDO::FETCH_ASSOC);
        if(!$row) throw new \InvalidArgumentException('Submission unavailable.');
        if(!hash_equals($row['payload_checksum'],hash('sha256',$row['payload']))) throw new \RuntimeException('Submission integrity failed.');
        $payload=Contract::json($row['payload']); $keys=Contract::json($row['document_keys']);
        foreach($keys as $role=>$key) {
            if(!preg_match('/^[a-f0-9]{64}$/D',$key)) throw new \RuntimeException('Document integrity failed.');
            $path=$this->storage.'/'.$key;
            if(!is_file($path)||filesize($path)>Contract::MAX_FILE) throw new \RuntimeException('Document unavailable.');
            $bytes=file_get_contents($path);
            if(!is_string($bytes)||!hash_equals($payload['documents'][$role]['checksum'],hash('sha256',$bytes))) throw new \RuntimeException('Document integrity failed.');
            $payload['documents'][$role]['bytes']=base64_encode($bytes);
        }
        $this->query('UPDATE careers_submissions SET last_served_at=UTC_TIMESTAMP() WHERE id=?',[$id]);
        return $payload;
    }
    private function ack(array $input): array
    {
        $payload=$this->submission($input);
        if(!is_string($input['checksum']??null)||!hash_equals(hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR)),$input['checksum'])) throw new \InvalidArgumentException('Acknowledgement integrity mismatch.');
        $this->query('UPDATE careers_submissions SET acknowledged=1 WHERE id=?',[$payload['id']]);
        return ['acknowledged'=>true];
    }
    public function vacancies(int $page=1): array
    {
        $rows=$this->query("SELECT payload FROM careers_vacancies WHERE state='published' ORDER BY reference LIMIT 30 OFFSET ".(max(0,min(10000,$page-1))*30))->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_filter(array_map(fn($j)=>Contract::json($j),$rows),fn($v)=>Contract::open($v)));
    }
    public function vacancy(string $reference,bool $lock=false): array
    {
        $json=$this->query('SELECT payload FROM careers_vacancies WHERE reference=?'.($lock?' FOR UPDATE':''),[$reference])->fetchColumn();
        if(!$json) throw new \InvalidArgumentException('Vacancy unavailable.');
        return Contract::json($json);
    }
    /** Returns field-specific errors; HTTP handler supplies only real uploaded files. */
    public function validate(array $v,array $input,array $files): array
    {
        $errors=[];
        foreach(['name'=>190,'phone'=>80] as $field=>$max) if(!is_string($input[$field]??null)||trim($input[$field])===''||mb_strlen($input[$field])>$max) $errors[$field]='Please enter your '.$field.'.';
        if(!is_string($input['email']??null)||!Rules::email($input['email'])) $errors['email']='Enter a valid email address.';
        if(($input['consent']??'')!=='yes') $errors['consent']='Please confirm your consent.';
        $answers=$input['answers']??[];
        if(!is_array($answers)) $errors['answers']='Invalid answers.';
        elseif(array_diff(array_keys($answers),array_column($v['questions']??[],'code'))) $errors['answers']='Unknown question. Reload the application form.';
        foreach($v['questions']??[] as $c) {
            try { $a=Contract::answer($c,is_array($answers)?($answers[$c['code']]??null):null); if($a===null&&$c['required']) $errors[$c['code']]='Please answer this question.'; }
            catch(\InvalidArgumentException $e) { $errors[$c['code']]=$e->getMessage(); }
        }
        if(count($files)!==2) $errors['documents']='Upload one CV and one application letter.';
        foreach(['cv','letter'] as $role) {
            try { if(!isset($files[$role])) throw new \InvalidArgumentException('Please upload this document.'); Contract::document($files[$role]['name'],$files[$role]['bytes']); }
            catch(\InvalidArgumentException $e) { $errors[$role]=$e->getMessage(); }
        }
        return $errors;
    }
    public function submit(string $reference,string $id,array $input,array $files): string
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new \InvalidArgumentException('Invalid form reference.');
        $keys=[]; $this->db->beginTransaction();
        try {
            $v=$this->vacancy($reference,true);
            // A retry after a lost response retains its original reference, even after closing.
            $prior=$this->query('SELECT id FROM careers_submissions WHERE id=? AND vacancy_reference=?',[$id,$reference])->fetchColumn();
            if($prior) { $this->db->commit(); return $prior; }
            if(!Contract::open($v)||($input['revision']??'')!==(string)$v['revision']) throw new \InvalidArgumentException('This vacancy has closed or changed. Reload the vacancy before applying.');
            if($this->validate($v,$input,$files)) throw new \InvalidArgumentException('Review the highlighted fields.');
            $documents=[];
            foreach($files as $role=>$file) {
                $key=bin2hex(random_bytes(32)); $path=$this->storage.'/'.$key; $keys[$role]=$key;
                $h=fopen($path,'xb'); if(!$h) throw new \RuntimeException('Storage unavailable.');
                try { if(fwrite($h,$file['bytes'])!==strlen($file['bytes'])||!fflush($h)) throw new \RuntimeException('Storage failed.'); if(function_exists('fsync')) fsync($h); } finally { fclose($h); }
                chmod($path,0600);
                $name=mb_substr(preg_replace('/[\x00-\x1f\x7f]/','_',basename(str_replace('\\','/',$file['name']))),0,254);
                $documents[$role]=['name'=>$name,'checksum'=>hash('sha256',$file['bytes'])];
            }
            $payload=['id'=>$id,'reference'=>$reference,'revision'=>$v['revision'],'submitted_at'=>gmdate('Y-m-d\TH:i:s\Z'),'person'=>['name'=>trim($input['name']),'email'=>trim($input['email']),'phone'=>trim($input['phone'])],'answers'=>$input['answers']??[],'documents'=>$documents,'consent'=>true];
            $json=json_encode($payload,JSON_THROW_ON_ERROR);
            $this->query('INSERT INTO careers_submissions(id,vacancy_reference,payload,payload_checksum,document_keys) VALUES(?,?,?,?,?)',[$id,$reference,$json,hash('sha256',$json),json_encode($keys,JSON_THROW_ON_ERROR)]);
            $this->db->commit(); return $id;
        } catch(\Throwable $e) { if($this->db->inTransaction()) $this->db->rollBack(); foreach($keys as $key) if(is_file($this->storage.'/'.$key)) unlink($this->storage.'/'.$key); throw $e; }
    }
}
