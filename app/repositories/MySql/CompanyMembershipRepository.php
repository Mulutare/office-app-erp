<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

class CompanyMembershipRepository extends MySqlRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public function activeForUser(
        int $userId,
        bool $platformOnly = false
    ): array
    {
        $statement = $this->connection()->prepare(
            'SELECT
                companies.company_id,
                companies.code,
                companies.name,
                companies.legal_name,
                companies.default_currency,
                companies.timezone,
                companies.brand_primary_color,
                memberships.is_default,
                memberships.joined_at
             FROM company_users memberships
             INNER JOIN companies
                 ON companies.company_id =
                    memberships.company_id
             WHERE memberships.user_id = :user_id
               AND memberships.active = TRUE
               AND companies.active = TRUE
               AND companies.approval_status =
                    \'approved\'
               AND companies.deleted_at IS NULL
               AND (
                    :platform_only = 0
                    OR companies.code = \'default\'
               )
               AND companies.subscription_status
                    IN (\'active\', \'trial\')
               AND (
                    companies.subscription_expires_at
                        IS NULL
                    OR companies.subscription_expires_at
                        > NOW()
               )
             ORDER BY
                memberships.is_default DESC,
                companies.name'
        );
        $statement->execute([
            'user_id' => $userId,
            'platform_only' =>
                $platformOnly ? 1 : 0,
        ]);
        $memberships = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        if (!is_array($memberships)) {
            return [];
        }

        foreach ($memberships as &$membership) {
            $membership['company_id'] = (int) (
                $membership['company_id'] ?? 0
            );
            $membership['is_default'] = (bool) (
                $membership['is_default'] ?? false
            );
        }

        unset($membership);

        return $memberships;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeMembership(
        int $userId,
        int $companyId,
        bool $platformOnly = false
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                companies.company_id,
                companies.code,
                companies.name,
                companies.legal_name,
                companies.default_currency,
                companies.timezone,
                companies.brand_primary_color,
                memberships.is_default,
                memberships.joined_at
             FROM company_users memberships
             INNER JOIN companies
                 ON companies.company_id =
                    memberships.company_id
             WHERE memberships.user_id = :user_id
               AND memberships.company_id = :company_id
               AND memberships.active = TRUE
               AND companies.active = TRUE
               AND companies.approval_status =
                    \'approved\'
               AND companies.deleted_at IS NULL
               AND (
                    :platform_only = 0
                    OR companies.code = \'default\'
               )
               AND companies.subscription_status
                    IN (\'active\', \'trial\')
               AND (
                    companies.subscription_expires_at
                        IS NULL
                    OR companies.subscription_expires_at
                        > NOW()
               )
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'company_id' => $companyId,
            'platform_only' =>
                $platformOnly ? 1 : 0,
        ]);
        $membership = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        if (!is_array($membership)) {
            return null;
        }

        $membership['company_id'] = (int) (
            $membership['company_id'] ?? 0
        );
        $membership['is_default'] = (bool) (
            $membership['is_default'] ?? false
        );

        return $membership;
    }

    /**
     * @return list<string>
     */
    public function roleCodes(
        int $userId,
        int $companyId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT roles.code
             FROM company_user_roles assignments
             INNER JOIN roles
                 ON roles.role_id =
                    assignments.role_id
             WHERE assignments.user_id = :user_id
               AND assignments.company_id = :company_id
               AND roles.active = TRUE
             ORDER BY roles.code'
        );
        $statement->execute([
            'user_id' => $userId,
            'company_id' => $companyId,
        ]);

        return array_values(array_map(
            'strval',
            $statement->fetchAll(
                \PDO::FETCH_COLUMN
            )
        ));
    }

    /**
     * @return list<string>
     */
    public function permissionCodes(
        int $userId,
        int $companyId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT DISTINCT permissions.code
             FROM company_user_roles assignments
             INNER JOIN roles
                 ON roles.role_id =
                    assignments.role_id
             INNER JOIN company_role_permissions grants
                 ON grants.company_id =
                    assignments.company_id
                AND grants.role_id = roles.role_id
             INNER JOIN permissions
                 ON permissions.permission_id =
                    grants.permission_id
             WHERE assignments.user_id = :user_id
               AND assignments.company_id = :company_id
               AND roles.active = TRUE
               AND permissions.active = TRUE
             ORDER BY permissions.code'
        );
        $statement->execute([
            'user_id' => $userId,
            'company_id' => $companyId,
        ]);

        return array_values(array_map(
            'strval',
            $statement->fetchAll(
                \PDO::FETCH_COLUMN
            )
        ));
    }

    public function add(
        int $companyId,
        int $userId,
        int $assignedBy,
        bool $isDefault,
        bool $active = true,
        ?int $managerUserId = null
    ): void {
        $statement = $this->connection()->prepare(
            'INSERT INTO company_users
                (
                    company_id,
                    user_id,
                    manager_user_id,
                    active,
                    is_default,
                    assigned_by
                )
             VALUES
                (
                    :company_id,
                    :user_id,
                    :manager_user_id,
                    :active,
                    :is_default,
                    :assigned_by
                )
             ON DUPLICATE KEY UPDATE
                manager_user_id =
                    VALUES(manager_user_id),
                active = VALUES(active),
                is_default = VALUES(is_default),
                assigned_by = VALUES(assigned_by)'
        );
        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
            'manager_user_id' => $managerUserId,
            'active' => $active ? 1 : 0,
            'is_default' => $isDefault ? 1 : 0,
            'assigned_by' => $assignedBy,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function managerOptions(
        int $companyId,
        ?int $excludeUserId = null
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                users.user_id,
                users.username,
                users.display_name,
                users.email
             FROM company_users memberships
             INNER JOIN users
               ON users.user_id = memberships.user_id
             WHERE memberships.company_id =
                    :company_id
               AND memberships.active = TRUE
               AND users.active = TRUE
               AND users.deleted_at IS NULL
               AND (
                    :exclude_user_null IS NULL
                    OR users.user_id
                        <> :exclude_user_value
               )
             ORDER BY
                users.display_name,
                users.username
             LIMIT 250'
        );
        $statement->execute([
            'company_id' => $companyId,
            'exclude_user_null' => $excludeUserId,
            'exclude_user_value' => $excludeUserId,
        ]);
        $managers = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($managers)
            ? $managers
            : [];
    }

    public function managerExists(
        int $companyId,
        int $managerUserId,
        ?int $excludeUserId = null
    ): bool {
        $statement = $this->connection()->prepare(
            'SELECT 1
             FROM company_users memberships
             INNER JOIN users
               ON users.user_id = memberships.user_id
             WHERE memberships.company_id =
                    :company_id
               AND memberships.user_id =
                    :manager_user_id
               AND memberships.active = TRUE
               AND users.active = TRUE
               AND users.deleted_at IS NULL
               AND (
                    :exclude_user_null IS NULL
                    OR users.user_id
                        <> :exclude_user_value
               )
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'manager_user_id' => $managerUserId,
            'exclude_user_null' => $excludeUserId,
            'exclude_user_value' => $excludeUserId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param list<int> $roleIds
     */
    public function assignRoles(
        int $companyId,
        int $userId,
        array $roleIds,
        int $assignedBy
    ): void {
        $statement = $this->connection()->prepare(
            'INSERT INTO company_user_roles
                (
                    company_id,
                    user_id,
                    role_id,
                    assigned_by
                )
             VALUES
                (
                    :company_id,
                    :user_id,
                    :role_id,
                    :assigned_by
                )'
        );

        foreach ($roleIds as $roleId) {
            $statement->execute([
                'company_id' => $companyId,
                'user_id' => $userId,
                'role_id' => $roleId,
                'assigned_by' => $assignedBy,
            ]);
        }
    }

    public function assignRoleCode(
        int $companyId,
        int $userId,
        string $roleCode,
        int $assignedBy
    ): bool {
        $statement = $this->connection()->prepare(
            'INSERT INTO company_user_roles
                (
                    company_id,
                    user_id,
                    role_id,
                    assigned_by
                )
             SELECT
                :company_id,
                :user_id,
                roles.role_id,
                :assigned_by
             FROM roles
             WHERE roles.code = :role_code
               AND roles.active = TRUE
             ON DUPLICATE KEY UPDATE
                assigned_by = VALUES(assigned_by)'
        );
        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
            'assigned_by' => $assignedBy,
            'role_code' => $roleCode,
        ]);

        return $statement->rowCount() > 0;
    }

    public function setActive(
        int $companyId,
        int $userId,
        bool $active
    ): void {
        $statement = $this->connection()->prepare(
            'UPDATE company_users
             SET active = :active
             WHERE company_id = :company_id
               AND user_id = :user_id'
        );
        $statement->execute([
            'active' => $active ? 1 : 0,
            'company_id' => $companyId,
            'user_id' => $userId,
        ]);
    }
}
