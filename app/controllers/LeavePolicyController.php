<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\LeavePolicyService;

final class LeavePolicyController
{
    private AuthorizationService $authorization;
    private LeavePolicyService $policies;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->policies =
            new LeavePolicyService();
    }

    public function index(): void
    {
        $this->requireManagement();
        $listing = $this->policies->listing();

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Leave Policies',
            'pageDescription' =>
                'Configure company leave types, annual entitlements and approval controls.',
            'contentView' =>
                'hr.leave.policies.index',
            'user' => $_SESSION['auth'],
            'policies' => $listing['policies'],
            'summary' => $listing['summary'],
            'notice' => \getFlash(
                'leave_policy_notice'
            ),
        ]);
    }

    public function create(): void
    {
        $this->requireManagement();
        $old = \getFlash(
            'leave_policy_create_old'
        );

        if (!is_array($old)) {
            $old = [
                'annual_entitlement' => '0.00',
                'requires_approval' => true,
                'active' => true,
            ];
        }

        $this->renderForm(
            'create',
            0,
            $old,
            \getFlash(
                'leave_policy_create_errors',
                []
            )
        );
    }

    public function store(): void
    {
        $this->requireManagement();

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash(
                'leave_policy_create_errors',
                [
                    'form' =>
                        'The form session expired. Please try again.',
                ]
            );
            \redirect('/hr/leave/policies/create');
        }

        $input = $this->policyInput();
        $result = $this->policies->create(
            $input,
            (int) $_SESSION['auth']['user_id']
        );

        if (!$result['successful']) {
            \flash(
                'leave_policy_create_errors',
                $result['errors']
            );
            \flash(
                'leave_policy_create_old',
                $input
            );
            \redirect('/hr/leave/policies/create');
        }

        \flash('leave_policy_notice', [
            'type' => 'success',
            'message' => sprintf(
                'Leave policy %s was created successfully.',
                (string) $result['policyName']
            ),
        ]);
        \redirect('/hr/leave/policies');
    }

    public function edit(): void
    {
        $this->requireManagement();
        $leaveTypeId = $this->queryInteger('id');
        $policy = $this->policies->form(
            $leaveTypeId
        );

        if ($policy === null) {
            $this->notFound();
        }

        $old = \getFlash(
            'leave_policy_update_old'
        );

        if (!is_array($old)) {
            $old = $policy;
        }

        $this->renderForm(
            'edit',
            $leaveTypeId,
            $old,
            \getFlash(
                'leave_policy_update_errors',
                []
            )
        );
    }

    public function update(): void
    {
        $this->requireManagement();
        $leaveTypeId = $this->postInteger(
            'leave_type_id'
        );

        if ($leaveTypeId < 1) {
            $this->notFound();
        }

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash(
                'leave_policy_update_errors',
                [
                    'form' =>
                        'The form session expired. Please try again.',
                ]
            );
            \redirect(
                '/hr/leave/policies/edit?id='
                . $leaveTypeId
            );
        }

        $input = $this->policyInput();
        $result = $this->policies->update(
            $leaveTypeId,
            $input,
            (int) $_SESSION['auth']['user_id']
        );

        if (!empty($result['notFound'])) {
            $this->notFound();
        }

        if (!$result['successful']) {
            \flash(
                'leave_policy_update_errors',
                $result['errors']
            );
            \flash(
                'leave_policy_update_old',
                $input
            );
            \redirect(
                '/hr/leave/policies/edit?id='
                . $leaveTypeId
            );
        }

        \flash('leave_policy_notice', [
            'type' => 'success',
            'message' => !empty($result['changed'])
                ? sprintf(
                    'Leave policy %s was updated successfully.',
                    (string) $result['policyName']
                )
                : 'No leave-policy changes were required.',
        ]);
        \redirect('/hr/leave/policies');
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function renderForm(
        string $mode,
        int $leaveTypeId,
        array $old,
        array $errors
    ): void {
        $isEdit = $mode === 'edit';

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => $isEdit
                ? 'Edit Leave Policy'
                : 'Create Leave Policy',
            'pageDescription' => $isEdit
                ? 'Update entitlement, approval and availability settings.'
                : 'Add a company-specific leave type and entitlement rule.',
            'contentView' =>
                'hr.leave.policies.form',
            'user' => $_SESSION['auth'],
            'formMode' => $mode,
            'leaveTypeId' => $leaveTypeId,
            'old' => $old,
            'errors' => $errors,
        ]);
    }

    private function requireManagement(): void
    {
        $this->authorization->requireModule('hr');
        $this->authorization
            ->requireTenantPermission(
                'hr.leave.policy.manage'
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function policyInput(): array
    {
        return [
            'code' => \postString('code'),
            'name' => \postString('name'),
            'annual_entitlement' =>
                \postString('annual_entitlement'),
            'requires_approval' =>
                isset($_POST['requires_approval']),
            'active' => isset($_POST['active']),
        ];
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
