<?php

declare(strict_types=1);

namespace App\Services;

final class AuthorizationService
{
    private AuthService $auth;
    private CompanyModuleService $modules;

    public function __construct()
    {
        $this->auth = new AuthService();
        $this->modules =
            new CompanyModuleService();
    }

    public function requireAuthentication(): void
    {
        if (!$this->auth->check()) {
            \flash(
                'auth_error',
                'Please sign in to continue.'
            );

            \redirect('/login');
        }

        if ($this->auth->mustChangePassword()) {
            \redirect('/change-password');
        }
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

    public function requirePlatformAdministrator(): void
    {
        $this->requireAuthentication();

        if ($this->auth->isPlatformAdministrator()) {
            return;
        }

        $this->deny();
    }

    public function requireModule(
        string $moduleCode
    ): void {
        $this->requireAuthentication();

        if (
            $this->modules->isEnabled(
                $moduleCode
            )
        ) {
            return;
        }

        http_response_code(404);

        \view('errors.module-disabled', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
        ]);

        exit;
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
