<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\DashboardStatisticsRepository;
use App\Repositories\RepositoryFactory;

final class DashboardService
{
    private TenantContext $tenant;
    private DashboardStatisticsRepository $statistics;

    public function __construct(
        ?DashboardStatisticsRepository $statistics = null,
        ?TenantContext $tenant = null
    ) {
        $this->statistics = $statistics
            ?? RepositoryFactory::dashboardStatistics();
        $this->tenant = $tenant
            ?? new TenantContext();
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
        return $this->statistics->statistics(
            $this->tenant->companyId()
        );
    }
}
