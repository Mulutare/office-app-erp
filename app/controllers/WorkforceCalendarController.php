<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\WorkforceCalendarService;

final class WorkforceCalendarController
{
    private AuthorizationService $authorization;
    private WorkforceCalendarService $calendars;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->calendars =
            new WorkforceCalendarService();
    }

    public function index(): void
    {
        $this->requireManagement();
        $workspace = $this->calendars->workspace(
            $this->queryInteger('calendar'),
            $this->queryInteger('year')
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
            'pageTitle' => 'Workforce Calendars',
            'pageDescription' =>
                'International workweeks, public holidays and effective employee schedules.',
            'contentView' =>
                'attendance.calendars.index',
            'user' => $_SESSION['auth'],
            'workspace' => $workspace,
            'notice' => \getFlash(
                'workforce_calendar_notice'
            ),
            'calendarErrors' => \getFlash(
                'workforce_calendar_errors',
                []
            ),
            'calendarOld' => \getFlash(
                'workforce_calendar_old',
                []
            ),
            'weekErrors' => \getFlash(
                'workforce_week_errors',
                []
            ),
            'holidayErrors' => \getFlash(
                'workforce_holiday_errors',
                []
            ),
            'holidayOld' => \getFlash(
                'workforce_holiday_old',
                []
            ),
            'scheduleErrors' => \getFlash(
                'workforce_schedule_errors',
                []
            ),
            'scheduleOld' => \getFlash(
                'workforce_schedule_old',
                []
            ),
        ]);
    }

    public function storeCalendar(): void
    {
        $this->requireManagement();
        $this->verifyCsrf(
            'workforce_calendar_errors',
            '/attendance/calendars'
        );
        $input = [
            'code' => \postString('code'),
            'name' => \postString('name'),
            'timezone' => \postString('timezone'),
            'country_code' =>
                \postString('country_code'),
            'subdivision_code' =>
                \postString('subdivision_code'),
            'week_start' =>
                \postString('week_start'),
            'is_default' =>
                isset($_POST['is_default']),
        ];
        $result = $this->calendars->create(
            $input,
            $this->actorUserId()
        );

        if (!$result['successful']) {
            \flash(
                'workforce_calendar_errors',
                $result['errors']
            );
            \flash(
                'workforce_calendar_old',
                $input
            );
            \redirect('/attendance/calendars');
        }

        \flash('workforce_calendar_notice', [
            'type' => 'success',
            'message' => sprintf(
                'Workforce calendar %s was created.',
                (string) $result['name']
            ),
        ]);
        \redirect(
            '/attendance/calendars?calendar='
            . (int) $result['calendarId']
        );
    }

    public function saveWeek(): void
    {
        $this->requireManagement();
        $calendarId = $this->postInteger(
            'calendar_id'
        );
        $redirect = $this->calendarRedirect(
            $calendarId
        );
        $this->verifyCsrf(
            'workforce_week_errors',
            $redirect
        );
        $result = $this->calendars->saveWeek(
            $calendarId,
            is_array($_POST['days'] ?? null)
                ? $_POST['days']
                : [],
            $this->actorUserId()
        );

        if (!empty($result['notFound'])) {
            $this->notFound();
        }

        if (!$result['successful']) {
            \flash(
                'workforce_week_errors',
                $result['errors']
            );
            \redirect($redirect);
        }

        \flash('workforce_calendar_notice', [
            'type' => 'success',
            'message' =>
                'The standard workweek was updated.',
        ]);
        \redirect($redirect);
    }

    public function storeHoliday(): void
    {
        $this->requireManagement();
        $calendarId = $this->postInteger(
            'calendar_id'
        );
        $year = substr(
            \postString('holiday_date'),
            0,
            4
        );
        $redirect = $this->calendarRedirect(
            $calendarId,
            ctype_digit($year) ? (int) $year : 0
        );
        $this->verifyCsrf(
            'workforce_holiday_errors',
            $redirect
        );
        $input = [
            'holiday_date' =>
                \postString('holiday_date'),
            'name' => \postString('name'),
            'holiday_type' =>
                \postString('holiday_type'),
            'day_portion' =>
                \postString('day_portion'),
            'observed' =>
                isset($_POST['observed']),
            'description' =>
                \postString('description'),
        ];
        $result = $this->calendars->addHoliday(
            $calendarId,
            $input,
            $this->actorUserId()
        );

        if (!empty($result['notFound'])) {
            $this->notFound();
        }

        if (!$result['successful']) {
            \flash(
                'workforce_holiday_errors',
                $result['errors']
            );
            \flash(
                'workforce_holiday_old',
                $input
            );
            \redirect($redirect);
        }

        \flash('workforce_calendar_notice', [
            'type' => 'success',
            'message' => sprintf(
                '%s was added to the holiday calendar.',
                (string) $result['name']
            ),
        ]);
        \redirect($redirect);
    }

    public function assignSchedule(): void
    {
        $this->requireManagement();
        $calendarId = $this->postInteger(
            'calendar_id'
        );
        $redirect = $this->calendarRedirect(
            $calendarId
        );
        $this->verifyCsrf(
            'workforce_schedule_errors',
            $redirect
        );
        $input = [
            'employee_id' =>
                \postString('employee_id'),
            'calendar_id' => (string) $calendarId,
            'effective_from' =>
                \postString('effective_from'),
            'effective_to' =>
                \postString('effective_to'),
        ];
        $result = $this->calendars->assign(
            $input,
            $this->actorUserId()
        );

        if (!$result['successful']) {
            \flash(
                'workforce_schedule_errors',
                $result['errors']
            );
            \flash(
                'workforce_schedule_old',
                $input
            );
            \redirect($redirect);
        }

        \flash('workforce_calendar_notice', [
            'type' => 'success',
            'message' =>
                'The employee work schedule was assigned.',
        ]);
        \redirect($redirect);
    }

    private function requireManagement(): void
    {
        $this->authorization
            ->requireModule('attendance');
        $this->authorization
            ->requireTenantPermission(
                'attendance.records.manage'
            );
    }

    private function verifyCsrf(
        string $flashKey,
        string $redirect
    ): void {
        if (\verifyCsrfToken(
            \postString('_token')
        )) {
            return;
        }

        \flash($flashKey, [
            'form' =>
                'The form session expired. Please try again.',
        ]);
        \redirect($redirect);
    }

    private function calendarRedirect(
        int $calendarId,
        int $year = 0
    ): string {
        $query = $calendarId > 0
            ? '?calendar=' . $calendarId
            : '';

        if ($year > 0) {
            $query .= ($query === '' ? '?' : '&')
                . 'year=' . $year;
        }

        return '/attendance/calendars' . $query;
    }

    private function actorUserId(): int
    {
        return (int) (
            $_SESSION['auth']['user_id'] ?? 0
        );
    }

    private function queryInteger(string $key): int
    {
        $value = $_GET[$key] ?? null;

        return is_string($value)
            && ctype_digit($value)
                ? (int) $value
                : (is_int($value) ? $value : 0);
    }

    private function postInteger(string $key): int
    {
        $value = $_POST[$key] ?? null;

        return is_string($value)
            && ctype_digit($value)
                ? (int) $value
                : (is_int($value) ? $value : 0);
    }

    private function notFound(): never
    {
        http_response_code(404);
        \view('errors.404', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
        ]);
        exit;
    }
}
