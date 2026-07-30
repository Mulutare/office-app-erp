<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AttendanceSelfServiceService;
use App\Services\AuthorizationService;

final class AttendanceSelfServiceController
{
    private AuthorizationService $authorization;
    private AttendanceSelfServiceService $attendance;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->attendance =
            new AttendanceSelfServiceService();
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
            'notice' => \getFlash(
                'attendance_self_notice'
            ),
            'errors' => \getFlash(
                'attendance_self_errors',
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
}
