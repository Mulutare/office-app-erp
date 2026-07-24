<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\LoginAttempt;
use App\Models\User;

final class UserDetailsService
{
    private User $users;
    private LoginAttempt $loginAttempts;
    private AuditLog $auditLogs;

    public function __construct()
    {
        $this->users = new User();
        $this->loginAttempts = new LoginAttempt();
        $this->auditLogs = new AuditLog();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function details(int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }

        $user = $this->users->findById($userId);

        if ($user === null) {
            return null;
        }

        $user['is_locked'] = $this->isLocked(
            $user['locked_until'] ?? null
        );

        return [
            'user' => $user,
            'roles' => $this->users
                ->administrationRoles($userId),
            'permissions' => $this->users
                ->administrationPermissions($userId),
            'loginAttempts' => $this->loginAttempts
                ->recentForUser($userId),
            'auditActivity' => $this->auditLogs
                ->recentForUser($userId),
        ];
    }

    private function isLocked(mixed $lockedUntil): bool
    {
        if (
            !is_string($lockedUntil)
            || trim($lockedUntil) === ''
        ) {
            return false;
        }

        $timestamp = strtotime($lockedUntil);

        return $timestamp !== false
            && $timestamp > time();
    }
}
