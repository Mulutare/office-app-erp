<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CompanyMembership;
use App\Models\Role;
use App\Models\User;
use Throwable;

final class UserCreationService
{
    private User $users;
    private Role $roles;
    private CompanyMembership $memberships;
    private TenantContext $tenant;
    private AuditLog $auditLogs;

    public function __construct()
    {
        $this->users = new User();
        $this->roles = new Role();
        $this->memberships =
            new CompanyMembership();
        $this->tenant = new TenantContext();
        $this->auditLogs = new AuditLog();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function roles(): array
    {
        return $this->roles->activeRoles();
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     successful: bool,
     *     errors: array<string, string>,
     *     temporaryPassword?: string,
     *     userId?: int
     * }
     */
    public function create(
        array $input,
        int $createdBy
    ): array {
        $username = strtolower(
            trim((string) ($input['username'] ?? ''))
        );

        $email = strtolower(
            trim((string) ($input['email'] ?? ''))
        );

        $displayName = trim(
            (string) ($input['display_name'] ?? '')
        );

        $active = !empty($input['active']);

        $roleIds = $this->normalizeRoleIds(
            $input['role_ids'] ?? []
        );

        $errors = $this->validate(
            $username,
            $email,
            $displayName,
            $roleIds
        );

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
            ];
        }

        $validRoleIds = $this->roles
            ->validActiveRoleIds($roleIds);

        sort($roleIds);
        sort($validRoleIds);

        if ($roleIds !== $validRoleIds) {
            return [
                'successful' => false,
                'errors' => [
                    'roles' =>
                        'One or more selected roles are invalid.',
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
            $companyId =
                $this->tenant->companyId();

            $userId = $this->users
                ->createAdministrationUser(
                    $username,
                    $email,
                    $displayName,
                    $passwordHash,
                    $active
                );

            $this->memberships->add(
                $companyId,
                $userId,
                $createdBy,
                true,
                $active
            );

            $this->users->assignRoles(
                $companyId,
                $userId,
                $validRoleIds,
                $createdBy
            );

            $this->auditLogs->record(
                $createdBy,
                'CREATE',
                'administration',
                'users',
                (string) $userId,
                null,
                [
                    'username' => $username,
                    'email' => $email,
                    'display_name' => $displayName,
                    'active' => $active,
                    'role_ids' => $validRoleIds,
                    'company_id' => $companyId,
                    'must_change_password' => true,
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
            'errors' => [],
            'temporaryPassword' =>
                $temporaryPassword,
            'userId' => $userId,
        ];
    }

    /**
     * @param list<int> $roleIds
     *
     * @return array<string, string>
     */
    private function validate(
        string $username,
        string $email,
        string $displayName,
        array $roleIds
    ): array {
        $errors = [];

        if (
            !preg_match(
                '/^[a-z][a-z0-9._-]{2,49}$/',
                $username
            )
        ) {
            $errors['username'] =
                'Username must contain 3–50 lowercase letters, numbers, dots, hyphens or underscores and must begin with a letter.';
        } elseif (
            $this->users->usernameExists(
                $username
            )
        ) {
            $errors['username'] =
                'That username is already in use.';
        }

        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $errors['email'] =
                'Enter a valid email address.';
        } elseif (
            strlen($email) > 190
        ) {
            $errors['email'] =
                'The email address is too long.';
        } elseif (
            $this->users->emailExists($email)
        ) {
            $errors['email'] =
                'That email address is already in use.';
        }

        $displayNameLength =
            mb_strlen($displayName);

        if (
            $displayNameLength < 2
            || $displayNameLength > 120
        ) {
            $errors['display_name'] =
                'Display name must contain 2–120 characters.';
        }

        if ($roleIds === []) {
            $errors['roles'] =
                'Select at least one role.';
        }

        return $errors;
    }

    /**
     * @return list<int>
     */
    private function normalizeRoleIds(
        mixed $value
    ): array {
        if (!is_array($value)) {
            return [];
        }

        $roleIds = [];

        foreach ($value as $roleId) {
            if (
                is_string($roleId)
                && ctype_digit($roleId)
            ) {
                $roleIds[] = (int) $roleId;
            } elseif (is_int($roleId)) {
                $roleIds[] = $roleId;
            }
        }

        return array_values(
            array_unique($roleIds)
        );
    }

    private function generateTemporaryPassword(): string
    {
        $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lowercase = 'abcdefghijkmnopqrstuvwxyz';
        $numbers = '23456789';
        $symbols = '!@#$%&*?';

        $characters = [
            $uppercase[
                random_int(
                    0,
                    strlen($uppercase) - 1
                )
            ],
            $lowercase[
                random_int(
                    0,
                    strlen($lowercase) - 1
                )
            ],
            $numbers[
                random_int(
                    0,
                    strlen($numbers) - 1
                )
            ],
            $symbols[
                random_int(
                    0,
                    strlen($symbols) - 1
                )
            ],
        ];

        $allCharacters =
            $uppercase
            . $lowercase
            . $numbers
            . $symbols;

        while (count($characters) < 16) {
            $characters[] =
                $allCharacters[
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
            $swapIndex = random_int(
                0,
                $index
            );

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
