<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\CompanyLifecycleService;
use App\Services\CompanyOwnerPasswordResetService;
use App\Services\CompanyProvisioningService;
use App\Services\CompanyUpdateService;
use App\Services\PlatformCompanyUserPasswordResetService;

final class CompanyAdministrationController
{
    private AuthorizationService $authorization;
    private CompanyProvisioningService $companies;
    private CompanyUpdateService $updates;
    private CompanyLifecycleService $lifecycle;
    private CompanyOwnerPasswordResetService
        $ownerPasswordResets;
    private PlatformCompanyUserPasswordResetService
        $companyUserPasswordResets;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->companies =
            new CompanyProvisioningService();
        $this->updates =
            new CompanyUpdateService();
        $this->lifecycle =
            new CompanyLifecycleService();
        $this->ownerPasswordResets =
            new CompanyOwnerPasswordResetService();
        $this->companyUserPasswordResets =
            new PlatformCompanyUserPasswordResetService();
    }

    public function index(): void
    {
        $this->authorization
            ->requirePlatformAdministrator();
        $listing = $this->companies->listing(
            $this->queryString('search'),
            $this->queryString(
                'status',
                'all'
            ),
            $this->queryInteger('page', 1)
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
            'pageTitle' => 'Customer Companies',
            'pageDescription' =>
                'Provision and review customer ERP workspaces and module subscriptions.',
            'contentView' =>
                'administration.companies.index',
            'user' => $_SESSION['auth'],
            'companies' => $listing['companies'],
            'filters' => $listing['filters'],
            'pagination' =>
                $listing['pagination'],
            'notice' => \getFlash(
                'company_notice'
            ),
        ]);
    }

    public function create(): void
    {
        $this->authorization
            ->requirePlatformAdministrator();
        $options = $this->companies
            ->formOptions();
        $companyModules = [];

        foreach ($details['modules'] as $module) {
            $companyModules[(string) $module['code']] = $module;
        }

        foreach ($options['modules'] as &$module) {
            $module += $companyModules[
                (string) $module['code']
            ] ?? [];
        }

        unset($module);

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Provision Company',
            'pageDescription' =>
                'Create a customer workspace and assign its initial ERP subscription.',
            'contentView' =>
                'administration.companies.create',
            'user' => $_SESSION['auth'],
            'modules' => $options['modules'],
            'timezones' => $options['timezones'],
            'currencies' => $options['currencies'],
            'errors' => \getFlash(
                'company_create_errors',
                []
            ),
            'old' => \getFlash(
                'company_create_old',
                []
            ),
        ]);
    }

    public function store(): void
    {
        $this->authorization
            ->requirePlatformAdministrator();

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash(
                'company_create_errors',
                [
                    'form' =>
                        'The form session expired. Please try again.',
                ]
            );
            \redirect(
                '/administration/companies/create'
            );
        }

        $input = [
            'code' => \postString('code'),
            'name' => \postString('name'),
            'legal_name' =>
                \postString('legal_name'),
            'contact_email' =>
                \postString('contact_email'),
            'contact_phone' =>
                \postString('contact_phone'),
            'country_code' =>
                \postString('country_code'),
            'default_currency' =>
                \postString('default_currency'),
            'timezone' =>
                \postString('timezone'),
            'subscription_status' =>
                \postString(
                    'subscription_status'
                ),
            'subscription_expires_at' =>
                \postString(
                    'subscription_expires_at'
                ),
            'brand_primary_color' =>
                \postString(
                    'brand_primary_color'
                ),
            'module_codes' =>
                $_POST['module_codes'] ?? [],
            'owner_display_name' =>
                \postString(
                    'owner_display_name'
                ),
            'owner_username' =>
                \postString('owner_username'),
            'owner_email' =>
                \postString('owner_email'),
        ];
        $result = $this->companies->create(
            $input,
            (int) $_SESSION['auth']['user_id']
        );

        if (!$result['successful']) {
            \flash(
                'company_create_errors',
                $result['errors']
            );
            \flash(
                'company_create_old',
                $input
            );
            \redirect(
                '/administration/companies/create'
            );
        }

        \flash(
            'company_notice',
            'Customer company is pending vendor approval.'
        );
        \flash(
            'company_owner_credentials',
            [
                'username' =>
                    $result['ownerUsername'],
                'temporary_password' =>
                    $result['temporaryPassword'],
                'purpose' => 'provisioning',
            ]
        );
        \redirect(
            '/administration/companies/view?id='
            . (int) $result['companyId']
        );
    }

    public function show(): void
    {
        $this->authorization
            ->requirePlatformAdministrator();
        $details = $this->companies->details(
            $this->queryInteger('id')
        );

        if ($details === null) {
            $this->notFound();
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
            'pageTitle' => (string) (
                $details['company']['name']
                ?? 'Company Details'
            ),
            'pageDescription' =>
                'Customer workspace profile and provisioned ERP modules.',
            'contentView' =>
                'administration.companies.show',
            'user' => $_SESSION['auth'],
            'company' => $details['company'],
            'modules' => $details['modules'],
            'enabledModuleCount' =>
                $details['enabledModuleCount'],
            'notice' => \getFlash(
                'company_notice'
            ),
            'ownerCredentials' => \getFlash(
                'company_owner_credentials'
            ),
            'companyUserCredentials' => \getFlash(
                'company_user_reset_credentials'
            ),
            'companyUsers' =>
                $this->companyUserPasswordResets->users(
                    (int) $details['company']['company_id'],
                    (int) ($_SESSION['auth']['user_id'] ?? 0)
                ),
            'approvalErrors' => \getFlash(
                'company_approval_errors',
                []
            ),
            'lifecycleErrors' => \getFlash(
                'company_lifecycle_errors',
                []
            ),
        ]);
    }

    public function showCompanyUserPasswordReset(): void
    {
        $this->authorization->requirePlatformAdministrator();
        $companyId = $this->queryInteger('company_id');
        $userId = $this->queryInteger('user_id');
        $target = $this->companyUserPasswordResets->target(
            $companyId,
            $userId,
            (int) ($_SESSION['auth']['user_id'] ?? 0)
        );
        if ($target === null) {
            $this->notFound();
        }

        \view('layouts.app', [
            'applicationName' => \config('name', 'OfficeApp ERP'),
            'environment' => \config('environment', 'unknown'),
            'pageTitle' => 'Reset Company User Password',
            'pageDescription' =>
                'Issue a one-time credential for a user in this customer workspace.',
            'contentView' =>
                'administration.companies.reset-user-password',
            'user' => $_SESSION['auth'],
            'company' => $target['company'],
            'targetUser' => $target['targetUser'],
            'errors' => \getFlash(
                'company_user_password_reset_errors',
                []
            ),
        ]);
    }

    public function resetCompanyUserPassword(): void
    {
        $this->authorization->requirePlatformAdministrator();
        $companyId = $this->postInteger('company_id');
        $userId = $this->postInteger('user_id');
        $redirect = '/administration/companies/reset-user-password'
            . '?company_id=' . $companyId . '&user_id=' . $userId;

        if (!\verifyCsrfToken(\postString('_token'))) {
            \flash('company_user_password_reset_errors', [
                'form' => 'The form session expired. Please try again.',
            ]);
            \redirect($redirect);
        }

        $result = $this->companyUserPasswordResets->reset(
            $companyId,
            $userId,
            (int) ($_SESSION['auth']['user_id'] ?? 0)
        );
        if (!empty($result['notFound'])) {
            $this->notFound();
        }
        if (empty($result['successful'])) {
            \flash(
                'company_user_password_reset_errors',
                $result['errors'] ?? ['form' => 'Password reset failed.']
            );
            \redirect($redirect);
        }

        \flash('company_notice',
            'The company user password was reset. Transfer the one-time credential securely.'
        );
        \flash('company_user_reset_credentials', [
            'username' => $result['username'],
            'temporary_password' => $result['temporaryPassword'],
        ]);
        \redirect('/administration/companies/view?id=' . $companyId);
    }

    public function showOwnerPasswordReset(): void
    {
        $this->authorization
            ->requirePlatformAdministrator();
        $companyId = $this->queryInteger('id');
        $target = $this->ownerPasswordResets
            ->target(
                $companyId,
                (int) (
                    $_SESSION['auth']['user_id']
                    ?? 0
                )
            );

        if ($target === null) {
            $this->notFound();
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
            'pageTitle' =>
                'Reset Company Owner Password',
            'pageDescription' =>
                'Issue a one-time credential for the primary owner of this customer workspace.',
            'contentView' =>
                'administration.companies.reset-owner-password',
            'user' => $_SESSION['auth'],
            'company' => $target['company'],
            'owner' => $target['owner'],
            'errors' => \getFlash(
                'company_owner_password_reset_errors',
                []
            ),
        ]);
    }

    public function resetOwnerPassword(): void
    {
        $this->authorization
            ->requirePlatformAdministrator();
        $companyId = $this->postInteger(
            'company_id'
        );

        if (!\verifyCsrfToken(
            \postString('_token')
        )) {
            \flash(
                'company_owner_password_reset_errors',
                [
                    'form' =>
                        'The form session expired. Please try again.',
                ]
            );
            \redirect(
                '/administration/companies/reset-owner-password?id='
                . $companyId
            );
        }

        $result = $this->ownerPasswordResets
            ->reset(
                $companyId,
                (int) (
                    $_SESSION['auth']['user_id']
                    ?? 0
                )
            );

        if (!empty($result['notFound'])) {
            $this->notFound();
        }

        if (!$result['successful']) {
            \flash(
                'company_owner_password_reset_errors',
                $result['errors']
            );
            \redirect(
                '/administration/companies/reset-owner-password?id='
                . $companyId
            );
        }

        \flash(
            'company_notice',
            'The company owner password was reset. Transfer the one-time credential securely.'
        );
        \flash(
            'company_owner_credentials',
            [
                'username' =>
                    $result['username'],
                'temporary_password' =>
                    $result[
                        'temporaryPassword'
                    ],
                'purpose' => 'reset',
            ]
        );
        \redirect(
            '/administration/companies/view?id='
            . $companyId
        );
    }

    public function edit(): void
    {
        $this->authorization
            ->requirePlatformAdministrator();
        $details = $this->companies->details(
            $this->queryInteger('id')
        );

        if ($details === null) {
            $this->notFound();
        }

        $options = $this->companies
            ->formOptions();
        $old = \getFlash(
            'company_update_old',
            []
        );

        if (!is_array($old) || $old === []) {
            $old = $details['company'];
            $old['subscription_status'] =
                $details['company'][
                    'commercialStatus'
                ] ?? 'active';
            $old['subscription_expires_at'] =
                $this->dateInputValue(
                    $details['company'][
                        'subscription_expires_at'
                    ] ?? null
                );
            $old['module_codes'] = array_values(
                array_map(
                    static fn (array $module): string =>
                        (string) $module['code'],
                    array_filter(
                        $details['modules'],
                        static fn (array $module): bool =>
                        in_array(
                                (string) (
                                    $module[
                                        'license_status'
                                    ] ?? ''
                                ),
                                ['active', 'trial'],
                                true
                            )
                    )
                )
            );
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
            'pageTitle' => 'Edit Company',
            'pageDescription' =>
                'Maintain customer workspace, commercial terms and licensed ERP modules.',
            'contentView' =>
                'administration.companies.edit',
            'user' => $_SESSION['auth'],
            'company' => $details['company'],
            'modules' => $options['modules'],
            'timezones' => $options['timezones'],
            'currencies' => $options['currencies'],
            'errors' => \getFlash(
                'company_update_errors',
                []
            ),
            'old' => $old,
        ]);
    }

    public function update(): void
    {
        $this->authorization
            ->requirePlatformAdministrator();
        $companyId = $this->postInteger(
            'company_id'
        );

        if (!\verifyCsrfToken(
            \postString('_token')
        )) {
            \flash('company_update_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);
            \redirect(
                '/administration/companies/edit?id='
                . $companyId
            );
        }

        $input = [
            'name' => \postString('name'),
            'legal_name' =>
                \postString('legal_name'),
            'contact_email' =>
                \postString('contact_email'),
            'contact_phone' =>
                \postString('contact_phone'),
            'country_code' =>
                \postString('country_code'),
            'default_currency' =>
                \postString('default_currency'),
            'timezone' => \postString('timezone'),
            'subscription_status' =>
                \postString(
                    'subscription_status'
                ),
            'subscription_expires_at' =>
                \postString(
                    'subscription_expires_at'
                ),
            'brand_primary_color' =>
                \postString(
                    'brand_primary_color'
                ),
            'module_codes' =>
                $_POST['module_codes'] ?? [],
        ];
        $result = $this->updates->update(
            $companyId,
            $input,
            (int) (
                $_SESSION['auth']['user_id'] ?? 0
            )
        );

        if (!empty($result['notFound'])) {
            $this->notFound();
        }

        if (!$result['successful']) {
            \flash(
                'company_update_errors',
                $result['errors']
            );
            \flash(
                'company_update_old',
                $input
            );
            \redirect(
                '/administration/companies/edit?id='
                . $companyId
            );
        }

        \flash(
            'company_notice',
            !empty($result['changed'])
                ? 'Company settings and module licenses were updated.'
                : 'No company changes were required.'
        );
        \redirect(
            '/administration/companies/view?id='
            . $companyId
        );
    }

    public function changeLifecycle(): void
    {
        $this->authorization
            ->requirePlatformAdministrator();
        $companyId = $this->postInteger(
            'company_id'
        );

        if (!\verifyCsrfToken(
            \postString('_token')
        )) {
            \flash('company_lifecycle_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);
            \redirect(
                '/administration/companies/view?id='
                . $companyId
            );
        }

        $action = \postString('action');
        $result = $this->lifecycle->change(
            $companyId,
            $action,
            \postString('reason'),
            (int) (
                $_SESSION['auth']['user_id'] ?? 0
            )
        );

        if (!empty($result['notFound'])) {
            $this->notFound();
        }

        if (!$result['successful']) {
            \flash(
                'company_lifecycle_errors',
                $result['errors']
            );
            \redirect(
                '/administration/companies/view?id='
                . $companyId
            );
        }

        \flash(
            'company_notice',
            $action === 'suspend'
                ? 'Company access was suspended. Customer sessions are now blocked.'
                : 'Company access was reactivated.'
        );
        \redirect(
            '/administration/companies/view?id='
            . $companyId
        );
    }

    public function approve(): void
    {
        $this->authorization
            ->requirePlatformAdministrator();
        $companyId = $this->postInteger(
            'company_id'
        );

        if (!\verifyCsrfToken(
            \postString('_token')
        )) {
            \flash('company_approval_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);
            \redirect(
                '/administration/companies/view?id='
                . $companyId
            );
        }

        $result = $this->companies->approve(
            $companyId,
            (int) (
                $_SESSION['auth']['user_id'] ?? 0
            )
        );

        if (!empty($result['notFound'])) {
            $this->notFound();
        }

        if (!$result['successful']) {
            \flash(
                'company_approval_errors',
                $result['errors']
            );
            \redirect(
                '/administration/companies/view?id='
                . $companyId
            );
        }

        \flash(
            'company_notice',
            !empty($result['changed'])
                ? 'Company approved. The owner can now sign in.'
                : 'The company was already approved.'
        );
        \redirect(
            '/administration/companies/view?id='
            . $companyId
        );
    }

    private function queryString(
        string $key,
        string $default = ''
    ): string {
        $value = $_GET[$key] ?? $default;

        return is_string($value)
            ? trim($value)
            : $default;
    }

    private function queryInteger(
        string $key,
        int $default = 0
    ): int {
        $value = $_GET[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value)
            && ctype_digit($value)
                ? (int) $value
                : $default;
    }

    private function postInteger(
        string $key
    ): int {
        $value = $_POST[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value)
            && ctype_digit($value)
                ? (int) $value
                : 0;
    }

    private function dateInputValue(
        mixed $value
    ): string {
        if (!is_string($value)) {
            return '';
        }

        $timestamp = strtotime($value);

        return $timestamp === false
            ? ''
            : date('Y-m-d', $timestamp);
    }

    private function notFound(): void
    {
        http_response_code(404);

        \view('errors.company-404', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
        ]);

        exit;
    }
}
