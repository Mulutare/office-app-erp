<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AttendanceManagementService;
use App\Services\AuthorizationService;

final class AttendanceController
{
    private AuthorizationService $authorization;
    private AttendanceManagementService $attendance;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->attendance =
            new AttendanceManagementService();
    }

    public function index(): void
    {
        $this->requireView();
        $dashboard = $this->attendance->dashboard(
            $this->queryDate()
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
            'pageTitle' => 'Attendance Control',
            'pageDescription' =>
                'Daily workforce presence, exceptions and recorded working time.',
            'contentView' => 'attendance.index',
            'user' => $_SESSION['auth'],
            'date' => $dashboard['date'],
            'records' => $dashboard['records'],
            'summary' => $dashboard['summary'],
            'statuses' => $dashboard['statuses'],
            'canManage' => $this->canManage(),
            'notice' =>
                \getFlash('attendance_notice'),
            'errors' => \getFlash(
                'attendance_errors',
                []
            ),
            'old' => \getFlash(
                'attendance_old',
                []
            ),
        ]);
    }

    public function store(): void
    {
        $this->requireManage();

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash('attendance_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);
            \redirect('/attendance');
        }

        $input = [
            'employee_id' =>
                \postString('employee_id'),
            'attendance_date' =>
                \postString('attendance_date'),
            'attendance_status' =>
                \postString('attendance_status'),
            'check_in' =>
                \postString('check_in'),
            'check_out' =>
                \postString('check_out'),
            'notes' => \postString('notes'),
        ];
        $result = $this->attendance->record(
            $input,
            (int) $_SESSION['auth']['user_id']
        );
        $date = (string) (
            $input['attendance_date'] ?? ''
        );
        $redirect = '/attendance'
            . ($date === ''
                ? ''
                : '?date=' . rawurlencode($date));

        if (!$result['successful']) {
            \flash(
                'attendance_errors',
                $result['errors']
            );
            \flash('attendance_old', $input);
            \redirect($redirect);
        }

        \flash('attendance_notice', [
            'type' => 'success',
            'message' => !empty($result['changed'])
                ? 'Attendance was recorded successfully.'
                : 'No attendance changes were required.',
        ]);
        \redirect($redirect);
    }

    private function requireView(): void
    {
        $this->authorization
            ->requireModule('attendance');
        $this->authorization
            ->requireAnyPermission([
                'attendance.records.view',
                'attendance.records.manage',
            ]);
    }

    private function requireManage(): void
    {
        $this->authorization
            ->requireModule('attendance');
        $this->authorization
            ->requirePermission(
                'attendance.records.manage'
            );
    }

    private function canManage(): bool
    {
        return in_array(
            'attendance.records.manage',
            $_SESSION['auth']['permissions'] ?? [],
            true
        );
    }

    private function queryDate(): string
    {
        $date = $_GET['date'] ?? '';

        return is_string($date)
            ? trim($date)
            : '';
    }
}
