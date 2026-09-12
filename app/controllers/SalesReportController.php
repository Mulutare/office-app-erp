<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\SalesPerformanceReportService;

final class SalesReportController
{
    private AuthorizationService $authorization;
    private SalesPerformanceReportService $reports;

    public function __construct()
    {
        $this->authorization = new AuthorizationService();
        $this->reports = new SalesPerformanceReportService();
    }

    public function index(): void
    {
        $this->authorization->requireModulePermission('sales', 'sales.view');

        $report = $this->reports->report(
            (int) ($_SESSION['auth']['user_id'] ?? 0),
            is_array($_GET) ? $_GET : []
        );

        \view('layouts.app', [
            'applicationName' => \config('name', 'OfficeApp ERP'),
            'environment' => \config('environment', 'unknown'),
            'pageTitle' => 'DSA/DSP Sales Report',
            'pageDescription' => 'Finalized DSA/DSP sales by period, product, employee and authorized shop.',
            'contentView' => 'sales.dsa-dsp-report',
            'report' => $report,
            'simpleSalesUser' => !empty($report['isAgent']),
            'moduleContext' => [
                'module' => 'sales',
                'section' => 'dsa_dsp_report',
            ],
            'user' => $_SESSION['auth'],
        ]);
    }
}
