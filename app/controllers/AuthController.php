<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;

final class AuthController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    public function showLogin(): void
    {
        if ($this->auth->check()) {
            \redirect('/dashboard');
        }

        \view('auth.login', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
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

        \redirect('/dashboard');
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

    \redirect('/dashboard');
}
}