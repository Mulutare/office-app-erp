<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use PDO;

class UserRepository extends MySqlRepository
{
    /**
     * Find one active user by username or email.
     *
     * This method intentionally includes password_hash because
     * the authentication service will need it to verify a password.
     *
     * @return array<string, mixed>|null
     */
    public function findForAuthentication(string $login): ?array
    {
        $sql = <<<'SQL'
            SELECT
                user_id,
                username,
                email,
                password_hash,
                display_name,
                is_platform_admin,
                active,
                must_change_password,
                failed_login_count,
                locked_until,
                last_login_at,
                password_changed_at
            FROM users
            WHERE deleted_at IS NULL
              AND (
                    username = :username
                    OR email = :email
                  )
            LIMIT 1
        SQL;

        $statement = $this->connection()->prepare($sql);

        $statement->execute([
            'username' => $login,
            'email' => $login,
        ]);

        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($user) ? $user : null;
    }

    /**
     * Find a user by ID without exposing the password hash.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $userId): ?array
    {
        $sql = <<<'SQL'
            SELECT
                user_id,
                username,
                email,
                display_name,
                is_platform_admin,
                active,
                must_change_password,
                failed_login_count,
                locked_until,
                last_login_at,
                password_changed_at,
                created_at,
                updated_at
            FROM users
            WHERE user_id = :user_id
              AND deleted_at IS NULL
            LIMIT 1
        SQL;

        $statement = $this->connection()->prepare($sql);

        $statement->execute([
            'user_id' => $userId,
        ]);

        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($user) ? $user : null;
    }

    /**
     * Find a user who belongs to a specific company.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdInCompany(
        int $userId,
        int $companyId
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                users.user_id,
                users.username,
                users.email,
                users.display_name,
                (
                    users.active = TRUE
                    AND memberships.active = TRUE
                ) AS active,
                users.active AS platform_active,
                memberships.active
                    AS membership_active,
                memberships.is_default,
                memberships.joined_at,
                users.must_change_password,
                users.failed_login_count,
                users.locked_until,
                users.last_login_at,
                users.password_changed_at,
                users.created_at,
                users.updated_at
             FROM company_users memberships
             INNER JOIN users
                 ON users.user_id =
                    memberships.user_id
             WHERE memberships.company_id =
                    :company_id
               AND users.user_id = :user_id
               AND users.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
        ]);
        $user = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($user)
            ? $user
            : null;
    }

    /**
     * Determine whether a username already exists.
     */
    public function usernameExists(string $username): bool
    {
        $statement = $this->connection()->prepare(
            'SELECT 1
             FROM users
             WHERE username = :username
               AND deleted_at IS NULL
             LIMIT 1'
        );

        $statement->execute([
            'username' => $username,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Determine whether an email address already exists.
     */
    public function emailExists(string $email): bool
    {
        $statement = $this->connection()->prepare(
            'SELECT 1
             FROM users
             WHERE email = :email
               AND deleted_at IS NULL
             LIMIT 1'
        );

        $statement->execute([
            'email' => $email,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function usernameExistsForOtherUser(
        string $username,
        int $userId
    ): bool {
        $statement = $this->connection()->prepare(
            'SELECT 1
             FROM users
             WHERE username = :username
               AND user_id <> :user_id
               AND deleted_at IS NULL
             LIMIT 1'
        );

        $statement->execute([
            'username' => $username,
            'user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function emailExistsForOtherUser(
        string $email,
        int $userId
    ): bool {
        $statement = $this->connection()->prepare(
            'SELECT 1
             FROM users
             WHERE email = :email
               AND user_id <> :user_id
               AND deleted_at IS NULL
             LIMIT 1'
        );

        $statement->execute([
            'email' => $email,
            'user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Return the number of non-deleted users.
     */
    public function count(): int
    {
        $result = $this->connection()->query(
            'SELECT COUNT(*)
             FROM users
             WHERE deleted_at IS NULL'
        );

        return (int) $result->fetchColumn();
    }

    /**
     * Update data after a successful login.
     */
    public function recordSuccessfulLogin(int $userId): void
    {
        $statement = $this->connection()->prepare(
            'UPDATE users
             SET last_login_at = NOW(),
                 failed_login_count = 0,
                 locked_until = NULL
             WHERE user_id = :user_id'
        );

        $statement->execute([
            'user_id' => $userId,
        ]);
    }

    /**
     * Increase the failed-login counter.
     */
    public function incrementFailedLoginCount(int $userId): void
    {
        $statement = $this->connection()->prepare(
            'UPDATE users
             SET failed_login_count = failed_login_count + 1
             WHERE user_id = :user_id'
        );

        $statement->execute([
            'user_id' => $userId,
        ]);
    }

    /**
     * Temporarily lock a user account.
     */
    public function lockUntil(
        int $userId,
        string $lockedUntil
    ): void {
        $statement = $this->connection()->prepare(
            'UPDATE users
             SET locked_until = :locked_until
             WHERE user_id = :user_id'
        );

        $statement->execute([
            'locked_until' => $lockedUntil,
            'user_id' => $userId,
        ]);
    }
    /**
 * Return active role codes assigned to a user.
 *
 * @return list<string>
 */
public function roleCodes(
    int $companyId,
    int $userId
): array
{
    $statement = $this->connection()->prepare(
        'SELECT r.code
         FROM roles r
         INNER JOIN company_user_roles ur
             ON ur.role_id = r.role_id
         WHERE ur.user_id = :user_id
           AND ur.company_id = :company_id
           AND r.active = TRUE
         ORDER BY r.code'
    );

    $statement->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
    ]);

    $roles = $statement->fetchAll(
        \PDO::FETCH_COLUMN
    );

    return array_values(
        array_filter(
            array_map('strval', $roles)
        )
    );
}
/**
 * Update a user's password and clear the mandatory-change flag.
 */
public function updatePassword(
    int $userId,
    string $passwordHash
): void {
    $statement = $this->connection()->prepare(
        'UPDATE users
         SET password_hash = :password_hash,
             must_change_password = FALSE,
             password_changed_at = NOW(),
             failed_login_count = 0,
             locked_until = NULL
         WHERE user_id = :user_id
           AND deleted_at IS NULL'
    );

    $statement->execute([
        'password_hash' => $passwordHash,
        'user_id' => $userId,
    ]);
}

/**
 * Return the password hash for an active session user.
 */
public function passwordHashById(int $userId): ?string
{
    $statement = $this->connection()->prepare(
        'SELECT password_hash
         FROM users
         WHERE user_id = :user_id
           AND active = TRUE
           AND deleted_at IS NULL
         LIMIT 1'
    );

    $statement->execute([
        'user_id' => $userId,
    ]);

    $passwordHash = $statement->fetchColumn();

    return is_string($passwordHash)
        ? $passwordHash
        : null;
}
/**
 * Return active permission codes assigned through user roles.
 *
 * @return list<string>
 */
public function permissionCodes(
    int $companyId,
    int $userId
): array
{
    $statement = $this->connection()->prepare(
        'SELECT DISTINCT p.code
         FROM permissions p
         INNER JOIN company_role_permissions rp
             ON rp.permission_id = p.permission_id
         INNER JOIN company_user_roles ur
             ON ur.company_id = rp.company_id
            AND ur.role_id = rp.role_id
         INNER JOIN roles r
             ON r.role_id = ur.role_id
         WHERE ur.user_id = :user_id
           AND ur.company_id = :company_id
           AND p.active = TRUE
           AND r.active = TRUE
         ORDER BY p.code'
    );

    $statement->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
    ]);

    $permissions = $statement->fetchAll(
        \PDO::FETCH_COLUMN
    );

    return array_values(
        array_filter(
            array_map('strval', $permissions)
        )
    );
}
/**
 * Count users matching administration filters.
 */
public function administrationCount(
    int $companyId,
    string $search = '',
    string $status = 'all'
): int {
    $conditions = [
        'users.deleted_at IS NULL',
        'memberships.company_id = :company_id',
    ];

    $parameters = [
        'company_id' => $companyId,
    ];

    if ($search !== '') {
        $conditions[] = '(
            users.username LIKE :search
            OR users.email LIKE :search
            OR users.display_name LIKE :search
        )';

        $parameters['search'] = '%' . $search . '%';
    }

    if ($status === 'active') {
        $conditions[] = '
            users.active = TRUE
            AND memberships.active = TRUE
        ';
    } elseif ($status === 'inactive') {
        $conditions[] = '(
            users.active = FALSE
            OR memberships.active = FALSE
        )';
    } elseif ($status === 'locked') {
        $conditions[] = '
            users.locked_until IS NOT NULL
            AND users.locked_until > NOW()
        ';
    }

    $sql = '
        SELECT COUNT(*)
        FROM company_users memberships
        INNER JOIN users
            ON users.user_id = memberships.user_id
        WHERE ' . implode(' AND ', $conditions);

    $statement = $this->connection()->prepare($sql);
    $statement->execute($parameters);

    return (int) $statement->fetchColumn();
}

/**
 * Return one page of users for administration.
 *
 * @return list<array<string, mixed>>
 */
public function administrationPage(
    int $companyId,
    string $search,
    string $status,
    string $sort,
    string $direction,
    int $limit,
    int $offset
): array {
    $conditions = [
        'users.deleted_at IS NULL',
        'memberships.company_id = :company_id',
    ];

    $parameters = [
        'company_id' => $companyId,
    ];

    if ($search !== '') {
        $conditions[] = '(
            users.username LIKE :search
            OR users.email LIKE :search
            OR users.display_name LIKE :search
        )';

        $parameters['search'] = '%' . $search . '%';
    }

    if ($status === 'active') {
        $conditions[] = '
            users.active = TRUE
            AND memberships.active = TRUE
        ';
    } elseif ($status === 'inactive') {
        $conditions[] = '(
            users.active = FALSE
            OR memberships.active = FALSE
        )';
    } elseif ($status === 'locked') {
        $conditions[] = '
            users.locked_until IS NOT NULL
            AND users.locked_until > NOW()
        ';
    }

    $allowedSorts = [
        'username' => 'users.username',
        'display_name' => 'users.display_name',
        'email' => 'users.email',
        'last_login_at' => 'users.last_login_at',
        'created_at' => 'users.created_at',
    ];

    $orderColumn =
        $allowedSorts[$sort] ?? 'users.created_at';

    $orderDirection =
        strtolower($direction) === 'asc'
            ? 'ASC'
            : 'DESC';

    $sql = '
        SELECT
            users.user_id,
            users.username,
            users.email,
            users.display_name,
            (
                users.active = TRUE
                AND memberships.active = TRUE
            ) AS active,
            memberships.active
                AS membership_active,
            users.must_change_password,
            users.failed_login_count,
            users.locked_until,
            users.last_login_at,
            users.password_changed_at,
            users.created_at,
            users.updated_at
        FROM company_users memberships
        INNER JOIN users
            ON users.user_id = memberships.user_id
        WHERE ' . implode(' AND ', $conditions) . '
        ORDER BY ' . $orderColumn . ' '
            . $orderDirection . '
        LIMIT :limit
        OFFSET :offset
    ';

    $statement = $this->connection()->prepare($sql);

    foreach ($parameters as $key => $value) {
        $statement->bindValue(
            ':' . $key,
            $value,
            \PDO::PARAM_STR
        );
    }

    $statement->bindValue(
        ':limit',
        $limit,
        \PDO::PARAM_INT
    );

    $statement->bindValue(
        ':offset',
        $offset,
        \PDO::PARAM_INT
    );

    $statement->execute();

    $users = $statement->fetchAll(
        \PDO::FETCH_ASSOC
    );

    return is_array($users)
        ? $users
        : [];
}

/**
 * Return role codes assigned to multiple users.
 *
 * @param list<int> $userIds
 *
 * @return array<int, list<string>>
 */
public function roleCodesForUsers(
    int $companyId,
    array $userIds
): array
{
    if ($userIds === []) {
        return [];
    }

    $placeholders = implode(
        ', ',
        array_fill(0, count($userIds), '?')
    );

    $statement = $this->connection()->prepare(
        'SELECT
            assignments.user_id,
            r.code
         FROM company_user_roles assignments
         INNER JOIN roles r
             ON r.role_id = assignments.role_id
         WHERE assignments.company_id = ?
           AND assignments.user_id IN (' . $placeholders . ')
           AND r.active = TRUE
         ORDER BY r.code'
    );

    $statement->bindValue(
        1,
        $companyId,
        \PDO::PARAM_INT
    );

    foreach ($userIds as $index => $userId) {
        $statement->bindValue(
            $index + 2,
            $userId,
            \PDO::PARAM_INT
        );
    }

    $statement->execute();

    $rows = $statement->fetchAll(
        \PDO::FETCH_ASSOC
    );

    $result = [];

    foreach ($rows as $row) {
        $userId = (int) $row['user_id'];

        if (!isset($result[$userId])) {
            $result[$userId] = [];
        }

        $result[$userId][] =
            (string) $row['code'];
    }

    return $result;
}
/**
 * Create a new ERP user.
 */
public function createAdministrationUser(
    string $username,
    string $email,
    string $displayName,
    string $passwordHash,
    bool $active
): int {
    $statement = $this->connection()->prepare(
        'INSERT INTO users
            (
                username,
                email,
                display_name,
                password_hash,
                active,
                must_change_password,
                failed_login_count
            )
         VALUES
            (
                :username,
                :email,
                :display_name,
                :password_hash,
                :active,
                TRUE,
                0
            )'
    );

    $statement->execute([
        'username' => $username,
        'email' => $email,
        'display_name' => $displayName,
        'password_hash' => $passwordHash,
        'active' => $active ? 1 : 0,
    ]);

    return (int) $this->connection()->lastInsertId();
}

/**
 * Assign roles to a user.
 *
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

public function updateAdministrationUser(
    int $companyId,
    int $userId,
    string $username,
    string $email,
    string $displayName,
    bool $active
): void {
    $statement = $this->connection()->prepare(
        'UPDATE users
         SET username = :username,
             email = :email,
             display_name = :display_name
         WHERE user_id = :user_id
           AND deleted_at IS NULL'
    );

    $statement->execute([
        'username' => $username,
        'email' => $email,
        'display_name' => $displayName,
        'user_id' => $userId,
    ]);

    $membershipStatement = $this->connection()->prepare(
        'UPDATE company_users
         SET active = :active
         WHERE company_id = :company_id
           AND user_id = :user_id'
    );
    $membershipStatement->execute([
        'active' => $active ? 1 : 0,
        'company_id' => $companyId,
        'user_id' => $userId,
    ]);

    if ($active) {
        $platformStatement = $this->connection()->prepare(
            'UPDATE users
             SET active = TRUE
             WHERE user_id = :user_id
               AND deleted_at IS NULL'
        );
        $platformStatement->execute([
            'user_id' => $userId,
        ]);
    }
}

public function resetAdministrationPassword(
    int $userId,
    string $passwordHash
): void {
    $statement = $this->connection()->prepare(
        'UPDATE users
         SET password_hash = :password_hash,
             must_change_password = TRUE,
             password_changed_at = NOW(),
             failed_login_count = 0,
             locked_until = NULL
         WHERE user_id = :user_id
           AND deleted_at IS NULL'
    );

    $statement->execute([
        'password_hash' => $passwordHash,
        'user_id' => $userId,
    ]);
}

public function setAdministrationActive(
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

    if ($active) {
        $platformStatement = $this->connection()->prepare(
            'UPDATE users
             SET active = TRUE
             WHERE user_id = :user_id
               AND deleted_at IS NULL'
        );
        $platformStatement->execute([
            'user_id' => $userId,
        ]);
    }
}

public function unlockAdministrationAccount(
    int $userId
): void {
    $statement = $this->connection()->prepare(
        'UPDATE users
         SET failed_login_count = 0,
             locked_until = NULL
         WHERE user_id = :user_id
           AND deleted_at IS NULL'
    );

    $statement->execute([
        'user_id' => $userId,
    ]);
}

public function hasRoleCode(
    int $companyId,
    int $userId,
    string $roleCode
): bool {
    $statement = $this->connection()->prepare(
        'SELECT 1
         FROM company_user_roles ur
         INNER JOIN roles r
             ON r.role_id = ur.role_id
         WHERE ur.user_id = :user_id
           AND ur.company_id = :company_id
           AND r.code = :role_code
         LIMIT 1'
    );

    $statement->execute([
        'user_id' => $userId,
        'company_id' => $companyId,
        'role_code' => $roleCode,
    ]);

    return $statement->fetchColumn() !== false;
}

public function isPrimaryCompanyOwner(
    int $companyId,
    int $userId
): bool {
    $statement = $this->connection()->prepare(
        'SELECT 1
         FROM companies
         WHERE company_id = :company_id
           AND owner_user_id = :user_id
           AND deleted_at IS NULL
         LIMIT 1'
    );
    $statement->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
    ]);

    return $statement->fetchColumn() !== false;
}

public function activeUserCountForRole(
    int $companyId,
    string $roleCode
): int {
    $statement = $this->connection()->prepare(
        'SELECT COUNT(DISTINCT users.user_id)
         FROM users
         INNER JOIN company_users memberships
             ON memberships.user_id = users.user_id
         INNER JOIN company_user_roles assignments
             ON assignments.user_id = users.user_id
            AND assignments.company_id =
                memberships.company_id
         INNER JOIN roles
             ON roles.role_id = assignments.role_id
         WHERE roles.code = :role_code
           AND memberships.company_id = :company_id
           AND memberships.active = TRUE
           AND users.active = TRUE
           AND users.deleted_at IS NULL'
    );

    $statement->execute([
        'role_code' => $roleCode,
        'company_id' => $companyId,
    ]);

    return (int) $statement->fetchColumn();
}

/**
 * @param list<int> $roleIds
 */
public function replaceRoles(
    int $companyId,
    int $userId,
    array $roleIds,
    int $assignedBy
): void {
    $statement = $this->connection()->prepare(
        'DELETE FROM company_user_roles
         WHERE company_id = :company_id
           AND user_id = :user_id'
    );

    $statement->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
    ]);

    $this->assignRoles(
        $companyId,
        $userId,
        $roleIds,
        $assignedBy
    );
}

/**
 * Return assigned role details for a user.
 *
 * @return list<array<string, mixed>>
 */
public function administrationRoles(
    int $companyId,
    int $userId
): array
{
    $statement = $this->connection()->prepare(
        'SELECT
            r.role_id,
            r.code,
            r.name,
            r.description,
            ur.assigned_at,
            assigned_by.display_name AS assigned_by_name
         FROM company_user_roles ur
         INNER JOIN roles r
             ON r.role_id = ur.role_id
         LEFT JOIN users assigned_by
             ON assigned_by.user_id = ur.assigned_by
         WHERE ur.user_id = :user_id
           AND ur.company_id = :company_id
         ORDER BY r.name'
    );

    $statement->execute([
        'user_id' => $userId,
        'company_id' => $companyId,
    ]);

    $roles = $statement->fetchAll(\PDO::FETCH_ASSOC);

    return is_array($roles) ? $roles : [];
}

/**
 * @return list<int>
 */
public function roleIds(
    int $companyId,
    int $userId
): array
{
    $statement = $this->connection()->prepare(
        'SELECT role_id
         FROM company_user_roles
         WHERE company_id = :company_id
           AND user_id = :user_id
         ORDER BY role_id'
    );

    $statement->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
    ]);

    return array_map(
        'intval',
        $statement->fetchAll(\PDO::FETCH_COLUMN)
    );
}

/**
 * Return effective permissions inherited through active roles.
 *
 * @return list<array<string, mixed>>
 */
public function administrationPermissions(
    int $companyId,
    int $userId
): array
{
    $statement = $this->connection()->prepare(
        'SELECT DISTINCT
            p.permission_id,
            p.code,
            p.name,
            p.module,
            p.description
         FROM permissions p
         INNER JOIN company_role_permissions rp
             ON rp.permission_id = p.permission_id
         INNER JOIN company_user_roles ur
             ON ur.company_id = rp.company_id
            AND ur.role_id = rp.role_id
         INNER JOIN roles r
             ON r.role_id = ur.role_id
         WHERE ur.user_id = :user_id
           AND ur.company_id = :company_id
           AND p.active = TRUE
           AND r.active = TRUE
         ORDER BY p.module, p.name'
    );

    $statement->execute([
        'user_id' => $userId,
        'company_id' => $companyId,
    ]);

    $permissions = $statement->fetchAll(
        \PDO::FETCH_ASSOC
    );

    return is_array($permissions)
        ? $permissions
        : [];
}
}
