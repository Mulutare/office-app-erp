<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Throwable;

final class UserAccountStatusService
{
    private const SYSTEM_ADMINISTRATOR =
        'system_administrator';

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
    public function change(
        int $userId,
        bool $active,
        int $changedBy
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

        $currentlyActive = !empty($user['active']);

        if ($userId === $changedBy && !$active) {
            return [
                'successful' => false,
                'notFound' => false,
                'errors' => [
                    'form' =>
                        'You cannot deactivate your own account.',
                ],
            ];
        }

        if (
            !$active
            && $currentlyActive
            && $this->users->hasRoleCode(
                $companyId,
                $userId,
                self::SYSTEM_ADMINISTRATOR
            )
            && $this->users->activeUserCountForRole(
                $companyId,
                self::SYSTEM_ADMINISTRATOR
            ) <= 1
        ) {
            return [
                'successful' => false,
                'notFound' => false,
                'errors' => [
                    'form' =>
                        'The last active system administrator cannot be deactivated.',
                ],
            ];
        }

        if ($currentlyActive === $active) {
            return [
                'successful' => true,
                'notFound' => false,
                'errors' => [],
                'changed' => false,
                'active' => $active,
            ];
        }

        try {
            \db()->beginTransaction();

            $this->users->setAdministrationActive(
                $companyId,
                $userId,
                $active
            );

            $this->auditLogs->record(
                $changedBy,
                $active ? 'ENABLE' : 'DISABLE',
                'administration',
                'users',
                (string) $userId,
                ['active' => $currentlyActive],
                [
                    'active' => $active,
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
            'active' => $active,
        ];
    }
}
