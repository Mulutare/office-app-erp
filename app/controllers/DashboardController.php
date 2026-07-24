<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;

final class DashboardController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
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

        \view('dashboard.index', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'user' => $_SESSION['auth'],
        ]);
    }
}