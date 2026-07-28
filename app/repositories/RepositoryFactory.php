<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\MySql\DashboardStatisticsRepository
    as MySqlDashboardStatisticsRepository;
use RuntimeException;

/**
 * Selects repository implementations from the allowlisted database driver.
 */
final class RepositoryFactory
{
    public static function dashboardStatistics():
        DashboardStatisticsRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlDashboardStatisticsRepository();
        }

        throw new RuntimeException(
            'No repository is available for the configured database driver.'
        );
    }
}
