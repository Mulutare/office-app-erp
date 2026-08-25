<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\FinanceDashboardService;
use App\Services\FinanceOperationsService;
use App\Services\AccountingPeriodService;

final class FinanceController
{
    private AuthorizationService $authorization;
    private FinanceDashboardService $finance;
    private FinanceOperationsService $operations;
    private AccountingPeriodService $periods;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->finance =
            new FinanceDashboardService();
        $this->operations = new FinanceOperationsService();
        $this->periods = new AccountingPeriodService();
    }

    public function customerInvoices(): void
    {
        $this->authorizeOperations('finance.records.view');
        $filters = [
            'search' => $this->queryString('search'),
            'payment' => $this->queryString('payment'),
            'date_from' => $this->queryString('date_from'),
            'date_to' => $this->queryString('date_to'),
            'customer' => $this->queryString('customer'),
        ];
        $allInvoices = $this->operations->customerInvoices();
        \view('layouts.app', [
            'applicationName' => \config('name', 'OfficeApp ERP'),
            'environment' => \config('environment', 'unknown'),
            'pageTitle' => 'Customer Invoices',
            'pageDescription' => 'Posted customer invoices, residuals and payment states.',
            'contentView' => 'finance.customer-invoices',
            'invoices' => $this->operations->customerInvoices($filters),
            'invoiceFilters' => $filters,
            'invoiceCustomers' => array_values(array_unique(array_filter(array_column($allInvoices, 'customer_name')))),
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
        $this->authorization->requireModulePermission(
            'finance',
            $permission
        );
    }

    public function accountingPeriods(): void
    {
        $this->authorizeOperations('finance.period.view');
        \view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>'Accounting Periods','pageDescription'=>'Controlled fiscal-year and posting-period lifecycle.','contentView'=>'finance.accounting-periods','user'=>$_SESSION['auth'],'notice'=>\getFlash('finance_period_notice'),'error'=>\getFlash('finance_period_error')]+$this->periods->workspace());
    }

    public function createFiscalYear(): void { $this->periodMutation('finance.period.manage',function():void{$this->periods->createFiscalYear($_POST,$this->actor());},'Fiscal year created.'); }
    public function createAccountingPeriod(): void { $this->periodMutation('finance.period.manage',function():void{$this->periods->createPeriod($_POST,$this->actor());},'Accounting period opened.'); }
    public function transitionAccountingPeriod(string $id): void
    {
        $action=\postString('action');$permission=$action==='reopen'?'finance.period.reopen':'finance.period.close';
        $this->periodMutation($permission,function()use($id,$action):void{$this->periods->transition((int)$id,$action,\postString('reason'),$this->actor());},'Accounting period updated.');
    }

    private function periodMutation(string $permission,callable $operation,string $message): void
    {
        $this->authorizeOperations($permission);if(!\verifyCsrfToken(\postString('_token'))){\flash('finance_period_error','The form session expired.');\redirect('/finance/accounting-periods');}
        try{$operation();\flash('finance_period_notice',$message);}catch(\Throwable $e){\flash('finance_period_error',$e->getMessage());}\redirect('/finance/accounting-periods');
    }
    private function actor(): int{return(int)($_SESSION['auth']['user_id']??0);}

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
