<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

final class User
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

        $statement = \db()->prepare($sql);

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

        $statement = \db()->prepare($sql);

        $statement->execute([
            'user_id' => $userId,
        ]);

        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($user) ? $user : null;
    }

    /**
     * Determine whether a username already exists.
     */
    public function usernameExists(string $username): bool
    {
        $statement = \db()->prepare(
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
        $statement = \db()->prepare(
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

    /**
     * Return the number of non-deleted users.
     */
    public function count(): int
    {
        $result = \db()->query(
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
        $statement = \db()->prepare(
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
        $statement = \db()->prepare(
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
        $statement = \db()->prepare(
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
public function roleCodes(int $userId): array
{
    $statement = \db()->prepare(
        'SELECT r.code
         FROM roles r
         INNER JOIN user_roles ur
             ON ur.role_id = r.role_id
         WHERE ur.user_id = :user_id
           AND r.active = TRUE
         ORDER BY r.code'
    );

    $statement->execute([
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
    $statement = \db()->prepare(
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
    $statement = \db()->prepare(
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
public function permissionCodes(int $userId): array
{
    $statement = \db()->prepare(
        'SELECT DISTINCT p.code
         FROM permissions p
         INNER JOIN role_permissions rp
             ON rp.permission_id = p.permission_id
         INNER JOIN user_roles ur
             ON ur.role_id = rp.role_id
         INNER JOIN roles r
             ON r.role_id = ur.role_id
         WHERE ur.user_id = :user_id
           AND p.active = TRUE
           AND r.active = TRUE
         ORDER BY p.code'
    );

    $statement->execute([
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
    string $search = '',
    string $status = 'all'
): int {
    $conditions = [
        'deleted_at IS NULL',
    ];

    $parameters = [];

    if ($search !== '') {
        $conditions[] = '(
            username LIKE :search
            OR email LIKE :search
            OR display_name LIKE :search
        )';

        $parameters['search'] = '%' . $search . '%';
    }

    if ($status === 'active') {
        $conditions[] = 'active = TRUE';
    } elseif ($status === 'inactive') {
        $conditions[] = 'active = FALSE';
    } elseif ($status === 'locked') {
        $conditions[] = '
            locked_until IS NOT NULL
            AND locked_until > NOW()
        ';
    }

    $sql = '
        SELECT COUNT(*)
        FROM users
        WHERE ' . implode(' AND ', $conditions);

    $statement = \db()->prepare($sql);
    $statement->execute($parameters);

    return (int) $statement->fetchColumn();
}

/**
 * Return one page of users for administration.
 *
 * @return list<array<string, mixed>>
 */
public function administrationPage(
    string $search,
    string $status,
    string $sort,
    string $direction,
    int $limit,
    int $offset
): array {
    $conditions = [
        'deleted_at IS NULL',
    ];

    $parameters = [];

    if ($search !== '') {
        $conditions[] = '(
            username LIKE :search
            OR email LIKE :search
            OR display_name LIKE :search
        )';

        $parameters['search'] = '%' . $search . '%';
    }

    if ($status === 'active') {
        $conditions[] = 'active = TRUE';
    } elseif ($status === 'inactive') {
        $conditions[] = 'active = FALSE';
    } elseif ($status === 'locked') {
        $conditions[] = '
            locked_until IS NOT NULL
            AND locked_until > NOW()
        ';
    }

    $allowedSorts = [
        'username' => 'username',
        'display_name' => 'display_name',
        'email' => 'email',
        'last_login_at' => 'last_login_at',
        'created_at' => 'created_at',
    ];

    $orderColumn =
        $allowedSorts[$sort] ?? 'created_at';

    $orderDirection =
        strtolower($direction) === 'asc'
            ? 'ASC'
            : 'DESC';

    $sql = '
        SELECT
            user_id,
            username,
            email,
            display_name,
            active,
            must_change_password,
            failed_login_count,
            locked_until,
            last_login_at,
            password_changed_at,
            created_at,
            updated_at
        FROM users
        WHERE ' . implode(' AND ', $conditions) . '
        ORDER BY ' . $orderColumn . ' '
            . $orderDirection . '
        LIMIT :limit
        OFFSET :offset
    ';

    $statement = \db()->prepare($sql);

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
public function roleCodesForUsers(array $userIds): array
{
    if ($userIds === []) {
        return [];
    }

    $placeholders = implode(
        ', ',
        array_fill(0, count($userIds), '?')
    );

    $statement = \db()->prepare(
        'SELECT
            ur.user_id,
            r.code
         FROM user_roles ur
         INNER JOIN roles r
             ON r.role_id = ur.role_id
         WHERE ur.user_id IN (' . $placeholders . ')
           AND r.active = TRUE
         ORDER BY r.code'
    );

    foreach ($userIds as $index => $userId) {
        $statement->bindValue(
            $index + 1,
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
    $statement = \db()->prepare(
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

    return (int) \db()->lastInsertId();
}

/**
 * Assign roles to a user.
 *
 * @param list<int> $roleIds
 */
public function assignRoles(
    int $userId,
    array $roleIds,
    int $assignedBy
): void {
    $statement = \db()->prepare(
        'INSERT INTO user_roles
            (
                user_id,
                role_id,
                assigned_by
            )
         VALUES
            (
                :user_id,
                :role_id,
                :assigned_by
            )'
    );

    foreach ($roleIds as $roleId) {
        $statement->execute([
            'user_id' => $userId,
            'role_id' => $roleId,
            'assigned_by' => $assignedBy,
        ]);
    }
}

/**
 * Return assigned role details for a user.
 *
 * @return list<array<string, mixed>>
 */
public function administrationRoles(int $userId): array
{
    $statement = \db()->prepare(
        'SELECT
            r.role_id,
            r.code,
            r.name,
            r.description,
            ur.assigned_at,
            assigned_by.display_name AS assigned_by_name
         FROM user_roles ur
         INNER JOIN roles r
             ON r.role_id = ur.role_id
         LEFT JOIN users assigned_by
             ON assigned_by.user_id = ur.assigned_by
         WHERE ur.user_id = :user_id
         ORDER BY r.name'
    );

    $statement->execute([
        'user_id' => $userId,
    ]);

    $roles = $statement->fetchAll(\PDO::FETCH_ASSOC);

    return is_array($roles) ? $roles : [];
}

/**
 * Return effective permissions inherited through active roles.
 *
 * @return list<array<string, mixed>>
 */
public function administrationPermissions(int $userId): array
{
    $statement = \db()->prepare(
        'SELECT DISTINCT
            p.permission_id,
            p.code,
            p.name,
            p.module,
            p.description
         FROM permissions p
         INNER JOIN role_permissions rp
             ON rp.permission_id = p.permission_id
         INNER JOIN user_roles ur
             ON ur.role_id = rp.role_id
         INNER JOIN roles r
             ON r.role_id = ur.role_id
         WHERE ur.user_id = :user_id
           AND p.active = TRUE
           AND r.active = TRUE
         ORDER BY p.module, p.name'
    );

    $statement->execute([
        'user_id' => $userId,
    ]);

    $permissions = $statement->fetchAll(
        \PDO::FETCH_ASSOC
    );

    return is_array($permissions)
        ? $permissions
        : [];
}
}
