<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\RoleAdministrationService;
use App\Services\RolePermissionUpdateService;

final class RoleAdministrationController
{
    private AuthorizationService $authorization;
    private RoleAdministrationService $roles;
    private RolePermissionUpdateService $permissionUpdates;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->roles =
            new RoleAdministrationService();
        $this->permissionUpdates =
            new RolePermissionUpdateService();
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
            'canEditPermissions' =>
                !in_array(
                    (string) (
                        $details['role']['code']
                        ?? ''
                    ),
                    [
                        'system_administrator',
                        'company_owner',
                    ],
                    true
                ),
            'successMessage' => \getFlash(
                'role_permission_success'
            ),
        ]);
    }

    public function editPermissions(): void
    {
        $this->authorization->requirePermission(
            'administration.roles.manage'
        );

        $roleId = $this->queryInteger('id');
        $formData = $this->permissionUpdates
            ->formData($roleId);

        if ($formData === null) {
            $this->notFound();
        }

        if (in_array(
            (string) $formData['role']['code'],
            [
                'system_administrator',
                'company_owner',
            ],
            true
        )) {
            \flash(
                'role_permission_success',
                'This ownership permission baseline is protected.'
            );

            \redirect(
                '/administration/roles/view?id='
                . $roleId
            );
        }

        $oldSelection = \getFlash(
            'role_permission_old'
        );

        if (is_array($oldSelection)) {
            $formData['selectedPermissionIds'] =
                array_map('intval', $oldSelection);
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
            'pageTitle' => 'Edit Role Permissions',
            'pageDescription' =>
                'Apply least-privilege access to this role.',
            'contentView' =>
                'administration.roles.edit-permissions',
            'user' => $_SESSION['auth'],
            'role' => $formData['role'],
            'permissions' =>
                $formData['permissions'],
            'selectedPermissionIds' =>
                $formData['selectedPermissionIds'],
            'errors' => \getFlash(
                'role_permission_errors',
                []
            ),
        ]);
    }

    public function updatePermissions(): void
    {
        $this->authorization->requirePermission(
            'administration.roles.manage'
        );

        $roleId = $this->postInteger('role_id');

        if (!\verifyCsrfToken(
            \postString('_token')
        )) {
            \flash('role_permission_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);

            \redirect(
                '/administration/roles/edit-permissions?id='
                . $roleId
            );
        }

        $submitted = $_POST['permission_ids'] ?? [];
        $result = $this->permissionUpdates->update(
            $roleId,
            $submitted,
            (int) (
                $_SESSION['auth']['user_id'] ?? 0
            )
        );

        if (!empty($result['notFound'])) {
            $this->notFound();
        }

        if (!$result['successful']) {
            \flash(
                'role_permission_errors',
                $result['errors']
            );
            \flash(
                'role_permission_old',
                is_array($submitted)
                    ? $submitted
                    : []
            );

            \redirect(
                '/administration/roles/edit-permissions?id='
                . $roleId
            );
        }

        \flash(
            'role_permission_success',
            !empty($result['changed'])
                ? 'Role permissions updated successfully.'
                : 'Role permissions were already up to date.'
        );

        \redirect(
            '/administration/roles/view?id='
            . $roleId
        );
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

        \view('errors.role-404', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
        ]);

        exit;
    }
}
