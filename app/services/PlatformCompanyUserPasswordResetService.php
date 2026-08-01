<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Throwable;

final class PlatformCompanyUserPasswordResetService
{
    private Company $companies;
    private User $users;
    private AuditLog $audit;
    private TemporaryPasswordGenerator $passwords;

    public function __construct()
    {
        $this->companies = new Company();
        $this->users = new User();
        $this->audit = new AuditLog();
        $this->passwords = new TemporaryPasswordGenerator();
    }

    /** @return list<array<string, mixed>> */
    public function users(int $companyId, int $requestedBy): array
    {
        if (!$this->isPlatformAdministrator($requestedBy)) {
            return [];
        }

        return $this->users->administrationPage(
            $companyId,
            '',
            'all',
            'display_name',
            'asc',
            200,
            0
        );
    }

    /** @return array<string, mixed>|null */
    public function target(
        int $companyId,
        int $userId,
        int $requestedBy
    ): ?array {
        if (!$this->isPlatformAdministrator($requestedBy)) {
            return null;
        }
        $company = $this->companies->findForAdministration($companyId);
        $user = $this->users->findByIdInCompany($userId, $companyId);
        if (
            $company === null
            || (string) ($company['code'] ?? '') === 'default'
            || $user === null
            || !empty($user['is_platform_admin'])
        ) {
            return null;
        }

        return ['company' => $company, 'targetUser' => $user];
    }

    /** @return array<string, mixed> */
    public function reset(
        int $companyId,
        int $userId,
        int $resetBy
    ): array {
        $target = $this->target($companyId, $userId, $resetBy);
        if ($target === null) {
            return [
                'successful' => false,
                'notFound' => true,
                'errors' => [],
            ];
        }
        $user = $target['targetUser'];
        $temporaryPassword = $this->passwords->generate();
        $passwordHash = password_hash(
            $temporaryPassword,
            PASSWORD_DEFAULT
        );
        if (!is_string($passwordHash)) {
            throw new \RuntimeException(
                'Unable to securely hash the temporary password.'
            );
        }

        $connection = \db();
        try {
            $connection->beginTransaction();
            $this->users->resetAdministrationPassword(
                $userId,
                $passwordHash
            );
            $this->audit->record(
                $resetBy,
                'RESET_COMPANY_USER_PASSWORD',
                'administration',
                'users',
                (string) $userId,
                [
                    'company_id' => $companyId,
                    'must_change_password' =>
                        (bool) ($user['must_change_password'] ?? false),
                    'failed_login_count' =>
                        (int) ($user['failed_login_count'] ?? 0),
                    'locked_until' => $user['locked_until'] ?? null,
                ],
                [
                    'company_id' => $companyId,
                    'must_change_password' => true,
                    'failed_login_count' => 0,
                    'locked_until' => null,
                ],
                $companyId
            );
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }

        return [
            'successful' => true,
            'notFound' => false,
            'errors' => [],
            'username' => (string) $user['username'],
            'temporaryPassword' => $temporaryPassword,
        ];
    }

    private function isPlatformAdministrator(int $userId): bool
    {
        $user = $userId > 0 ? $this->users->findById($userId) : null;

        return is_array($user)
            && !empty($user['active'])
            && !empty($user['is_platform_admin']);
    }
}
