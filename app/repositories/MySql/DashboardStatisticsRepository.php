<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\DashboardStatisticsRepository
    as DashboardStatisticsRepositoryContract;

final class DashboardStatisticsRepository
    extends MySqlRepository
    implements DashboardStatisticsRepositoryContract
{
    public function statistics(int $companyId): array
    {
        return [
            'users' => $this->activeUserCount(
                $companyId
            ),
            'successfulLogins' => $this->loginCount(
                $companyId,
                true
            ),
            'failedLogins' => $this->loginCount(
                $companyId,
                false
            ),
            'securityAlerts' =>
                $this->securityAlertCount($companyId),
        ];
    }

    private function activeUserCount(
        int $companyId
    ): int {
        $statement = $this->connection()->prepare(
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
            'company_id' => $companyId,
        ]);

        return (int) $statement->fetchColumn();
    }

    private function loginCount(
        int $companyId,
        bool $successful
    ): int {
        $todayPredicate = $this->dialect()
            ->todayRangePredicate('attempted_at');
        $statement = $this->connection()->prepare(
            'SELECT COUNT(*)
             FROM login_attempts
             WHERE company_id = :company_id
               AND successful = :successful
               AND ' . $todayPredicate
        );
        $statement->execute([
            'company_id' => $companyId,
            'successful' => $successful ? 1 : 0,
        ]);

        return (int) $statement->fetchColumn();
    }

    private function securityAlertCount(
        int $companyId
    ): int {
        $todayPredicate = $this->dialect()
            ->todayRangePredicate('attempted_at');
        $statement = $this->connection()->prepare(
            'SELECT COUNT(*)
             FROM login_attempts
             WHERE company_id = :company_id
               AND successful = FALSE
               AND failure_reason IN (
                   :locked,
                   :locked_after_failure
               )
               AND ' . $todayPredicate
        );
        $statement->execute([
            'company_id' => $companyId,
            'locked' => 'account_locked',
            'locked_after_failure' =>
                'invalid_password_account_locked',
        ]);

        return (int) $statement->fetchColumn();
    }
}
