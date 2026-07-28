<?php

declare(strict_types=1);

namespace App\Repositories;

interface DashboardStatisticsRepository
{
    /**
     * @return array{
     *     users: int,
     *     successfulLogins: int,
     *     failedLogins: int,
     *     securityAlerts: int
     * }
     */
    public function statistics(int $companyId): array;
}
