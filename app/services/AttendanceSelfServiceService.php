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
    private WorkforceCalendarService $calendars;
    private AttendanceWorkPolicyService $workPolicy;

    public function __construct(
        ?AttendanceRepository $attendance = null,
        ?AuditLogWriter $auditLogs = null,
        ?TenantContext $tenant = null,
        ?WorkforceCalendarService $calendars = null,
        ?AttendanceWorkPolicyService $workPolicy = null
    ) {
        $this->attendance = $attendance
            ?? RepositoryFactory::attendance();
        $this->auditLogs = $auditLogs
            ?? RepositoryFactory::auditLogs();
        $this->tenant = $tenant
            ?? new TenantContext();
        $this->calendars = $calendars
            ?? new WorkforceCalendarService();
        $this->workPolicy = $workPolicy
            ?? new AttendanceWorkPolicyService();
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
        $schedule = $employeeId > 0
            ? $this->scheduleContext(
                $actorUserId
            )
            : null;
        $todayDate = (string) (
            $schedule['localDate'] ?? date('Y-m-d')
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
                $todayDate
            )
            : null;
        $todaySessions = is_array($today)
            ? $this->attendance
                ->sessionsForRecord(
                    $companyId,
                    (int) (
                        $today['attendance_id'] ?? 0
                    )
                )
            : [];
        $todaySessions =
            $this->presentSessions($todaySessions);
        $openSession = $this->openSessionFrom(
            $todaySessions
        );

        if ($employee !== null) {
            $employee['displayName'] =
                $this->employeeName($employee);
        }

        $records = $this->presentRecords($records);
        $today = is_array($today)
            ? $this->presentRecord($today)
            : null;

        if (is_array($today)) {
            $today['sessions'] = $todaySessions;
            $today['sessionCount'] =
                count($todaySessions);
            $today['isWorking'] =
                $openSession !== null;
        }

        if (
            is_array($today)
            && !empty($today['check_in_at'])
        ) {
            $metrics = $this->workPolicy->evaluate(
                $schedule,
                $todayDate,
                (string) $today['check_in_at'],
                empty($today['check_out_at'])
                    ? null
                    : (string) $today['check_out_at']
            );
            $today['expectedCheckoutTime'] =
                $this->timeOnly(
                    $metrics['expected_checkout_at']
                        ?? null
                );
        }
        $employmentStatus = (string) (
            $employee['employment_status'] ?? ''
        );
        $hasCheckIn = is_array($today)
            && !empty($today['check_in_at']);
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
            'todayDate' => $todayDate,
            'workSchedule' => $schedule,
            'isWorking' => $openSession !== null,
            'sessionCount' => count($todaySessions),
            'summary' =>
                $this->summarize($records),
            'range' => $range,
            'canCheckIn' =>
                $employeeId > 0
                && $employmentStatus === 'active'
                && $openSession === null
                && !$blockedStatus,
            'canCheckOut' =>
                $employeeId > 0
                && $employmentStatus === 'active'
                && $hasCheckIn
                && $openSession !== null,
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

        $now = $this->now(
            (string) (
                $context['schedule']['timezone']
                    ?? \config('timezone', 'UTC')
            )
        );
        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $old = $this->attendance
                ->findForUpdate(
                    (int) $context['companyId'],
                    (int) $context['employeeId'],
                    (string) $context['date']
                );

            if (
                is_array($old)
                && in_array(
                    $old['attendance_status'] ?? '',
                    ['absent', 'on_leave', 'holiday'],
                    true
                )
            ) {
                return $this->transactionError(
                    $connection,
                    $ownsTransaction,
                    'Today is already marked as unavailable. Ask HR to review the attendance record.'
                );
            }

            $attendanceId = (int) (
                $old['attendance_id'] ?? 0
            );

            if (
                $attendanceId > 0
                && $this->attendance->openSession(
                    (int) $context['companyId'],
                    $attendanceId
                ) !== null
            ) {
                return $this->transactionError(
                    $connection,
                    $ownsTransaction,
                    'You are already clocked in. Clock out before starting another work session.'
                );
            }

            if ($attendanceId < 1) {
                $metrics = $this->workPolicy->evaluate(
                    $context['schedule'],
                    (string) $context['date'],
                    $now
                );
                $attendanceId = $this->attendance
                    ->save(
                        (int) $context['companyId'],
                        (int) $context['employeeId'],
                        (string) $context['date'],
                        [
                            'attendance_status' =>
                                !empty($metrics['late'])
                                    ? 'late'
                                    : 'present',
                            'check_in_at' => $now,
                            'check_out_at' => null,
                            'work_minutes' => 0,
                            'gross_minutes' => 0,
                            'break_minutes' => 0,
                            'target_work_minutes' =>
                                $metrics[
                                    'target_work_minutes'
                                ],
                            'work_variance_minutes' =>
                                $metrics[
                                    'work_variance_minutes'
                                ],
                            'source' => 'self_service',
                            'notes' => null,
                        ],
                        $actorUserId
                    );
            }

            $sessionId = $this->attendance
                ->startSession(
                    (int) $context['companyId'],
                    $attendanceId,
                    (int) $context['employeeId'],
                    $now,
                    'self_service',
                    $actorUserId
                );
            $sessions = $this->attendance
                ->sessionsForRecord(
                    (int) $context['companyId'],
                    $attendanceId
                );
            $values = $this->summaryValues(
                $context,
                $old,
                $sessions
            );
            $this->attendance->save(
                (int) $context['companyId'],
                (int) $context['employeeId'],
                (string) $context['date'],
                $values,
                $actorUserId
            );
            $this->auditLogs->record(
                $actorUserId,
                'SELF_CHECK_IN',
                'attendance',
                'attendance_records',
                (string) $attendanceId,
                $old,
                array_merge($values, [
                    'session_id' => $sessionId,
                ]),
                (int) $context['companyId']
            );

            if ($ownsTransaction) {
                $connection->commit();
            }

            return [
                'successful' => true,
                'errors' => [],
                'attendanceId' => $attendanceId,
                'sessionId' => $sessionId,
            ];
        } catch (Throwable $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            throw $exception;
        }
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

        $now = $this->now(
            (string) (
                $context['schedule']['timezone']
                    ?? \config('timezone', 'UTC')
            )
        );
        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $old = $this->attendance
                ->findForUpdate(
                    (int) $context['companyId'],
                    (int) $context['employeeId'],
                    (string) $context['date']
                );

            if (
                !is_array($old)
                || empty($old['check_in_at'])
            ) {
                return $this->transactionError(
                    $connection,
                    $ownsTransaction,
                    'Clock in before clocking out.'
                );
            }

            $attendanceId = (int) (
                $old['attendance_id'] ?? 0
            );
            $openSession = $this->attendance
                ->openSession(
                    (int) $context['companyId'],
                    $attendanceId
                );

            if (!is_array($openSession)) {
                return $this->transactionError(
                    $connection,
                    $ownsTransaction,
                    'No work session is currently open. Clock in again when work resumes.'
                );
            }

            $checkIn = strtotime((string) (
                $openSession['check_in_at'] ?? ''
            ));
            $checkOut = strtotime($now);

            if (
                $checkIn === false
                || $checkOut === false
                || $checkOut < $checkIn
            ) {
                return $this->transactionError(
                    $connection,
                    $ownsTransaction,
                    'The current time is earlier than the open clock-in. Ask HR to review the record.'
                );
            }

            $finished = $this->attendance
                ->finishSession(
                    (int) $context['companyId'],
                    $attendanceId,
                    (int) (
                        $openSession['session_id'] ?? 0
                    ),
                    $now,
                    $actorUserId
                );

            if (!$finished) {
                return $this->transactionError(
                    $connection,
                    $ownsTransaction,
                    'The work session changed before clock-out completed. Refresh and try again.'
                );
            }

            $sessions = $this->attendance
                ->sessionsForRecord(
                    (int) $context['companyId'],
                    $attendanceId
                );
            $values = $this->summaryValues(
                $context,
                $old,
                $sessions
            );
            $this->attendance->save(
                (int) $context['companyId'],
                (int) $context['employeeId'],
                (string) $context['date'],
                $values,
                $actorUserId
            );
            $this->auditLogs->record(
                $actorUserId,
                'SELF_CHECK_OUT',
                'attendance',
                'attendance_records',
                (string) $attendanceId,
                $old,
                array_merge($values, [
                    'session_id' => (int) (
                        $openSession['session_id'] ?? 0
                    ),
                ]),
                (int) $context['companyId']
            );

            if ($ownsTransaction) {
                $connection->commit();
            }

            return [
                'successful' => true,
                'errors' => [],
                'attendanceId' => $attendanceId,
                'sessionId' => (int) (
                    $openSession['session_id'] ?? 0
                ),
            ];
        } catch (Throwable $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            throw $exception;
        }
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
        $schedule = $employeeId > 0
            ? $this->scheduleContext(
                $actorUserId
            )
            : null;
        $date = (string) (
            $schedule['localDate'] ?? date('Y-m-d')
        );

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
            'date' => $date,
            'schedule' => $schedule,
            'record' => $employeeId > 0
                ? $this->attendance->find(
                    $companyId,
                    $employeeId,
                    $date
                )
                : null,
            'errors' => $errors,
        ];
    }

    /**
     * Recalculate the daily reporting row from effective work sessions.
     *
     * Open sessions do not add time until they are clocked out. This prevents
     * a page refresh from storing a moving and unaudited duration.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $old
     * @param list<array<string, mixed>> $sessions
     * @return array<string, mixed>
     */
    private function summaryValues(
        array $context,
        ?array $old,
        array $sessions
    ): array {
        if ($sessions === []) {
            throw new \RuntimeException(
                'An attendance summary requires at least one work session.'
            );
        }

        $firstCheckIn = (string) (
            $sessions[0]['check_in_at'] ?? ''
        );
        $baseMetrics = $this->workPolicy->evaluate(
            $context['schedule'],
            (string) $context['date'],
            $firstCheckIn
        );
        $latestCheckOut = null;
        $grossMinutes = 0;
        $breakMinutes = 0;
        $workMinutes = 0;

        foreach ($sessions as $session) {
            $sessionCheckIn = (string) (
                $session['check_in_at'] ?? ''
            );
            $sessionCheckOut = $session[
                'check_out_at'
            ] ?? null;

            if (
                $sessionCheckIn === ''
                || !is_string($sessionCheckOut)
                || trim($sessionCheckOut) === ''
            ) {
                continue;
            }

            $metrics = $this->workPolicy->evaluate(
                $context['schedule'],
                (string) $context['date'],
                $sessionCheckIn,
                $sessionCheckOut
            );
            $grossMinutes += (int) (
                $metrics['gross_minutes'] ?? 0
            );
            $breakMinutes += (int) (
                $metrics['break_minutes'] ?? 0
            );
            $workMinutes += (int) (
                $metrics['work_minutes'] ?? 0
            );

            if (
                $latestCheckOut === null
                || strcmp(
                    $sessionCheckOut,
                    $latestCheckOut
                ) > 0
            ) {
                $latestCheckOut = $sessionCheckOut;
            }
        }

        $target = (int) (
            $baseMetrics['target_work_minutes'] ?? 0
        );
        $oldStatus = (string) (
            $old['attendance_status'] ?? ''
        );

        return [
            'attendance_status' =>
                $oldStatus === 'remote'
                    ? 'remote'
                    : (!empty($baseMetrics['late'])
                        ? 'late'
                        : 'present'),
            'check_in_at' => $firstCheckIn,
            'check_out_at' => $latestCheckOut,
            'work_minutes' => $workMinutes,
            'gross_minutes' => $grossMinutes,
            'break_minutes' => $breakMinutes,
            'target_work_minutes' => $target,
            'work_variance_minutes' =>
                $target > 0
                    ? $workMinutes - $target
                    : 0,
            'source' => 'self_service',
            'notes' => $old['notes'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionError(
        \PDO $connection,
        bool $ownsTransaction,
        string $message
    ): array {
        if (
            $ownsTransaction
            && $connection->inTransaction()
        ) {
            $connection->rollBack();
        }

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
     * @param list<array<string, mixed>> $sessions
     * @return list<array<string, mixed>>
     */
    private function presentSessions(
        array $sessions
    ): array {
        foreach ($sessions as &$session) {
            $checkIn = (string) (
                $session['check_in_at'] ?? ''
            );
            $checkOut = $session['check_out_at']
                ?? null;
            $session['checkInTime'] =
                $this->timeOnly($checkIn);
            $session['checkOutTime'] =
                $this->timeOnly($checkOut);
            $session['isOpen'] =
                !is_string($checkOut)
                || trim($checkOut) === '';
            $session['duration'] =
                $this->sessionDuration(
                    $checkIn,
                    is_string($checkOut)
                        ? $checkOut
                        : null
                );
        }

        unset($session);

        return $sessions;
    }

    /**
     * @param list<array<string, mixed>> $sessions
     * @return array<string, mixed>|null
     */
    private function openSessionFrom(
        array $sessions
    ): ?array {
        foreach ($sessions as $session) {
            if (!empty($session['isOpen'])) {
                return $session;
            }
        }

        return null;
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
        $record['grossDuration'] =
            $this->durationLabel((int) (
                $record['gross_minutes']
                ?? $minutes
            ));
        $record['breakDuration'] =
            $this->durationLabel((int) (
                $record['break_minutes'] ?? 0
            ));
        $record['targetDuration'] =
            $this->durationLabel((int) (
                $record['target_work_minutes'] ?? 0
            ));
        $variance = (int) (
            $record['work_variance_minutes'] ?? 0
        );
        $record['varianceDuration'] =
            ($variance >= 0 ? '+' : '-')
            . $this->durationLabel(abs($variance));

        return $record;
    }

    private function sessionDuration(
        string $checkInAt,
        ?string $checkOutAt
    ): string {
        if (
            $checkInAt === ''
            || $checkOutAt === null
            || trim($checkOutAt) === ''
        ) {
            return 'In progress';
        }

        $start = strtotime($checkInAt);
        $end = strtotime($checkOutAt);

        if (
            $start === false
            || $end === false
            || $end < $start
        ) {
            return '—';
        }

        return $this->durationLabel(
            (int) floor(($end - $start) / 60)
        );
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

    /** @return array<string, mixed>|null */
    private function scheduleContext(
        int $actorUserId
    ): ?array {
        $context = $this->calendars
            ->contextForUser(
                $actorUserId,
                date('Y-m-d')
            );

        if ($context === null) {
            return null;
        }

        $timezone = $this->timezone(
            (string) (
                $context['timezone'] ?? 'UTC'
            )
        );
        $localDate = (new DateTimeImmutable(
            'now',
            $timezone
        ))->format('Y-m-d');

        if ($localDate !== date('Y-m-d')) {
            $context = $this->calendars
                ->contextForUser(
                    $actorUserId,
                    $localDate
                ) ?? $context;
        }

        $context['localDate'] = $localDate;

        return $context;
    }

    private function now(string $timezone): string
    {
        return (new DateTimeImmutable(
            'now',
            $this->timezone($timezone)
        ))->format('Y-m-d H:i:s');
    }

    private function timezone(
        string $identifier
    ): \DateTimeZone {
        try {
            return new \DateTimeZone($identifier);
        } catch (Throwable $exception) {
            return new \DateTimeZone('UTC');
        }
    }
}
