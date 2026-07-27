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

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Administration',
            'pageDescription' =>
                'Manage customer companies, modules, users, access and system activity.',
            'contentView' => 'administration.index',
            'user' => $_SESSION['auth'],
        ]);
    }
}
