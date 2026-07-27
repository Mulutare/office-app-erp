<?php

declare(strict_types=1);

namespace App\Models;

final class Role
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
        $statement = \db()->prepare(
            'SELECT
                roles.role_id,
                roles.code,
                roles.name,
                roles.description,
                roles.is_system,
                roles.active,
                roles.created_at,
                COUNT(
                    DISTINCT role_permissions.permission_id
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
             LEFT JOIN role_permissions
                 ON role_permissions.role_id = roles.role_id
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
        $statement = \db()->prepare(
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
        int $roleId
    ): array {
        $statement = \db()->prepare(
            'SELECT
                permissions.permission_id,
                permissions.code,
                permissions.name,
                permissions.module,
                permissions.description,
                permissions.active,
                role_permissions.granted_at
             FROM role_permissions
             INNER JOIN permissions
                 ON permissions.permission_id =
                    role_permissions.permission_id
             WHERE role_permissions.role_id = :role_id
             ORDER BY
                permissions.module,
                permissions.name'
        );

        $statement->execute([
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
        $statement = \db()->prepare(
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
    public function activePermissions(): array
    {
        $statement = \db()->query(
            'SELECT
                permission_id,
                code,
                name,
                module,
                description
             FROM permissions
             WHERE active = TRUE
             ORDER BY module, name'
        );

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
    public function permissionIds(int $roleId): array
    {
        $statement = \db()->prepare(
            'SELECT permission_id
             FROM role_permissions
             WHERE role_id = :role_id
             ORDER BY permission_id'
        );

        $statement->execute([
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
        $statement = \db()->prepare(
            'SELECT permission_id
             FROM permissions
             WHERE active = TRUE
               AND permission_id IN ('
                . $placeholders . ')'
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

        return array_map(
            'intval',
            $statement->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    public function isAssignedToUser(
        int $companyId,
        int $roleId,
        int $userId
    ): bool {
        $statement = \db()->prepare(
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
        int $roleId
    ): bool {
        $statement = \db()->prepare(
            'SELECT role_id
             FROM roles
             WHERE role_id = :role_id
             FOR UPDATE'
        );

        $statement->execute([
            'role_id' => $roleId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param list<int> $permissionIds
     */
    public function replacePermissions(
        int $roleId,
        array $permissionIds
    ): void {
        $delete = \db()->prepare(
            'DELETE FROM role_permissions
             WHERE role_id = :role_id'
        );
        $delete->execute([
            'role_id' => $roleId,
        ]);

        $insert = \db()->prepare(
            'INSERT INTO role_permissions
                (role_id, permission_id)
             VALUES
                (:role_id, :permission_id)'
        );

        foreach ($permissionIds as $permissionId) {
            $insert->execute([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeRoles(): array
    {
        $statement = \db()->query(
            'SELECT
                role_id,
                code,
                name,
                description
             FROM roles
             WHERE active = TRUE
             ORDER BY name'
        );

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
        array $roleIds
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

        $statement = \db()->prepare(
            'SELECT role_id
             FROM roles
             WHERE active = TRUE
               AND role_id IN (' . $placeholders . ')'
        );

        foreach ($roleIds as $index => $roleId) {
            $statement->bindValue(
                $index + 1,
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
}
