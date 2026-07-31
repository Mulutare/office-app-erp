<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\AttendanceRepository
    as AttendanceRepositoryContract;

final class AttendanceRepository extends MySqlRepository
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
                employees.department_id,
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
               AND memberships.active = TRUE
               AND users.active = TRUE
               AND users.deleted_at IS NULL
             LIMIT 1'
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
                attendance.attendance_date,
                attendance.check_in_at,
                attendance.check_out_at,
                attendance.attendance_status,
                attendance.work_minutes,
                attendance.gross_minutes,
                attendance.break_minutes,
                attendance.target_work_minutes,
                attendance.work_variance_minutes,
                attendance.source,
                attendance.notes,
                attendance.updated_at
             FROM attendance_records attendance
             WHERE attendance.company_id =
                    :company_id
               AND attendance.employee_id =
                    :employee_id
               AND attendance.attendance_date
                    BETWEEN :from_date AND :to_date
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
                attendance.attendance_date,
                attendance.check_in_at,
                attendance.check_out_at,
                attendance.attendance_status,
                attendance.work_minutes,
                attendance.gross_minutes,
                attendance.break_minutes,
                attendance.target_work_minutes,
                attendance.work_variance_minutes,
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
                    BETWEEN :from_date AND :to_date
             WHERE memberships.company_id =
                    :company_id
               AND memberships.manager_user_id =
                    :manager_user_id
               AND memberships.active = TRUE
               AND users.active = TRUE
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
                attendance.attendance_date,
                attendance.check_in_at,
                attendance.check_out_at,
                attendance.attendance_status,
                attendance.work_minutes,
                attendance.gross_minutes,
                attendance.break_minutes,
                attendance.target_work_minutes,
                attendance.work_variance_minutes,
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
        return $this->findRecord(
            $companyId,
            $employeeId,
            $attendanceDate,
            false
        );
    }

    public function findForUpdate(
        int $companyId,
        int $employeeId,
        string $attendanceDate
    ): ?array {
        return $this->findRecord(
            $companyId,
            $employeeId,
            $attendanceDate,
            true
        );
    }

    public function sessionsForRecord(
        int $companyId,
        int $attendanceId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                session_id,
                attendance_id,
                company_id,
                employee_id,
                sequence_no,
                check_in_at,
                check_out_at,
                source,
                created_by,
                updated_by,
                created_at,
                updated_at
             FROM attendance_sessions
             WHERE company_id = :company_id
               AND attendance_id = :attendance_id
               AND active = TRUE
             ORDER BY sequence_no'
        );
        $statement->execute([
            'company_id' => $companyId,
            'attendance_id' => $attendanceId,
        ]);
        $sessions = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($sessions)
            ? $sessions
            : [];
    }

    public function openSession(
        int $companyId,
        int $attendanceId
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                session_id,
                attendance_id,
                company_id,
                employee_id,
                sequence_no,
                check_in_at,
                check_out_at,
                source
             FROM attendance_sessions
             WHERE company_id = :company_id
               AND attendance_id = :attendance_id
               AND active = TRUE
               AND check_out_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'attendance_id' => $attendanceId,
        ]);
        $session = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($session)
            ? $session
            : null;
    }

    public function startSession(
        int $companyId,
        int $attendanceId,
        int $employeeId,
        string $checkInAt,
        string $source,
        int $actorUserId
    ): int {
        $sequence = $this->nextSessionSequence(
            $companyId,
            $attendanceId
        );
        $statement = $this->connection()->prepare(
            'INSERT INTO attendance_sessions
                (
                    attendance_id,
                    company_id,
                    employee_id,
                    sequence_no,
                    check_in_at,
                    check_out_at,
                    active,
                    source,
                    created_by,
                    updated_by
                )
             VALUES
                (
                    :attendance_id,
                    :company_id,
                    :employee_id,
                    :sequence_no,
                    :check_in_at,
                    NULL,
                    TRUE,
                    :source,
                    :created_by,
                    :updated_by
                )'
        );
        $statement->execute([
            'attendance_id' => $attendanceId,
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'sequence_no' => $sequence,
            'check_in_at' => $checkInAt,
            'source' => $source,
            'created_by' => $actorUserId,
            'updated_by' => $actorUserId,
        ]);

        return (int) $this->connection()
            ->lastInsertId();
    }

    public function finishSession(
        int $companyId,
        int $attendanceId,
        int $sessionId,
        string $checkOutAt,
        int $actorUserId
    ): bool {
        $statement = $this->connection()->prepare(
            'UPDATE attendance_sessions
             SET check_out_at = :check_out_at,
                 updated_by = :updated_by
             WHERE company_id = :company_id
               AND attendance_id = :attendance_id
               AND session_id = :session_id
               AND active = TRUE
               AND check_out_at IS NULL
               AND check_in_at <= :check_out_limit'
        );
        $statement->execute([
            'check_out_at' => $checkOutAt,
            'check_out_limit' => $checkOutAt,
            'updated_by' => $actorUserId,
            'company_id' => $companyId,
            'attendance_id' => $attendanceId,
            'session_id' => $sessionId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function syncFirstLastSession(
        int $companyId,
        int $attendanceId,
        int $employeeId,
        string $checkInAt,
        ?string $checkOutAt,
        string $source,
        int $actorUserId
    ): int {
        $current = $this->connection()->prepare(
            'SELECT session_id, check_in_at
             FROM attendance_sessions
             WHERE company_id = :company_id
               AND attendance_id = :attendance_id
               AND active = TRUE
             ORDER BY sequence_no DESC
             LIMIT 1
             FOR UPDATE'
        );
        $current->execute([
            'company_id' => $companyId,
            'attendance_id' => $attendanceId,
        ]);
        $session = $current->fetch(\PDO::FETCH_ASSOC);

        if (
            is_array($session)
            && (string) $session['check_in_at']
                === $checkInAt
        ) {
            $update = $this->connection()->prepare(
                'UPDATE attendance_sessions
                 SET check_out_at = :check_out_at,
                     source = :source,
                     updated_by = :updated_by
                 WHERE company_id = :company_id
                   AND attendance_id = :attendance_id
                   AND session_id = :session_id
                   AND active = TRUE'
            );
            $update->execute([
                'check_out_at' => $checkOutAt,
                'source' => $source,
                'updated_by' => $actorUserId,
                'company_id' => $companyId,
                'attendance_id' => $attendanceId,
                'session_id' => (int) $session['session_id'],
            ]);

            return (int) $session['session_id'];
        }

        $invalidate = $this->connection()->prepare(
            'UPDATE attendance_sessions
             SET active = FALSE,
                 invalidated_at = CURRENT_TIMESTAMP,
                 invalidated_by = :invalidated_by,
                 updated_by = :updated_by
             WHERE company_id = :company_id
               AND attendance_id = :attendance_id
               AND active = TRUE'
        );
        $invalidate->execute([
            'invalidated_by' => $actorUserId,
            'updated_by' => $actorUserId,
            'company_id' => $companyId,
            'attendance_id' => $attendanceId,
        ]);
        $sessionId = $this->startSession(
            $companyId,
            $attendanceId,
            $employeeId,
            $checkInAt,
            $source,
            $actorUserId
        );

        if ($checkOutAt !== null) {
            $this->finishSession(
                $companyId,
                $attendanceId,
                $sessionId,
                $checkOutAt,
                $actorUserId
            );
        }

        return $sessionId;
    }

    public function scanEventByRequestKey(
        int $companyId,
        int $employeeId,
        string $requestKey
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT *
             FROM attendance_scan_events
             WHERE company_id = :company_id
               AND employee_id = :employee_id
               AND request_key = :request_key
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'request_key' => $requestKey,
        ]);
        $event = $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($event) ? $event : null;
    }

    public function appendScanEvent(
        int $companyId,
        int $employeeId,
        array $values
    ): int {
        $statement = $this->connection()->prepare(
            'INSERT INTO attendance_scan_events
                (
                    company_id,
                    employee_id,
                    attendance_id,
                    attendance_date,
                    request_key,
                    scanned_at,
                    timezone,
                    event_type,
                    source,
                    device_reference,
                    processing_result,
                    result_reason,
                    actor_user_id
                )
             VALUES
                (
                    :company_id,
                    :employee_id,
                    :attendance_id,
                    :attendance_date,
                    :request_key,
                    :scanned_at,
                    :timezone,
                    :event_type,
                    :source,
                    :device_reference,
                    :processing_result,
                    :result_reason,
                    :actor_user_id
                )'
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'attendance_id' => $values['attendance_id'] ?? null,
            'attendance_date' => $values['attendance_date'],
            'request_key' => $values['request_key'],
            'scanned_at' => $values['scanned_at'],
            'timezone' => $values['timezone'],
            'event_type' => $values['event_type'],
            'source' => $values['source'],
            'device_reference' =>
                $values['device_reference'] ?? null,
            'processing_result' =>
                $values['processing_result'],
            'result_reason' =>
                $values['result_reason'] ?? null,
            'actor_user_id' =>
                $values['actor_user_id'] ?? null,
        ]);

        return (int) $this->connection()->lastInsertId();
    }

    public function scanEventsForRecord(
        int $companyId,
        int $attendanceId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT *
             FROM attendance_scan_events
             WHERE company_id = :company_id
               AND attendance_id = :attendance_id
             ORDER BY scanned_at, event_id'
        );
        $statement->execute([
            'company_id' => $companyId,
            'attendance_id' => $attendanceId,
        ]);
        $events = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($events) ? $events : [];
    }

    public function replaceSessionsForManualRecord(
        int $companyId,
        int $attendanceId,
        int $employeeId,
        ?string $checkInAt,
        ?string $checkOutAt,
        int $actorUserId
    ): void {
        $invalidate = $this->connection()->prepare(
            'UPDATE attendance_sessions
             SET active = FALSE,
                 invalidated_at = CURRENT_TIMESTAMP,
                 invalidated_by = :invalidated_by,
                 updated_by = :updated_by
             WHERE company_id = :company_id
               AND attendance_id = :attendance_id
               AND active = TRUE'
        );
        $invalidate->execute([
            'invalidated_by' => $actorUserId,
            'updated_by' => $actorUserId,
            'company_id' => $companyId,
            'attendance_id' => $attendanceId,
        ]);

        if ($checkInAt === null) {
            return;
        }

        $sequence = $this->nextSessionSequence(
            $companyId,
            $attendanceId
        );
        $statement = $this->connection()->prepare(
            'INSERT INTO attendance_sessions
                (
                    attendance_id,
                    company_id,
                    employee_id,
                    sequence_no,
                    check_in_at,
                    check_out_at,
                    active,
                    source,
                    created_by,
                    updated_by
                )
             VALUES
                (
                    :attendance_id,
                    :company_id,
                    :employee_id,
                    :sequence_no,
                    :check_in_at,
                    :check_out_at,
                    TRUE,
                    \'manual\',
                    :created_by,
                    :updated_by
                )'
        );
        $statement->execute([
            'attendance_id' => $attendanceId,
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'sequence_no' => $sequence,
            'check_in_at' => $checkInAt,
            'check_out_at' => $checkOutAt,
            'created_by' => $actorUserId,
            'updated_by' => $actorUserId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRecord(
        int $companyId,
        int $employeeId,
        string $attendanceDate,
        bool $lock
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
                gross_minutes,
                break_minutes,
                target_work_minutes,
                work_variance_minutes,
                schedule_calendar_id,
                schedule_timezone,
                scheduled_start_at,
                scheduled_end_at,
                scan_window_start_at,
                scan_window_end_at,
                department_id_snapshot,
                department_name_snapshot,
                late_minutes,
                early_departure_minutes,
                missing_clock_out,
                schedule_snapshot_json,
                source,
                notes
             FROM attendance_records
             WHERE company_id = :company_id
               AND employee_id = :employee_id
               AND attendance_date =
                    :attendance_date
             LIMIT 1'
            . ($lock ? ' FOR UPDATE' : '')
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

    private function nextSessionSequence(
        int $companyId,
        int $attendanceId
    ): int {
        $statement = $this->connection()->prepare(
            'SELECT COALESCE(MAX(sequence_no), 0) + 1
             FROM attendance_sessions
             WHERE company_id = :company_id
               AND attendance_id = :attendance_id'
        );
        $statement->execute([
            'company_id' => $companyId,
            'attendance_id' => $attendanceId,
        ]);

        return (int) $statement->fetchColumn();
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

    public function lockEmployee(
        int $companyId,
        int $employeeId
    ): bool {
        $statement = $this->connection()->prepare(
            'SELECT employee_id
             FROM hr_employees
             WHERE company_id = :company_id
               AND employee_id = :employee_id
               AND deleted_at IS NULL
             FOR UPDATE'
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
                    gross_minutes,
                    break_minutes,
                    target_work_minutes,
                    work_variance_minutes,
                    schedule_calendar_id,
                    schedule_timezone,
                    scheduled_start_at,
                    scheduled_end_at,
                    scan_window_start_at,
                    scan_window_end_at,
                    department_id_snapshot,
                    department_name_snapshot,
                    late_minutes,
                    early_departure_minutes,
                    missing_clock_out,
                    schedule_snapshot_json,
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
                    :gross_minutes,
                    :break_minutes,
                    :target_work_minutes,
                    :work_variance_minutes,
                    :schedule_calendar_id,
                    :schedule_timezone,
                    :scheduled_start_at,
                    :scheduled_end_at,
                    :scan_window_start_at,
                    :scan_window_end_at,
                    :department_id_snapshot,
                    :department_name_snapshot,
                    :late_minutes,
                    :early_departure_minutes,
                    :missing_clock_out,
                    :schedule_snapshot_json,
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
                gross_minutes = VALUES(gross_minutes),
                break_minutes = VALUES(break_minutes),
                target_work_minutes =
                    VALUES(target_work_minutes),
                work_variance_minutes =
                    VALUES(work_variance_minutes),
                late_minutes = VALUES(late_minutes),
                early_departure_minutes =
                    VALUES(early_departure_minutes),
                missing_clock_out =
                    VALUES(missing_clock_out),
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
            'gross_minutes' =>
                $values['gross_minutes'] ?? 0,
            'break_minutes' =>
                $values['break_minutes'] ?? 0,
            'target_work_minutes' =>
                $values['target_work_minutes'] ?? 0,
            'work_variance_minutes' =>
                $values['work_variance_minutes'] ?? 0,
            'schedule_calendar_id' =>
                $values['schedule_calendar_id'] ?? null,
            'schedule_timezone' =>
                $values['schedule_timezone'] ?? null,
            'scheduled_start_at' =>
                $values['scheduled_start_at'] ?? null,
            'scheduled_end_at' =>
                $values['scheduled_end_at'] ?? null,
            'scan_window_start_at' =>
                $values['scan_window_start_at'] ?? null,
            'scan_window_end_at' =>
                $values['scan_window_end_at'] ?? null,
            'department_id_snapshot' =>
                $values['department_id_snapshot'] ?? null,
            'department_name_snapshot' =>
                $values['department_name_snapshot'] ?? null,
            'late_minutes' =>
                $values['late_minutes'] ?? 0,
            'early_departure_minutes' =>
                $values['early_departure_minutes'] ?? 0,
            'missing_clock_out' =>
                !empty($values['missing_clock_out']) ? 1 : 0,
            'schedule_snapshot_json' =>
                $values['schedule_snapshot_json'] ?? null,
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
