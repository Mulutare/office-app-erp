<?php

declare(strict_types=1);

namespace App\Services;

final class DashboardService
{
    private TenantContext $tenant;

    public function __construct()
    {
        $this->tenant = new TenantContext();
    }

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
        $statement = \db()->prepare(
            'SELECT COUNT(*)
             FROM company_users memberships
             INNER JOIN users
                 ON users.user_id =
                    memberships.user_id
             WHERE memberships.company_id =
                    :company_id
               AND memberships.active = TRUE
               AND users.active = TRUE
               AND users.deleted_at IS NULL'
        );
        $statement->execute([
            'company_id' =>
                $this->tenant->companyId(),
        ]);

        return (int) $statement->fetchColumn();
    }

    private function successfulLoginsToday(): int
    {
        $statement = \db()->prepare(
            'SELECT COUNT(*)
             FROM login_attempts
             WHERE company_id = :company_id
               AND successful = TRUE
               AND attempted_at >= CURDATE()
               AND attempted_at < CURDATE()
                   + INTERVAL 1 DAY'
        );

        $statement->execute([
            'company_id' =>
                $this->tenant->companyId(),
        ]);

        return (int) $statement->fetchColumn();
    }

    private function failedLoginsToday(): int
    {
        $statement = \db()->prepare(
            'SELECT COUNT(*)
             FROM login_attempts
             WHERE company_id = :company_id
               AND successful = FALSE
               AND attempted_at >= CURDATE()
               AND attempted_at < CURDATE()
                   + INTERVAL 1 DAY'
        );

        $statement->execute([
            'company_id' =>
                $this->tenant->companyId(),
        ]);

        return (int) $statement->fetchColumn();
    }

    private function securityAlertCount(): int
    {
        $statement = \db()->prepare(
            'SELECT COUNT(*)
             FROM login_attempts
             WHERE company_id = :company_id
               AND successful = FALSE
               AND failure_reason IN (
                   "account_locked",
                   "invalid_password_account_locked"
               )
               AND attempted_at >= CURDATE()
               AND attempted_at < CURDATE()
                   + INTERVAL 1 DAY'
        );

        $statement->execute([
            'company_id' =>
                $this->tenant->companyId(),
        ]);

        return (int) $statement->fetchColumn();
    }
}
