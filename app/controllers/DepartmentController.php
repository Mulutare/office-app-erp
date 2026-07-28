<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\DepartmentCatalogueService;

final class DepartmentController
{
    private AuthorizationService $authorization;
    private DepartmentCatalogueService $departments;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->departments =
            new DepartmentCatalogueService();
    }

    public function index(): void
    {
        $this->authorization
            ->requireTenantPermission(
                'organization.departments.view'
            );
        $catalogue = $this->departments
            ->catalogue();

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Department Catalogue',
            'pageDescription' =>
                'Maintain the company structure used by people, finance, projects and reporting.',
            'contentView' =>
                'organization.departments.index',
            'user' => $_SESSION['auth'],
            'departments' =>
                $catalogue['departments'],
            'summary' => $catalogue['summary'],
            'canManage' => $this->canManage(),
            'notice' => \getFlash(
                'department_catalogue_notice'
            ),
        ]);
    }

    public function create(): void
    {
        $this->requireManagement();
        $old = \getFlash(
            'department_catalogue_create_old'
        );

        if (!is_array($old)) {
            $old = ['active' => true];
        }

        $this->renderForm(
            'create',
            0,
            $old,
            \getFlash(
                'department_catalogue_create_errors',
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
                'department_catalogue_create_errors',
                [
                    'form' =>
                        'The form session expired. Please try again.',
                ]
            );
            \redirect(
                '/organization/departments/create'
            );
        }

        $input = $this->departmentInput();
        $result = $this->departments->create(
            $input,
            (int) $_SESSION['auth']['user_id']
        );

        if (!$result['successful']) {
            \flash(
                'department_catalogue_create_errors',
                $result['errors']
            );
            \flash(
                'department_catalogue_create_old',
                $input
            );
            \redirect(
                '/organization/departments/create'
            );
        }

        \flash('department_catalogue_notice', [
            'type' => 'success',
            'message' => sprintf(
                'Department %s was created successfully.',
                (string) $result['departmentName']
            ),
        ]);
        \redirect('/organization/departments');
    }

    public function edit(): void
    {
        $this->requireManagement();
        $departmentId = $this->queryInteger('id');
        $department = $this->departments->form(
            $departmentId
        );

        if ($department === null) {
            $this->notFound();
        }

        $old = \getFlash(
            'department_catalogue_update_old'
        );

        if (!is_array($old)) {
            $old = $department;
        }

        $this->renderForm(
            'edit',
            $departmentId,
            $old,
            \getFlash(
                'department_catalogue_update_errors',
                []
            )
        );
    }

    public function update(): void
    {
        $this->requireManagement();
        $departmentId = $this->postInteger(
            'department_id'
        );

        if ($departmentId < 1) {
            $this->notFound();
        }

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash(
                'department_catalogue_update_errors',
                [
                    'form' =>
                        'The form session expired. Please try again.',
                ]
            );
            \redirect(
                '/organization/departments/edit?id='
                . $departmentId
            );
        }

        $input = $this->departmentInput();
        $result = $this->departments->update(
            $departmentId,
            $input,
            (int) $_SESSION['auth']['user_id']
        );

        if (!empty($result['notFound'])) {
            $this->notFound();
        }

        if (!$result['successful']) {
            \flash(
                'department_catalogue_update_errors',
                $result['errors']
            );
            \flash(
                'department_catalogue_update_old',
                $input
            );
            \redirect(
                '/organization/departments/edit?id='
                . $departmentId
            );
        }

        \flash('department_catalogue_notice', [
            'type' => 'success',
            'message' => !empty($result['changed'])
                ? sprintf(
                    'Department %s was updated successfully.',
                    (string) $result['departmentName']
                )
                : 'No department changes were required.',
        ]);
        \redirect('/organization/departments');
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function renderForm(
        string $mode,
        int $departmentId,
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
                ? 'Edit Department'
                : 'Create Department',
            'pageDescription' => $isEdit
                ? 'Update department identity, hierarchy and availability.'
                : 'Create a shared organizational unit for company operations.',
            'contentView' =>
                'organization.departments.form',
            'user' => $_SESSION['auth'],
            'formMode' => $mode,
            'departmentId' => $departmentId,
            'parentOptions' =>
                $this->departments->parentOptions(
                    $departmentId
                ),
            'old' => $old,
            'errors' => $errors,
        ]);
    }

    private function requireManagement(): void
    {
        $this->authorization
            ->requireTenantPermission(
                'organization.departments.manage'
            );
    }

    private function canManage(): bool
    {
        return in_array(
            'organization.departments.manage',
            $_SESSION['auth']['permissions'] ?? [],
            true
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function departmentInput(): array
    {
        return [
            'code' => \postString('code'),
            'name' => \postString('name'),
            'parent_department_id' =>
                \postString('parent_department_id'),
            'description' =>
                \postString('description'),
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

        \view('errors.department-404', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
        ]);

        exit;
    }
}
