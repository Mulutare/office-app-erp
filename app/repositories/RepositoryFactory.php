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
use App\Repositories\MySql\JobTitleRepository
    as MySqlJobTitleRepository;
use App\Repositories\Oracle\JobTitleRepository
    as OracleJobTitleRepository;
use App\Repositories\MySql\DepartmentRepository
    as MySqlDepartmentRepository;
use App\Repositories\Oracle\DepartmentRepository
    as OracleDepartmentRepository;
use App\Repositories\MySql\PositionRepository
    as MySqlPositionRepository;
use App\Repositories\Oracle\PositionRepository
    as OraclePositionRepository;
use App\Repositories\MySql\EmployeePositionAssignmentRepository
    as MySqlEmployeePositionAssignmentRepository;
use App\Repositories\Oracle\EmployeePositionAssignmentRepository
    as OracleEmployeePositionAssignmentRepository;
use RuntimeException;

/**
 * Selects repository implementations from the allowlisted database driver.
 */
final class RepositoryFactory
{
    public static function employeePositionAssignments():
        EmployeePositionAssignmentRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlEmployeePositionAssignmentRepository();
        }

        if (\databaseDriver()->name() === 'oracle') {
            return new OracleEmployeePositionAssignmentRepository();
        }

        throw new RuntimeException(
            'No employee-position assignment repository is available for the configured database driver.'
        );
    }

    public static function positions():
        PositionRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlPositionRepository();
        }

        if (\databaseDriver()->name() === 'oracle') {
            return new OraclePositionRepository();
        }

        throw new RuntimeException(
            'No position repository is available for the configured database driver.'
        );
    }

    public static function departments():
        DepartmentRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlDepartmentRepository();
        }

        if (\databaseDriver()->name() === 'oracle') {
            return new OracleDepartmentRepository();
        }

        throw new RuntimeException(
            'No department repository is available for the configured database driver.'
        );
    }

    public static function jobTitles():
        JobTitleRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlJobTitleRepository();
        }

        if (\databaseDriver()->name() === 'oracle') {
            return new OracleJobTitleRepository();
        }

        throw new RuntimeException(
            'No job-title repository is available for the configured database driver.'
        );
    }

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
