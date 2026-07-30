<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AttendanceNotificationRepository;
use App\Repositories\AttendanceRepository;
use App\Repositories\RepositoryFactory;
use App\Repositories\WorkforceCalendarRepository;
use DateTimeImmutable;
use DateTimeZone;

final class AttendanceNotificationService
{
    private AttendanceNotificationRepository $notifications;
    private AttendanceRepository $attendance;
    private WorkforceCalendarRepository $calendars;
    private TenantContext $tenant;

    public function __construct(
        ?AttendanceNotificationRepository $notifications = null,
        ?AttendanceRepository $attendance = null,
        ?WorkforceCalendarRepository $calendars = null,
        ?TenantContext $tenant = null
    ) {
        $this->notifications = $notifications
            ?? RepositoryFactory::attendanceNotifications();
        $this->attendance = $attendance
            ?? RepositoryFactory::attendance();
        $this->calendars = $calendars
            ?? RepositoryFactory::workforceCalendars();
        $this->tenant = $tenant
            ?? new TenantContext();
    }

    /**
     * Queue due in-app alerts for every active employee preference.
     *
     * @return array{candidates: int, queued: int, skipped: int}
     */
    public function queueDue(
        ?DateTimeImmutable $nowUtc = null
    ): array {
        $nowUtc = ($nowUtc ?? new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        ))->setTimezone(new DateTimeZone('UTC'));
        $candidates = $this->notifications
            ->reminderCandidates();
        $queued = 0;
        $skipped = 0;

        foreach ($candidates as $candidate) {
            $result = $this->queueCandidate(
                $candidate,
                $nowUtc
            );
            $queued += $result ? 1 : 0;
            $skipped += $result ? 0 : 1;
        }

