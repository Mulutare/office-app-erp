<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;

final class AdministrationController
{
    private AuthorizationService $authorization;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
    }

    public function index(): void
    {
        $this->authorization
            ->requireAnyPermission([
                'administration.users.manage',
                'administration.roles.manage',
                'administration.companies.manage',
                'administration.modules.manage',
                'audit.logs.view',
            ]);

        $isPlatformAdmin = !empty(
            $_SESSION['auth']['is_platform_admin']
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
            'pageTitle' => $isPlatformAdmin
                ? 'Vendor Administration'
                : 'Company Administration',
            'pageDescription' =>
                $isPlatformAdmin
                    ? 'Approve customer companies, assign licensed utilities and govern the software platform.'
                    : 'Manage this company’s users, roles, permissions and audit activity.',
            'contentView' => 'administration.index',
            'user' => $_SESSION['auth'],
        ]);
    }
}
