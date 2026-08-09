<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\FinanceDashboardService;
use App\Services\FinanceOperationsService;

final class FinanceController
{
    private AuthorizationService $authorization;
    private FinanceDashboardService $finance;
    private FinanceOperationsService $operations;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->finance =
            new FinanceDashboardService();
        $this->operations = new FinanceOperationsService();
    }

    public function customerInvoices(): void
    {
        $this->authorizeOperations('finance.records.view');
        \view('layouts.app', [
            'applicationName' => \config('name', 'OfficeApp ERP'),
            'environment' => \config('environment', 'unknown'),
            'pageTitle' => 'Customer Invoices',
            'pageDescription' => 'Posted customer invoices, residuals and payment states.',
            'contentView' => 'finance.customer-invoices',
            'invoices' => $this->operations->customerInvoices(),
            'user' => $_SESSION['auth'],
        ]);
    }

    public function customerInvoice(string $id): void
    {
        $this->authorizeOperations('finance.records.view');
        $invoice = $this->operations->customerInvoice((int) $id);
        if ($invoice === null) {
            http_response_code(404);
            \view('errors.404', ['applicationName' => \config('name', 'OfficeApp ERP')]);
            return;
        }
        \view('layouts.app', [
            'applicationName' => \config('name', 'OfficeApp ERP'),
            'environment' => \config('environment', 'unknown'),
            'pageTitle' => (string) $invoice['invoice_number'],
            'pageDescription' => 'Authoritative customer invoice and payment allocation history.',
            'contentView' => 'finance.customer-invoice',
            'invoice' => $invoice,
            'paymentJournals' => $this->operations->paymentJournals(),
            'notice' => \getFlash('finance_invoice_notice'),
            'errors' => \getFlash('finance_invoice_errors', []),
            'canRegisterPayment' => in_array(
                'finance.records.manage',
                $_SESSION['auth']['permissions'] ?? [],
                true
            ),
            'canPostInvoice' => in_array(
                'finance.records.manage',
                $_SESSION['auth']['permissions'] ?? [],
                true
            ),
            'user' => $_SESSION['auth'],
        ]);
    }

    public function registerCustomerPayment(string $id): void
    {
        $this->authorizeOperations('finance.records.manage');
        if (!\verifyCsrfToken(\postString('_token'))) {
            \flash('finance_invoice_errors', ['form' => 'The form session expired. Please try again.']);
            \redirect('/finance/customer-invoices/' . (int) $id);
        }
        $result = $this->operations->registerPayment(
            (int) $id,
            $_POST,
            (int) ($_SESSION['auth']['user_id'] ?? 0)
        );
        if (empty($result['successful'])) {
            \flash('finance_invoice_errors', $result['errors'] ?? []);
        } else {
            \flash('finance_invoice_notice', [
                'message' => 'Payment ' . (string) ($result['result']['paymentNumber'] ?? '') . ' posted and allocated.',
            ]);
        }
        \redirect('/finance/customer-invoices/' . (int) $id);
    }

    public function postCustomerInvoice(string $id): void
    {
        $this->authorizeOperations('finance.records.manage');
        if (!\verifyCsrfToken(\postString('_token'))) {
            \flash('finance_invoice_errors', ['form' => 'The form session expired. Please try again.']);
            \redirect('/finance/customer-invoices/' . (int) $id);
        }
        $result = $this->operations->postInvoice(
            (int) $id, (int) ($_SESSION['auth']['user_id'] ?? 0)
        );
        if (empty($result['successful'])) {
            \flash('finance_invoice_errors', $result['errors'] ?? []);
        } else {
            \flash('finance_invoice_notice', ['message' => 'Invoice posted to the Sales Journal.']);
        }
        \redirect('/finance/customer-invoices/' . (int) $id);
    }

    private function authorizeOperations(string $permission): void
    {
        $this->authorization->requireModule('finance');
        $this->authorization->requireTenantPermission($permission);
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