        return [
            'candidates' => count($candidates),
            'queued' => $queued,
            'skipped' => $skipped,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function inbox(int $actorUserId): array
    {
        $rows = $this->notifications->inbox(
            $this->tenant->companyId(),
            $actorUserId
        );

        foreach ($rows as &$row) {
            $row['unread'] =
                ($row['status'] ?? '') === 'unread';
            $row['scheduledLabel'] = $this->dateLabel(
                (string) (
                    $row['scheduled_for'] ?? ''
                ),
                (string) (
                    $row['timezone'] ?? 'UTC'
                )
            );
        }
        unset($row);

        return $rows;
    }

    public function markRead(
        int $actorUserId,
        int $notificationId
    ): bool {
        return $this->notifications->markRead(
            $this->tenant->companyId(),
            $actorUserId,
            $notificationId
        );
    }

    /** @param array<string, mixed> $candidate */
    private function queueCandidate(
        array $candidate,
        DateTimeImmutable $nowUtc
    ): bool {
        $timezoneName = (string) (
            $candidate['timezone'] ?? 'UTC'
        );

        try {
            $timezone = new DateTimeZone(
                $timezoneName
            );
        } catch (\Throwable $exception) {
            return false;
        }

        $localNow = $nowUtc->setTimezone($timezone);
        $localDate = $localNow->format('Y-m-d');
        $companyId = (int) (
            $candidate['company_id'] ?? 0
        );
        $userId = (int) (
            $candidate['user_id'] ?? 0
        );
        $employeeId = (int) (
            $candidate['employee_id'] ?? 0
        );

        if (
            $companyId < 1
            || $userId < 1
            || $employeeId < 1
        ) {
            return false;
        }

        $checkInTime = (string) (
            $candidate['check_in_time'] ?? ''
        );
        $checkOutTime = (string) (
            $candidate['check_out_time'] ?? ''
        );
        $checkInEnabled =
            !empty($candidate['check_in_enabled'])
            && $this->validTime($checkInTime);
        $checkOutEnabled =
            !empty($candidate['check_out_enabled'])
            && $this->validTime($checkOutTime);
        $overnight =
            $checkInEnabled
            && $checkOutEnabled
            && strcmp($checkOutTime, $checkInTime) <= 0;
        $workDate = $localDate;
        $scheduledDate = $localDate;
        $attendance = null;
        $kind = null;
        $time = null;

        if ($overnight) {
            $previousWorkDate = $localNow
                ->modify('-1 day')
                ->format('Y-m-d');
            $previousAttendance =
                $this->attendance->find(
                    $companyId,
                    $employeeId,
                    $previousWorkDate
                );

            if (
                is_array($previousAttendance)
                && !empty(
                    $previousAttendance['check_in_at']
                )
                && empty(
                    $previousAttendance['check_out_at']
                )
            ) {
                $workDate = $previousWorkDate;
                $attendance = $previousAttendance;
                $kind = 'check_out';
                $time = $checkOutTime;
            }
        }

        $workday = new DateTimeImmutable(
            $workDate . ' 12:00:00',
            $timezone
        );
        $weekday = (int) $workday->format('N');
        $mask = (int) (
            $candidate['workday_mask'] ?? 0
        );

        if (
            ($mask & (1 << ($weekday - 1))) === 0
        ) {
            return false;
        }

        $calendar = $this->calendars
            ->contextForUser(
                $companyId,
                $userId,
                $workDate
            );

        if ($calendar !== null) {
            $day = is_array($calendar['day'] ?? null)
                ? $calendar['day']
                : null;
            $holiday = is_array(
                $calendar['holiday'] ?? null
            )
                ? $calendar['holiday']
                : null;

            if (
                $day !== null
                && empty($day['working_day'])
            ) {
                return false;
            }

            if (
                $holiday !== null
                && ($holiday['day_portion'] ?? 'full')
                    === 'full'
            ) {
                return false;
            }
        }

        $attendance = $attendance
            ?? $this->attendance->find(
                $companyId,
                $employeeId,
                $workDate
            );
        $status = (string) (
            $attendance['attendance_status'] ?? ''
        );

        if (in_array(
            $status,
            ['absent', 'on_leave', 'holiday'],
            true
        )) {
            return false;
        }

        $hasCheckIn = is_array($attendance)
            && !empty($attendance['check_in_at']);
        $hasCheckOut = is_array($attendance)
            && !empty($attendance['check_out_at']);

        if (
            $kind === null
            && !$hasCheckIn
            && $checkInEnabled
        ) {
            $kind = 'check_in';
            $time = $checkInTime;
        } elseif (
            $kind === null
            && $hasCheckIn
            && !$hasCheckOut
            && $checkOutEnabled
        ) {
            $kind = 'check_out';
            $time = $checkOutTime;
            $scheduledDate = $overnight
                ? $workday->modify('+1 day')
                    ->format('Y-m-d')
                : $workDate;
        }

        if (
            $kind === null
            || !$this->validTime((string) $time)
        ) {
            return false;
        }

        $scheduled = new DateTimeImmutable(
            $scheduledDate . ' ' . $time,
            $timezone
        );
        $lead = max(
            0,
            (int) (
                $candidate[
                    'reminder_lead_minutes'
                ] ?? 0
            )
        );
        $notifyAt = $scheduled->modify(
            '-' . $lead . ' minutes'
        );

        if (
            $localNow < $notifyAt
            || $localNow > $scheduled->modify(
                '+12 hours'
            )
        ) {
            return false;
        }

        $action = $kind === 'check_in'
            ? 'Check in'
            : 'Check out';
        $overdue = $localNow > $scheduled;

        return $this->notifications->create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'notification_type' => $kind,
            'title' => $overdue
                ? $action . ' is overdue'
                : $action . ' reminder',
            'body' => $action . ' for your '
                . $time . ' schedule ('
                . $timezoneName . ').',
            'scheduled_for' => $scheduled
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s'),
            'local_date' => $workDate,
            'dedupe_key' => 'attendance:'
                . $kind . ':' . $workDate,
        ]);
    }

    private function validTime(string $value): bool
    {
        return preg_match(
            '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
            $value
        ) === 1;
    }

    private function dateLabel(
        string $value,
        string $timezoneName
    ): string
    {
        try {
            $timezone = new DateTimeZone(
                $timezoneName
            );
            $date = new DateTimeImmutable(
                $value,
                new DateTimeZone('UTC')
            );

            return $date->setTimezone($timezone)
                ->format('d M Y, H:i T');
        } catch (\Throwable $exception) {
            return $value;
        }
    }
}
