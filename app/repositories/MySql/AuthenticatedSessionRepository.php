<?php
declare(strict_types=1);
namespace App\Repositories\MySql;

use App\Repositories\AuthenticatedSessionRepository as Contract;
use RuntimeException;

final class AuthenticatedSessionRepository extends MySqlRepository implements Contract
{
    public function available(): bool
    {
        $statement=$this->connection()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='authenticated_user_sessions'");
        return (int)$statement->fetchColumn()===1;
    }
    public function register(array $session): void
    {
        $existing=$this->findByHash((int)$session['company_id'],(int)$session['user_id'],(string)$session['session_hash']);
        if($existing!==null){
            $statement=$this->connection()->prepare('UPDATE authenticated_user_sessions SET last_activity_at=:activity,expires_at=:expires,revoked_at=NULL,ip_address=:ip,user_agent=:agent WHERE company_id=:company AND user_id=:user AND session_hash=:hash');
            $statement->execute(['activity'=>$session['last_activity_at'],'expires'=>$session['expires_at'],'ip'=>$session['ip_address'],'agent'=>$session['user_agent'],'company'=>$session['company_id'],'user'=>$session['user_id'],'hash'=>$session['session_hash']]);
            return;
        }
        $collision=$this->connection()->prepare('SELECT 1 FROM authenticated_user_sessions WHERE session_hash=:hash');
        $collision->execute(['hash'=>$session['session_hash']]);
        if($collision->fetchColumn())throw new RuntimeException('Authenticated session identity collision.');
        $statement=$this->connection()->prepare('INSERT INTO authenticated_user_sessions(company_id,user_id,session_hash,signed_in_at,last_activity_at,expires_at,revoked_at,ip_address,user_agent) VALUES(:company,:user,:hash,:signed_in,:activity,:expires,NULL,:ip,:agent)');
        $statement->execute(['company'=>$session['company_id'],'user'=>$session['user_id'],'hash'=>$session['session_hash'],'signed_in'=>$session['signed_in_at'],'activity'=>$session['last_activity_at'],'expires'=>$session['expires_at'],'ip'=>$session['ip_address'],'agent'=>$session['user_agent']]);
    }

    public function touch(int $companyId,int $userId,string $sessionHash,string $activityAt,string $expiresAt): bool
    {
        $statement=$this->connection()->prepare('UPDATE authenticated_user_sessions SET last_activity_at=:activity,expires_at=:expires WHERE company_id=:company AND user_id=:user AND session_hash=:hash AND revoked_at IS NULL');
        $statement->execute(['activity'=>$activityAt,'expires'=>$expiresAt,'company'=>$companyId,'user'=>$userId,'hash'=>$sessionHash]);
        return $statement->rowCount()===1;
    }

    public function revoke(int $companyId,int $userId,string $sessionHash,string $revokedAt): void
    {
        $statement=$this->connection()->prepare('UPDATE authenticated_user_sessions SET revoked_at=COALESCE(revoked_at,:revoked) WHERE company_id=:company AND user_id=:user AND session_hash=:hash');
        $statement->execute(['revoked'=>$revokedAt,'company'=>$companyId,'user'=>$userId,'hash'=>$sessionHash]);
    }

    public function countActive(int $companyId,int $userId,string $now): int
    {
        $statement=$this->connection()->prepare('SELECT COUNT(*) FROM authenticated_user_sessions WHERE company_id=:company AND user_id=:user AND revoked_at IS NULL AND expires_at>:now');
        $statement->execute(['company'=>$companyId,'user'=>$userId,'now'=>$now]);
        return (int)$statement->fetchColumn();
    }

    public function findByHash(int $companyId,int $userId,string $sessionHash): ?array
    {
        $statement=$this->connection()->prepare('SELECT * FROM authenticated_user_sessions WHERE company_id=:company AND user_id=:user AND session_hash=:hash LIMIT 1');
        $statement->execute(['company'=>$companyId,'user'=>$userId,'hash'=>$sessionHash]);
        $row=$statement->fetch(\PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    public function listActive(int $companyId,int $userId,string $now): array
    {
        $statement=$this->connection()->prepare('SELECT authenticated_user_session_id,signed_in_at,last_activity_at,expires_at,ip_address,user_agent,session_hash FROM authenticated_user_sessions WHERE company_id=:company AND user_id=:user AND revoked_at IS NULL AND expires_at>:now ORDER BY last_activity_at DESC');
        $statement->execute(['company'=>$companyId,'user'=>$userId,'now'=>$now]);
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
