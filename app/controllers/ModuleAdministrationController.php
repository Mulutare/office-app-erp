<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\CompanyModuleService;

final class ModuleAdministrationController
{
    private AuthorizationService $authorization;
    private CompanyModuleService $modules;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->modules =
            new CompanyModuleService();
    }

    public function index(): void
    {
        $this->authorization
            ->requirePermission(
                'administration.modules.manage'
            );
        $catalog = $this->modules->catalog();

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Company Modules',
            'pageDescription' =>
                'Control the licensed ERP capabilities available to this company.',
            'contentView' =>
                'administration.modules.index',
            'user' => $_SESSION['auth'],
            'company' => $catalog['company'],
            'modules' => $catalog['modules'],
            'notice' => \getFlash(
                'module_notice'
            ),
            'errors' => \getFlash(
                'module_errors',
                []
            ),
        ]);
    }

    public function update(): void
    {
        $this->authorization
            ->requirePermission(
                'administration.modules.manage'
            );

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash('module_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);
            \redirect('/administration/modules');
        }

        $result = $this->modules
            ->updateEnabledModules(
                $_POST['module_codes'] ?? [],
                (int) $_SESSION['auth']['user_id']
            );

        if (!$result['successful']) {
            \flash(
                'module_errors',
                $result['errors']
            );
            \redirect('/administration/modules');
        }

        \flash('module_notice', [
            'message' => !empty($result['changed'])
                ? 'Company modules were updated successfully.'
                : 'No module changes were required.',
        ]);
        \redirect('/administration/modules');
    }
}
