<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\DashboardService;

final class DashboardController
{
    private AuthService $auth;
    private DashboardService $dashboard;

    public function __construct()
    {
        $this->auth = new AuthService();
        $this->dashboard = new DashboardService();
    }

    public function index(): void
    {
        if (!$this->auth->check()) {
            \flash(
                'auth_error',
                'Please sign in to continue.'
            );

            \redirect('/login');
        }

        if (
            !empty(
                $_SESSION['auth']['must_change_password']
            )
        ) {
            \redirect('/change-password');
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
            'pageTitle' => 'Dashboard',
            'pageDescription' =>
                'Enterprise operations and system overview.',
            'contentView' => 'dashboard.index',
            'user' => $_SESSION['auth'],
            'statistics' =>
                $this->dashboard->statistics(),
        ]);
    }
}