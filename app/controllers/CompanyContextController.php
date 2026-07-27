<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\AuthorizationService;

final class CompanyContextController
{
    private AuthorizationService $authorization;
    private AuthService $auth;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->auth = new AuthService();
    }

    public function switch(): void
    {
        $this->authorization
            ->requireAuthentication();

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash(
                'company_switch_error',
                'The workspace request expired. Please try again.'
            );
            \redirect('/dashboard');
        }

        $companyId = filter_var(
            $_POST['company_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (
            !is_int($companyId)
            || $companyId < 1
            || !$this->auth->switchCompany(
                $companyId
            )
        ) {
            \flash(
                'company_switch_error',
                'You do not have access to that company workspace.'
            );
            \redirect('/dashboard');
        }

        \flash(
            'company_switch_success',
            'Company workspace changed successfully.'
        );
        \redirect('/dashboard');
    }
}
