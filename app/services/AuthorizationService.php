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

    public function requireTenantPermission(
        string $permissionCode
    ): void {
        $this->requirePermission($permissionCode);

        if ($this->auth->isPlatformAdministrator()) {
            $this->deny();
        }
    }

    public function requireModulePermission(
        string $moduleCode,
        string $permissionCode
    ): void {
        $this->requireModule($moduleCode);
        $this->requireTenantPermission($permissionCode);
    }

    /**
     * Allow a tenant route when at least one module/permission pair is
     * effective. This is intended for shared records, such as settlements,
     * that legitimately belong to more than one licensed module.
     *
     * @param list<array{0: string, 1: string}> $requirements
     */
    public function requireAnyModulePermission(
        array $requirements
    ): void {
        $this->requireAuthentication();

        if ($this->auth->isPlatformAdministrator()) {
            $this->deny();
        }

        $hasEnabledModule = false;

        foreach ($requirements as $requirement) {
            [$moduleCode, $permissionCode] = $requirement;

            if (!$this->modules->isEnabled($moduleCode)) {
                continue;
            }

            if (!$this->moduleEntitled($moduleCode)) continue;
            $hasEnabledModule = true;

            if ($this->auth->can($permissionCode)) {
                return;
            }
        }

        if ($hasEnabledModule) {
            $this->deny();
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

    public function requireLicensedModulePermission(
        string $moduleCode,
        string $permissionCode
    ): void {
        $this->requireTenantPermission($permissionCode);
        $this->requireModuleEntitlement($moduleCode);

        if ($this->modules->isLicensed($moduleCode)) {
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

    /**
     * @param list<string> $permissionCodes
     */
    public function requireAnyTenantPermission(
        array $permissionCodes
    ): void {
        $this->requireAnyPermission(
            $permissionCodes
        );

        if ($this->auth->isPlatformAdministrator()) {
            $this->deny();
        }
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
        $this->requireModuleEntitlement($moduleCode);

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

    private function moduleEntitled(string $module): bool
    {
        return (new ModuleRoleService())->entitled((int) ($_SESSION['auth']['company']['company_id'] ?? 0), (int) ($_SESSION['auth']['user_id'] ?? 0), $module);
    }

    private function requireModuleEntitlement(string $module): void
    {
        if (!$this->moduleEntitled($module)) $this->deny();
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
