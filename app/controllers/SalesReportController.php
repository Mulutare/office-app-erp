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
        $this->authorization->requireAnyModulePermission([
            ['sales', 'sales.report.submit'],
            ['sales', 'sales.report.review'],
        ]);

        $report = $this->reports->report(
            (int) ($_SESSION['auth']['user_id'] ?? 0),
            is_array($_GET) ? $_GET : []
        );

        $canExport=in_array('sales.reports.export',$_SESSION['auth']['permissions']??[],true);
        if(isset($_GET['download'])) {
            $this->authorization->requireTenantPermission('sales.reports.export');
            $columns=['currency'=>'Currency','sold_quantity'=>'Sold quantity','returned_quantity'=>'Returned quantity','sales_amount'=>'Sales amount','report_count'=>'Reports'];
            if($report['viewBy']!=='employee')$columns=['sku'=>'SKU','product_name'=>'Product']+$columns;
            if($report['viewBy']!=='product')$columns=['employee_name'=>'Employee','shop_name'=>'Shop']+$columns;
            \App\Services\Lists\ListDownload::send('dsa-dsp-sales',$report['exportList'],$columns,$_GET['download']);
        }

        \view('layouts.app', [
            'applicationName' => \config('name', 'OfficeApp ERP'),
            'environment' => \config('environment', 'unknown'),
            'pageTitle' => 'DSA/DSP Sales Report',
            'pageDescription' => 'Finalized DSA/DSP sales by period, product, employee and authorized shop.',
            'contentView' => 'sales.dsa-dsp-report',
            'report' => $report,
            'canExportList' => $canExport,
            'simpleSalesUser' => !empty($report['isAgent']),
            'moduleContext' => [
                'module' => 'sales',
                'section' => 'dsa_dsp_report',
            ],
            'user' => $_SESSION['auth'],
        ]);
    }
}
