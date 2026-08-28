<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\DashboardService;

final class DashboardController
{
    private AuthorizationService $authorization;
    private DashboardService $dashboard;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->dashboard = new DashboardService();
    }

    public function index(): void
    {
        $this->authorization->requirePermission(
            'dashboard.view'
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
            'pageTitle' => 'Dashboard',
            'pageDescription' =>
                'Enterprise operations and system overview.',
            'contentView' => 'dashboard.index',
            'user' => $_SESSION['auth'],
            'statistics' =>
                $this->dashboard->statistics(),
            'account' => $this->dashboard->signedInAccount(
                $_SESSION['auth']
            ),
            'sessionSuccess' => \getFlash(
                'dashboard_session_success'
            ),
            'sessionError' => \getFlash(
                'dashboard_session_error'
            ),
        ]);
    }
}
