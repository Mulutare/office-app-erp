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
                TO_CHAR(
                    check_in_at,
                    \'YYYY-MM-DD HH24:MI:SS\'
                ) AS check_in_at,
                TO_CHAR(
                    check_out_at,
                    \'YYYY-MM-DD HH24:MI:SS\'
                ) AS check_out_at,
                source,
                created_by,
                updated_by,
                created_at,
                updated_at
             FROM attendance_sessions
             WHERE company_id = :company_id
               AND attendance_id = :attendance_id
               AND active = 1
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
                TO_CHAR(
                    check_in_at,
                    \'YYYY-MM-DD HH24:MI:SS\'
                ) AS check_in_at,
                TO_CHAR(
                    check_out_at,
                    \'YYYY-MM-DD HH24:MI:SS\'
                ) AS check_out_at,
                source
             FROM attendance_sessions
             WHERE company_id = :company_id
               AND attendance_id = :attendance_id
               AND active = 1
               AND check_out_at IS NULL
             FETCH FIRST 1 ROWS ONLY'
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
                    TO_TIMESTAMP(
                        :check_in_at,
                        \'YYYY-MM-DD HH24:MI:SS\'
                    ),
                    NULL,
                    1,
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
        $lookup = $this->connection()->prepare(
            'SELECT session_id
             FROM attendance_sessions
             WHERE company_id = :company_id
               AND attendance_id = :attendance_id
               AND sequence_no = :sequence_no
             FETCH FIRST 1 ROWS ONLY'
        );
        $lookup->execute([
            'company_id' => $companyId,
            'attendance_id' => $attendanceId,
            'sequence_no' => $sequence,
        ]);

        return (int) $lookup->fetchColumn();
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
             SET check_out_at = TO_TIMESTAMP(
                    :check_out_at,
                    \'YYYY-MM-DD HH24:MI:SS\'
                 ),
                 updated_by = :updated_by,
                 updated_at = SYSTIMESTAMP
             WHERE company_id = :company_id
               AND attendance_id = :attendance_id
               AND session_id = :session_id
               AND active = 1
               AND check_out_at IS NULL
               AND check_in_at <= TO_TIMESTAMP(
                    :check_out_limit,
                    \'YYYY-MM-DD HH24:MI:SS\'
               )'
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
            'SELECT session_id,
                    TO_CHAR(
                        check_in_at,
                        \'YYYY-MM-DD HH24:MI:SS\'
                    ) AS check_in_at
             FROM attendance_sessions current_session
             WHERE current_session.company_id = :company_id
               AND current_session.attendance_id = :attendance_id
               AND current_session.active = 1
               AND current_session.sequence_no = (
                    SELECT MAX(latest.sequence_no)
                    FROM attendance_sessions latest
                    WHERE latest.company_id =
                            current_session.company_id
                      AND latest.attendance_id =
                            current_session.attendance_id
                      AND latest.active = 1
               )
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
                 SET check_out_at = TO_TIMESTAMP(
                        :check_out_at,
                        \'YYYY-MM-DD HH24:MI:SS\'
                     ),
                     source = :source,
                     updated_by = :updated_by,
                     updated_at = SYSTIMESTAMP
                 WHERE company_id = :company_id
                   AND attendance_id = :attendance_id
                   AND session_id = :session_id
                   AND active = 1'
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
             SET active = 0,
                 invalidated_at = SYSTIMESTAMP,
                 invalidated_by = :invalidated_by,
                 updated_by = :updated_by,
                 updated_at = SYSTIMESTAMP
             WHERE company_id = :company_id
               AND attendance_id = :attendance_id
               AND active = 1'
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
             FETCH FIRST 1 ROWS ONLY'
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
                    TO_DATE(
                        :attendance_date,
                        \'YYYY-MM-DD\'
                    ),
                    :request_key,
                    TO_TIMESTAMP(
                        :scanned_at,
                        \'YYYY-MM-DD HH24:MI:SS\'
                    ),
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
        $event = $this->scanEventByRequestKey(
            $companyId,
            $employeeId,
            (string) $values['request_key']
        );

        return (int) ($event['event_id'] ?? 0);
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
             SET active = 0,
                 invalidated_at = SYSTIMESTAMP,
                 invalidated_by = :invalidated_by,
                 updated_by = :updated_by,
                 updated_at = SYSTIMESTAMP
             WHERE company_id = :company_id
               AND attendance_id = :attendance_id
               AND active = 1'
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
                    TO_TIMESTAMP(
                        :check_in_at,
                        \'YYYY-MM-DD HH24:MI:SS\'
                    ),
                    TO_TIMESTAMP(
                        :check_out_at,
                        \'YYYY-MM-DD HH24:MI:SS\'
                    ),
                    1,
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
                gross_minutes,
                break_minutes,
                target_work_minutes,
                work_variance_minutes,
                schedule_calendar_id,
                schedule_timezone,
                TO_CHAR(
                    scheduled_start_at,
                    \'YYYY-MM-DD HH24:MI:SS\'
                ) AS scheduled_start_at,
                TO_CHAR(
                    scheduled_end_at,
                    \'YYYY-MM-DD HH24:MI:SS\'
                ) AS scheduled_end_at,
                TO_CHAR(
                    scan_window_start_at,
                    \'YYYY-MM-DD HH24:MI:SS\'
                ) AS scan_window_start_at,
                TO_CHAR(
                    scan_window_end_at,
                    \'YYYY-MM-DD HH24:MI:SS\'
                ) AS scan_window_end_at,
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
               AND attendance_date = TO_DATE(
                    :attendance_date,
                    \'YYYY-MM-DD\'
               )'
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
             FETCH FIRST 1 ROWS ONLY'
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
                    :gross_minutes AS gross_minutes,
                    :break_minutes AS break_minutes,
                    :target_work_minutes
                        AS target_work_minutes,
                    :work_variance_minutes
                        AS work_variance_minutes,
                    :schedule_calendar_id
                        AS schedule_calendar_id,
                    :schedule_timezone
                        AS schedule_timezone,
                    TO_TIMESTAMP(
                        :scheduled_start_at,
                        \'YYYY-MM-DD HH24:MI:SS\'
                    ) AS scheduled_start_at,
                    TO_TIMESTAMP(
                        :scheduled_end_at,
                        \'YYYY-MM-DD HH24:MI:SS\'
                    ) AS scheduled_end_at,
                    TO_TIMESTAMP(
                        :scan_window_start_at,
                        \'YYYY-MM-DD HH24:MI:SS\'
                    ) AS scan_window_start_at,
                    TO_TIMESTAMP(
                        :scan_window_end_at,
                        \'YYYY-MM-DD HH24:MI:SS\'
                    ) AS scan_window_end_at,
                    :department_id_snapshot
                        AS department_id_snapshot,
                    :department_name_snapshot
                        AS department_name_snapshot,
                    :late_minutes AS late_minutes,
                    :early_departure_minutes
                        AS early_departure_minutes,
                    :missing_clock_out
                        AS missing_clock_out,
                    :schedule_snapshot_json
                        AS schedule_snapshot_json,
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
                target.gross_minutes =
                    source.gross_minutes,
                target.break_minutes =
                    source.break_minutes,
                target.target_work_minutes =
                    source.target_work_minutes,
                target.work_variance_minutes =
                    source.work_variance_minutes,
                target.late_minutes =
                    source.late_minutes,
                target.early_departure_minutes =
                    source.early_departure_minutes,
                target.missing_clock_out =
                    source.missing_clock_out,
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
             ) VALUES (
                source.company_id,
                source.employee_id,
                source.attendance_date,
                source.check_in_at,
                source.check_out_at,
                source.attendance_status,
                source.work_minutes,
                source.gross_minutes,
                source.break_minutes,
                source.target_work_minutes,
                source.work_variance_minutes,
                source.schedule_calendar_id,
                source.schedule_timezone,
                source.scheduled_start_at,
                source.scheduled_end_at,
                source.scan_window_start_at,
                source.scan_window_end_at,
                source.department_id_snapshot,
                source.department_name_snapshot,
                source.late_minutes,
                source.early_departure_minutes,
                source.missing_clock_out,
                source.schedule_snapshot_json,
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
