<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Throwable;

final class UserPasswordResetService
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
    public function reset(
        int $userId,
        int $resetBy
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

        if ($userId === $resetBy) {
            return [
                'successful' => false,
                'notFound' => false,
                'errors' => [
                    'form' =>
                        'You cannot reset your own password from user administration.',
                ],
            ];
        }

        $temporaryPassword =
            $this->generateTemporaryPassword();
        $passwordHash = password_hash(
            $temporaryPassword,
            PASSWORD_DEFAULT
        );

        if (!is_string($passwordHash)) {
            throw new \RuntimeException(
                'Unable to securely hash the temporary password.'
            );
        }

        try {
            \db()->beginTransaction();

            $this->users->resetAdministrationPassword(
                $userId,
                $passwordHash
            );

            $this->auditLogs->record(
                $resetBy,
                'PASSWORD_RESET',
                'administration',
                'users',
                (string) $userId,
                [
                    'must_change_password' =>
                        (bool) $user[
                            'must_change_password'
                        ],
                    'failed_login_count' =>
                        (int) $user[
                            'failed_login_count'
                        ],
                    'locked_until' =>
                        $user['locked_until'],
                ],
                [
                    'must_change_password' => true,
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
            'username' => (string) $user['username'],
            'temporaryPassword' =>
                $temporaryPassword,
        ];
    }

    private function generateTemporaryPassword(): string
    {
        $groups = [
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            'abcdefghijkmnopqrstuvwxyz',
            '23456789',
            '!@#$%&*?',
        ];
        $characters = [];

        foreach ($groups as $group) {
            $characters[] = $group[
                random_int(0, strlen($group) - 1)
            ];
        }

        $allCharacters = implode('', $groups);

        while (count($characters) < 16) {
            $characters[] = $allCharacters[
                random_int(
                    0,
                    strlen($allCharacters) - 1
                )
            ];
        }

        for (
            $index = count($characters) - 1;
            $index > 0;
            $index--
        ) {
            $swapIndex = random_int(0, $index);
            [
                $characters[$index],
                $characters[$swapIndex],
            ] = [
                $characters[$swapIndex],
                $characters[$index],
            ];
        }

        return implode('', $characters);
    }
}
