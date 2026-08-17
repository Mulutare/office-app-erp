<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\CompanyModuleService;

final class AuthController
{
    private AuthService $auth;
    private CompanyModuleService $companyModules;

    public function __construct()
    {
        $this->auth = new AuthService();
        $this->companyModules =
            new CompanyModuleService();
    }

    public function showLogin(): void
    {
        if ($this->auth->check()) {
            \redirect($this->landingDestination());
        }

        \view('auth.login', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'company' =>
                $this->companyModules->company(),
            'error' => \getFlash('auth_error'),
        ]);
    }

    public function login(): void
    {
        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash(
                'auth_error',
                'The form session expired. Please try again.'
            );

            \redirect('/login');
        }

        $login = \postString('login');
        $password = \postString('password');

        \flashInput([
            'login' => $login,
        ]);

        $result = $this->auth->attempt(
            $login,
            $password
        );

        if (!$result['successful']) {
            \flash(
                'auth_error',
                $result['message']
            );

            \redirect('/login');
        }

        \redirect($this->landingDestination());
    }

    public function logout(): void
    {
        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            http_response_code(419);

            echo 'Invalid or expired session token.';

            return;
        }

        $this->auth->logout();

        \redirect('/login');
    }
    public function showChangePassword(): void
{
    if (!$this->auth->check()) {
        \flash(
            'auth_error',
            'Please sign in to continue.'
        );

        \redirect('/login');
    }

    \view('auth.change-password', [
        'applicationName' => \config(
            'name',
            'OfficeApp ERP'
        ),
        'company' =>
            $_SESSION['auth']['company'] ?? [],
        'user' =>
            $_SESSION['auth'] ?? [],
        'error' => \getFlash('password_error'),
        'success' => \getFlash('password_success'),
    ]);
}

public function changePassword(): void
{
    if (!$this->auth->check()) {
        \redirect('/login');
    }

    if (
        !\verifyCsrfToken(
            \postString('_token')
        )
    ) {
        \flash(
            'password_error',
            'The form session expired. Please try again.'
        );

        \redirect('/change-password');
    }

    $result = $this->auth->changePassword(
        \postString('current_password'),
        \postString('new_password'),
        \postString('new_password_confirmation')
    );

    if (!$result['successful']) {
        \flash(
            'password_error',
            $result['message']
        );

        \redirect('/change-password');
    }

    \flash(
        'password_success',
        $result['message']
    );

    \redirect($this->landingDestination());
}
    private function landingDestination(): string
    {
        if ($this->auth->mustChangePassword()) {
            return '/change-password';
        }

        if ($this->auth->isPlatformAdministrator()) {
            return '/administration';
        }

        if ($this->auth->can('dashboard.view')) {
            return '/dashboard';
        }

        if ($this->auth->canAny([
            'administration.users.manage',
            'administration.roles.manage',
            'administration.companies.manage',
            'administration.modules.manage',
            'audit.logs.view',
            'organization.branches.view',
            'organization.branches.manage',
            'organization.job_titles.view',
            'organization.job_titles.manage',
            'organization.departments.view',
            'organization.departments.manage',
            'organization.positions.view',
            'organization.positions.manage',
        ])) {
            return '/administration';
        }

        if (
            $this->companyModules->isEnabled('procurement')
            && $this->auth->can('procurement.view')
        ) {
            return '/procurement';
        }

        if (
            $this->companyModules->isEnabled('sales')
            && $this->auth->can('sales.view')
        ) {
            return '/sales';
        }

        if (
            $this->companyModules->isEnabled('inventory')
            && $this->auth->can('inventory.view')
        ) {
            return '/inventory';
        }

        if (
            $this->companyModules->isEnabled('finance')
            && $this->auth->canAny([
                'finance.records.view',
                'finance.records.manage',
                'finance.requests.approve',
            ])
        ) {
            return '/finance';
        }

        if (
            $this->companyModules->isEnabled('hr')
            && $this->auth->canAny([
                'hr.records.view',
                'hr.records.manage',
                'hr.leave.view',
                'hr.leave.manage',
                'hr.leave.approve',
                'hr.leave.self.view',
                'hr.leave.self.request',
                'hr.leave.team.approve',
                'hr.leave.policy.manage',
                'hr.leave.balance.manage',
            ])
        ) {
            return '/hr';
        }

        if (
            $this->companyModules->isEnabled('attendance')
            && $this->auth->canAny([
                'attendance.records.view',
                'attendance.records.manage',
                'attendance.self.view',
                'attendance.team.view',
            ])
        ) {
            return '/attendance';
        }

        if (
            $this->companyModules->isEnabled('assets')
            && $this->auth->can('assets.view')
        ) {
            return '/assets-management';
        }

        /*
         * No usable landing permission exists.
         * /dashboard will produce the recoverable 403 page,
         * including secure sign-out, rather than trapping
         * the session without a way back to login.
         */
        return '/dashboard';
    }
}
