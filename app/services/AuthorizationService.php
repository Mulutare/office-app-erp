<?php

declare(strict_types=1);

namespace App\Services;

final class AuthorizationService
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    public function requireAuthentication(): void
    {
        if ($this->auth->check()) {
            return;
        }

        \flash(
            'auth_error',
            'Please sign in to continue.'
        );

        \redirect('/login');
    }

    public function requirePermission(
        string $permissionCode
    ): void {
        $this->requireAuthentication();

        if ($this->auth->can($permissionCode)) {
            return;
        }

        $this->deny();
    }

    /**
     * @param list<string> $permissionCodes
     */
    public function requireAnyPermission(
        array $permissionCodes
    ): void {
        $this->requireAuthentication();

        if ($this->auth->canAny($permissionCodes)) {
            return;
        }

        $this->deny();
    }

    public function requireRole(
        string $roleCode
    ): void {
        $this->requireAuthentication();

        if ($this->auth->hasRole($roleCode)) {
            return;
        }

        $this->deny();
    }

    private function deny(): void
    {
        http_response_code(403);

        \view('errors.403', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
        ]);

        exit;
    }
}