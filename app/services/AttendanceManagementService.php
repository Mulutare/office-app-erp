<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AttendanceRepository;
use App\Repositories\AuditLogWriter;
use App\Repositories\RepositoryFactory;
use DateTimeImmutable;
use Throwable;

final class AttendanceManagementService
{
    private const STATUSES = [
        'present' => [
            'label' => 'Present',
            'tone' => 'success',
        ],
        'late' => [
            'label' => 'Late',
            'tone' => 'warning',
        ],
        'absent' => [
            'label' => 'Absent',
            'tone' => 'danger',
        ],
        'remote' => [
            'label' => 'Remote',
            'tone' => 'info',
        ],
        'on_leave' => [
            'label' => 'On leave',
            'tone' => 'muted',
        ],
        'holiday' => [
            'label' => 'Holiday',
            'tone' => 'muted',
        ],
        'not_recorded' => [
            'label' => 'Not recorded',
            'tone' => 'muted',
        ],
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
    public function dashboard(string $date): array
    {
        $date = $this->validDate($date)
            ? $date
            : date('Y-m-d');
        $records = $this->attendance->dailyRoster(
            $this->tenant->companyId(),
            $date
        );
        $summary = [
            'total' => count($records),
            'recorded' => 0,
            'present' => 0,
            'late' => 0,
            'absent' => 0,
            'remote' => 0,
            'on_leave' => 0,
            'not_recorded' => 0,
        ];

        foreach ($records as &$record) {
            $status = (string) (
                $record['attendance_status']
                    ?? 'not_recorded'
            );

            if (!isset(self::STATUSES[$status])) {
                $status = 'not_recorded';
            }

            $record['attendance_status'] = $status;
            $record['statusLabel'] =
                self::STATUSES[$status]['label'];
            $record['statusTone'] =
                self::STATUSES[$status]['tone'];
            $record['employeeName'] =
                $this->employeeName($record);
            $record['checkInTime'] =
                $this->timeOnly(
                    $record['check_in_at'] ?? null
                );
            $record['checkOutTime'] =
                $this->timeOnly(
                    $record['check_out_at'] ?? null
                );
            $record['workDuration'] =
                $this->durationLabel(
                    (int) (
                        $record['work_minutes'] ?? 0
                    )
                );
            $record['grossDuration'] =
                $this->durationLabel((int) (
                    $record['gross_minutes']
                        ?? $record['work_minutes']
                        ?? 0
                ));
            $record['breakDuration'] =
                $this->durationLabel((int) (
                    $record['break_minutes'] ?? 0
                ));
            $record['targetDuration'] =
                $this->durationLabel((int) (
                    $record['target_work_minutes']
                        ?? 0
                ));
            $variance = (int) (
                $record['work_variance_minutes']
                    ?? 0
            );
            $record['varianceDuration'] =
                ($variance >= 0 ? '+' : '-')
                . $this->durationLabel(
                    abs($variance)
                );

            if ($status !== 'not_recorded') {
                $summary['recorded']++;
            }

            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        unset($record);

        return [
            'date' => $date,
            'records' => $records,
            'summary' => $summary,
            'statuses' => array_map(
                static fn (array $status): string =>
                    $status['label'],
                array_filter(
                    self::STATUSES,
                    static fn (
                        string $key
                    ): bool => $key !== 'not_recorded',
                    ARRAY_FILTER_USE_KEY
                )
            ),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function summary(string $date): array
    {
        return $this->dashboard($date)['summary'];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function record(
        array $input,
        int $updatedBy
    ): array {
        $companyId = $this->tenant->companyId();
        $values = $this->normalize($input);
        $errors = $this->validate(
            $companyId,
            $values
        );

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
            ];
        }

        $employeeId = (int) (
            $values['employee_id']
        );
        $attendanceDate = (string) (
            $values['attendance_date']
        );
        $values = $this->applyWorkPolicy(
            $values,
            $employeeId,
            $attendanceDate
        );
        $old = $this->attendance->find(
            $companyId,
            $employeeId,
            $attendanceDate
        );
        $new = $this->recordValues($values);

        if (
            is_array($old)
            && $this->recordValues($old) === $new
        ) {
            return [
                'successful' => true,
                'errors' => [],
                'changed' => false,
                'attendanceId' => (int) (
                    $old['attendance_id'] ?? 0
                ),
            ];
        }

        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $attendanceId = $this->attendance
                ->save(
                    $companyId,
                    $employeeId,
                    $attendanceDate,
                    $new,
                    $updatedBy
                );
            $this->attendance
                ->replaceSessionsForManualRecord(
                    $companyId,
                    $attendanceId,
                    $employeeId,
                    is_string($new['check_in_at'])
                        ? $new['check_in_at']
                        : null,
                    is_string($new['check_out_at'])
                        ? $new['check_out_at']
                        : null,
                    $updatedBy
                );
            $this->auditLogs->record(
                $updatedBy,
                $old === null
                    ? 'RECORD_ATTENDANCE'
                    : 'UPDATE_ATTENDANCE',
                'attendance',
                'attendance_records',
                (string) $attendanceId,
                $old === null
                    ? null
                    : $this->recordValues($old),
                $new,
                $companyId
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
            'changed' => true,
            'attendanceId' => $attendanceId,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $date = trim((string) (
            $input['attendance_date'] ?? ''
        ));
        $status = strtolower(trim((string) (
            $input['attendance_status'] ?? ''
        )));
        $checkIn = $this->normalizeTime(
            $input['check_in'] ?? null
        );
        $checkOut = $this->normalizeTime(
            $input['check_out'] ?? null
        );

        if (in_array(
            $status,
            ['absent', 'on_leave', 'holiday'],
            true
        )) {
            $checkIn = null;
            $checkOut = null;
        }

        $checkInAt = $checkIn === null
            ? null
            : $date . ' ' . $checkIn . ':00';
        $checkOutAt = $checkOut === null
            ? null
            : $date . ' ' . $checkOut . ':00';

        return [
            'employee_id' => $this->integer(
                $input['employee_id'] ?? null
            ),
            'attendance_date' => $date,
            'attendance_status' => $status,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'check_in_at' => $checkInAt,
            'check_out_at' => $checkOutAt,
            'work_minutes' =>
                $this->workMinutes(
                    $checkInAt,
                    $checkOutAt
                ),
            'gross_minutes' =>
                $this->workMinutes(
                    $checkInAt,
                    $checkOutAt
                ),
            'break_minutes' => 0,
            'target_work_minutes' => 0,
            'work_variance_minutes' => 0,
            'source' => 'manual',
            'notes' => $this->nullable(
                $input['notes'] ?? null
            ),
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, string>
     */
    private function validate(
        int $companyId,
        array $values
    ): array {
        $errors = [];
        $employeeId = (int) (
            $values['employee_id'] ?? 0
        );
        $date = (string) (
            $values['attendance_date'] ?? ''
        );
        $status = (string) (
            $values['attendance_status'] ?? ''
        );

        if (
            $employeeId < 1
            || !$this->attendance->employeeExists(
                $companyId,
                $employeeId
            )
        ) {
            $errors['employee_id'] =
                'Select an active employee from the current company.';
        }

        if (!$this->validDate($date)) {
            $errors['attendance_date'] =
                'Enter a valid attendance date.';
        } else {
            $target = new DateTimeImmutable($date);
            $today = new DateTimeImmutable('today');
            $days = (int) $today->diff($target)
                ->format('%r%a');

            if ($days < -366 || $days > 1) {
                $errors['attendance_date'] =
                    'Attendance can be recorded from one year ago through tomorrow.';
            }
        }

        if (
            !isset(self::STATUSES[$status])
            || $status === 'not_recorded'
        ) {
            $errors['attendance_status'] =
                'Select a valid attendance status.';
        }

        if (
            in_array(
                $status,
                ['present', 'late', 'remote'],
                true
            )
            && $values['check_in'] === null
        ) {
            $errors['check_in'] =
                'Check-in time is required for this status.';
        }

        if ($values['check_in'] === '__invalid__') {
            $errors['check_in'] =
                'Enter a valid check-in time.';
        }

        if ($values['check_out'] === '__invalid__') {
            $errors['check_out'] =
                'Enter a valid check-out time.';
        }

        if (
            $values['check_out'] !== null
            && $values['check_in'] === null
        ) {
            $errors['check_out'] =
                'Record check-in before check-out.';
        } elseif (
            !isset($errors['check_in'])
            && !isset($errors['check_out'])
            && $values['check_in_at'] !== null
            && $values['check_out_at'] !== null
            && strtotime(
                (string) $values['check_out_at']
            ) < strtotime(
                (string) $values['check_in_at']
            )
        ) {
            $errors['check_out'] =
                'Check-out cannot be earlier than check-in.';
        }

        if (
            is_string($values['notes'])
            && mb_strlen($values['notes']) > 500
        ) {
            $errors['notes'] =
                'Notes cannot exceed 500 characters.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function recordValues(array $record): array
    {
        return [
            'employee_id' => (int) (
                $record['employee_id'] ?? 0
            ),
            'attendance_date' => substr(
                (string) (
                    $record['attendance_date'] ?? ''
                ),
                0,
                10
            ),
            'attendance_status' => (string) (
                $record['attendance_status'] ?? ''
            ),
            'check_in_at' => $this->nullable(
                $record['check_in_at'] ?? null
            ),
            'check_out_at' => $this->nullable(
                $record['check_out_at'] ?? null
            ),
            'work_minutes' => (int) (
                $record['work_minutes'] ?? 0
            ),
            'gross_minutes' => (int) (
                $record['gross_minutes'] ?? 0
            ),
            'break_minutes' => (int) (
                $record['break_minutes'] ?? 0
            ),
            'target_work_minutes' => (int) (
                $record['target_work_minutes'] ?? 0
            ),
            'work_variance_minutes' => (int) (
                $record['work_variance_minutes'] ?? 0
            ),
            'source' => (string) (
                $record['source'] ?? 'manual'
            ),
            'notes' => $this->nullable(
                $record['notes'] ?? null
            ),
        ];
    }

    private function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date
        );

        return $parsed !== false
            && $parsed->format('Y-m-d') === $date;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function applyWorkPolicy(
        array $values,
        int $employeeId,
        string $attendanceDate
    ): array {
        if (empty($values['check_in_at'])) {
            $values['work_minutes'] = 0;
            $values['gross_minutes'] = 0;
            $values['break_minutes'] = 0;
            $values['target_work_minutes'] = 0;
            $values['work_variance_minutes'] = 0;

            return $values;
        }

        $context = $this->calendars
            ->contextForEmployee(
                $employeeId,
                $attendanceDate
            );
        $metrics = $this->workPolicy->evaluate(
            $context,
            $attendanceDate,
            (string) $values['check_in_at'],
            empty($values['check_out_at'])
                ? null
                : (string) $values['check_out_at']
        );

        foreach ([
            'work_minutes',
            'gross_minutes',
            'break_minutes',
            'target_work_minutes',
            'work_variance_minutes',
        ] as $field) {
            $values[$field] = (int) $metrics[$field];
        }

        if (in_array(
            $values['attendance_status'],
            ['present', 'late'],
            true
        )) {
            $values['attendance_status'] =
                !empty($metrics['late'])
                    ? 'late'
                    : 'present';
        }

        return $values;
    }

    private function normalizeTime(
        mixed $value
    ): ?string {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(
            '!H:i',
            $value
        );

        return $parsed !== false
            && $parsed->format('H:i') === $value
                ? $value
                : '__invalid__';
    }

    private function workMinutes(
        ?string $checkInAt,
        ?string $checkOutAt
    ): int {
        if (
            $checkInAt === null
            || $checkOutAt === null
        ) {
            return 0;
        }

        $start = strtotime($checkInAt);
        $end = strtotime($checkOutAt);

        if (
            $start === false
            || $end === false
            || $end < $start
        ) {
            return 0;
        }

        return (int) floor(
            ($end - $start) / 60
        );
    }

    private function employeeName(
        array $record
    ): string {
        $preferred = trim((string) (
            $record['preferred_name'] ?? ''
        ));
        $first = $preferred !== ''
            ? $preferred
            : trim((string) (
                $record['first_name'] ?? ''
            ));

        return trim(
            $first . ' ' . (string) (
                $record['last_name'] ?? ''
            )
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

    private function durationLabel(int $minutes): string
    {
        if ($minutes < 1) {
            return '—';
        }

        return sprintf(
            '%dh %02dm',
            intdiv($minutes, 60),
            $minutes % 60
        );
    }

    private function integer(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        return is_string($value)
            && ctype_digit($value)
                ? (int) $value
                : 0;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
