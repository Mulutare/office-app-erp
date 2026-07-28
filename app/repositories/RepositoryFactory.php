<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\MySql\DashboardStatisticsRepository
    as MySqlDashboardStatisticsRepository;
use App\Repositories\Oracle\DashboardStatisticsRepository
    as OracleDashboardStatisticsRepository;
use App\Repositories\MySql\BranchRepository
    as MySqlBranchRepository;
use App\Repositories\Oracle\BranchRepository
    as OracleBranchRepository;
use App\Repositories\MySql\AuditLogRepository
    as MySqlAuditLogRepository;
use App\Repositories\Oracle\AuditLogRepository
    as OracleAuditLogRepository;
use RuntimeException;

/**
 * Selects repository implementations from the allowlisted database driver.
 */
final class RepositoryFactory
{
    public static function auditLogs(): AuditLogWriter
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlAuditLogRepository();
        }

        if (\databaseDriver()->name() === 'oracle') {
            return new OracleAuditLogRepository();
        }

        throw new RuntimeException(
            'No audit-log writer is available for the configured database driver.'
        );
    }

    public static function branches():
        BranchRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlBranchRepository();
        }

        if (\databaseDriver()->name() === 'oracle') {
            return new OracleBranchRepository();
        }

        throw new RuntimeException(
            'No branch repository is available for the configured database driver.'
        );
    }

    public static function dashboardStatistics():
        DashboardStatisticsRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlDashboardStatisticsRepository();
        }

        if (\databaseDriver()->name() === 'oracle') {
            return new OracleDashboardStatisticsRepository();
        }

        throw new RuntimeException(
            'No repository is available for the configured database driver.'
        );
    }
}
