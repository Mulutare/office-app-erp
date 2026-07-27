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
            'pageTitle' => 'Finance',
            'pageDescription' =>
                'Expense request monitoring and financial workflow visibility.',
            'contentView' => 'finance.index',
            'user' => $_SESSION['auth'],
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
