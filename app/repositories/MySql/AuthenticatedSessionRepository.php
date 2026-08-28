<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\AuthenticatedSessionRepository as Contract;
use RuntimeException;

final class AuthenticatedSessionRepository
    extends MySqlRepository
    implements Contract
{
    public function available(): bool
    {
        $statement = $this->connection()->query(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = 'authenticated_user_sessions'"
        );

        return (int) $statement->fetchColumn() === 1;
    }

    public function register(array $session): int
    {
        $companyId = (int) ($session['company_id'] ?? 0);
        $userId = (int) ($session['user_id'] ?? 0);
        $sessionHash = (string) ($session['session_hash'] ?? '');

        $existing = $this->findByHash(
            $companyId,
            $userId,
            $sessionHash
        );

        if ($existing !== null) {
            $statement = $this->connection()->prepare(
                'UPDATE authenticated_user_sessions
                 SET last_activity_at = :activity,
                     expires_at = :expires,
                     ip_address = :ip,
                     user_agent = :agent
                 WHERE company_id = :company
                   AND user_id = :user
                   AND session_hash = :hash'
            );

            $statement->execute([
                'activity' => $session['last_activity_at'],
                'expires' => $session['expires_at'],
                'ip' => $session['ip_address'],
                'agent' => $session['user_agent'],
                'company' => $companyId,
                'user' => $userId,
                'hash' => $sessionHash,
            ]);

            return (int) $existing['authenticated_user_session_id'];
        }

        $collision = $this->connection()->prepare(
            'SELECT authenticated_user_session_id
             FROM authenticated_user_sessions
             WHERE session_hash = :hash
             LIMIT 1'
        );

        $collision->execute([
            'hash' => $sessionHash,
        ]);

        if ($collision->fetchColumn() !== false) {
            throw new RuntimeException(
                'Authenticated session identity collision.'
            );
        }

        $statement = $this->connection()->prepare(
            'INSERT INTO authenticated_user_sessions (
                company_id,
                user_id,
                session_hash,
                signed_in_at,
                last_activity_at,
                expires_at,
                revoked_at,
                ip_address,
                user_agent
             ) VALUES (
                :company,
                :user,
                :hash,
                :signed_in,
                :activity,
                :expires,
                NULL,
                :ip,
                :agent
             )'
        );

        $statement->execute([
            'company' => $companyId,
            'user' => $userId,
            'hash' => $sessionHash,
            'signed_in' => $session['signed_in_at'],
            'activity' => $session['last_activity_at'],
            'expires' => $session['expires_at'],
            'ip' => $session['ip_address'],
            'agent' => $session['user_agent'],
        ]);

        return (int) $this->connection()->lastInsertId();
    }

    public function touch(
        int $companyId,
        int $userId,
        string $sessionHash,
        string $activityAt,
        string $expiresAt
    ): bool {
        $statement = $this->connection()->prepare(
            'UPDATE authenticated_user_sessions
             SET last_activity_at = :activity,
                 expires_at = :expires
             WHERE company_id = :company
               AND user_id = :user
               AND session_hash = :hash
               AND revoked_at IS NULL'
        );

        $statement->execute([
            'activity' => $activityAt,
            'expires' => $expiresAt,
            'company' => $companyId,
            'user' => $userId,
            'hash' => $sessionHash,
        ]);

        return $statement->rowCount() === 1;
    }

    public function revoke(
        int $companyId,
        int $userId,
        string $sessionHash,
        string $revokedAt
    ): void {
        $statement = $this->connection()->prepare(
            'UPDATE authenticated_user_sessions
             SET revoked_at = COALESCE(revoked_at, :revoked)
             WHERE company_id = :company
               AND user_id = :user
               AND session_hash = :hash'
        );

        $statement->execute([
            'revoked' => $revokedAt,
            'company' => $companyId,
            'user' => $userId,
            'hash' => $sessionHash,
        ]);
    }

    public function countActive(
        int $companyId,
        int $userId,
        string $now
    ): int {
        $statement = $this->connection()->prepare(
            'SELECT COUNT(*)
             FROM authenticated_user_sessions
             WHERE company_id = :company
               AND user_id = :user
               AND revoked_at IS NULL
               AND expires_at > :now'
        );

        $statement->execute([
            'company' => $companyId,
            'user' => $userId,
            'now' => $now,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function findByHash(
        int $companyId,
        int $userId,
        string $sessionHash
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT *
             FROM authenticated_user_sessions
             WHERE company_id = :company
               AND user_id = :user
               AND session_hash = :hash
             LIMIT 1'
        );

        $statement->execute([
            'company' => $companyId,
            'user' => $userId,
            'hash' => $sessionHash,
        ]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function listActive(
        int $companyId,
        int $userId,
        string $now
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                authenticated_user_session_id,
                signed_in_at,
                last_activity_at,
                expires_at,
                ip_address,
                user_agent,
                session_hash
             FROM authenticated_user_sessions
             WHERE company_id = :company
               AND user_id = :user
               AND revoked_at IS NULL
               AND expires_at > :now
             ORDER BY last_activity_at DESC'
        );

        $statement->execute([
            'company' => $companyId,
            'user' => $userId,
            'now' => $now,
        ]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function revokeById(
        int $companyId,
        int $userId,
        int $authenticatedSessionId,
        string $revokedAt
    ): bool {
        $statement = $this->connection()->prepare(
            'UPDATE authenticated_user_sessions
             SET revoked_at = :revoked
             WHERE company_id = :company
               AND user_id = :user
               AND authenticated_user_session_id = :session
               AND revoked_at IS NULL
               AND expires_at > :now'
        );
        $statement->execute([
            'revoked' => $revokedAt,
            'company' => $companyId,
            'user' => $userId,
            'session' => $authenticatedSessionId,
            'now' => $revokedAt,
        ]);

        return $statement->rowCount() === 1;
    }

    public function revokeAllExceptHash(
        int $companyId,
        int $userId,
        string $keepSessionHash,
        string $revokedAt
    ): int {
        $statement = $this->connection()->prepare(
            'UPDATE authenticated_user_sessions
             SET revoked_at = :revoked
             WHERE company_id = :company
               AND user_id = :user
               AND session_hash <> :keep_hash
               AND revoked_at IS NULL
               AND expires_at > :now'
        );
        $statement->execute([
            'revoked' => $revokedAt,
            'company' => $companyId,
            'user' => $userId,
            'keep_hash' => $keepSessionHash,
            'now' => $revokedAt,
        ]);

        return $statement->rowCount();
    }

    public function revokeAll(
        int $companyId,
        int $userId,
        string $revokedAt
    ): int {
        $statement = $this->connection()->prepare(
            'UPDATE authenticated_user_sessions
             SET revoked_at = :revoked
             WHERE company_id = :company
               AND user_id = :user
               AND revoked_at IS NULL
               AND expires_at > :now'
        );
        $statement->execute([
            'revoked' => $revokedAt,
            'company' => $companyId,
            'user' => $userId,
            'now' => $revokedAt,
        ]);

        return $statement->rowCount();
    }
}
