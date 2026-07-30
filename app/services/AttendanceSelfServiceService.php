<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AttendanceRepository;
use App\Repositories\AuditLogWriter;
use App\Repositories\RepositoryFactory;
use DateTimeImmutable;
use Throwable;

final class AttendanceSelfServiceService
{
    private const STATUSES = [
        'present' => ['Present', 'success'],
        'late' => ['Late', 'warning'],
        'absent' => ['Absent', 'danger'],
        'remote' => ['Remote', 'info'],
        'on_leave' => ['On leave', 'muted'],
        'holiday' => ['Holiday', 'muted'],
    ];

    private AttendanceRepository $attendance;
    private AuditLogWriter $auditLogs;
    private TenantContext $tenant;

    public function __construct(
        ?AttendanceRepository $attendance = null,
        ?AuditLogWriter $auditLogs = null,
        ?TenantContext $tenant = null
    ) {
        $this->attendance = $attendance
            ?? RepositoryFactory::attendance();
        $this->auditLogs = $auditLogs
            ?? RepositoryFactory::auditLogs();
        $this->tenant = $tenant
            ?? new TenantContext();
    }

    /**
     * @return array<string, mixed>
     */
    public function workspace(
        int $actorUserId,
        string $month
    ): array {
        $range = $this->monthRange($month);
        $companyId = $this->tenant->companyId();
        $employee = $this->attendance
            ->employeeForUser(
                $companyId,
                $actorUserId
            );
        $employeeId = (int) (
            $employee['employee_id'] ?? 0
        );
        $records = $employeeId > 0
            ? $this->attendance
                ->historyForEmployee(
                    $companyId,
                    $employeeId,
                    $range['from'],
                    $range['to']
                )
            : [];
        $today = $employeeId > 0
            ? $this->attendance->find(
                $companyId,
                $employeeId,
                date('Y-m-d')
            )
            : null;

        if ($employee !== null) {
            $employee['displayName'] =
                $this->employeeName($employee);
        }

        $records = $this->presentRecords($records);
        $today = is_array($today)
            ? $this->presentRecord($today)
            : null;
        $employmentStatus = (string) (
            $employee['employment_status'] ?? ''
        );
        $hasCheckIn = is_array($today)
            && !empty($today['check_in_at']);
        $hasCheckOut = is_array($today)
            && !empty($today['check_out_at']);
        $blockedStatus = is_array($today)
            && in_array(
                $today['attendance_status'] ?? '',
                ['absent', 'on_leave', 'holiday'],
                true
            );

        return [
            'employee' => $employee,
            'profileRequired' => $employeeId < 1,
            'records' => $records,
            'today' => $today,
            'todayDate' => date('Y-m-d'),
            'summary' =>
                $this->summarize($records),
            'range' => $range,
            'canCheckIn' =>
                $employeeId > 0
                && $employmentStatus === 'active'
                && !$hasCheckIn
                && !$blockedStatus,
            'canCheckOut' =>
                $employeeId > 0
                && $employmentStatus === 'active'
                && $hasCheckIn
                && !$hasCheckOut,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function teamWorkspace(
        int $actorUserId,
        string $month
    ): array {
        $range = $this->monthRange($month);
        $rows = $this->attendance
            ->historyForManager(
                $this->tenant->companyId(),
                $actorUserId,
                $range['from'],
                $range['to']
            );
        $people = [];

        foreach ($rows as $row) {
            $userId = (int) (
                $row['user_id'] ?? 0
            );

            if ($userId < 1) {
                continue;
            }

            if (!isset($people[$userId])) {
                $people[$userId] = [
                    'userId' => $userId,
                    'employeeId' => (int) (
                        $row['employee_id'] ?? 0
                    ),
                    'employeeNumber' => (string) (
                        $row['employee_number'] ?? ''
                    ),
                    'displayName' =>
                        $this->employeeName($row),
                    'jobTitle' => (string) (
                        $row['job_title'] ?? ''
                    ),
                    'departmentName' => (string) (
                        $row['department_name'] ?? ''
                    ),
                    'email' => (string) (
                        $row['email'] ?? ''
                    ),
                    'records' => [],
                ];
            }

            if (
                (int) (
                    $row['attendance_id'] ?? 0
                ) > 0
            ) {
                $people[$userId]['records'][] =
                    $this->presentRecord($row);
            }
        }

        $totalRecorded = 0;
        $totalExceptions = 0;
        $profilesMissing = 0;

        foreach ($people as &$person) {
            $person['summary'] =
                $this->summarize(
                    $person['records']
                );
            $person['latest'] =
                $person['records'][0] ?? null;
            $totalRecorded += (int) (
                $person['summary']['recorded']
                ?? 0
            );
            $totalExceptions += (int) (
                $person['summary']['exceptions']
                ?? 0
            );

            if (($person['employeeId'] ?? 0) < 1) {
                $profilesMissing++;
            }
        }

        unset($person);

        return [
            'people' => array_values($people),
            'range' => $range,
            'summary' => [
                'directReports' => count($people),
                'recorded' => $totalRecorded,
                'exceptions' => $totalExceptions,
                'profilesMissing' =>
                    $profilesMissing,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkIn(
        int $actorUserId
    ): array {
        $context = $this->recordingContext(
            $actorUserId
        );

        if ($context['errors'] !== []) {
            return [
                'successful' => false,
                'errors' => $context['errors'],
            ];
        }

        $old = $context['record'];

        if (
            is_array($old)
            && in_array(
                $old['attendance_status'] ?? '',
                ['absent', 'on_leave', 'holiday'],
                true
            )
        ) {
            return $this->error(
                'Today is already marked as unavailable. Ask HR to review the attendance record.'
            );
        }

        if (
            is_array($old)
            && !empty($old['check_in_at'])
        ) {
            return $this->error(
                'Check-in has already been recorded for today.'
            );
        }

        $now = date('Y-m-d H:i:s');
        $values = [
            'attendance_status' => is_array($old)
                && in_array(
                    $old['attendance_status'] ?? '',
                    ['present', 'late', 'remote'],
                    true
                )
                    ? $old['attendance_status']
                    : 'present',
            'check_in_at' => $now,
            'check_out_at' => null,
            'work_minutes' => 0,
            'source' => 'self_service',
            'notes' => $old['notes'] ?? null,
        ];

        return $this->saveAction(
            $context,
            $values,
            'SELF_CHECK_IN',
            $old,
            $actorUserId
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function checkOut(
        int $actorUserId
    ): array {
        $context = $this->recordingContext(
            $actorUserId
        );

        if ($context['errors'] !== []) {
            return [
                'successful' => false,
                'errors' => $context['errors'],
            ];
        }

        $old = $context['record'];

        if (
            !is_array($old)
            || empty($old['check_in_at'])
        ) {
            return $this->error(
                'Record check-in before checking out.'
            );
        }

        if (!empty($old['check_out_at'])) {
            return $this->error(
                'Check-out has already been recorded for today.'
            );
        }

        $now = date('Y-m-d H:i:s');
        $checkIn = strtotime(
            (string) $old['check_in_at']
        );
        $checkOut = strtotime($now);

        if (
            $checkIn === false
            || $checkOut === false
            || $checkOut < $checkIn
        ) {
            return $this->error(
                'The current time is earlier than the recorded check-in. Ask HR to review the record.'
            );
        }

        $values = [
            'attendance_status' => (string) (
                $old['attendance_status']
                ?? 'present'
            ),
            'check_in_at' => (string) (
                $old['check_in_at']
            ),
            'check_out_at' => $now,
            'work_minutes' => (int) floor(
                ($checkOut - $checkIn) / 60
            ),
            'source' => 'self_service',
            'notes' => $old['notes'] ?? null,
        ];

        return $this->saveAction(
            $context,
            $values,
            'SELF_CHECK_OUT',
            $old,
            $actorUserId
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function recordingContext(
        int $actorUserId
    ): array {
        $companyId = $this->tenant->companyId();
        $employee = $this->attendance
            ->employeeForUser(
                $companyId,
                $actorUserId
            );
        $employeeId = (int) (
            $employee['employee_id'] ?? 0
        );
        $errors = [];

        if ($employeeId < 1) {
            $errors['form'] =
                'Your company account must be linked to an active employee profile before recording attendance.';
        } elseif (
            ($employee['employment_status'] ?? '')
                !== 'active'
        ) {
            $errors['form'] =
                'Attendance cannot be recorded while the employee profile is not active.';
        }

        return [
            'companyId' => $companyId,
            'employeeId' => $employeeId,
            'date' => date('Y-m-d'),
            'record' => $employeeId > 0
                ? $this->attendance->find(
                    $companyId,
                    $employeeId,
                    date('Y-m-d')
                )
                : null,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $values
     * @param array<string, mixed>|null $old
     *
     * @return array<string, mixed>
     */
    private function saveAction(
        array $context,
        array $values,
        string $action,
        ?array $old,
        int $actorUserId
    ): array {
        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $attendanceId = $this->attendance
                ->save(
                    (int) $context['companyId'],
                    (int) $context['employeeId'],
                    (string) $context['date'],
                    $values,
                    $actorUserId
                );
            $this->auditLogs->record(
                $actorUserId,
                $action,
                'attendance',
                'attendance_records',
                (string) $attendanceId,
                $old,
                $values,
                (int) $context['companyId']
            );

            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (Throwable $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            throw $exception;
        }

        return [
            'successful' => true,
            'errors' => [],
            'attendanceId' => $attendanceId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(string $message): array
    {
        return [
            'successful' => false,
            'errors' => ['form' => $message],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function monthRange(
        string $month
    ): array {
        $month = trim($month);
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m',
            $month
        );

        if (
            $date === false
            || $date->format('Y-m') !== $month
        ) {
            $date = new DateTimeImmutable(
                date('Y-m-01')
            );
        }

        return [
            'month' => $date->format('Y-m'),
            'label' => $date->format('F Y'),
            'from' => $date->format('Y-m-01'),
            'to' => $date->format('Y-m-t'),
            'previous' => $date
                ->modify('-1 month')
                ->format('Y-m'),
            'next' => $date
                ->modify('+1 month')
                ->format('Y-m'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return list<array<string, mixed>>
     */
    private function presentRecords(
        array $records
    ): array {
        foreach ($records as &$record) {
            $record = $this->presentRecord($record);
        }

        unset($record);

        return $records;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function presentRecord(
        array $record
    ): array {
        $status = (string) (
            $record['attendance_status'] ?? ''
        );
        $presentation = self::STATUSES[$status]
            ?? ['Not recorded', 'muted'];
        $minutes = (int) (
            $record['work_minutes'] ?? 0
        );

        $record['statusLabel'] = $presentation[0];
        $record['statusTone'] = $presentation[1];
        $record['checkInTime'] = $this->timeOnly(
            $record['check_in_at'] ?? null
        );
        $record['checkOutTime'] = $this->timeOnly(
            $record['check_out_at'] ?? null
        );
        $record['workDuration'] =
            $this->durationLabel($minutes);

        return $record;
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return array<string, int|string>
     */
    private function summarize(array $records): array
    {
        $summary = [
            'recorded' => count($records),
            'present' => 0,
            'late' => 0,
            'remote' => 0,
            'absent' => 0,
            'onLeave' => 0,
            'exceptions' => 0,
            'workMinutes' => 0,
            'workDuration' => '0h',
        ];

        foreach ($records as $record) {
            $status = (string) (
                $record['attendance_status'] ?? ''
            );
            $summary['workMinutes'] += (int) (
                $record['work_minutes'] ?? 0
            );

            if (isset($summary[$status])) {
                $summary[$status]++;
            }

            if ($status === 'on_leave') {
                $summary['onLeave']++;
            }

            if (in_array(
                $status,
                ['late', 'absent'],
                true
            )) {
                $summary['exceptions']++;
            }
        }

        $summary['workDuration'] =
            $this->durationLabel(
                (int) $summary['workMinutes']
            );

        return $summary;
    }

    /**
     * @param array<string, mixed> $employee
     */
    private function employeeName(
        array $employee
    ): string {
        $preferred = trim((string) (
            $employee['preferred_name'] ?? ''
        ));
        $first = $preferred !== ''
            ? $preferred
            : trim((string) (
                $employee['first_name'] ?? ''
            ));
        $name = trim(
            $first . ' ' . (string) (
                $employee['last_name'] ?? ''
            )
        );

        return $name !== ''
            ? $name
            : (string) (
                $employee['display_name']
                ?? 'Employee'
            );
    }

    private function timeOnly(mixed $value): string
    {
        if (
            !is_string($value)
            || trim($value) === ''
        ) {
            return '—';
        }

        $timestamp = strtotime($value);

        return $timestamp === false
            ? '—'
            : date('H:i', $timestamp);
    }

    private function durationLabel(
        int $minutes
    ): string {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        if ($hours === 0) {
            return $remaining . 'm';
        }

        return $hours . 'h'
            . ($remaining > 0
                ? ' ' . $remaining . 'm'
                : '');
    }
}
