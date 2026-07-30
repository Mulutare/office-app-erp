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
            'You cannot assign a role containing permissions you do not hold.',
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
