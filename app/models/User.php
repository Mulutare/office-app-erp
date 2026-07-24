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
}