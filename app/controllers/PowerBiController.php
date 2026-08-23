<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\CompanyModuleService;
use App\Services\PowerBiConfigurationService;

final class PowerBiController
{
    private AuthorizationService $authorization;
    private PowerBiConfigurationService $powerBi;
    private CompanyModuleService $modules;

    public function __construct()
    {
        $this->authorization = new AuthorizationService();
        $this->powerBi = new PowerBiConfigurationService();
        $this->modules = new CompanyModuleService();
    }

    public function index(): void
    {
        $this->authorization->requireModulePermission(
            'analytics',
            'analytics.view'
        );
        $state = $this->powerBi->pageState();

        \view('layouts.app', [
            'applicationName' => \config('name', 'OfficeApp ERP'),
            'pageTitle' => 'Analytics',
            'pageDescription' => 'Company reporting and Power BI insights.',
            'contentView' => 'analytics.index',
            'user' => $_SESSION['auth'],
        ] + $state);
    }

    public function configuration(): void
    {
        $this->authorization->requireLicensedModulePermission(
            'analytics',
            'analytics.configure'
        );
        $configuration = $this->powerBi->configuration();

        \view('layouts.app', [
            'applicationName' => \config('name', 'OfficeApp ERP'),
            'pageTitle' => 'Analytics Configuration',
            'pageDescription' => "Manage this company's Power BI report mapping and authentication.",
            'contentView' => 'administration.analytics',
            'user' => $_SESSION['auth'],
            'configuration' => $configuration,
            'moduleEnabled' => $this->modules->isEnabled('analytics'),
            'notice' => \getFlash('analytics_notice'),
            'errors' => \getFlash('analytics_errors', []),
        ]);
    }

    public function save(): void
    {
        $this->authorizeWrite();
        $result = $this->powerBi->save($_POST, $this->actor());
        $this->finish($result, 'Analytics configuration saved.');
    }

    public function validateConfiguration(): void
    {
        $this->authorizeWrite();
        $result = $this->powerBi->validateConfiguration($this->actor());
        $this->finish(
            $result,
            'Configuration validation completed successfully.'
        );
    }

    public function enable(): void
    {
        $this->authorizeWrite();

        try {
            $this->modules->setLicensedModuleEnabled(
                'analytics',
                !empty($_POST['enabled']),
                $this->actor()
            );
            $this->finish(
                ['successful' => true, 'errors' => []],
                'Analytics module setting updated.'
            );
        } catch (\Throwable $exception) {
            error_log(
                'Analytics module setting update failed: '
                . $exception::class
                . ': '
                . $exception->getMessage()
            );
            $this->finish([
                'successful' => false,
                'errors' => [
                    'module' => 'Unable to update the Analytics module setting.',
                ],
            ], '');
        }
    }

    private function authorizeWrite(): void
    {
        $this->authorization->requireLicensedModulePermission(
            'analytics',
            'analytics.configure'
        );

        if (!\verifyCsrfToken(\postString('_token'))) {
            \flash('analytics_errors', [
                'form' => 'The form session expired.',
            ]);
            \redirect('/administration/analytics');
        }
    }

    private function actor(): int
    {
        return (int) ($_SESSION['auth']['user_id'] ?? 0);
    }

    private function finish(array $result, string $message): never
    {
        if (empty($result['successful'])) {
            \flash('analytics_errors', $result['errors'] ?? []);
        } else {
            \flash('analytics_notice', ['message' => $message]);
        }

        \redirect('/administration/analytics');
    }
}
