<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\DepartmentCreationService;
use App\Services\EmployeeDirectoryService;
use App\Services\EmployeeCreationService;

final class HrController
{
    private AuthorizationService $authorization;
    private EmployeeDirectoryService $employees;
    private EmployeeCreationService $employeeCreation;
    private DepartmentCreationService
        $departmentCreation;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->employees =
            new EmployeeDirectoryService();
        $this->employeeCreation =
            new EmployeeCreationService();
        $this->departmentCreation =
            new DepartmentCreationService();
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

        $input = [
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
}
