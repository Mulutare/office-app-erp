<?php
declare(strict_types=1);
namespace App\Services\Recruitment;

final class CareersRequestSigner
{
    public function __construct(private string $key)
    {
        if (strlen($key)<32) throw new \RuntimeException('Dedicated Careers integration key must contain at least 32 bytes.');
    }
    public function sign(string $method,string $path,string $time,string $nonce,string $body): string
    {
        return hash_hmac('sha256',strtoupper($method)."\n".$path."\n".$time."\n".$nonce."\n".hash('sha256',$body),$this->key);
    }
    /** claimNonce must atomically insert a unique nonce, retaining it for at least 600 seconds. */
    public function verify(string $method,string $path,string $time,string $nonce,string $body,string $signature,callable $claimNonce,?int $now=null): void
    {
        if (strlen($body)>CareersContract::MAX_BODY || !preg_match('/^[0-9]{10}$/D',$time) || abs(($now??time())-(int)$time)>300 || !preg_match('/^[a-f0-9]{64}$/D',$nonce) || !preg_match('/^[a-f0-9]{64}$/D',$signature) || !hash_equals($this->sign($method,$path,$time,$nonce,$body),$signature)) throw new \InvalidArgumentException('Integration authentication denied.');
        if (!$claimNonce($nonce,(int)$time+601)) throw new \InvalidArgumentException('Integration authentication denied.');
    }
}
