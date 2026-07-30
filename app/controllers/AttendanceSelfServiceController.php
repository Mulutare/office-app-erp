<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AttendanceSelfServiceService;
use App\Services\AttendanceReminderService;
use App\Services\AttendanceNotificationService;
use App\Services\AttendancePushService;
use App\Services\AuthorizationService;

final class AttendanceSelfServiceController
{
    private AuthorizationService $authorization;
    private AttendanceSelfServiceService $attendance;
    private AttendanceReminderService $reminders;
    private AttendanceNotificationService $notifications;
    private AttendancePushService $push;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->attendance =
            new AttendanceSelfServiceService();
        $this->reminders =
            new AttendanceReminderService();
        $this->notifications =
            new AttendanceNotificationService();
        $this->push =
            new AttendancePushService();
    }

    public function index(): void
    {
        $this->requirePermission(
            'attendance.self.view'
        );
        $workspace = $this->attendance->workspace(
            $this->actorUserId(),
            $this->queryMonth()
        );
        $reminderWorkspace =
            $this->reminders->workspace(
                $this->actorUserId(),
                $workspace
            );

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'My Attendance',
            'pageDescription' =>
                'Personal check-in, working time and monthly attendance history.',
            'contentView' =>
                'attendance.self.index',
            'user' => $_SESSION['auth'],
            'employee' => $workspace['employee'],
            'profileRequired' =>
                $workspace['profileRequired'],
            'records' => $workspace['records'],
            'today' => $workspace['today'],
            'todayDate' =>
                $workspace['todayDate'],
            'summary' => $workspace['summary'],
            'range' => $workspace['range'],
            'canCheckIn' =>
                $workspace['canCheckIn'],
            'canCheckOut' =>
                $workspace['canCheckOut'],
            'canRecord' => $this->can(
                'attendance.self.record'
            ),
            'canViewTeam' => $this->can(
                'attendance.team.view'
            ),
            'reminderSettings' =>
                $reminderWorkspace['settings'],
            'attendanceNotification' =>
                $reminderWorkspace[
                    'notification'
                ],
            'workSchedule' =>
                $workspace['workSchedule']
                    ?? $reminderWorkspace['schedule'],
            'serverNotifications' =>
                $this->notifications->inbox(
                    $this->actorUserId()
                ),
            'pushStatus' => $this->push->status(
                $this->actorUserId()
            ),
            'workdayOptions' =>
                $reminderWorkspace[
                    'workdayOptions'
                ],
            'reminderLeadOptions' =>
                $reminderWorkspace[
                    'leadOptions'
                ],
            'timezoneOptions' =>
                $reminderWorkspace[
                    'timezoneOptions'
                ],
            'reminderOld' => \getFlash(
                'attendance_reminder_old',
                []
            ),
            'notice' => \getFlash(
                'attendance_self_notice'
            ),
            'errors' => \getFlash(
                'attendance_self_errors',
                []
            ),
            'reminderErrors' => \getFlash(
                'attendance_reminder_errors',
                []
            ),
        ]);
    }

    public function checkIn(): void
    {
        $this->recordAction('checkIn');
    }

    public function checkOut(): void
    {
        $this->recordAction('checkOut');
    }

    public function saveReminders(): void
    {
        $this->requirePermission(
            'attendance.self.view'
        );

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash(
                'attendance_reminder_errors',
                [
                    'form' =>
                        'The reminder session expired. Please try again.',
                ]
            );
            \redirect('/attendance/me');
        }

        $input = [
            'timezone' =>
                \postString('timezone'),
            'workdays' =>
                $_POST['workdays'] ?? [],
            'check_in_enabled' =>
                isset($_POST[
                    'check_in_enabled'
                ]),
            'check_in_time' =>
                \postString('check_in_time'),
            'check_out_enabled' =>
                isset($_POST[
                    'check_out_enabled'
                ]),
            'check_out_time' =>
                \postString('check_out_time'),
            'reminder_lead_minutes' =>
                \postString(
                    'reminder_lead_minutes'
                ),
            'browser_notifications_enabled' =>
                isset($_POST[
                    'browser_notifications_enabled'
                ]),
        ];
        $result = $this->reminders->save(
            $input,
            $this->actorUserId()
        );

        if (!$result['successful']) {
            \flash(
                'attendance_reminder_errors',
                $result['errors']
            );
            \flash(
                'attendance_reminder_old',
                $result['old'] ?? $input
            );
            \redirect('/attendance/me');
        }

        \flash('attendance_self_notice', [
            'type' => 'success',
            'message' =>
                'Your personal attendance reminders were updated.',
        ]);
        \redirect('/attendance/me');
    }

    public function team(): void
    {
        $this->requirePermission(
            'attendance.team.view'
        );
        $workspace =
            $this->attendance->teamWorkspace(
                $this->actorUserId(),
                $this->queryMonth()
            );

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Team Attendance',
            'pageDescription' =>
                'Monthly attendance visibility for direct reports only.',
            'contentView' =>
                'attendance.team.index',
            'user' => $_SESSION['auth'],
            'people' => $workspace['people'],
            'summary' => $workspace['summary'],
            'range' => $workspace['range'],
        ]);
    }

    public function markNotificationRead(): void
    {
        $this->requirePermission(
            'attendance.self.view'
        );

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash('attendance_self_errors', [
                'form' =>
                    'The notification session expired. Please try again.',
            ]);
            \redirect('/attendance/me');
        }

        $notificationId = $this->postInteger(
            'notification_id'
        );

        if ($notificationId > 0) {
            $this->notifications->markRead(
                $this->actorUserId(),
                $notificationId
            );
        }

        \redirect('/attendance/me');
    }

    public function subscribePush(): void
    {
        $this->requirePermission(
            'attendance.self.view'
        );

        if (!\verifyCsrfToken(
            \postString('_token')
        )) {
            $this->jsonResponse([
                'successful' => false,
                'message' =>
                    'The notification session expired. Refresh the page and try again.',
                'errors' => [
                    'csrf' => 'Invalid CSRF token.',
                ],
            ], 419);
        }

        $result = $this->push->subscribe(
            $this->actorUserId(),
            [
                'endpoint' =>
                    \postString('endpoint'),
                'p256dh' =>
                    \postString('p256dh'),
                'auth' => \postString('auth'),
                'content_encoding' =>
                    \postString(
                        'content_encoding'
                    ),
            ]
        );

        $this->jsonResponse(
            $result,
            $result['successful'] ? 200 : 422
        );
    }

    public function unsubscribePush(): void
    {
        $this->requirePermission(
            'attendance.self.view'
        );

        if (!\verifyCsrfToken(
            \postString('_token')
        )) {
            $this->jsonResponse([
                'successful' => false,
                'message' =>
                    'The notification session expired. Refresh the page and try again.',
                'errors' => [
                    'csrf' => 'Invalid CSRF token.',
                ],
            ], 419);
        }

        $result = $this->push->unsubscribe(
            $this->actorUserId(),
            \postString('endpoint')
        );

        $this->jsonResponse(
            $result,
            $result['successful'] ? 200 : 422
        );
    }

    private function recordAction(
        string $method
    ): void {
        $this->requirePermission(
            'attendance.self.record'
        );

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash('attendance_self_errors', [
                'form' =>
                    'The attendance session expired. Please try again.',
            ]);
            \redirect('/attendance/me');
        }

        $result = $this->attendance->{$method}(
            $this->actorUserId()
        );

        if (!$result['successful']) {
            \flash(
                'attendance_self_errors',
                $result['errors']
            );
            \redirect('/attendance/me');
        }

        \flash('attendance_self_notice', [
            'type' => 'success',
            'message' => $method === 'checkIn'
                ? 'Your check-in was recorded.'
                : 'Your check-out was recorded.',
        ]);
        \redirect('/attendance/me');
    }

    private function requirePermission(
        string $permission
    ): void {
        $this->authorization
            ->requireModule('attendance');
        $this->authorization
            ->requirePermission($permission);
    }

    private function can(string $permission): bool
    {
        return in_array(
            $permission,
            $_SESSION['auth']['permissions'] ?? [],
            true
        );
    }

    private function actorUserId(): int
    {
        return (int) (
            $_SESSION['auth']['user_id'] ?? 0
        );
    }

    private function queryMonth(): string
    {
        $month = $_GET['month'] ?? '';

        return is_string($month)
            ? trim($month)
            : '';
    }

    private function postInteger(string $key): int
    {
        $value = $_POST[$key] ?? null;

        return is_string($value)
            && ctype_digit($value)
                ? (int) $value
                : (is_int($value) ? $value : 0);
    }

    /** @param array<string, mixed> $payload */
    private function jsonResponse(
        array $payload,
        int $statusCode = 200
    ): never {
        http_response_code($statusCode);
        header(
            'Content-Type: application/json; charset=UTF-8'
        );
        header('Cache-Control: no-store');
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );
        echo is_string($json)
            ? $json
            : '{"successful":false}';
        exit;
    }
}
