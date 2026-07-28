<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\AttendanceRepository
    as AttendanceRepositoryContract;

final class AttendanceRepository extends MySqlRepository
    implements AttendanceRepositoryContract
{
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
                attendance.attendance_date,
                attendance.check_in_at,
                attendance.check_out_at,
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
                    :attendance_date
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
                attendance_date,
                check_in_at,
                check_out_at,
                attendance_status,
                work_minutes,
                source,
                notes
             FROM attendance_records
             WHERE company_id = :company_id
               AND employee_id = :employee_id
               AND attendance_date =
                    :attendance_date
             LIMIT 1'
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
             LIMIT 1'
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
            'INSERT INTO attendance_records
                (
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
                )
             VALUES
                (
                    :company_id,
                    :employee_id,
                    :attendance_date,
                    :check_in_at,
                    :check_out_at,
                    :attendance_status,
                    :work_minutes,
                    :source,
                    :notes,
                    :created_by,
                    :updated_by
                )
             ON DUPLICATE KEY UPDATE
                check_in_at = VALUES(check_in_at),
                check_out_at = VALUES(check_out_at),
                attendance_status =
                    VALUES(attendance_status),
                work_minutes = VALUES(work_minutes),
                source = VALUES(source),
                notes = VALUES(notes),
                updated_by = VALUES(updated_by)'
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
            'created_by' => $updatedBy,
            'updated_by' => $updatedBy,
        ]);

        $lookup = $this->find(
            $companyId,
            $employeeId,
            $attendanceDate
        );

        return (int) (
            $lookup['attendance_id'] ?? 0
        );
    }
}
