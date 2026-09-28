<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\FinanceDashboardService;
use App\Services\FinanceOperationsService;
use App\Services\AccountingPeriodService;
use App\Services\SalesWorkflowTraceService;
use App\Services\TenantContext;

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

        $lists=new \App\Services\Lists\FinanceListService();
        $list=$lists->listing('invoices',$_GET);
        $queueFactory=new \App\Services\Lists\FinanceQuickSaleListService();
        $queueList=$queueFactory->listing($this->actor(),$_GET);

        if(isset($_GET['download'])) {
            $this->authorizeOperations('finance.export');
            $entity=\App\Services\Lists\ListQuery::text($_GET['register']??'invoices');
            if(!in_array($entity,['invoices','quick-sales'],true)){http_response_code(400);echo 'Unknown invoice register.';return;}
            \App\Services\Lists\ListDownload::send($entity,$entity==='invoices'?$list:$queueList,
                $entity==='invoices'?$lists->columns('invoices'):\App\Services\Lists\FinanceQuickSaleListService::columns(),$_GET['download']);
        }

        $page=$list->page();$queuePage=$queueList->page();

        \view('layouts.app',[
            'applicationName'=>\config(
                'name',
                'OfficeApp ERP'
            ),
            'environment'=>\config(
                'environment',
                'unknown'
            ),
            'pageTitle'=>'Customer Invoices',
            'pageDescription'=>
                'Posted customer invoices, residuals and payment states.',
            'contentView'=>'finance.customer-invoices',

            'invoiceList'=>$page,
            'invoiceControls'=>
                $lists->controls('invoices'),

            'invoiceCustomers'=>
                $lists->customerOptions(),

            'quickSaleQueue'=>$queuePage['rows'],
            'quickSaleList'=>$queuePage,'quickSaleControls'=>$queueFactory->controls($this->actor()),

            'canExport'=>in_array(
                'finance.export',
                $_SESSION['auth']['permissions']??[],
                true
            ),

            'user'=>$_SESSION['auth'],
        ]);
    }
    public function customerInvoice(string $id): void
    {
        $this->authorizeOperations('finance.records.view');
        $invoice = $this->operations->customerInvoice((int) $id,false);
        if ($invoice === null) {
            http_response_code(404);
            \view('errors.404', ['applicationName' => \config('name', 'OfficeApp ERP')]);
            return;
        }
        $invoice['related']=(new \App\Services\Lists\DocumentListService())->workspace(['invoice-payments'],$_GET,(int)$id,\appBasePath().'/finance/customer-invoices/'.(int)$id,
            (new \App\Services\ModuleRoleService())->permissionAllowed((new TenantContext())->companyId(),$this->actor(),'finance.export'));
        \App\Services\Lists\DocumentListService::download($invoice['related'],$_GET);
        $invoice['payments']=$invoice['related']['lists']['invoice-payments']['rows'];
        $quickSaleEvidence = null;
        foreach (
            (new \App\Services\SalesQuickSaleService())->financeQueue($this->actor(),(int)$invoice['invoice_id'])
            as $task
        ) {
            if (
                (int) ($task['invoice_id'] ?? 0)
                === (int) $invoice['invoice_id']
            ) {
                $quickSaleEvidence = $task;
                break;
            }
        }

        \view('layouts.app', [
            'applicationName' => \config('name', 'OfficeApp ERP'),
            'environment' => \config('environment', 'unknown'),
            'pageTitle' => (string) $invoice['invoice_number'],
            'pageDescription' => 'Authoritative customer invoice and payment allocation history.',
            'contentView' => 'finance.customer-invoice',
            'invoice' => $invoice,
            'quickSaleEvidence' => $quickSaleEvidence,
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
            'workflowTrace' => (new SalesWorkflowTraceService())->trace(
                (new TenantContext())->companyId(),
                'invoice',
                (int) $invoice['invoice_id'],
                $_SESSION['auth'] ?? []
            ),
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
        $workspace=$this->periods->workspace($_GET);
        if(isset($_GET['download'])) {
            $this->authorizeOperations('finance.export');
            $entity=\App\Services\Lists\ListQuery::text($_GET['register']??'accounting-periods');
            if(!isset($workspace['exportLists'][$entity])){http_response_code(400);echo 'Choose an accounting-period register.';return;}
            \App\Services\Lists\ListDownload::send($entity,$workspace['exportLists'][$entity],(new \App\Services\Lists\FinanceListService())->columns($entity),$_GET['download']);
        }

        \view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>'Accounting Periods','pageDescription'=>'Controlled fiscal-year and posting-period lifecycle.','contentView'=>'finance.accounting-periods','user'=>$_SESSION['auth'],'notice'=>\getFlash('finance_period_notice'),'error'=>\getFlash('finance_period_error')]+$workspace);
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
        $section=$this->queryString(
            'section',
            'dashboard'
        );

        /*
         * Smart registers use one SQL source for:
         * search, filters, count, pagination and export.
         */
        if(in_array(
            $section,
            [
                'receivables',
                'receipts',
                'journals',
            ],
            true
        )){
            $lists=
                new \App\Services\Lists\FinanceListService();

            $list=$lists->listing(
                $section,
                $_GET
            );

            if(isset($_GET['download'])){
                $this->authorizeOperations(
                    'finance.export'
                );

                $exportName=match($section){
                    'receivables'=>
                        'Finance_Receivables',

                    'receipts'=>
                        'Finance_Receipts',

                    'journals'=>
                        'Finance_Journals',
                };

                \App\Services\Lists\ListDownload::send(
                    $exportName,
                    $list,
                    $lists->columns($section),
                    $_GET['download']
                );
            }

            $page=$list->page();

            $title=match($section){
                'receivables'=>'Receivables',
                'receipts'=>'Receipts',
                'journals'=>'Journals',
            };

            $description=match($section){
                'receivables'=>
                    'Posted customer balances, due dates and collection status.',

                'receipts'=>
                    'Posted customer payments and collection references.',

                'journals'=>
                    'Finance journal batches and accounting totals.',
            };

            \view('layouts.app',[
                'applicationName'=>\config(
                    'name',
                    'OfficeApp ERP'
                ),

                'environment'=>\config(
                    'environment',
                    'unknown'
                ),

                'pageTitle'=>$title,
                'pageDescription'=>$description,

                'contentView'=>'finance.register',

                'registerEntity'=>$section,
                'register'=>$page,

                'registerControls'=>
                    $lists->controls($section),

                'canExport'=>in_array(
                    'finance.export',
                    $_SESSION['auth']['permissions']??[],
                    true
                ),

                'user'=>$_SESSION['auth'],
            ]);

            return;
        }

        /*
         * Modern Expenses owns the active expense workflow.
         * Keep the old history URL only as compatibility.
         */
        if(
            $section==='expenses'
            && in_array(
                'finance.expenses.view',
                $_SESSION['auth']['permissions']??[],
                true
            )
        ){
            \redirect('/finance/expenses');
        }
        $dashboard=$this->finance->smartOverview($section,array_replace($_GET,['section'=>$section]),
            (new \App\Services\ModuleRoleService())->permissionAllowed((new TenantContext())->companyId(),$this->actor(),'finance.export'));
        if(isset($_GET['download']))$this->authorizeOperations('finance.export');
        \App\Services\Lists\DocumentListService::download($dashboard['overviewRegister'],$_GET);
        \view('layouts.app',[
            'applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),
            'pageTitle'=>'Finance','pageDescription'=>'Sales receivables, collections and current company work.',
            'contentView'=>'finance.index','user'=>$_SESSION['auth'],
            'workCenter'=>in_array('finance.records.view',$_SESSION['auth']['permissions']??[],true)
                ?(new \App\Services\FinanceWorkCenterService())->summary():[],
        ]+$dashboard);
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
