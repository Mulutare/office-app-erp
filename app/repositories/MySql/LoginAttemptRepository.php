<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Services\TenantContext;

class LoginAttemptRepository extends MySqlRepository
{
    /**
     * Return recent authentication attempts for a user.
     *
     * @return list<array<string, mixed>>
     */
    public function recentForUser(
        int $userId,
        int $companyId,
        int $limit = 10
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                login_attempt_id,
                username_entered,
                ip_address,
                successful,
                failure_reason,
                attempted_at
             FROM login_attempts
             WHERE company_id = :company_id
               AND user_id = :user_id
             ORDER BY attempted_at DESC
             LIMIT :limit'
        );

        $statement->bindValue(
            ':company_id',
            $companyId,
            \PDO::PARAM_INT
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
        string $userAgent,
        ?int $companyId = null
    ): void {
        $companyId = $companyId
            ?? (new TenantContext())->companyIdOrNull();
        $statement = $this->connection()->prepare(
            'INSERT INTO login_attempts
                (
                    company_id,
                    username_entered,
                    user_id,
                    ip_address,
                    user_agent,
                    successful,
                    failure_reason
                )
             VALUES
                (
                    :company_id,
                    :username_entered,
                    :user_id,
                    :ip_address,
                    :user_agent,
                    :successful,
                    :failure_reason
                )'
        );

        $statement->execute([
            'company_id' => $companyId,
            'username_entered' => $login,
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'successful' => $successful ? 1 : 0,
            'failure_reason' => $failureReason,
        ]);
    }
}
