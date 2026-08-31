<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

class RoleRepository extends MySqlRepository
{
    /**
     * Return role summaries for administration.
     *
     * @return list<array<string, mixed>>
     */
    public function administrationSummaries(
        int $companyId
    ): array
    {
        $statement = $this->connection()->prepare(
            'SELECT
                roles.role_id,
                roles.code,
                roles.name,
                roles.description,
                roles.is_system,
                roles.active,
                roles.created_at,
                COUNT(
                    DISTINCT company_role_permissions.permission_id
                ) AS permission_count,
                COUNT(
                    DISTINCT users.user_id
                ) AS user_count,
                COUNT(
                    DISTINCT CASE
                        WHEN users.active = TRUE
                         AND memberships.active = TRUE
                        THEN users.user_id
                    END
                ) AS active_user_count
             FROM roles
             LEFT JOIN company_role_permissions
                ON company_role_permissions.role_id =
                    roles.role_id
                AND company_role_permissions.company_id =
                    :permissions_company_id
             LEFT JOIN company_user_roles
                 ON company_user_roles.role_id =
                    roles.role_id
                AND company_user_roles.company_id =
                    :company_id
             LEFT JOIN company_users memberships
                 ON memberships.company_id =
                    company_user_roles.company_id
                AND memberships.user_id =
                    company_user_roles.user_id
             LEFT JOIN users
                 ON users.user_id =
                    company_user_roles.user_id
                AND users.deleted_at IS NULL
             GROUP BY
                roles.role_id,
                roles.code,
                roles.name,
                roles.description,
                roles.is_system,
                roles.active,
                roles.created_at
             ORDER BY roles.name'
        );
        $statement->execute([
            'company_id' => $companyId,
            'permissions_company_id' =>
                $companyId,
        ]);

        $roles = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($roles) ? $roles : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForAdministration(
        int $roleId
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                role_id,
                code,
                name,
                description,
                is_system,
                active,
                created_at,
                updated_at
             FROM roles
             WHERE role_id = :role_id
             LIMIT 1'
        );

        $statement->execute([
            'role_id' => $roleId,
        ]);

        $role = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($role) ? $role : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function permissionsForRole(
        int $companyId,
        int $roleId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                permissions.permission_id,
                permissions.code,
                permissions.name,
                permissions.module,
                permissions.description,
                permissions.active,
                company_role_permissions.granted_at
             FROM company_role_permissions
             INNER JOIN permissions
                 ON permissions.permission_id =
                    company_role_permissions.permission_id
             WHERE company_role_permissions.company_id =
                    :company_id
               AND company_role_permissions.role_id =
                    :role_id
             ORDER BY
                permissions.module,
                permissions.name'
        );

        $statement->execute([
            'company_id' => $companyId,
            'role_id' => $roleId,
        ]);

        $permissions = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($permissions)
            ? $permissions
            : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function usersForRole(
        int $companyId,
        int $roleId,
        int $limit = 25
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                users.user_id,
                users.username,
                users.display_name,
                users.email,
                (
                    users.active = TRUE
                    AND memberships.active = TRUE
                ) AS active,
                users.last_login_at,
                assignments.assigned_at,
                assigned_by.display_name
                    AS assigned_by_name
             FROM company_user_roles assignments
             INNER JOIN company_users memberships
                 ON memberships.company_id =
                    assignments.company_id
                AND memberships.user_id =
                    assignments.user_id
             INNER JOIN users
                 ON users.user_id =
                    assignments.user_id
                AND users.deleted_at IS NULL
             LEFT JOIN users assigned_by
                 ON assigned_by.user_id =
                    assignments.assigned_by
             WHERE assignments.company_id =
                    :company_id
               AND assignments.role_id = :role_id
             ORDER BY users.display_name
             LIMIT :limit'
        );

        $statement->bindValue(
            ':company_id',
            $companyId,
            \PDO::PARAM_INT
        );
        $statement->bindValue(
            ':role_id',
            $roleId,
            \PDO::PARAM_INT
        );
        $statement->bindValue(
            ':limit',
            max(1, min($limit, 100)),
            \PDO::PARAM_INT
        );
        $statement->execute();

        $users = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($users) ? $users : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activePermissions(
        bool $includePlatformPermissions = false,
        ?int $companyId = null
    ): array
    {
        $statement = $this->connection()->prepare(
            'SELECT
                permission_id,
                code,
                name,
                module,
                description
             FROM permissions
             WHERE active = TRUE
               AND (:company_id IS NULL OR NOT EXISTS(SELECT 1 FROM erp_modules catalog WHERE catalog.code=permissions.module) OR EXISTS(SELECT 1 FROM erp_modules catalog INNER JOIN company_modules entitlement ON entitlement.module_id=catalog.module_id WHERE catalog.code=permissions.module AND entitlement.company_id=:enabled_company_id AND entitlement.enabled=TRUE AND entitlement.license_status=\'active\' AND (entitlement.expires_at IS NULL OR entitlement.expires_at>NOW())))
               AND (
                    :include_platform_permissions = 1
                    OR code NOT IN (
                        \'administration.companies.manage\',
                        \'administration.modules.manage\'
                    )
               )
             ORDER BY module, name'
        );
        $statement->execute([
            'include_platform_permissions' =>
                $includePlatformPermissions ? 1 : 0,
            'company_id' => $companyId,
            'enabled_company_id' => $companyId,
        ]);

        $permissions = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($permissions)
            ? $permissions
            : [];
    }

    /**
     * @return list<int>
     */
    public function permissionIds(
        int $companyId,
        int $roleId
    ): array
    {
        $statement = $this->connection()->prepare(
            'SELECT permission_id
             FROM company_role_permissions
             WHERE company_id = :company_id
               AND role_id = :role_id
             ORDER BY permission_id'
        );

        $statement->execute([
            'company_id' => $companyId,
            'role_id' => $roleId,
        ]);

        return array_map(
            'intval',
            $statement->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    /**
     * @param list<int> $permissionIds
     */
    public function validActivePermissionIds(
        array $permissionIds,
        bool $includePlatformPermissions = false,
        ?int $companyId = null
    ): array {
        $permissionIds = array_values(array_unique(
            array_filter(
                $permissionIds,
                static fn (int $permissionId): bool =>
                    $permissionId > 0
            )
        ));

        if ($permissionIds === []) {
            return [];
        }

        $placeholders = implode(
            ', ',
            array_fill(0, count($permissionIds), '?')
        );
        $statement = $this->connection()->prepare(
            'SELECT permission_id
             FROM permissions
             WHERE active = TRUE
               AND (? IS NULL OR NOT EXISTS(SELECT 1 FROM erp_modules catalog WHERE catalog.code=permissions.module) OR EXISTS(SELECT 1 FROM erp_modules catalog INNER JOIN company_modules entitlement ON entitlement.module_id=catalog.module_id WHERE catalog.code=permissions.module AND entitlement.company_id=? AND entitlement.enabled=TRUE AND entitlement.license_status=\'active\' AND (entitlement.expires_at IS NULL OR entitlement.expires_at>NOW())))
               AND (
                    ? = 1
                    OR code NOT IN (
                        \'administration.companies.manage\',
                        \'administration.modules.manage\'
                    )
               )
               AND permission_id IN ('
                . $placeholders . ')'
        );

        $statement->bindValue(1,$companyId,$companyId===null?\PDO::PARAM_NULL:\PDO::PARAM_INT);
        $statement->bindValue(2,$companyId,$companyId===null?\PDO::PARAM_NULL:\PDO::PARAM_INT);
        $statement->bindValue(
            3,
            $includePlatformPermissions ? 1 : 0,
            \PDO::PARAM_INT
        );

        foreach (
            $permissionIds as $index => $permissionId
        ) {
            $statement->bindValue(
                $index + 4,
                $permissionId,
                \PDO::PARAM_INT
            );
        }

        $statement->execute();

        return array_map(
            'intval',
            $statement->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    /**
     * @param list<int> $roleIds
     *
     * @return list<string>
     */
    public function permissionCodesForRoles(
        int $companyId,
        array $roleIds
    ): array {
        $roleIds = array_values(array_unique(
            array_filter(
                $roleIds,
                static fn (int $roleId): bool =>
                    $roleId > 0
            )
        ));

        if ($roleIds === []) {
            return [];
        }

        $placeholders = implode(
            ', ',
            array_fill(0, count($roleIds), '?')
        );
        $statement = $this->connection()->prepare(
            'SELECT DISTINCT permissions.code
             FROM company_role_permissions grants
             INNER JOIN permissions
                 ON permissions.permission_id =
                    grants.permission_id
             INNER JOIN roles
                 ON roles.role_id = grants.role_id
             WHERE grants.company_id = ?
               AND grants.role_id IN ('
                . $placeholders . ')
               AND permissions.active = TRUE
               AND roles.active = TRUE
             ORDER BY permissions.code'
        );
        $statement->bindValue(
            1,
            $companyId,
            \PDO::PARAM_INT
        );

        foreach ($roleIds as $index => $roleId) {
            $statement->bindValue(
                $index + 2,
                $roleId,
                \PDO::PARAM_INT
            );
        }

        $statement->execute();

        return array_values(array_map(
            'strval',
            $statement->fetchAll(
                \PDO::FETCH_COLUMN
            )
        ));
    }

    /**
     * @param list<int> $permissionIds
     *
     * @return list<string>
     */
    public function permissionCodesForIds(
        array $permissionIds
    ): array {
        $permissionIds = array_values(array_unique(
            array_filter(
                $permissionIds,
                static fn (int $permissionId): bool =>
                    $permissionId > 0
            )
        ));

        if ($permissionIds === []) {
            return [];
        }

        $placeholders = implode(
            ', ',
            array_fill(0, count($permissionIds), '?')
        );
        $statement = $this->connection()->prepare(
            'SELECT code
             FROM permissions
             WHERE permission_id IN ('
                . $placeholders . ')
               AND active = TRUE
             ORDER BY code'
        );

        foreach (
            $permissionIds as $index => $permissionId
        ) {
            $statement->bindValue(
                $index + 1,
                $permissionId,
                \PDO::PARAM_INT
            );
        }

        $statement->execute();

        return array_values(array_map(
            'strval',
            $statement->fetchAll(
                \PDO::FETCH_COLUMN
            )
        ));
    }

    public function isAssignedToUser(
        int $companyId,
        int $roleId,
        int $userId
    ): bool {
        $statement = $this->connection()->prepare(
            'SELECT 1
             FROM company_user_roles
             WHERE role_id = :role_id
               AND company_id = :company_id
               AND user_id = :user_id
             LIMIT 1'
        );

        $statement->execute([
            'company_id' => $companyId,
            'role_id' => $roleId,
            'user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function lockForPermissionUpdate(
        int $companyId,
        int $roleId
    ): bool {
        $statement = $this->connection()->prepare(
            'SELECT roles.role_id
             FROM roles
             INNER JOIN companies
                 ON companies.company_id =
                    :company_id
             WHERE roles.role_id = :role_id
               AND companies.deleted_at IS NULL
             FOR UPDATE'
        );

        $statement->execute([
            'company_id' => $companyId,
            'role_id' => $roleId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param list<int> $permissionIds
     */
    public function replacePermissions(
        int $companyId,
        int $roleId,
        array $permissionIds,
        int $grantedBy
    ): void {
        $delete = $this->connection()->prepare(
            'DELETE FROM company_role_permissions
             WHERE company_id = :company_id
               AND role_id = :role_id'
        );
        $delete->execute([
            'company_id' => $companyId,
            'role_id' => $roleId,
        ]);

        $insert = $this->connection()->prepare(
            'INSERT INTO company_role_permissions
                (
                    company_id,
                    role_id,
                    permission_id,
                    granted_by
                )
             VALUES
                (
                    :company_id,
                    :role_id,
                    :permission_id,
                    :granted_by
                )'
        );

        foreach ($permissionIds as $permissionId) {
            $insert->execute([
                'company_id' => $companyId,
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'granted_by' => $grantedBy,
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeRoles(
        bool $includePlatformRoles = false
    ): array
    {
        $statement = $this->connection()->prepare(
            'SELECT
                role_id,
                code,
                name,
                description
             FROM roles
             WHERE active = TRUE
               AND (
                    :include_platform_roles = 1
                    OR code <> \'system_administrator\'
               )
             ORDER BY name'
        );
        $statement->execute([
            'include_platform_roles' =>
                $includePlatformRoles ? 1 : 0,
        ]);

        $roles = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($roles)
            ? $roles
            : [];
    }

    /**
     * Return only valid active role IDs.
     *
     * @param list<int> $roleIds
     *
     * @return list<int>
     */
    public function validActiveRoleIds(
        array $roleIds,
        bool $includePlatformRoles = false
    ): array {
        $roleIds = array_values(
            array_unique(
                array_filter(
                    $roleIds,
                    static fn (int $roleId): bool =>
                        $roleId > 0
                )
            )
        );

        if ($roleIds === []) {
            return [];
        }

        $placeholders = implode(
            ', ',
            array_fill(0, count($roleIds), '?')
        );

        $statement = $this->connection()->prepare(
            'SELECT role_id
             FROM roles
             WHERE active = TRUE
               AND (
                    ? = 1
                    OR code <> \'system_administrator\'
               )
               AND role_id IN (' . $placeholders . ')'
        );

        $statement->bindValue(
            1,
            $includePlatformRoles ? 1 : 0,
            \PDO::PARAM_INT
        );

        foreach ($roleIds as $index => $roleId) {
            $statement->bindValue(
                $index + 2,
                $roleId,
                \PDO::PARAM_INT
            );
        }

        $statement->execute();

        return array_map(
            'intval',
            $statement->fetchAll(
                \PDO::FETCH_COLUMN
            )
        );
    }

    public function copyPermissionTemplatesToCompany(
        int $companyId,
        int $grantedBy
    ): void {
        $statement = $this->connection()->prepare(
            'INSERT IGNORE INTO company_role_permissions
                (
                    company_id,
                    role_id,
                    permission_id,
                    granted_by
                )
             SELECT
                :company_id,
                templates.role_id,
                templates.permission_id,
                :granted_by
             FROM role_permissions templates'
        );
        $statement->execute([
            'company_id' => $companyId,
            'granted_by' => $grantedBy,
        ]);
    }
}
