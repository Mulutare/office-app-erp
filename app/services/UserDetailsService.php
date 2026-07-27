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
    private TenantContext $tenant;

    public function __construct()
    {
        $this->users = new User();
        $this->loginAttempts = new LoginAttempt();
        $this->auditLogs = new AuditLog();
        $this->tenant = new TenantContext();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function details(int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }

        $companyId = $this->tenant->companyId();
        $user = $this->users->findByIdInCompany(
            $userId,
            $companyId
        );

        if ($user === null) {
            return null;
        }

        $user['is_locked'] = $this->isLocked(
            $user['locked_until'] ?? null
        );
        $user['is_primary_owner'] =
            $this->users->isPrimaryCompanyOwner(
                $companyId,
                $userId
            );

        return [
            'user' => $user,
            'roles' => $this->users
                ->administrationRoles(
                    $companyId,
                    $userId
                ),
            'permissions' => $this->users
                ->administrationPermissions(
                    $companyId,
                    $userId
                ),
            'loginAttempts' => $this->loginAttempts
                ->recentForUser(
                    $userId,
                    $companyId
                ),
            'auditActivity' => $this->auditLogs
                ->recentForUser(
                    $userId,
                    $companyId
                ),
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
