<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\EmployeeDirectoryService;

final class HrController
{
    private AuthorizationService $authorization;
    private EmployeeDirectoryService $employees;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->employees =
            new EmployeeDirectoryService();
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
        ]);
    }

    private function requireHrAccess(): void
    {
        $this->authorization
            ->requireAnyPermission([
                'hr.records.view',
                'hr.records.manage',
            ]);
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
