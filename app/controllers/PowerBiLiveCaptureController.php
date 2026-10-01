<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\PowerBiLiveCaptureService;

final class PowerBiLiveCaptureController
{
    private AuthorizationService $authorization;
    private PowerBiLiveCaptureService $capture;

    public function __construct()
    {
        $this->authorization = new AuthorizationService();
        $this->capture = new PowerBiLiveCaptureService();
    }

    public function index(): void
    {
        $this->authorize();

        try {
            $state = $this->capture->pageState($this->actor(), $_GET);
        } catch (\Throwable $exception) {
            $state = [
                'warehouses' => [],
                'selectedWarehouseId' => 0,
                'selectedDate' => date('Y-m-d'),
                'metric' => null,
                'recentMetrics' => [],
                'reportingControl' => [],
            ];
            \flash('powerbi_capture_error', $exception->getMessage());
        }

        \view('layouts.app', [
            'applicationName' => \config('name', 'OfficeApp ERP'),
            'pageTitle' => 'Daily Shop Metrics',
            'pageDescription' => 'Capture shop-level reporting facts that do not already exist in operational ERP tables.',
            'contentView' => 'sales.powerbi-live-capture',
            'user' => $_SESSION['auth'],
            'moduleContext' => ['module' => 'sales', 'section' => 'daily_shop_metrics'],
            'notice' => \getFlash('powerbi_capture_notice'),
            'error' => \getFlash('powerbi_capture_error'),
        ] + $state);
    }

    public function save(): void
    {
        $this->authorize();
        if (!\verifyCsrfToken(\postString('_token'))) {
            \flash('powerbi_capture_error', 'The form session expired.');
            \redirect('/sales/daily-shop-metrics');
        }

        $result = $this->capture->saveDailyMetrics($_POST, $this->actor());
        $warehouseId = max(0, (int)($_POST['warehouse_id'] ?? 0));
        $date = trim((string)($_POST['report_date'] ?? date('Y-m-d')));
        $query = http_build_query(['warehouse_id' => $warehouseId, 'date' => $date]);

        if (empty($result['successful'])) {
            \flash('powerbi_capture_error', (string)($result['errors']['form'] ?? 'The daily shop metrics were not saved.'));
        } else {
            \flash('powerbi_capture_notice', ['message' => 'Daily shop metrics saved.']);
        }

        \redirect('/sales/daily-shop-metrics?' . $query);
    }

    private function authorize(): void
    {
        $this->authorization->requireAnyModulePermission([
            ['sales', 'sales.quick_sale.review'],
            ['sales', 'sales.report.review'],
        ]);
    }

    private function actor(): int
    {
        return (int)($_SESSION['auth']['user_id'] ?? 0);
    }
}
