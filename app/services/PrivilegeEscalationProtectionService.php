<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompanyMembership;
use App\Models\Role;

final class PrivilegeEscalationProtectionService
{
    private CompanyMembership $memberships;
    private Role $roles;

    public function __construct()
    {
        $this->memberships =
            new CompanyMembership();
        $this->roles = new Role();
    }

    /**
     * Use the same company authority check as POST validation for role choices.
     * Existing roles outside that authority are retained, including inactive roles.
     *
     * @param list<int> $assignedRoleIds
     * @return array{assignableRoles: array, protectedAssignedRoles: array}
     */
    public function roleChoices(
        int $actorId,
        int $companyId,
        array $assignedRoleIds = [],
        bool $protectAll = false
    ): array {
        $assignableRoles = [];
        if (!$protectAll) {
            foreach ($this->roles->activeRoles(false) as $role) {
                if ($this->roleAssignmentError(
                    [(int) $role['role_id']], $actorId, $companyId
                ) === null) {
                    $assignableRoles[] = $role;
                }
            }
        }

        $assignableIds = array_map('intval', array_column($assignableRoles, 'role_id'));
        $protectedAssignedRoles = [];
        foreach (array_diff($assignedRoleIds, $assignableIds) as $roleId) {
            $role = $this->roles->findForAdministration($roleId);
            if ($role !== null) {
                $protectedAssignedRoles[] = $role;
            }
        }

        return compact('assignableRoles', 'protectedAssignedRoles');
    }

    /**
     * @param list<int> $roleIds
     */
    public function roleAssignmentError(
        array $roleIds,
        int $actorId,
        int $companyId
    ): ?string {
        $requestedPermissionCodes =
            $this->roles->permissionCodesForRoles(
                $companyId,
                $roleIds
            );

        return $this->authorityError(
            $requestedPermissionCodes,
            $actorId,
            $companyId,
            'One or more selected roles cannot be assigned by your account.',
            true
        );
    }

    /**
     * @param list<int> $permissionIds
     */
    public function permissionGrantError(
        array $permissionIds,
        int $actorId,
        int $companyId
    ): ?string {
        $requestedPermissionCodes =
            $this->roles->permissionCodesForIds(
                $permissionIds
            );

        return $this->authorityError(
            $requestedPermissionCodes,
            $actorId,
            $companyId,
            'You cannot grant or modify permissions you do not hold.'
        );
    }

    /**
     * @param list<string> $requestedPermissionCodes
     */
    private function authorityError(
        array $requestedPermissionCodes,
        int $actorId,
        int $companyId,
        string $message,
        bool $allowSelfServiceDelegation = false
    ): ?string {
        if (
            $actorId < 1
            || $companyId < 1
            || $this->memberships->activeMembership(
                $actorId,
                $companyId
            ) === null
        ) {
            return 'Your active company authority could not be verified.';
        }

        $actorPermissionCodes =
            $this->memberships->permissionCodes(
                $actorId,
                $companyId
            );
        $excessPermissions = array_diff(
            $requestedPermissionCodes,
            $actorPermissionCodes
        );

        if (
            $allowSelfServiceDelegation
            && in_array(
                'administration.users.manage',
                $actorPermissionCodes,
                true
            )
            && in_array(
                'administration.roles.manage',
                $actorPermissionCodes,
                true
            )
        ) {
            $excessPermissions = array_values(
                array_filter(
                    $excessPermissions,
                    static fn (string $permission): bool =>
                        !str_contains(
                            $permission,
                            '.self.'
                        )
                )
            );
        }

        return $excessPermissions === []
            ? null
            : $message;
    }
}
