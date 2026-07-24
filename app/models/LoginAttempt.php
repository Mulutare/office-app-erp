<?php

declare(strict_types=1);

namespace App\Models;

final class LoginAttempt
{
    /**
     * Return recent authentication attempts for a user.
     *
     * @return list<array<string, mixed>>
     */
    public function recentForUser(
        int $userId,
        int $limit = 10
    ): array {
        $statement = \db()->prepare(
            'SELECT
                login_attempt_id,
                username_entered,
                ip_address,
                successful,
                failure_reason,
                attempted_at
             FROM login_attempts
             WHERE user_id = :user_id
             ORDER BY attempted_at DESC
             LIMIT :limit'
        );

        $statement->bindValue(
            ':user_id',
            $userId,
            \PDO::PARAM_INT
        );
        $statement->bindValue(
            ':limit',
            max(1, min($limit, 50)),
            \PDO::PARAM_INT
        );
        $statement->execute();

        $attempts = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($attempts) ? $attempts : [];
    }

    public function record(
        string $login,
        ?int $userId,
        bool $successful,
        ?string $failureReason,
        string $ipAddress,
        string $userAgent
    ): void {
        $statement = \db()->prepare(
            'INSERT INTO login_attempts
                (
                    username_entered,
                    user_id,
                    ip_address,
                    user_agent,
                    successful,
                    failure_reason
                )
             VALUES
                (
                    :username_entered,
                    :user_id,
                    :ip_address,
                    :user_agent,
                    :successful,
                    :failure_reason
                )'
        );

        $statement->execute([
            'username_entered' => $login,
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'successful' => $successful ? 1 : 0,
            'failure_reason' => $failureReason,
        ]);
    }
}
