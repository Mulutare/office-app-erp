<?php

declare(strict_types=1);

namespace App\Models;

final class LoginAttempt
{
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