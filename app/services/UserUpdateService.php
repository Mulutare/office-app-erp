<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use PDOException;
use Throwable;

final class UserUpdateService
{
    private User $users;
    private Role $roles;
    private TenantContext $tenant;
    private AuditLog $auditLogs;

    public function __construct()
    {
        $this->users = new User();
        $this->roles = new Role();
        $this->tenant = new TenantContext();
        $this->auditLogs = new AuditLog();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function formData(int $userId): ?array
    {
        $companyId = $this->tenant->companyId();
        $user = $this->users->findByIdInCompany(
            $userId,
            $companyId
        );

        if ($user === null) {
            return null;
        }

        $user['role_ids'] = $this->users
            ->roleIds(
                $companyId,
                $userId
            );

        return [
            'profile' => $user,
            'roles' => $this->roles->activeRoles(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function update(
        int $userId,
        array $input,
        int $updatedBy
    ): array {
        $companyId = $this->tenant->companyId();
        $existing = $this->users->findByIdInCompany(
            $userId,
            $companyId
        );

        if ($existing === null) {
            return [
                'successful' => false,
                'notFound' => true,
                'errors' => [],
            ];
        }

        $username = strtolower(trim(
            (string) ($input['username'] ?? '')
        ));
        $email = strtolower(trim(
            (string) ($input['email'] ?? '')
        ));
        $displayName = trim(
            (string) ($input['display_name'] ?? '')
        );
        $active = !empty($input['active']);
        $roleIds = $this->normalizeRoleIds(
            $input['role_ids'] ?? []
        );

        $errors = $this->validate(
            $userId,
            $username,
            $email,
            $displayName,
            $roleIds
        );

        $validRoleIds = $this->roles
            ->validActiveRoleIds($roleIds);

        sort($roleIds);
        sort($validRoleIds);

        if ($roleIds !== $validRoleIds) {
            $errors['roles'] =
                'One or more selected roles are invalid.';
        }

        $existingRoleIds = $this->users
            ->roleIds(
                $companyId,
                $userId
            );
        sort($existingRoleIds);

        if ($userId === $updatedBy) {
            if (!$active) {
                $errors['active'] =
                    'You cannot deactivate your own account.';
            }

            if ($roleIds !== $existingRoleIds) {
                $errors['roles'] =
                    'You cannot change your own role assignments.';
            }
        }

        if ($errors !== []) {
            return [
                'successful' => false,
                'notFound' => false,
                'errors' => $errors,
            ];
        }

        $oldValues = [
            'username' => (string) $existing['username'],
            'email' => (string) $existing['email'],
            'display_name' =>
                (string) $existing['display_name'],
            'active' => (bool) $existing['active'],
            'role_ids' => $existingRoleIds,
        ];
        $newValues = [
            'username' => $username,
            'email' => $email,
            'display_name' => $displayName,
            'active' => $active,
            'role_ids' => $validRoleIds,
        ];

        try {
            \db()->beginTransaction();

            $this->users->updateAdministrationUser(
                $companyId,
                $userId,
                $username,
                $email,
                $displayName,
                $active
            );

            if ($existingRoleIds !== $validRoleIds) {
                $this->users->replaceRoles(
                    $companyId,
                    $userId,
                    $validRoleIds,
                    $updatedBy
                );
            }

            $this->auditLogs->record(
                $updatedBy,
                'UPDATE',
                'administration',
                'users',
                (string) $userId,
                $oldValues,
                $newValues
            );

            \db()->commit();
        } catch (PDOException $exception) {
            if (\db()->inTransaction()) {
                \db()->rollBack();
            }

            if ((string) $exception->getCode() === '23000') {
                return [
                    'successful' => false,
                    'notFound' => false,
                    'errors' => [
                        'form' =>
                            'The username or email is already in use.',
                    ],
                ];
            }

            throw $exception;
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
            'profile' => $newValues,
        ];
    }

    /**
     * @param list<int> $roleIds
     *
     * @return array<string, string>
     */
    private function validate(
        int $userId,
        string $username,
        string $email,
        string $displayName,
        array $roleIds
    ): array {
        $errors = [];

        if (!preg_match(
            '/^[a-z][a-z0-9._-]{2,49}$/',
            $username
        )) {
            $errors['username'] =
                'Username must contain 3-50 lowercase letters, numbers, dots, hyphens or underscores and begin with a letter.';
        } elseif (
            $this->users->usernameExistsForOtherUser(
                $username,
                $userId
            )
        ) {
            $errors['username'] =
                'That username is already in use.';
        }

        if (!filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )) {
            $errors['email'] =
                'Enter a valid email address.';
        } elseif (strlen($email) > 190) {
            $errors['email'] =
                'The email address is too long.';
        } elseif (
            $this->users->emailExistsForOtherUser(
                $email,
                $userId
            )
        ) {
            $errors['email'] =
                'That email address is already in use.';
        }

        $displayNameLength = mb_strlen($displayName);

        if (
            $displayNameLength < 2
            || $displayNameLength > 120
        ) {
            $errors['display_name'] =
                'Display name must contain 2-120 characters.';
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
    private function normalizeRoleIds(mixed $value): array
    {
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

        return array_values(array_unique($roleIds));
    }
}
