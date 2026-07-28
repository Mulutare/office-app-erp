<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\BranchManagementService;

final class BranchController
{
    private AuthorizationService $authorization;
    private BranchManagementService $branches;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->branches =
            new BranchManagementService();
    }

    public function index(): void
    {
        $this->authorization
            ->requireTenantPermission(
                'organization.branches.view'
            );
        $listing = $this->branches->listing();

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Company Branches',
            'pageDescription' =>
                'Maintain the locations that define this company workspace.',
            'contentView' =>
                'organization.branches.index',
            'user' => $_SESSION['auth'],
            'branches' => $listing['branches'],
            'summary' => $listing['summary'],
            'canManage' => $this->canManage(),
            'notice' => \getFlash('branch_notice'),
        ]);
    }

    public function create(): void
    {
        $this->requireManagement();
        $old = \getFlash('branch_create_old');

        if (!is_array($old)) {
            $old = $this->branches->defaults()
                + [
                    'active' => true,
                    'is_head_office' => false,
                ];
        }

        $this->renderForm(
            'create',
            0,
            $old,
            \getFlash('branch_create_errors', [])
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
            \flash('branch_create_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);
            \redirect('/organization/branches/create');
        }

        $input = $this->branchInput();
        $result = $this->branches->create(
            $input,
            (int) $_SESSION['auth']['user_id']
        );

        if (!$result['successful']) {
            \flash(
                'branch_create_errors',
                $result['errors']
            );
            \flash('branch_create_old', $input);
            \redirect('/organization/branches/create');
        }

        \flash('branch_notice', [
            'type' => 'success',
            'message' => sprintf(
                'Branch %s was created successfully.',
                (string) $result['branchName']
            ),
        ]);
        \redirect('/organization/branches');
    }

    public function edit(): void
    {
        $this->requireManagement();
        $branchId = $this->queryInteger('id');
        $branch = $this->branches->form($branchId);

        if ($branch === null) {
            $this->notFound();
        }

        $old = \getFlash('branch_update_old');

        if (!is_array($old)) {
            $old = $branch;
        }

        $this->renderForm(
            'edit',
            $branchId,
            $old,
            \getFlash('branch_update_errors', [])
        );
    }

    public function update(): void
    {
        $this->requireManagement();
        $branchId = $this->postInteger(
            'branch_id'
        );

        if ($branchId < 1) {
            $this->notFound();
        }

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash('branch_update_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);
            \redirect(
                '/organization/branches/edit?id='
                . $branchId
            );
        }

        $input = $this->branchInput();
        $result = $this->branches->update(
            $branchId,
            $input,
            (int) $_SESSION['auth']['user_id']
        );

        if (!empty($result['notFound'])) {
            $this->notFound();
        }

        if (!$result['successful']) {
            \flash(
                'branch_update_errors',
                $result['errors']
            );
            \flash('branch_update_old', $input);
            \redirect(
                '/organization/branches/edit?id='
                . $branchId
            );
        }

        \flash('branch_notice', [
            'type' => 'success',
            'message' => !empty($result['changed'])
                ? sprintf(
                    'Branch %s was updated successfully.',
                    (string) $result['branchName']
                )
                : 'No branch changes were required.',
        ]);
        \redirect('/organization/branches');
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function renderForm(
        string $mode,
        int $branchId,
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
                ? 'Edit Branch'
                : 'Create Branch',
            'pageDescription' => $isEdit
                ? 'Update branch identity, location and availability.'
                : 'Add a company location for future operational assignments.',
            'contentView' =>
                'organization.branches.form',
            'user' => $_SESSION['auth'],
            'formMode' => $mode,
            'branchId' => $branchId,
            'old' => $old,
            'errors' => $errors,
        ]);
    }

    private function requireManagement(): void
    {
        $this->authorization
            ->requireTenantPermission(
                'organization.branches.manage'
            );
    }

    private function canManage(): bool
    {
        return in_array(
            'organization.branches.manage',
            $_SESSION['auth']['permissions'] ?? [],
            true
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function branchInput(): array
    {
        return [
            'code' => \postString('code'),
            'name' => \postString('name'),
            'contact_email' =>
                \postString('contact_email'),
            'contact_phone' =>
                \postString('contact_phone'),
            'address_line' =>
                \postString('address_line'),
            'city' => \postString('city'),
            'country_code' =>
                \postString('country_code'),
            'timezone' => \postString('timezone'),
            'is_head_office' =>
                isset($_POST['is_head_office']),
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

        \view('errors.branch-404', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
        ]);

        exit;
    }
}
