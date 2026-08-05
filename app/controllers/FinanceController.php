<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\FinanceDashboardService;

final class FinanceController
{
    private AuthorizationService $authorization;
    private FinanceDashboardService $finance;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->finance =
            new FinanceDashboardService();
    }

    public function index(): void
    {
        $this->authorization
            ->requireModule('finance');
        $this->authorization
            ->requireAnyPermission([
                'finance.records.view',
                'finance.records.manage',
                'finance.requests.approve',
            ]);
        $dashboard = $this->finance->dashboard(
            $this->queryString('search'),
            $this->queryString('status'),
            $this->queryInteger('page', 1),
            $this->queryString(
                'receivable_search'
            ),
            $this->queryString(
                'receivable_status'
            ),
            $this->queryInteger(
                'receivable_page',
                1
            )
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
            'pageTitle' => 'Finance',
            'pageDescription' =>
                'Sales receivables, receipts, journal postings and expense workflow visibility.',
            'contentView' => 'finance.index',
            'user' => $_SESSION['auth'],
            'receivableSummary' =>
                $dashboard['receivableSummary'],
            'receivables' =>
                $dashboard['receivables'],
            'receivableTotal' =>
                $dashboard['receivableTotal'],
            'receivableStatusOptions' =>
                $dashboard[
                    'receivableStatusOptions'
                ],
            'receivableFilters' =>
                $dashboard[
                    'receivableFilters'
                ],
            'receivablePagination' =>
                $dashboard[
                    'receivablePagination'
                ],
            'recentReceipts' =>
                $dashboard['recentReceipts'],
            'recentJournals' =>
                $dashboard['recentJournals'],
            'requests' => $dashboard['requests'],
            'summary' => $dashboard['summary'],
            'statusOptions' =>
                $dashboard['statusOptions'],
            'filters' => $dashboard['filters'],
            'pagination' =>
                $dashboard['pagination'],
            'canManage' => in_array(
                'finance.records.manage',
                $_SESSION['auth']['permissions'] ?? [],
                true
            ),
            'canApprove' => in_array(
                'finance.requests.approve',
                $_SESSION['auth']['permissions'] ?? [],
                true
            ),
        ]);
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
        int $default
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
}
