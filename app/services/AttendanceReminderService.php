<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AttendanceReminderRepository;
use App\Repositories\AttendanceRepository;
use App\Repositories\AuditLogWriter;
use App\Repositories\RepositoryFactory;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

final class AttendanceReminderService
{
    private const LEAD_OPTIONS = [
        0 => 'At the scheduled time',
        5 => '5 minutes before',
        10 => '10 minutes before',
        15 => '15 minutes before',
        30 => '30 minutes before',
        60 => '1 hour before',
    ];

    private const WORKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    private AttendanceReminderRepository $reminders;
    private AttendanceRepository $attendance;
    private AuditLogWriter $auditLogs;
    private TenantContext $tenant;

    public function __construct(
        ?AttendanceReminderRepository $reminders = null,
        ?AttendanceRepository $attendance = null,
        ?AuditLogWriter $auditLogs = null,
        ?TenantContext $tenant = null
    ) {
        $this->reminders = $reminders
            ?? RepositoryFactory::attendanceReminders();
        $this->attendance = $attendance
            ?? RepositoryFactory::attendance();
        $this->auditLogs = $auditLogs
            ?? RepositoryFactory::auditLogs();
        $this->tenant = $tenant
            ?? new TenantContext();
    }

    /**
     * @param array<string, mixed> $attendanceWorkspace
     *
     * @return array<string, mixed>
     */
    public function workspace(
        int $actorUserId,
        array $attendanceWorkspace,
        ?DateTimeImmutable $now = null
    ): array {
        $companyId = $this->tenant->companyId();
        $stored = $this->reminders->findForUser(
            $companyId,
            $actorUserId
        );
        $settings = $this->presentSettings(
            $stored ?? $this->defaults()
        );
        $timezone = new DateTimeZone(
            (string) $settings['timezone']
        );
        $now = $now === null
            ? new DateTimeImmutable('now', $timezone)
            : $now->setTimezone($timezone);

        return [
            'settings' => $settings,
            'notification' =>
                $this->notification(
                    $settings,
                    $attendanceWorkspace,
                    $now
                ),
            'workdayOptions' => self::WORKDAYS,
            'leadOptions' => self::LEAD_OPTIONS,
            'timezoneOptions' =>
                $this->timezoneOptions(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function save(
        array $input,
        int $actorUserId
    ): array {
        $companyId = $this->tenant->companyId();
        $employee = $this->attendance
            ->employeeForUser(
                $companyId,
                $actorUserId
            );

        if ($employee === null) {
            return [
                'successful' => false,
                'errors' => [
                    'form' =>
                        'An active employee profile is required before personal attendance reminders can be configured.',
                ],
            ];
        }

        $values = $this->normalize($input);
        $errors = $this->validate($values);

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
                'old' => $input,
            ];
        }

        $old = $this->reminders->findForUser(
            $companyId,
            $actorUserId
        );
        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $this->reminders->saveForUser(
                $companyId,
                $actorUserId,
                $values
            );
            $this->auditLogs->record(
                $actorUserId,
                'UPDATE_ATTENDANCE_REMINDERS',
                'attendance',
                'attendance_user_reminders',
                (string) $actorUserId,
                $old,
                $values,
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        $company = $this->tenant->company();
        $timezone = trim((string) (
            $company['timezone']
                ?? date_default_timezone_get()
        ));

        if (!in_array(
            $timezone,
            DateTimeZone::listIdentifiers(),
            true
        )) {
            $timezone = 'UTC';
        }

        return [
            'timezone' => $timezone,
            'workday_mask' => 31,
            'check_in_enabled' => 0,
            'check_in_time' => '08:30',
            'check_out_enabled' => 0,
            'check_out_time' => '17:30',
            'reminder_lead_minutes' => 10,
            'browser_notifications_enabled' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function presentSettings(
        array $settings
    ): array {
        $workdayMask = (int) (
            $settings['workday_mask'] ?? 31
        );
        $workdays = [];

        foreach (array_keys(self::WORKDAYS) as $day) {
            if (
                ($workdayMask & (1 << ($day - 1)))
                    !== 0
            ) {
                $workdays[] = $day;
            }
        }

        return [
            'timezone' => (string) (
                $settings['timezone'] ?? 'UTC'
            ),
            'workdayMask' => $workdayMask,
            'workdays' => $workdays,
            'checkInEnabled' => !empty(
                $settings['check_in_enabled']
            ),
            'checkInTime' => (string) (
                $settings['check_in_time']
                    ?? '08:30'
            ),
            'checkOutEnabled' => !empty(
                $settings['check_out_enabled']
            ),
            'checkOutTime' => (string) (
                $settings['check_out_time']
                    ?? '17:30'
            ),
            'leadMinutes' => (int) (
                $settings['reminder_lead_minutes']
                    ?? 10
            ),
            'browserEnabled' => !empty(
                $settings[
                    'browser_notifications_enabled'
                ]
            ),
            'configured' => isset(
                $settings['reminder_id']
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $workdays = is_array(
            $input['workdays'] ?? null
        )
            ? $input['workdays']
            : [];
        $workdayMask = 0;

        foreach ($workdays as $workday) {
            if (
                is_string($workday)
                && ctype_digit($workday)
            ) {
                $day = (int) $workday;
            } elseif (is_int($workday)) {
                $day = $workday;
            } else {
                continue;
            }

            if ($day >= 1 && $day <= 7) {
                $workdayMask |= 1 << ($day - 1);
            }
        }

        $lead = filter_var(
            $input['reminder_lead_minutes'] ?? null,
            FILTER_VALIDATE_INT
        );

        return [
            'timezone' => trim((string) (
                $input['timezone'] ?? ''
            )),
            'workday_mask' => $workdayMask,
            'check_in_enabled' => !empty(
                $input['check_in_enabled']
            ),
            'check_in_time' => trim((string) (
                $input['check_in_time'] ?? ''
            )),
            'check_out_enabled' => !empty(
                $input['check_out_enabled']
            ),
            'check_out_time' => trim((string) (
                $input['check_out_time'] ?? ''
            )),
            'reminder_lead_minutes' =>
                is_int($lead) ? $lead : -1,
            'browser_notifications_enabled' =>
                !empty(
                    $input[
                        'browser_notifications_enabled'
                    ]
                ),
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, string>
     */
    private function validate(array $values): array
    {
        $errors = [];
        $timezone = (string) $values['timezone'];

        if (!in_array(
            $timezone,
            DateTimeZone::listIdentifiers(),
            true
        )) {
            $errors['timezone'] =
                'Select a valid IANA timezone.';
        }

        if ((int) $values['workday_mask'] < 1) {
            $errors['workdays'] =
                'Select at least one working day.';
        }

        if (
            empty($values['check_in_enabled'])
            && empty($values['check_out_enabled'])
        ) {
            $errors['reminders'] =
                'Enable at least one attendance reminder.';
        }

        if (!$this->validTime(
            (string) $values['check_in_time']
        )) {
            $errors['check_in_time'] =
                'Enter a valid check-in time.';
        }

        if (!$this->validTime(
            (string) $values['check_out_time']
        )) {
            $errors['check_out_time'] =
                'Enter a valid check-out time.';
        }

        if (!isset(self::LEAD_OPTIONS[
            (int) $values['reminder_lead_minutes']
        ])) {
            $errors['reminder_lead_minutes'] =
                'Select a supported reminder lead time.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $workspace
     *
     * @return array<string, mixed>
     */
    private function notification(
        array $settings,
        array $workspace,
        DateTimeImmutable $now
    ): array {
        $base = [
            'status' => 'inactive',
            'tone' => 'muted',
            'title' =>
                'Personal reminders are not active',
            'message' =>
                'Choose your working days and reminder times below.',
            'kind' => 'none',
            'scheduledTime' => null,
            'notifyAtIso' => null,
            'notificationKey' => null,
            'browserEnabled' => !empty(
                $settings['browserEnabled']
            ),
            'timezone' => (string) (
                $settings['timezone'] ?? 'UTC'
            ),
        ];

        if (!empty($workspace['profileRequired'])) {
            $base['title'] =
                'Employee profile required';
            $base['message'] =
                'Your account must be linked to an active employee before reminders can run.';

            return $base;
        }

        if (
            empty($settings['checkInEnabled'])
            && empty($settings['checkOutEnabled'])
        ) {
            return $base;
        }

        $day = (int) $now->format('N');

        if (
            !in_array(
                $day,
                $settings['workdays'] ?? [],
                true
            )
        ) {
            return array_merge($base, [
                'status' => 'rest-day',
                'tone' => 'info',
                'title' => 'No reminder today',
                'message' =>
                    'Today is not one of your configured working days.',
            ]);
        }

        $today = is_array(
            $workspace['today'] ?? null
        )
            ? $workspace['today']
            : null;
        $todayStatus = (string) (
            $today['attendance_status'] ?? ''
        );

        if (in_array(
            $todayStatus,
            ['absent', 'on_leave', 'holiday'],
            true
        )) {
            return array_merge($base, [
                'status' => 'suppressed',
                'tone' => 'info',
                'title' => 'Reminder paused today',
                'message' =>
                    'Your attendance status does not require a self-service reminder.',
            ]);
        }

        $hasCheckIn = is_array($today)
            && !empty($today['check_in_at']);
        $hasCheckOut = is_array($today)
            && !empty($today['check_out_at']);

        if (
            !$hasCheckIn
            && !empty($settings['checkInEnabled'])
        ) {
            return $this->scheduledNotification(
                'check-in',
                (string) $settings['checkInTime'],
                (int) $settings['leadMinutes'],
                $now,
                $base
            );
        }

        if (
            $hasCheckIn
            && !$hasCheckOut
            && !empty($settings['checkOutEnabled'])
        ) {
            return $this->scheduledNotification(
                'check-out',
                (string) $settings['checkOutTime'],
                (int) $settings['leadMinutes'],
                $now,
                $base
            );
        }

        return array_merge($base, [
            'status' => 'complete',
            'tone' => 'success',
            'title' => 'Attendance is up to date',
            'message' => $hasCheckOut
                ? 'Check-in and check-out are complete for today.'
                : 'No further configured reminder is required today.',
        ]);
    }

    /**
     * @param array<string, mixed> $base
     *
     * @return array<string, mixed>
     */
    private function scheduledNotification(
        string $kind,
        string $time,
        int $leadMinutes,
        DateTimeImmutable $now,
        array $base
    ): array {
        $timezone = $now->getTimezone();
        $scheduled = new DateTimeImmutable(
            $now->format('Y-m-d') . ' ' . $time,
            $timezone
        );
        $notifyAt = $scheduled->modify(
            '-' . $leadMinutes . ' minutes'
        );
        $due = $now >= $notifyAt;
        $overdue = $now > $scheduled;
        $action = $kind === 'check-in'
            ? 'Check in'
            : 'Check out';
        $title = $due
            ? ($overdue
                ? $action . ' is overdue'
                : $action . ' reminder')
            : $action . ' reminder scheduled';
        $message = $due
            ? $action . ' for your '
                . $time . ' schedule.'
            : 'You will be reminded at '
                . $notifyAt->format('H:i')
                . ' for your ' . $time
                . ' schedule.';

        return array_merge($base, [
            'status' => $due
                ? 'due'
                : 'upcoming',
            'tone' => $due
                ? 'warning'
                : 'info',
            'title' => $title,
            'message' => $message,
            'kind' => $kind,
            'scheduledTime' => $time,
            'notifyAtIso' => $notifyAt->format(
                DateTimeInterface::ATOM
            ),
            'notificationKey' =>
                'attendance:'
                . $kind
                . ':'
                . $now->format('Y-m-d'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function timezoneOptions(): array
    {
        $options = [];

        foreach (
            DateTimeZone::listIdentifiers()
            as $timezone
        ) {
            $options[$timezone] = str_replace(
                '_',
                ' ',
                $timezone
            );
        }

        return $options;
    }

    private function validTime(string $value): bool
    {
        if (!preg_match(
            '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
            $value
        )) {
            return false;
        }

        $time = DateTimeImmutable::createFromFormat(
            '!H:i',
            $value
        );

        return $time !== false
            && $time->format('H:i') === $value;
    }
}
