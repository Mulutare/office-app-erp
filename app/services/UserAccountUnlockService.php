<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Throwable;

final class UserAccountUnlockService
{
    private User $users;
    private TenantContext $tenant;
    private AuditLog $auditLogs;

    public function __construct()
    {
        $this->users = new User();
        $this->tenant = new TenantContext();
        $this->auditLogs = new AuditLog();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function target(int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }

        return $this->users->findByIdInCompany(
            $userId,
            $this->tenant->companyId()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function unlock(
        int $userId,
        int $unlockedBy
    ): array {
        $companyId = $this->tenant->companyId();
        $user = $this->users->findByIdInCompany(
            $userId,
            $companyId
        );

        if ($user === null) {
            return [
                'successful' => false,
                'notFound' => true,
                'errors' => [],
            ];
        }

        if ($userId === $unlockedBy) {
            return [
                'successful' => false,
                'notFound' => false,
                'errors' => [
                    'form' =>
                        'You cannot unlock your own account from user administration.',
                ],
            ];
        }

        $failedLoginCount = (int) (
            $user['failed_login_count'] ?? 0
        );
        $lockedUntil = $user['locked_until'] ?? null;
        $hasLockState = $failedLoginCount > 0
            || (
                is_string($lockedUntil)
                && trim($lockedUntil) !== ''
            );

        if (!$hasLockState) {
            return [
                'successful' => true,
                'notFound' => false,
                'errors' => [],
                'changed' => false,
            ];
        }

        try {
            \db()->beginTransaction();

            $this->users
                ->unlockAdministrationAccount($userId);

            $this->auditLogs->record(
                $unlockedBy,
                'UNLOCK',
                'administration',
                'users',
                (string) $userId,
                [
                    'failed_login_count' =>
                        $failedLoginCount,
                    'locked_until' => $lockedUntil,
                ],
                [
                    'failed_login_count' => 0,
                    'locked_until' => null,
                    'company_id' => $companyId,
                ]
            );

            \db()->commit();
        } catch (Throwable $exception) {
            if (\db()->inTransaction()) {
                \db()->rollBack();
            }

            throw $exception;
        }

        return [
            'successful' => true,
            'notFound' => false,
            'errors' => [],
            'changed' => true,
        ];
    }
}
