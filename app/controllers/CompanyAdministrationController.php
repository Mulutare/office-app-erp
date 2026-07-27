<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\CompanyProvisioningService;

final class CompanyAdministrationController
{
    private AuthorizationService $authorization;
    private CompanyProvisioningService $companies;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->companies =
            new CompanyProvisioningService();
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
            'approvalErrors' => \getFlash(
                'company_approval_errors',
                []
            ),
        ]);
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
