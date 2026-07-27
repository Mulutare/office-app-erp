<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Role;
use Throwable;

final class RolePermissionUpdateService
{
    private const PROTECTED_ROLES = [
        'system_administrator',
        'company_owner',
    ];

    private Role $roles;
    private TenantContext $tenant;
    private AuditLog $auditLogs;

    public function __construct()
    {
        $this->roles = new Role();
        $this->tenant = new TenantContext();
        $this->auditLogs = new AuditLog();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function formData(int $roleId): ?array
    {
        $companyId = $this->tenant->companyId();
        $role = $this->roles
            ->findForAdministration($roleId);

        if ($role === null) {
            return null;
        }

        return [
            'role' => $role,
            'permissions' =>
                $this->roles->activePermissions(
                    !empty(
                        $_SESSION['auth'][
                            'is_platform_admin'
                        ]
                    )
                ),
            'selectedPermissionIds' =>
                $this->roles->permissionIds(
                    $companyId,
                    $roleId
                ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function update(
        int $roleId,
        mixed $submittedPermissionIds,
        int $updatedBy
    ): array {
        $role = $this->roles
            ->findForAdministration($roleId);

        if ($role === null) {
            return [
                'successful' => false,
                'notFound' => true,
                'errors' => [],
            ];
        }

        if (in_array(
            (string) $role['code'],
            self::PROTECTED_ROLES,
            true
        )) {
            return [
                'successful' => false,
                'notFound' => false,
                'errors' => [
                    'form' =>
                        'This ownership permission baseline is protected.',
                ],
            ];
        }

        if ($this->roles->isAssignedToUser(
            $this->tenant->companyId(),
            $roleId,
            $updatedBy
        )) {
            return [
                'successful' => false,
                'notFound' => false,
                'errors' => [
                    'form' =>
                        'You cannot modify a role assigned to your own account.',
                ],
            ];
        }

        $permissionIds = $this->normalizeIds(
            $submittedPermissionIds
        );

        if ($permissionIds === []) {
            return [
                'successful' => false,
                'notFound' => false,
                'errors' => [
                    'permissions' =>
                        'Select at least one permission.',
                ],
            ];
        }

        $includePlatformPermissions = !empty(
            $_SESSION['auth']['is_platform_admin']
        );
        $validPermissionIds = $this->roles
            ->validActivePermissionIds(
                $permissionIds,
                $includePlatformPermissions
            );
        sort($permissionIds);
        sort($validPermissionIds);

        if ($permissionIds !== $validPermissionIds) {
            return [
                'successful' => false,
                'notFound' => false,
                'errors' => [
                    'permissions' =>
                        'One or more selected permissions are invalid.',
                ],
            ];
        }

        $codeMap = [];

        foreach (
            $this->roles->activePermissions(
                $includePlatformPermissions
            )
            as $permission
        ) {
            $codeMap[(int) $permission['permission_id']] =
                (string) $permission['code'];
        }

        try {
            \db()->beginTransaction();

            if (!$this->roles->lockForPermissionUpdate(
                $this->tenant->companyId(),
                $roleId
            )) {
                \db()->rollBack();

                return [
                    'successful' => false,
                    'notFound' => true,
                    'errors' => [],
                ];
            }

            if ($this->roles->isAssignedToUser(
                $this->tenant->companyId(),
                $roleId,
                $updatedBy
            )) {
                \db()->rollBack();

                return [
                    'successful' => false,
                    'notFound' => false,
                    'errors' => [
                        'form' =>
                            'You cannot modify a role assigned to your own account.',
                    ],
                ];
            }

            $existingIds = $this->roles
                ->permissionIds(
                    $this->tenant->companyId(),
                    $roleId
                );
            sort($existingIds);

            if ($existingIds === $validPermissionIds) {
                \db()->commit();

                return [
                    'successful' => true,
                    'notFound' => false,
                    'errors' => [],
                    'changed' => false,
                ];
            }

            $this->roles->replacePermissions(
                $this->tenant->companyId(),
                $roleId,
                $validPermissionIds,
                $updatedBy
            );

            $this->auditLogs->record(
                $updatedBy,
                'UPDATE_PERMISSIONS',
                'administration',
                'roles',
                (string) $roleId,
                [
                    'permission_ids' => $existingIds,
                    'permission_codes' =>
                        $this->codesForIds(
                            $existingIds,
                            $codeMap
                        ),
                ],
                [
                    'permission_ids' =>
                        $validPermissionIds,
                    'permission_codes' =>
                        $this->codesForIds(
                            $validPermissionIds,
                            $codeMap
                        ),
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

    /**
     * @return list<int>
     */
    private function normalizeIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $id) {
            if (is_int($id)) {
                $ids[] = $id;
            } elseif (
                is_string($id)
                && ctype_digit($id)
            ) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<int> $ids
     * @param array<int, string> $codeMap
     *
     * @return list<string>
     */
    private function codesForIds(
        array $ids,
        array $codeMap
    ): array {
        $codes = [];

        foreach ($ids as $id) {
            if (isset($codeMap[$id])) {
                $codes[] = $codeMap[$id];
            }
        }

        sort($codes);

        return $codes;
    }
}
