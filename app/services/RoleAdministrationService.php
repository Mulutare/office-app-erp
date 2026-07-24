<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Role;

final class RoleAdministrationService
{
    private Role $roles;

    public function __construct()
    {
        $this->roles = new Role();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listing(): array
    {
        return $this->roles
            ->administrationSummaries();
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

        return [
            'role' => $role,
            'permissions' => $this->roles
                ->permissionsForRole($roleId),
            'users' => $this->roles
                ->usersForRole($roleId),
        ];
    }
}
