<?php
declare(strict_types=1);
namespace App\Services\Recruitment;

final class CareersIntegrationClient
{
    private string $origin;
    private CareersRequestSigner $signer;
    public function __construct(string $origin,string $key,private ?string $caFile=null)
    {
        $this->origin=CareersContract::origin($origin); $this->signer=new CareersRequestSigner($key);
    }
    public function request(string $operation,array $data): array
    {
        if(!in_array($operation,['publication','pending','submission','ack'],true)) throw new \LogicException('Unknown integration operation.');
        $path='/careers/integration/'.$operation; $body=json_encode($data,JSON_THROW_ON_ERROR);
        if(strlen($body)>CareersContract::MAX_BODY) throw new \RuntimeException('Integration request exceeds limit.');
        $nonce=bin2hex(random_bytes(32)); $time=(string)time(); $response='';
        $curl=curl_init($this->origin.$path);
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Careers-Time: '.$time,'X-Careers-Nonce: '.$nonce,'X-Careers-Signature: '.$this->signer->sign('POST',$path,$time,$nonce,$body)],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($handle,$bytes)use(&$response) { if(strlen($response)+strlen($bytes)>CareersContract::MAX_BODY) return 0; $response.=$bytes; return strlen($bytes); }]);
        if($this->caFile!==null) curl_setopt($curl,CURLOPT_CAINFO,$this->caFile);
        try {
            $ok=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); $type=(string)curl_getinfo($curl,CURLINFO_CONTENT_TYPE);
            if($ok===false || $status!==200 || !str_starts_with($type,'application/json')) throw new \RuntimeException('Careers integration request failed.');
            return CareersContract::json($response);
        } finally { curl_close($curl); }
    }
}
