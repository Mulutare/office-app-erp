<?php

declare(strict_types=1);

namespace App\Repositories\Oracle;

use App\Repositories\ManagerTeamRepository
    as ManagerTeamRepositoryContract;

final class ManagerTeamRepository extends OracleRepository
    implements ManagerTeamRepositoryContract
{
    public function reportingContext(
        int $companyId,
        int $userId
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                memberships.user_id,
                memberships.manager_user_id,
                users.display_name,
                users.email,
                employees.employee_id,
                employees.employee_number,
                employees.first_name,
                employees.last_name,
                employees.preferred_name,
                employees.job_title,
                departments.name AS department_name,
                manager_user.display_name
                    AS manager_display_name,
                manager_user.email AS manager_email,
                manager_employee.employee_number
                    AS manager_employee_number,
                manager_employee.job_title
                    AS manager_job_title
             FROM company_users memberships
             INNER JOIN users
               ON users.user_id = memberships.user_id
             LEFT JOIN hr_employees employees
               ON employees.company_id =
                    memberships.company_id
              AND employees.user_id =
                    memberships.user_id
              AND employees.deleted_at IS NULL
             LEFT JOIN hr_departments departments
               ON departments.company_id =
                    employees.company_id
              AND departments.department_id =
                    employees.department_id
              AND departments.deleted_at IS NULL
             LEFT JOIN users manager_user
               ON manager_user.user_id =
                    memberships.manager_user_id
              AND manager_user.deleted_at IS NULL
             LEFT JOIN hr_employees manager_employee
               ON manager_employee.company_id =
                    memberships.company_id
              AND manager_employee.user_id =
                    memberships.manager_user_id
              AND manager_employee.deleted_at IS NULL
             WHERE memberships.company_id =
                    :company_id
               AND memberships.user_id = :user_id
               AND memberships.active = 1
               AND users.active = 1
               AND users.deleted_at IS NULL
             FETCH FIRST 1 ROWS ONLY'
        );
        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
        ]);
        $context = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($context)
            ? $context
            : null;
    }

    public function directReports(
        int $companyId,
        int $managerUserId,
        string $attendanceDate
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                memberships.user_id,
                users.display_name,
                users.email,
                users.last_login_at,
                employees.employee_id,
                employees.employee_number,
                employees.first_name,
                employees.last_name,
                employees.preferred_name,
                employees.job_title,
                employees.employment_status,
                departments.name AS department_name,
                attendance.attendance_status,
                attendance.check_in_at,
                attendance.check_out_at,
                attendance.work_minutes,
                (
                    SELECT COUNT(*)
                    FROM hr_leave_requests requests
                    WHERE requests.company_id =
                            memberships.company_id
                      AND requests.employee_id =
                            employees.employee_id
                      AND requests.request_status =
                            \'pending\'
                ) AS pending_leave_count,
                (
                    SELECT MIN(upcoming.start_date)
                    FROM hr_leave_requests upcoming
                    WHERE upcoming.company_id =
                            memberships.company_id
                      AND upcoming.employee_id =
                            employees.employee_id
                      AND upcoming.request_status =
                            \'approved\'
                      AND upcoming.end_date >=
                            TO_DATE(
                                :upcoming_date,
                                \'YYYY-MM-DD\'
                            )
                ) AS next_leave_date
             FROM company_users memberships
             INNER JOIN users
               ON users.user_id = memberships.user_id
             LEFT JOIN hr_employees employees
               ON employees.company_id =
                    memberships.company_id
              AND employees.user_id =
                    memberships.user_id
              AND employees.deleted_at IS NULL
             LEFT JOIN hr_departments departments
               ON departments.company_id =
                    employees.company_id
              AND departments.department_id =
                    employees.department_id
              AND departments.deleted_at IS NULL
             LEFT JOIN attendance_records attendance
               ON attendance.company_id =
                    memberships.company_id
              AND attendance.employee_id =
                    employees.employee_id
              AND attendance.attendance_date =
                    TO_DATE(
                        :attendance_date,
                        \'YYYY-MM-DD\'
                    )
             WHERE memberships.company_id =
                    :company_id
               AND memberships.manager_user_id =
                    :manager_user_id
               AND memberships.active = 1
               AND users.active = 1
               AND users.deleted_at IS NULL
             ORDER BY
                users.display_name,
                users.username
             FETCH FIRST 250 ROWS ONLY'
        );
        $statement->execute([
            'company_id' => $companyId,
            'manager_user_id' => $managerUserId,
            'attendance_date' => $attendanceDate,
            'upcoming_date' => $attendanceDate,
        ]);
        $reports = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($reports)
            ? $reports
            : [];
    }
}
