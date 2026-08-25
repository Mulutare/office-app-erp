<?php
declare(strict_types=1);
namespace App\Services;

use App\Repositories\AuthenticatedSessionRepository;
use App\Repositories\RepositoryFactory;
use DateTimeImmutable;

final class AuthenticatedSessionService
{
    public function __construct(private ?AuthenticatedSessionRepository $sessions=null){$this->sessions??=RepositoryFactory::authenticatedSessions();}
    public function hashCurrent(): string { return hash('sha256',session_id()); }
    public function register(int $companyId,int $userId,?int $signedInTimestamp=null): void
    {
        if(session_id()===''||!$this->sessions->available())return;
        $now=new DateTimeImmutable();
        $signed=$signedInTimestamp===null?$now:(new DateTimeImmutable())->setTimestamp($signedInTimestamp);
        $this->sessions->register(['company_id'=>$companyId,'user_id'=>$userId,'session_hash'=>$this->hashCurrent(),'signed_in_at'=>$signed->format('Y-m-d H:i:s'),'last_activity_at'=>$now->format('Y-m-d H:i:s'),'expires_at'=>$now->modify('+'.$this->lifetime().' seconds')->format('Y-m-d H:i:s'),'ip_address'=>\requestIp(),'user_agent'=>\requestUserAgent()]);
        $_SESSION['auth']['session_activity_persisted_at']=$now->getTimestamp();
    }
    public function touchOrRegister(int $companyId,int $userId): void
    {
        if(!$this->sessions->available())return;
        $now=time();$last=(int)($_SESSION['auth']['session_activity_persisted_at']??0);
        if($last>0&&$now-$last<$this->throttle())return;
        $at=date('Y-m-d H:i:s',$now);$expires=date('Y-m-d H:i:s',$now+$this->lifetime());
        if(!$this->sessions->touch($companyId,$userId,$this->hashCurrent(),$at,$expires))$this->register($companyId,$userId,(int)($_SESSION['auth']['authenticated_at']??$now));
        else $_SESSION['auth']['session_activity_persisted_at']=$now;
    }
    public function revoke(int $companyId,int $userId): void { if(session_id()!==''&&$this->sessions->available())$this->sessions->revoke($companyId,$userId,$this->hashCurrent(),date('Y-m-d H:i:s')); }
    public function count(int $companyId,int $userId): int { return $this->sessions->available()?$this->sessions->countActive($companyId,$userId,date('Y-m-d H:i:s')):1; }
    public function list(int $companyId,int $userId): array
    {
        if(!$this->sessions->available())return [];
        $current=$this->hashCurrent();
        return array_map(function(array $row)use($current):array{return ['id'=>(int)$row['authenticated_user_session_id'],'current'=>hash_equals($current,(string)$row['session_hash']),'signed_in_at'=>(string)$row['signed_in_at'],'last_activity_at'=>(string)$row['last_activity_at'],'expires_at'=>(string)$row['expires_at'],'ip_address'=>(string)$row['ip_address'],'device'=>$this->device((string)($row['user_agent']??'')),'status'=>'Active'];},$this->sessions->listActive($companyId,$userId,date('Y-m-d H:i:s')));
    }
    private function lifetime():int{return max(300,(int)\config('session_lifetime_seconds',28800));}
    private function throttle():int{return max(60,(int)\config('session_activity_throttle_seconds',300));}
    private function device(string $agent):string
    {
        $os=str_contains($agent,'iPhone')?'iPhone':(str_contains($agent,'Android')?'Android':(str_contains($agent,'Windows')?'Windows':(str_contains($agent,'Macintosh')?'macOS':'')));
        $browser=str_contains($agent,'Edg/')?'Edge':(str_contains($agent,'Firefox/')?'Firefox':((str_contains($agent,'CriOS')||str_contains($agent,'Chrome/'))?'Chrome':(str_contains($agent,'Safari/')?(str_contains($agent,'Mobile/')?'Mobile Safari':'Safari'):'')));
        return $browser!==''?trim($browser.' / '.$os,' /'):'Unknown device';
    }
}
