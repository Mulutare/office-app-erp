<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CompanyMembership;
use App\Models\Role;
use App\Models\User;
use PDOException;
use Throwable;

final class UserUpdateService
{
    private User $users;
    private Role $roles;
    private CompanyMembership $memberships;
    private TenantContext $tenant;
    private AuditLog $auditLogs;
    private PlatformAdministratorProtectionService
        $platformAdministrators;
    private PrivilegeEscalationProtectionService
        $privilegeProtection;

    public function __construct()
    {
        $this->users = new User();
        $this->roles = new Role();
        $this->memberships =
            new CompanyMembership();
        $this->tenant = new TenantContext();
        $this->auditLogs = new AuditLog();
        $this->platformAdministrators =
            new PlatformAdministratorProtectionService();
        $this->privilegeProtection =
            new PrivilegeEscalationProtectionService();
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
        $isPrimaryOwner =
            $this->users->isPrimaryCompanyOwner(
                $companyId,
                $userId
            );

        return [
            'profile' => $user,
            'roles' => $this->roles
                ->activeRoles(
                    !empty(
                        $user['is_platform_admin']
                    )
                ),
            'managers' =>
                $this->memberships
                    ->managerOptions(
                        $companyId,
                        $userId
                    ),
            'managerRequired' =>
                empty($user['is_platform_admin'])
                && !$isPrimaryOwner,
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

        $platformManagementError =
            $this->platformAdministrators
                ->managementError(
                    $existing,
                    $updatedBy
                );

        if ($platformManagementError !== null) {
            return [
                'successful' => false,
                'notFound' => false,
                'errors' => [
                    'form' =>
                        $platformManagementError,
                ],
            ];
        }

        if (
            $userId !== $updatedBy
            && $this->users->isPrimaryCompanyOwner(
                $companyId,
                $userId
            )
        ) {
            return [
                'successful' => false,
                'notFound' => false,
                'errors' => [
                    'form' =>
                        'The primary company owner can only update their own profile.',
                ],
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
        $managerUserId = $this->integer(
            $input['manager_user_id'] ?? null
        );
        $currentManagerUserId = $this
            ->nullableInteger(
                $existing['manager_user_id']
                    ?? null
            );
        $isPrimaryOwner =
            $this->users->isPrimaryCompanyOwner(
                $companyId,
                $userId
            );
        $managerRequired =
            empty($existing['is_platform_admin'])
            && !$isPrimaryOwner;
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

        if (
            $managerRequired
            && (
                $managerUserId < 1
                || !$this->memberships
                    ->managerExists(
                        $companyId,
                        $managerUserId,
                        $userId
                    )
            )
        ) {
            $errors['manager_user_id'] =
                'Select an active manager from this company.';
        } elseif (
            !$managerRequired
            && $managerUserId > 0
            && !$this->memberships
                ->managerExists(
                    $companyId,
                    $managerUserId,
                    $userId
                )
        ) {
            $errors['manager_user_id'] =
                'Select an active manager from this company.';
        }

        $validRoleIds = $this->roles
            ->validActiveRoleIds(
                $roleIds,
                !empty(
                    $existing[
                        'is_platform_admin'
                    ]
                )
            );

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

        if (
            !empty(
                $existing['is_platform_admin']
            )
            && $roleIds !== $existingRoleIds
        ) {
            $errors['roles'] =
                'Platform administrator role assignments cannot be changed from company user administration.';
        }

        if ($roleIds !== $existingRoleIds) {
            $roleAssignmentError =
                $this->privilegeProtection
                    ->roleAssignmentError(
                        $validRoleIds,
                        $updatedBy,
                        $companyId
                    );

            if ($roleAssignmentError !== null) {
                $errors['roles'] =
                    $roleAssignmentError;
            }
        }

        if ($userId === $updatedBy) {
            if (!$active) {
                $errors['active'] =
                    'You cannot deactivate your own account.';
            }

            if ($roleIds !== $existingRoleIds) {
                $errors['roles'] =
                    'You cannot change your own role assignments.';
            }

            if (
                $this->nullableManagerId(
                    $managerUserId
                ) !== $currentManagerUserId
            ) {
                $errors['manager_user_id'] =
                    'You cannot change your own reporting manager.';
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
            'manager_user_id' =>
                $currentManagerUserId,
            'role_ids' => $existingRoleIds,
        ];
        $newValues = [
            'username' => $username,
            'email' => $email,
            'display_name' => $displayName,
            'active' => $active,
            'manager_user_id' =>
                $this->nullableManagerId(
                    $managerUserId
                ),
            'role_ids' => $validRoleIds,
        ];

        try {
            \db()->beginTransaction();

            if ($roleIds !== $existingRoleIds) {
                $roleAssignmentError =
                    $this->privilegeProtection
                        ->roleAssignmentError(
                            $validRoleIds,
                            $updatedBy,
                            $companyId
                        );

                if ($roleAssignmentError !== null) {
                    \db()->rollBack();

                    return [
                        'successful' => false,
                        'notFound' => false,
                        'errors' => [
                            'roles' =>
                                $roleAssignmentError,
                        ],
                    ];
                }
            }

            $platformDeactivationError =
                $this->platformAdministrators
                    ->deactivationError(
                        $existing,
                        $active
                    );

            if (
                $platformDeactivationError
                !== null
            ) {
                \db()->rollBack();

                return [
                    'successful' => false,
                    'notFound' => false,
                    'errors' => [
                        'form' =>
                            $platformDeactivationError,
                    ],
                ];
            }

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

            if (
                $currentManagerUserId
                !== $newValues[
                    'manager_user_id'
                ]
            ) {
                $this->memberships
                    ->updateManager(
                        $companyId,
                        $userId,
                        $newValues[
                            'manager_user_id'
                        ]
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

    private function integer(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        return is_string($value)
            && ctype_digit($value)
                ? (int) $value
                : 0;
    }

    private function nullableInteger(
        mixed $value
    ): ?int {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function nullableManagerId(
        int $managerUserId
    ): ?int {
        return $managerUserId > 0
            ? $managerUserId
            : null;
    }
}
