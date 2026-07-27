<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\EmployeeActivityService;

final class EmployeeActivityController
{
    private AuthorizationService $authorization;
    private EmployeeActivityService $activity;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->activity =
            new EmployeeActivityService();
    }

    public function index(): void
    {
        $this->authorization
            ->requireModule('hr');
        $this->authorization
            ->requireAnyPermission([
                'hr.records.view',
                'hr.records.manage',
            ]);
        $listing = $this->activity->listing(
            $this->queryInteger('id', 0),
            $this->queryInteger('page', 1)
        );

        if ($listing === null) {
            $this->notFound();
        }

        $employee = $listing['employee'];

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Employee Activity',
            'pageDescription' =>
                'Audited employee-record history for '
                . (string) (
                    $employee['displayName']
                    ?? 'this employee'
                )
                . '.',
            'contentView' =>
                'hr.employees.activity',
            'user' => $_SESSION['auth'],
            'employee' => $employee,
            'events' => $listing['events'],
            'pagination' =>
                $listing['pagination'],
            'canManage' => in_array(
                'hr.records.manage',
                $_SESSION['auth']['permissions'] ?? [],
                true
            ),
        ]);
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
