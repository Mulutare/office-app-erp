<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\DepartmentCreationService;
use App\Services\DepartmentManagementService;
use App\Services\EmployeeDirectoryService;
use App\Services\EmployeeCreationService;
use App\Services\EmployeeUpdateService;

final class HrController
{
    private AuthorizationService $authorization;
    private EmployeeDirectoryService $employees;
    private EmployeeCreationService $employeeCreation;
    private EmployeeUpdateService $employeeUpdates;
    private DepartmentCreationService
        $departmentCreation;
    private DepartmentManagementService
        $departmentManagement;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->employees =
            new EmployeeDirectoryService();
        $this->employeeCreation =
            new EmployeeCreationService();
        $this->employeeUpdates =
            new EmployeeUpdateService();
        $this->departmentCreation =
            new DepartmentCreationService();
        $this->departmentManagement =
            new DepartmentManagementService();
    }

    public function index(): void
    {
        $this->requireHrAccess();
        $directory = $this->employees->directory(
            $this->queryString('search'),
            $this->queryString('status'),
            $this->queryInteger('department', 0),
            $this->queryInteger('page', 1)
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
            'pageTitle' => 'Human Resources',
            'pageDescription' =>
                'Employee directory, reporting lines and employment status.',
            'contentView' => 'hr.index',
            'user' => $_SESSION['auth'],
            'employees' => $directory['employees'],
            'departments' =>
                $directory['departments'],
            'statusOptions' =>
                $directory['statusOptions'],
            'summary' => $directory['summary'],
            'filters' => $directory['filters'],
            'pagination' =>
                $directory['pagination'],
            'canManage' => $this->canManage(),
            'notice' => \getFlash('hr_notice'),
        ]);
    }

    public function show(): void
    {
        $this->requireHrAccess();
        $profile = $this->employees->profile(
            $this->queryInteger('id', 0)
        );

        if ($profile === null) {
            $this->notFound();
        }

        $employee = $profile['employee'];

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => (string) (
                $employee['displayName']
                ?? 'Employee Profile'
            ),
            'pageDescription' =>
                'Employment, organization and account information.',
            'contentView' => 'hr.show',
            'user' => $_SESSION['auth'],
            'employee' => $employee,
            'directReports' =>
                $profile['directReports'],
            'canManage' => $this->canManage(),
            'canManageUsers' => in_array(
                'administration.users.manage',
                $_SESSION['auth']['permissions'] ?? [],
                true
            ),
            'notice' => \getFlash('hr_notice'),
        ]);
    }

    public function createEmployee(): void
    {
        $this->requireHrManagement();
        $options =
            $this->employeeCreation->formOptions();

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Create Employee',
            'pageDescription' =>
                'Register employment, organization and optional ERP-account information.',
            'contentView' => 'hr.employees.create',
            'user' => $_SESSION['auth'],
            'departments' =>
                $options['departments'],
            'managers' => $options['managers'],
            'users' => $options['users'],
            'employmentTypes' =>
                $options['employmentTypes'],
            'employmentStatuses' =>
                $options['employmentStatuses'],
            'errors' => \getFlash(
                'employee_create_errors',
                []
            ),
            'old' => \getFlash(
                'employee_create_old',
                []
            ),
        ]);
    }

    public function storeEmployee(): void
    {
        $this->requireHrManagement();

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash(
                'employee_create_errors',
                [
                    'form' =>
                        'The form session expired. Please try again.',
                ]
            );
            \redirect('/hr/employees/create');
        }

        $input = $this->employeeInput();
        $result = $this->employeeCreation->create(
            $input,
            (int) $_SESSION['auth']['user_id']
        );

        if (!$result['successful']) {
            \flash(
                'employee_create_errors',
                $result['errors']
            );
            \flash(
                'employee_create_old',
                $input
            );
            \redirect('/hr/employees/create');
        }

        \flash('hr_notice', [
            'type' => 'success',
            'message' => sprintf(
                'Employee %s was created successfully.',
                (string) $result['employeeName']
            ),
        ]);
        \redirect(
            '/hr/employees/view?id='
            . (int) $result['employeeId']
        );
    }

    public function editEmployee(): void
    {
        $this->requireHrManagement();
        $employeeId = $this->queryInteger(
            'id',
            0
        );
        $form = $this->employeeUpdates->form(
            $employeeId
        );

        if ($form === null) {
            $this->notFound();
        }

        $old = \getFlash(
            'employee_update_old'
        );

        if (!is_array($old)) {
            $old = $form['values'];
        }

        $options = $form['options'];
        $employee = $form['employee'];

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Edit Employee',
            'pageDescription' =>
                'Update employment, organization and linked-account information.',
            'contentView' => 'hr.employees.create',
            'user' => $_SESSION['auth'],
            'formMode' => 'edit',
            'employeeId' => $employeeId,
            'employeeName' => trim(
                (string) (
                    $employee['preferred_name']
                    ?? $employee['first_name']
                    ?? ''
                )
                . ' '
                . (string) (
                    $employee['last_name'] ?? ''
                )
            ),
            'departments' =>
                $options['departments'],
            'managers' => $options['managers'],
            'users' => $options['users'],
            'employmentTypes' =>
                $options['employmentTypes'],
            'employmentStatuses' =>
                $options['employmentStatuses'],
            'errors' => \getFlash(
                'employee_update_errors',
                []
            ),
            'old' => $old,
        ]);
    }

    public function updateEmployee(): void
    {
        $this->requireHrManagement();
        $employeeId = $this->postInteger(
            'employee_id'
        );

        if ($employeeId < 1) {
            $this->notFound();
        }

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash(
                'employee_update_errors',
                [
                    'form' =>
                        'The form session expired. Please try again.',
                ]
            );
            \redirect(
                '/hr/employees/edit?id='
                . $employeeId
            );
        }

        $input = $this->employeeInput();
        $result = $this->employeeUpdates->update(
            $employeeId,
            $input,
            (int) $_SESSION['auth']['user_id']
        );

        if (!$result['successful']) {
            \flash(
                'employee_update_errors',
                $result['errors']
            );
            \flash(
                'employee_update_old',
                $input
            );
            \redirect(
                '/hr/employees/edit?id='
                . $employeeId
            );
        }

        \flash('hr_notice', [
            'type' => 'success',
            'message' => !empty($result['changed'])
                ? sprintf(
                    'Employee %s was updated successfully.',
                    (string) $result['employeeName']
                )
                : 'No employee changes were required.',
        ]);
        \redirect(
            '/hr/employees/view?id='
            . $employeeId
        );
    }

    public function createDepartment(): void
    {
        $this->requireHrManagement();

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Create Department',
            'pageDescription' =>
                'Add an organizational department for employee assignment.',
            'contentView' =>
                'hr.departments.create',
            'user' => $_SESSION['auth'],
            'errors' => \getFlash(
                'department_create_errors',
                []
            ),
            'old' => \getFlash(
                'department_create_old',
                []
            ),
        ]);
    }

    public function storeDepartment(): void
    {
        $this->requireHrManagement();

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash(
                'department_create_errors',
                [
                    'form' =>
                        'The form session expired. Please try again.',
                ]
            );
            \redirect('/hr/departments/create');
        }

        $input = [
            'code' => \postString('code'),
            'name' => \postString('name'),
            'description' =>
                \postString('description'),
            'active' => isset($_POST['active']),
        ];
        $result =
            $this->departmentCreation->create(
                $input,
                (int) $_SESSION['auth']['user_id']
            );

        if (!$result['successful']) {
            \flash(
                'department_create_errors',
                $result['errors']
            );
            \flash(
                'department_create_old',
                $input
            );
            \redirect('/hr/departments/create');
        }

        \flash('hr_notice', [
            'type' => 'success',
            'message' => sprintf(
                'Department %s was created successfully.',
                (string) $result['departmentName']
            ),
        ]);
        \redirect('/hr');
    }

    public function departments(): void
    {
        $this->requireHrManagement();

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Department Management',
            'pageDescription' =>
                'Maintain organizational departments and review employee assignments.',
            'contentView' =>
                'hr.departments.index',
            'user' => $_SESSION['auth'],
            'departments' =>
                $this->departmentManagement
                    ->listing(),
            'notice' => \getFlash('hr_notice'),
        ]);
    }

    public function editDepartment(): void
    {
        $this->requireHrManagement();
        $departmentId = $this->queryInteger(
            'id',
            0
        );
        $department =
            $this->departmentManagement->form(
                $departmentId
            );

        if ($department === null) {
            $this->departmentNotFound();
        }

        $old = \getFlash(
            'department_update_old'
        );

        if (!is_array($old)) {
            $old = [
                'code' => $department['code'],
                'name' => $department['name'],
                'description' =>
                    $department['description'],
                'active' =>
                    !empty($department['active']),
            ];
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
            'pageTitle' => 'Edit Department',
            'pageDescription' =>
                'Update department identity and availability.',
            'contentView' =>
                'hr.departments.create',
            'user' => $_SESSION['auth'],
            'formMode' => 'edit',
            'departmentId' => $departmentId,
            'errors' => \getFlash(
                'department_update_errors',
                []
            ),
            'old' => $old,
        ]);
    }

    public function updateDepartment(): void
    {
        $this->requireHrManagement();
        $departmentId = $this->postInteger(
            'department_id'
        );

        if ($departmentId < 1) {
            $this->departmentNotFound();
        }

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash(
                'department_update_errors',
                [
                    'form' =>
                        'The form session expired. Please try again.',
                ]
            );
            \redirect(
                '/hr/departments/edit?id='
                . $departmentId
            );
        }

        $input = $this->departmentInput();
        $result =
            $this->departmentManagement->update(
                $departmentId,
                $input,
                (int) $_SESSION['auth']['user_id']
            );

        if (!$result['successful']) {
            \flash(
                'department_update_errors',
                $result['errors']
            );
            \flash(
                'department_update_old',
                $input
            );
            \redirect(
                '/hr/departments/edit?id='
                . $departmentId
            );
        }

        \flash('hr_notice', [
            'type' => 'success',
            'message' => !empty($result['changed'])
                ? sprintf(
                    'Department %s was updated successfully.',
                    (string) $result[
                        'departmentName'
                    ]
                )
                : 'No department changes were required.',
        ]);
        \redirect('/hr/departments');
    }

    private function requireHrAccess(): void
    {
        $this->authorization
            ->requireAnyPermission([
                'hr.records.view',
                'hr.records.manage',
            ]);
    }

    private function requireHrManagement(): void
    {
        $this->authorization
            ->requirePermission('hr.records.manage');
    }

    private function canManage(): bool
    {
        return in_array(
            'hr.records.manage',
            $_SESSION['auth']['permissions'] ?? [],
            true
        );
    }

    private function queryString(
        string $key,
        string $default = ''
    ): string {
        $value = $_GET[$key] ?? $default;

        return is_string($value)
            ? trim($value)
            : $default;
    }

    private function queryInteger(
        string $key,
        int $default
    ): int {
        $value = $_GET[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value)
            && ctype_digit($value)
                ? (int) $value
                : $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeInput(): array
    {
        return [
            'employee_number' =>
                \postString('employee_number'),
            'user_id' => \postString('user_id'),
            'first_name' =>
                \postString('first_name'),
            'middle_name' =>
                \postString('middle_name'),
            'last_name' =>
                \postString('last_name'),
            'preferred_name' =>
                \postString('preferred_name'),
            'work_email' =>
                \postString('work_email'),
            'work_phone' =>
                \postString('work_phone'),
            'department_id' =>
                \postString('department_id'),
            'job_title' =>
                \postString('job_title'),
            'employment_type' =>
                \postString('employment_type'),
            'employment_status' =>
                \postString('employment_status'),
            'hire_date' =>
                \postString('hire_date'),
            'termination_date' =>
                \postString('termination_date'),
            'manager_employee_id' =>
                \postString(
                    'manager_employee_id'
                ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function departmentInput(): array
    {
        return [
            'code' => \postString('code'),
            'name' => \postString('name'),
            'description' =>
                \postString('description'),
            'active' => isset($_POST['active']),
        ];
    }

    private function postInteger(string $key): int
    {
        $value = $_POST[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value)
            && ctype_digit($value)
                ? (int) $value
                : 0;
    }

    private function notFound(): void
    {
        http_response_code(404);

        \view('errors.employee-404', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
        ]);

        exit;
    }

    private function departmentNotFound(): void
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
