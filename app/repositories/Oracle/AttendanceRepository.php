<?php

declare(strict_types=1);

namespace App\Repositories\Oracle;

use App\Repositories\AttendanceRepository
    as AttendanceRepositoryContract;

final class AttendanceRepository extends OracleRepository
    implements AttendanceRepositoryContract
{
    public function employeeForUser(
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
                employees.employment_status,
                departments.name AS department_name,
                manager.display_name
                    AS manager_display_name,
                manager.email AS manager_email
             FROM company_users memberships
             INNER JOIN users
               ON users.user_id = memberships.user_id
             LEFT JOIN hr_employees employees
               ON employees.company_id =
                    memberships.company_id
              AND employees.user_id =
                    memberships.user_id
              AND employees.employment_status
                    IN (\'active\', \'on_leave\')
              AND employees.deleted_at IS NULL
             LEFT JOIN hr_departments departments
               ON departments.company_id =
                    employees.company_id
              AND departments.department_id =
                    employees.department_id
              AND departments.deleted_at IS NULL
             LEFT JOIN users manager
               ON manager.user_id =
                    memberships.manager_user_id
              AND manager.deleted_at IS NULL
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
        $employee = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($employee)
            ? $employee
            : null;
    }

    public function historyForEmployee(
        int $companyId,
        int $employeeId,
        string $fromDate,
        string $toDate
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                attendance.attendance_id,
                attendance.employee_id,
                TO_CHAR(
                    attendance.attendance_date,
                    \'YYYY-MM-DD\'
                ) AS attendance_date,
                TO_CHAR(
                    attendance.check_in_at,
                    \'YYYY-MM-DD HH24:MI:SS\'
                ) AS check_in_at,
                TO_CHAR(
                    attendance.check_out_at,
                    \'YYYY-MM-DD HH24:MI:SS\'
                ) AS check_out_at,
                attendance.attendance_status,
                attendance.work_minutes,
                attendance.source,
                attendance.notes,
                attendance.updated_at
             FROM attendance_records attendance
             WHERE attendance.company_id =
                    :company_id
               AND attendance.employee_id =
                    :employee_id
               AND attendance.attendance_date
                    BETWEEN TO_DATE(
                        :from_date,
                        \'YYYY-MM-DD\'
                    )
                    AND TO_DATE(
                        :to_date,
                        \'YYYY-MM-DD\'
                    )
             ORDER BY attendance.attendance_date DESC'
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]);
        $records = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($records)
            ? $records
            : [];
    }

    public function historyForManager(
        int $companyId,
        int $managerUserId,
        string $fromDate,
        string $toDate
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                memberships.user_id,
                users.display_name,
                users.email,
                employees.employee_id,
                employees.employee_number,
                employees.first_name,
                employees.last_name,
                employees.preferred_name,
                employees.job_title,
                departments.name AS department_name,
                attendance.attendance_id,
                TO_CHAR(
                    attendance.attendance_date,
                    \'YYYY-MM-DD\'
                ) AS attendance_date,
                TO_CHAR(
                    attendance.check_in_at,
                    \'YYYY-MM-DD HH24:MI:SS\'
                ) AS check_in_at,
                TO_CHAR(
                    attendance.check_out_at,
                    \'YYYY-MM-DD HH24:MI:SS\'
                ) AS check_out_at,
                attendance.attendance_status,
                attendance.work_minutes,
                attendance.source,
                attendance.notes
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
              AND attendance.attendance_date
                    BETWEEN TO_DATE(
                        :from_date,
                        \'YYYY-MM-DD\'
                    )
                    AND TO_DATE(
                        :to_date,
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
                attendance.attendance_date DESC'
        );
        $statement->execute([
            'company_id' => $companyId,
            'manager_user_id' => $managerUserId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]);
        $records = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($records)
            ? $records
            : [];
    }

    public function dailyRoster(
        int $companyId,
        string $attendanceDate
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                employees.employee_id,
                employees.employee_number,
                employees.first_name,
                employees.last_name,
                employees.preferred_name,
                employees.job_title,
                departments.name AS department_name,
                attendance.attendance_id,
                TO_CHAR(
                    attendance.attendance_date,
                    \'YYYY-MM-DD\'
                ) AS attendance_date,
                TO_CHAR(
                    attendance.check_in_at,
                    \'YYYY-MM-DD HH24:MI:SS\'
                ) AS check_in_at,
                TO_CHAR(
                    attendance.check_out_at,
                    \'YYYY-MM-DD HH24:MI:SS\'
                ) AS check_out_at,
                attendance.attendance_status,
                attendance.work_minutes,
                attendance.source,
                attendance.notes,
                attendance.updated_at
             FROM hr_employees employees
             LEFT JOIN hr_departments departments
               ON departments.company_id =
                    employees.company_id
              AND departments.department_id =
                    employees.department_id
              AND departments.deleted_at IS NULL
             LEFT JOIN attendance_records attendance
               ON attendance.company_id =
                    employees.company_id
              AND attendance.employee_id =
                    employees.employee_id
              AND attendance.attendance_date =
                    TO_DATE(
                        :attendance_date,
                        \'YYYY-MM-DD\'
                    )
             WHERE employees.company_id =
                    :company_id
               AND employees.employment_status
                    IN (\'active\', \'on_leave\')
               AND employees.deleted_at IS NULL
             ORDER BY
                employees.last_name,
                employees.first_name,
                employees.employee_id'
        );
        $statement->execute([
            'company_id' => $companyId,
            'attendance_date' => $attendanceDate,
        ]);
        $records = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($records)
            ? $records
            : [];
    }

    public function find(
        int $companyId,
        int $employeeId,
        string $attendanceDate
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                attendance_id,
                company_id,
                employee_id,
                TO_CHAR(
                    attendance_date,
                    \'YYYY-MM-DD\'
                ) AS attendance_date,
                TO_CHAR(
                    check_in_at,
                    \'YYYY-MM-DD HH24:MI:SS\'
                ) AS check_in_at,
                TO_CHAR(
                    check_out_at,
                    \'YYYY-MM-DD HH24:MI:SS\'
                ) AS check_out_at,
                attendance_status,
                work_minutes,
                source,
                notes
             FROM attendance_records
             WHERE company_id = :company_id
               AND employee_id = :employee_id
               AND attendance_date = TO_DATE(
                    :attendance_date,
                    \'YYYY-MM-DD\'
               )
             FETCH FIRST 1 ROWS ONLY'
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'attendance_date' => $attendanceDate,
        ]);
        $record = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($record)
            ? $record
            : null;
    }

    public function employeeExists(
        int $companyId,
        int $employeeId
    ): bool {
        $statement = $this->connection()->prepare(
            'SELECT 1
             FROM hr_employees
             WHERE company_id = :company_id
               AND employee_id = :employee_id
               AND employment_status
                    IN (\'active\', \'on_leave\')
               AND deleted_at IS NULL
             FETCH FIRST 1 ROWS ONLY'
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function save(
        int $companyId,
        int $employeeId,
        string $attendanceDate,
        array $values,
        int $updatedBy
    ): int {
        $statement = $this->connection()->prepare(
            'MERGE INTO attendance_records target
             USING (
                SELECT
                    :company_id AS company_id,
                    :employee_id AS employee_id,
                    TO_DATE(
                        :attendance_date,
                        \'YYYY-MM-DD\'
                    ) AS attendance_date,
                    TO_TIMESTAMP(
                        :check_in_at,
                        \'YYYY-MM-DD HH24:MI:SS\'
                    ) AS check_in_at,
                    TO_TIMESTAMP(
                        :check_out_at,
                        \'YYYY-MM-DD HH24:MI:SS\'
                    ) AS check_out_at,
                    :attendance_status
                        AS attendance_status,
                    :work_minutes AS work_minutes,
                    :source AS source,
                    :notes AS notes,
                    :updated_by AS updated_by
                FROM dual
             ) source
             ON (
                target.company_id = source.company_id
                AND target.employee_id =
                    source.employee_id
                AND target.attendance_date =
                    source.attendance_date
             )
             WHEN MATCHED THEN UPDATE SET
                target.check_in_at =
                    source.check_in_at,
                target.check_out_at =
                    source.check_out_at,
                target.attendance_status =
                    source.attendance_status,
                target.work_minutes =
                    source.work_minutes,
                target.source = source.source,
                target.notes = source.notes,
                target.updated_by =
                    source.updated_by,
                target.updated_at = SYSTIMESTAMP
             WHEN NOT MATCHED THEN INSERT (
                company_id,
                employee_id,
                attendance_date,
                check_in_at,
                check_out_at,
                attendance_status,
                work_minutes,
                source,
                notes,
                created_by,
                updated_by
             ) VALUES (
                source.company_id,
                source.employee_id,
                source.attendance_date,
                source.check_in_at,
                source.check_out_at,
                source.attendance_status,
                source.work_minutes,
                source.source,
                source.notes,
                source.updated_by,
                source.updated_by
             )'
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'attendance_date' => $attendanceDate,
            'check_in_at' => $values['check_in_at'],
            'check_out_at' => $values['check_out_at'],
            'attendance_status' =>
                $values['attendance_status'],
            'work_minutes' => $values['work_minutes'],
            'source' => $values['source'],
            'notes' => $values['notes'],
            'updated_by' => $updatedBy,
        ]);

        $record = $this->find(
            $companyId,
            $employeeId,
            $attendanceDate
        );

        return (int) (
            $record['attendance_id'] ?? 0
        );
    }
}
