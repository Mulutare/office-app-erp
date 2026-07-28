<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\LeaveManagementService;

final class LeaveController
{
    private AuthorizationService $authorization;
    private LeaveManagementService $leave;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->leave =
            new LeaveManagementService();
    }

    public function index(): void
    {
        $this->requireView();
        $dashboard = $this->leave->dashboard(
            $this->queryStatus()
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
            'pageTitle' => 'Leave Management',
            'pageDescription' =>
                'Leave requests, approval decisions and workforce availability.',
            'contentView' => 'hr.leave.index',
            'user' => $_SESSION['auth'],
            'requests' => $dashboard['requests'],
            'leaveTypes' =>
                $dashboard['leaveTypes'],
            'employees' => $dashboard['employees'],
            'summary' => $dashboard['summary'],
            'statuses' => $dashboard['statuses'],
            'filterStatus' =>
                $dashboard['filterStatus'],
            'canManage' => $this->can(
                'hr.leave.manage'
            ),
            'canApprove' => $this->can(
                'hr.leave.approve'
            ),
            'notice' => \getFlash('leave_notice'),
            'errors' => \getFlash(
                'leave_errors',
                []
            ),
            'old' => \getFlash('leave_old', []),
            'decisionErrors' => \getFlash(
                'leave_decision_errors',
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
            \flash('leave_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);
            \redirect('/hr/leave');
        }

        $input = [
            'employee_id' =>
                \postString('employee_id'),
            'leave_type_id' =>
                \postString('leave_type_id'),
            'start_date' =>
                \postString('start_date'),
            'end_date' =>
                \postString('end_date'),
            'reason' => \postString('reason'),
        ];
        $result = $this->leave->create(
            $input,
            (int) $_SESSION['auth']['user_id']
        );

        if (!$result['successful']) {
            \flash('leave_errors', $result['errors']);
            \flash('leave_old', $input);
            \redirect('/hr/leave');
        }

        \flash('leave_notice', [
            'type' => 'success',
            'message' =>
                'Leave request was submitted for approval.',
        ]);
        \redirect('/hr/leave');
    }

    public function decide(): void
    {
        $this->requireApprove();

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash('leave_decision_errors', [
                'form' =>
                    'The decision session expired. Please try again.',
            ]);
            \redirect('/hr/leave');
        }

        $requestId = $this->postInteger(
            'leave_request_id'
        );

        if ($requestId < 1) {
            $this->notFound();
        }

        $result = $this->leave->decide(
            $requestId,
            \postString('decision'),
            \postString('decision_note'),
            (int) $_SESSION['auth']['user_id']
        );

        if (!empty($result['notFound'])) {
            $this->notFound();
        }

        if (!$result['successful']) {
            \flash(
                'leave_decision_errors',
                $result['errors']
            );
            \redirect('/hr/leave');
        }

        \flash('leave_notice', [
            'type' => 'success',
            'message' => (
                $result['status'] ?? ''
            ) === 'approved'
                ? 'Leave request was approved.'
                : 'Leave request was rejected.',
        ]);
        \redirect('/hr/leave');
    }

    private function requireView(): void
    {
        $this->authorization->requireModule('hr');
        $this->authorization
            ->requireAnyPermission([
                'hr.leave.view',
                'hr.leave.manage',
                'hr.leave.approve',
            ]);
    }

    private function requireManage(): void
    {
        $this->authorization->requireModule('hr');
        $this->authorization
            ->requirePermission('hr.leave.manage');
    }

    private function requireApprove(): void
    {
        $this->authorization->requireModule('hr');
        $this->authorization
            ->requirePermission('hr.leave.approve');
    }

    private function can(string $permission): bool
    {
        return in_array(
            $permission,
            $_SESSION['auth']['permissions'] ?? [],
            true
        );
    }

    private function queryStatus(): string
    {
        $status = $_GET['status'] ?? '';

        return is_string($status)
            ? trim($status)
            : '';
    }

    private function postInteger(string $key): int
    {
        $value = $_POST[$key] ?? null;

        if (is_int($value)) {
            return max(0, $value);
        }

        return is_string($value)
            && ctype_digit($value)
                ? (int) $value
                : 0;
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
