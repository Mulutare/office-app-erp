<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\LeaveBalanceManagementService;

final class LeaveBalanceController
{
    private AuthorizationService $authorization;
    private LeaveBalanceManagementService $balances;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->balances =
            new LeaveBalanceManagementService();
    }

    public function index(): void
    {
        $this->requireManagement();
        $workspace = $this->balances->workspace(
            $this->queryInteger('employee'),
            $this->queryInteger('year'),
            $this->queryInteger('policy')
        );

        if (!empty($workspace['notFound'])) {
            $this->notFound();
        }

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Leave Balances',
            'pageDescription' =>
                'Manage annual employee allocations, carry-over and auditable balance adjustments.',
            'contentView' =>
                'hr.leave.balances.index',
            'user' => $_SESSION['auth'],
            'workspace' => $workspace,
            'notice' => \getFlash(
                'leave_balance_notice'
            ),
            'allocationErrors' => \getFlash(
                'leave_allocation_errors',
                []
            ),
            'allocationOld' => \getFlash(
                'leave_allocation_old',
                []
            ),
            'adjustmentErrors' => \getFlash(
                'leave_adjustment_errors',
                []
            ),
            'adjustmentOld' => \getFlash(
                'leave_adjustment_old',
                []
            ),
        ]);
    }

    public function saveAllocation(): void
    {
        $this->requireManagement();
        $input = $this->allocationInput();

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash('leave_allocation_errors', [
                'form' =>
                    'The allocation session expired. Please try again.',
            ]);
            \redirect($this->redirect($input));
        }

        $result = $this->balances
            ->saveAllocation(
                $input,
                (int) $_SESSION['auth']['user_id']
            );

        if (!$result['successful']) {
            \flash(
                'leave_allocation_errors',
                $result['errors']
            );
            \flash('leave_allocation_old', $input);
            \redirect($this->redirect($input));
        }

        \flash('leave_balance_notice', [
            'type' => 'success',
            'message' => !empty($result['changed'])
                ? 'Annual leave allocation was saved.'
                : 'No allocation changes were required.',
        ]);
        \redirect($this->redirect($input));
    }

    public function addAdjustment(): void
    {
        $this->requireManagement();
        $input = $this->adjustmentInput();

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash('leave_adjustment_errors', [
                'form' =>
                    'The adjustment session expired. Please try again.',
            ]);
            \redirect($this->redirect($input));
        }

        $result = $this->balances
            ->addAdjustment(
                $input,
                (int) $_SESSION['auth']['user_id']
            );

        if (!$result['successful']) {
            \flash(
                'leave_adjustment_errors',
                $result['errors']
            );
            \flash('leave_adjustment_old', $input);
            \redirect($this->redirect($input));
        }

        \flash('leave_balance_notice', [
            'type' => 'success',
            'message' =>
                'Leave balance adjustment was recorded.',
        ]);
        \redirect($this->redirect($input));
    }

    private function requireManagement(): void
    {
        $this->authorization->requireModule('hr');
        $this->authorization
            ->requireTenantPermission(
                'hr.leave.balance.manage'
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function allocationInput(): array
    {
        return [
            'employee_id' =>
                \postString('employee_id'),
            'leave_type_id' =>
                \postString('leave_type_id'),
            'year' => \postString('year'),
            'entitlement_days' =>
                \postString('entitlement_days'),
            'carry_over_days' =>
                \postString('carry_over_days'),
            'notes' => \postString('notes'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adjustmentInput(): array
    {
        return [
            'employee_id' =>
                \postString('employee_id'),
            'leave_type_id' =>
                \postString('leave_type_id'),
            'year' => \postString('year'),
            'adjustment_type' =>
                \postString('adjustment_type'),
            'days' => \postString('days'),
            'effective_date' =>
                \postString('effective_date'),
            'reason' => \postString('reason'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function redirect(array $input): string
    {
        return '/hr/leave/balances?'
            . http_build_query([
                'employee' =>
                    $input['employee_id'] ?? '',
                'year' => $input['year'] ?? '',
                'policy' =>
                    $input['leave_type_id'] ?? '',
            ]);
    }

    private function queryInteger(string $key): int
    {
        $value = $_GET[$key] ?? null;

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
