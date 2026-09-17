<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class UserNotificationService
{
    public function __construct(private ?PDO $connection = null)
    {
        $this->connection ??= \db();
    }

    public static function internalPath(string $path): bool
    {
        return str_starts_with($path, '/') && !str_starts_with($path, '//')
            && !preg_match('/[\x00-\x20\x7f\\\\]/', $path)
            && !str_contains($path, '%')
            && parse_url($path, PHP_URL_HOST) === null;
    }

    public function notify(int $companyId, int $userId, string $type, string $title,
        string $message, string $referenceType, int $referenceId, string $actionUrl, string $eventKey): void
    {
        if ($companyId < 1 || $userId < 1 || $referenceId < 1 || !self::internalPath($actionUrl)
            || !preg_match('/^[a-zA-Z0-9:_.-]{1,190}$/', $eventKey)) {
            throw new RuntimeException('Invalid user notification.');
        }
        $statement = $this->connection->prepare(
            'INSERT INTO user_notifications
             (company_id,user_id,notification_type,title,message,reference_type,reference_id,action_url,event_key)
             VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE notification_id=notification_id'
        );
        $statement->execute([$companyId,$userId,$type,$title,$message,$referenceType,$referenceId,$actionUrl,$eventKey]);
    }

    /** Notify only active Finance users who can perform this action in the company. */
    public function notifyFinanceAction(int $companyId, string $permission, int $excludedUserId,
        string $type, string $title, string $message, string $referenceType,
        int $referenceId, string $actionUrl, string $eventKey): void
    {
        $query=$this->connection->prepare('SELECT DISTINCT u.user_id,r.code role_code FROM company_users cu JOIN companies c ON c.company_id=cu.company_id JOIN users u ON u.user_id=cu.user_id JOIN company_user_roles ur ON ur.company_id=cu.company_id AND ur.user_id=cu.user_id JOIN roles r ON r.role_id=ur.role_id JOIN company_role_permissions rp ON rp.company_id=ur.company_id AND rp.role_id=ur.role_id JOIN permissions p ON p.permission_id=rp.permission_id WHERE cu.company_id=:company AND cu.active=TRUE AND c.active=TRUE AND c.deleted_at IS NULL AND u.active=TRUE AND u.deleted_at IS NULL AND u.is_platform_admin=FALSE AND r.active=TRUE AND p.active=TRUE AND p.code=:permission AND u.user_id<>:excluded');
        $query->execute(['company'=>$companyId,'permission'=>$permission,'excluded'=>$excludedUserId]);
        $sent=[];
        foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
            $userId=(int)$row['user_id'];
            if(isset($sent[$userId]) || !ModuleRoleService::roleOwns((string)$row['role_code'],'finance'))continue;
            $this->notify($companyId,$userId,$type,$title,$message,$referenceType,$referenceId,$actionUrl,$eventKey);
            $sent[$userId]=true;
        }
    }

    /** A finished workflow must not leave its old action notice unread. */
    public function resolveFinanceActions(int $companyId,string $referenceType,int $referenceId): void
    {
        $query=$this->connection->prepare("UPDATE user_notifications SET read_at=CURRENT_TIMESTAMP WHERE company_id=:company AND reference_type=:type AND reference_id=:id AND notification_type IN('finance.expense.review','finance.expense.process','finance.loan.review','finance.loan.disburse') AND read_at IS NULL");
        $query->execute(['company'=>$companyId,'type'=>$referenceType,'id'=>$referenceId]);
    }

    public function unreadCount(int $companyId, int $userId): int
    {
        $s = $this->connection->prepare('SELECT COUNT(*) FROM user_notifications WHERE company_id=? AND user_id=? AND read_at IS NULL');
        $s->execute([$companyId,$userId]);
        return (int) $s->fetchColumn();
    }

    public function recentForUser(int $companyId, int $userId): array
    {
        $s = $this->connection->prepare('SELECT * FROM user_notifications WHERE company_id=? AND user_id=? ORDER BY created_at DESC,notification_id DESC LIMIT 30');
        $s->execute([$companyId,$userId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markRead(int $companyId, int $userId, int $notificationId): ?string
    {
        $s = $this->connection->prepare('SELECT action_url FROM user_notifications WHERE company_id=? AND user_id=? AND notification_id=?');
        $s->execute([$companyId,$userId,$notificationId]);
        $path = $s->fetchColumn();
        if (!is_string($path) || !self::internalPath($path)) return null;
        $this->connection->prepare('UPDATE user_notifications SET read_at=COALESCE(read_at,CURRENT_TIMESTAMP) WHERE company_id=? AND user_id=? AND notification_id=?')
            ->execute([$companyId,$userId,$notificationId]);
        return $path;
    }

    public function markAllRead(int $companyId, int $userId): void
    {
        $this->connection->prepare('UPDATE user_notifications SET read_at=CURRENT_TIMESTAMP WHERE company_id=? AND user_id=? AND read_at IS NULL')
            ->execute([$companyId,$userId]);
    }
}
