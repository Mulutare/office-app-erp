<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Role;

final class RoleAdministrationService
{
    public function smartDetails(int $roleId,array $input): ?array
    {
        $role=$this->roles->findForAdministration($roleId);if(!$role)return null;
        $factory=new \App\Services\Lists\AdministrationListService();$context=['role_id'=>$roleId];
        $permissions=$factory->workspace('role-permissions',$input,'permissions',$context);
        $users=$factory->workspace('role-users',$input,'members',$context);
        return ['role'=>$role,'permissions'=>$permissions['rows'],'users'=>$users['rows'],'permissionList'=>$permissions,'userList'=>$users];
    }
    private Role $roles;
    private TenantContext $tenant;

    public function __construct()
    {
        $this->roles = new Role();
        $this->tenant = new TenantContext();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listing(): array
    {
        return $this->roles
            ->administrationSummaries(
                $this->tenant->companyId()
            );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function details(int $roleId): ?array
    {
        if ($roleId < 1) {
            return null;
        }

        $role = $this->roles
            ->findForAdministration($roleId);

        if ($role === null) {
            return null;
        }

        $companyId = $this->tenant->companyId();

        return [
            'role' => $role,
            'permissions' => $this->roles
                ->permissionsForRole(
                    $companyId,
                    $roleId
                ),
            'users' => $this->roles
                ->usersForRole(
                    $companyId,
                    $roleId
                ),
        ];
    }
}
