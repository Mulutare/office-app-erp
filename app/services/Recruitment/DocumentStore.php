<?php
declare(strict_types=1);
namespace App\Services\Recruitment;

final class DocumentStore
{
    private string $root;
    public function __construct(?string $root=null)
    {
        $this->root=$root ?: (getenv('RECRUITMENT_STORAGE') ?: dirname(__DIR__,3).'/storage/private/recruitment');
        if(!is_dir($this->root) && !mkdir($this->root,0700,true) && !is_dir($this->root)) throw new \RuntimeException('Private recruitment storage is unavailable.');
        $public=realpath(dirname(__DIR__,3).'/public'); $resolved=realpath($this->root);
        if($public && $resolved && str_starts_with(strtolower(str_replace('\\','/',$resolved)).'/',strtolower(str_replace('\\','/',$public)).'/')) throw new \RuntimeException('Recruitment storage must be outside public/.');
    }
    public function put(int $company,string $bytes): string
    {
        $key=bin2hex(random_bytes(32)); $dir=$this->root.'/'.$company;
        if(!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new \RuntimeException('Private storage write failed.');
        $temp=$dir.'/'.$key.'.pending';
        $handle=fopen($temp,'xb');
        if(!$handle) throw new \RuntimeException('Private storage write failed.');
        try {
            $offset=0;
            while($offset<strlen($bytes)) { $written=fwrite($handle,substr($bytes,$offset)); if(!$written) throw new \RuntimeException('Private storage write failed.'); $offset+=$written; }
            if(!fflush($handle)) throw new \RuntimeException('Private storage flush failed.');
            if(function_exists('fsync') && !fsync($handle)) throw new \RuntimeException('Private storage flush failed.');
        } finally { fclose($handle); }
        chmod($temp,0600);
        if(!rename($temp,$dir.'/'.$key)) throw new \RuntimeException('Private storage commit failed.');
        return $key;
    }
    public function path(int $company,string $key): string
    {
        if($company<1 || !preg_match('/^[a-f0-9]{64}$/D',$key)) throw new \RuntimeException('Invalid document identifier.');
        return $this->root.'/'.$company.'/'.$key;
    }
    public function read(int $company,string $key,string $checksum): string
    {
        $bytes=file_get_contents($this->path($company,$key));
        if(!is_string($bytes) || !hash_equals($checksum,hash('sha256',$bytes))) throw new \RuntimeException('Stored document integrity check failed.');
        return $bytes;
    }
}
