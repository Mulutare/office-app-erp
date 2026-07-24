<?php

declare(strict_types=1);

namespace App\Services;

final class DashboardService
{
    /**
     * @return array{
     *     users: int,
     *     successfulLogins: int,
     *     failedLogins: int,
     *     securityAlerts: int
     * }
     */
    public function statistics(): array
    {
        return [
            'users' => $this->activeUserCount(),
            'successfulLogins' =>
                $this->successfulLoginsToday(),
            'failedLogins' =>
                $this->failedLoginsToday(),
            'securityAlerts' =>
                $this->securityAlertCount(),
        ];
    }

    private function activeUserCount(): int
    {
        $statement = \db()->query(
            'SELECT COUNT(*)
             FROM users
             WHERE active = TRUE
               AND deleted_at IS NULL'
        );

        return (int) $statement->fetchColumn();
    }

    private function successfulLoginsToday(): int
    {
        $statement = \db()->prepare(
            'SELECT COUNT(*)
             FROM login_attempts
             WHERE successful = TRUE
               AND attempted_at >= CURDATE()
               AND attempted_at < CURDATE()
                   + INTERVAL 1 DAY'
        );

        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    private function failedLoginsToday(): int
    {
        $statement = \db()->prepare(
            'SELECT COUNT(*)
             FROM login_attempts
             WHERE successful = FALSE
               AND attempted_at >= CURDATE()
               AND attempted_at < CURDATE()
                   + INTERVAL 1 DAY'
        );

        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    private function securityAlertCount(): int
    {
        $statement = \db()->prepare(
            'SELECT COUNT(*)
             FROM login_attempts
             WHERE successful = FALSE
               AND failure_reason IN (
                   "account_locked",
                   "invalid_password_account_locked"
               )
               AND attempted_at >= CURDATE()
               AND attempted_at < CURDATE()
                   + INTERVAL 1 DAY'
        );

        $statement->execute();

        return (int) $statement->fetchColumn();
    }
}