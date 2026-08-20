<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;

final class PowerBiController
{
    private AuthorizationService $authorization;

    public function __construct()
    {
        $this->authorization = new AuthorizationService();
    }

    public function index(): void
    {
        $this->authorization->requirePermission(
            'dashboard.view'
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
            'pageTitle' => 'Power BI Analytics',
            'pageDescription' =>
                'Power BI analytics integration test.',
            'contentView' => 'analytics.power-bi-test',
            'user' => $_SESSION['auth'],
        ]);
    }
}
