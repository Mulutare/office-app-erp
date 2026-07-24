<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\RoleAdministrationService;

final class RoleAdministrationController
{
    private AuthorizationService $authorization;
    private RoleAdministrationService $roles;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->roles =
            new RoleAdministrationService();
    }

    public function index(): void
    {
        $this->authorization->requirePermission(
            'administration.roles.manage'
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
            'pageTitle' => 'Roles and Permissions',
            'pageDescription' =>
                'Review role assignments and effective access baselines.',
            'contentView' =>
                'administration.roles.index',
            'user' => $_SESSION['auth'],
            'roles' => $this->roles->listing(),
        ]);
    }

    public function show(): void
    {
        $this->authorization->requirePermission(
            'administration.roles.manage'
        );

        $roleId = $this->queryInteger('id');
        $details = $this->roles->details($roleId);

        if ($details === null) {
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
            'pageTitle' => (string) (
                $details['role']['name']
                ?? 'Role Details'
            ),
            'pageDescription' =>
                'Assigned users and effective permissions.',
            'contentView' =>
                'administration.roles.show',
            'user' => $_SESSION['auth'],
            'role' => $details['role'],
            'permissions' =>
                $details['permissions'],
            'assignedUsers' => $details['users'],
        ]);
    }

    private function queryInteger(string $key): int
    {
        $value = $_GET[$key] ?? null;

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

        \view('errors.role-404', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
        ]);

        exit;
    }
}
