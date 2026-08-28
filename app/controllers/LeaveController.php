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
        $canViewCompany = $this->canAny([
            'hr.leave.view',
            'hr.leave.manage',
            'hr.leave.approve',
        ]);
        $canManageCompany = $this->can(
            'hr.leave.manage'
        );
        $canApproveCompany = $this->can(
            'hr.leave.approve'
        );
        $canRequestSelf = $this->can(
            'hr.leave.self.request'
        );
        $canApproveTeam = $this->can(
            'hr.leave.team.approve'
        );
        $dashboard = $this->leave->workspace(
            (int) $_SESSION['auth']['user_id'],
            $this->queryStatus(),
            $canViewCompany,
            $canManageCompany,
            $canApproveCompany,
            $canRequestSelf,
            $canApproveTeam
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
            'pageTitle' => 'Leave',
            'pageDescription' =>
                'Personal leave requests, manager approvals and workforce availability.',
            'contentView' => 'hr.leave.index',
            'user' => $_SESSION['auth'],
            'requests' => $dashboard['requests'],
            'leaveTypes' =>
                $dashboard['leaveTypes'],
            'employees' => $dashboard['employees'],
            'employee' => $dashboard['employee'],
            'balances' => $dashboard['balances'],
            'summary' => $dashboard['summary'],
            'statuses' => $dashboard['statuses'],
            'filterStatus' =>
                $dashboard['filterStatus'],
            'scopeLabel' =>
                $dashboard['scopeLabel'],
            'canManage' =>
                $dashboard['canManageCompany'],
            'canRequestSelf' =>
                $dashboard['canRequestSelf'],
            'canApprove' =>
                $dashboard['canApprove'],
            'canManagePolicies' => $this->can(
                'hr.leave.policy.manage'
            ),
            'canManageBalances' => $this->can(
                'hr.leave.balance.manage'
            ),
            'profileRequired' =>
                $dashboard['profileRequired'],
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
        $canManageCompany = $this->can(
            'hr.leave.manage'
        );
        $canRequestSelf = $this->can(
            'hr.leave.self.request'
        );
        $this->requireRequestPermission(
            $canManageCompany,
            $canRequestSelf
        );

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
        $result = $this->leave->createForActor(
            $input,
            (int) $_SESSION['auth']['user_id'],
            $canManageCompany,
            $canRequestSelf
        );

        if (!$result['successful']) {
            \flash('leave_errors', $result['errors']);
            \flash('leave_old', $input);
            \redirect('/hr/leave');
        }

        $approvers = is_array(
            $result['approvers'] ?? null
        )
            ? $result['approvers']
            : [];
        $approverNames = array_values(
            array_filter(array_map(
                static fn (array $approver): string =>
                    trim((string) (
                        $approver['approver_name']
                            ?? ''
                    )),
                $approvers
            ))
        );
        \flash('leave_notice', [
            'type' => 'success',
            'message' =>
                ($result['status'] ?? '') ===
                    'approved'
                ? 'Leave request was approved automatically under the selected policy.'
                : 'Leave request was submitted to '
                    . implode(
                        ', then ',
                        $approverNames
                    )
                    . ' for approval.',
        ]);
        \redirect('/hr/leave');
    }

    public function decide(): void
    {
        $canApproveCompany = $this->can(
            'hr.leave.approve'
        );
        $canApproveTeam = $this->can(
            'hr.leave.team.approve'
        );
        $this->requireDecisionPermission(
            $canApproveCompany,
            $canApproveTeam
        );

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

        $result = $this->leave->decideForActor(
            $requestId,
            \postString('decision'),
            \postString('decision_note'),
            (int) $_SESSION['auth']['user_id'],
            $canApproveCompany,
            $canApproveTeam
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
            'message' => !empty(
                $result['finalized']
            )
                ? (
                    ($result['status'] ?? '')
                    === 'approved'
                        ? 'Leave request completed every required approval and was approved.'
                        : 'Leave request was rejected.'
                )
                : 'Manager approval was recorded. The request is now waiting for HR approval.',
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
                'hr.leave.self.view',
                'hr.leave.self.request',
                'hr.leave.team.approve',
                'hr.leave.policy.manage',
                'hr.leave.balance.manage',
            ]);
    }

    private function requireRequestPermission(
        bool $canManageCompany,
        bool $canRequestSelf
    ): void
    {
        $this->authorization->requireModule('hr');

        if (
            !$canManageCompany
            && !$canRequestSelf
        ) {
            $this->authorization
                ->requirePermission(
                    'hr.leave.manage'
                );
        }
    }

    private function requireDecisionPermission(
        bool $canApproveCompany,
        bool $canApproveTeam
    ): void
    {
        $this->authorization->requireModule('hr');

        if (
            !$canApproveCompany
            && !$canApproveTeam
        ) {
            $this->authorization
                ->requirePermission(
                    'hr.leave.approve'
                );
        }
    }

    private function can(string $permission): bool
    {
        return in_array(
            $permission,
            $_SESSION['auth']['permissions'] ?? [],
            true
        );
    }

    /**
     * @param list<string> $permissions
     */
    private function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($permission)) {
                return true;
            }
        }

        return false;
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
