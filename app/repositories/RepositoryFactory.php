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
use App\Repositories\MySql\AttendanceRepository
    as MySqlAttendanceRepository;
use App\Repositories\Oracle\AttendanceRepository
    as OracleAttendanceRepository;
use App\Repositories\MySql\AttendanceReminderRepository
    as MySqlAttendanceReminderRepository;
use App\Repositories\Oracle\AttendanceReminderRepository
    as OracleAttendanceReminderRepository;
use App\Repositories\MySql\WorkforceCalendarRepository
    as MySqlWorkforceCalendarRepository;
use App\Repositories\Oracle\WorkforceCalendarRepository
    as OracleWorkforceCalendarRepository;
use App\Repositories\MySql\AttendanceNotificationRepository
    as MySqlAttendanceNotificationRepository;
use App\Repositories\Oracle\AttendanceNotificationRepository
    as OracleAttendanceNotificationRepository;
use App\Repositories\MySql\AttendancePushSubscriptionRepository
    as MySqlAttendancePushSubscriptionRepository;
use App\Repositories\Oracle\AttendancePushSubscriptionRepository
    as OracleAttendancePushSubscriptionRepository;
use App\Repositories\MySql\LeaveRepository
    as MySqlLeaveRepository;
use App\Repositories\Oracle\LeaveRepository
    as OracleLeaveRepository;
use App\Repositories\MySql\LeaveBalanceRepository
    as MySqlLeaveBalanceRepository;
use App\Repositories\Oracle\LeaveBalanceRepository
    as OracleLeaveBalanceRepository;
use App\Repositories\MySql\ManagerTeamRepository
    as MySqlManagerTeamRepository;
use App\Repositories\Oracle\ManagerTeamRepository
    as OracleManagerTeamRepository;
use App\Repositories\MySql\OrganizationReadinessRepository
    as MySqlOrganizationReadinessRepository;
use App\Repositories\Oracle\OrganizationReadinessRepository
    as OracleOrganizationReadinessRepository;
use RuntimeException;
use App\Repositories\MySql\SalesRepository
    as MySqlSalesRepository;
use App\Repositories\MySql\InventoryRepository
    as MySqlInventoryRepository;
use App\Repositories\MySql\WarehouseRepository
    as MySqlWarehouseRepository;
use App\Repositories\MySql\FinanceRepository
    as MySqlFinanceRepository;
use App\Repositories\MySql\IntegrationEventRepository
    as MySqlIntegrationEventRepository;

/**
 * Selects repository implementations from the allowlisted database driver.
 */
final class RepositoryFactory
{
    public static function integrationEvents():
        IntegrationEventRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlIntegrationEventRepository();
        }

        throw new RuntimeException(
            'No integration-event repository is available for the configured database driver.'
        );
    }

    public static function sales(): SalesRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlSalesRepository();
        }

        throw new RuntimeException(
            'No sales repository is available for the configured database driver.'
        );
    }
    public static function inventory(): InventoryRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlInventoryRepository();
        }

        throw new RuntimeException(
            'No inventory repository is available for the configured database driver.'
        );
    }

    public static function warehouses(): WarehouseRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlWarehouseRepository();
        }

        throw new RuntimeException(
            'No warehouse repository is available for the configured database driver.'
        );
    }
    public static function finance(): FinanceRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlFinanceRepository();
        }

        throw new RuntimeException(
            'No finance repository is available for the configured database driver.'
        );
    }

    public static function attendancePushSubscriptions():
        AttendancePushSubscriptionRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlAttendancePushSubscriptionRepository();
        }

        if (\databaseDriver()->name() === 'oracle') {
            return new OracleAttendancePushSubscriptionRepository();
        }

        throw new RuntimeException(
            'No attendance Web Push repository is available for the configured database driver.'
        );
    }

    public static function attendanceNotifications():
        AttendanceNotificationRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlAttendanceNotificationRepository();
        }

        if (\databaseDriver()->name() === 'oracle') {
            return new OracleAttendanceNotificationRepository();
        }

        throw new RuntimeException(
            'No attendance-notification repository is available for the configured database driver.'
        );
    }

    public static function workforceCalendars():
        WorkforceCalendarRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlWorkforceCalendarRepository();
        }

        if (\databaseDriver()->name() === 'oracle') {
            return new OracleWorkforceCalendarRepository();
        }

        throw new RuntimeException(
            'No workforce-calendar repository is available for the configured database driver.'
        );
    }

    public static function attendanceReminders():
        AttendanceReminderRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlAttendanceReminderRepository();
        }

        if (\databaseDriver()->name() === 'oracle') {
            return new OracleAttendanceReminderRepository();
        }

        throw new RuntimeException(
            'No attendance-reminder repository is available for the configured database driver.'
        );
    }

    public static function organizationReadiness():
        OrganizationReadinessRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlOrganizationReadinessRepository();
        }

        if (\databaseDriver()->name() === 'oracle') {
            return new OracleOrganizationReadinessRepository();
        }

        throw new RuntimeException(
            'No organization-readiness repository is available for the configured database driver.'
        );
    }

    public static function leaveBalances():
        LeaveBalanceRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlLeaveBalanceRepository();
        }

        if (\databaseDriver()->name() === 'oracle') {
            return new OracleLeaveBalanceRepository();
        }

        throw new RuntimeException(
            'No leave-balance repository is available for the configured database driver.'
        );
    }

    public static function managerTeams():
        ManagerTeamRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlManagerTeamRepository();
        }

        if (\databaseDriver()->name() === 'oracle') {
            return new OracleManagerTeamRepository();
        }

        throw new RuntimeException(
            'No manager-team repository is available for the configured database driver.'
        );
    }

    public static function attendance():
        AttendanceRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlAttendanceRepository();
        }

        if (\databaseDriver()->name() === 'oracle') {
            return new OracleAttendanceRepository();
        }

        throw new RuntimeException(
            'No attendance repository is available for the configured database driver.'
        );
    }

    public static function leave(): LeaveRepository
    {
        if (\databaseDriver()->name() === 'mysql') {
            return new MySqlLeaveRepository();
        }

        if (\databaseDriver()->name() === 'oracle') {
            return new OracleLeaveRepository();
        }

        throw new RuntimeException(
            'No leave repository is available for the configured database driver.'
        );
    }

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
