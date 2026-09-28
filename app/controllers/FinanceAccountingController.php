<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\FinanceAccountingWorkspaceService;
use App\Services\FinanceStatementService;
use App\Services\FinanceReconciliationService;

final class FinanceAccountingController
{
    public function show(string $section): void
    {
        $authorization=new AuthorizationService();

        $authorization->requireModulePermission(
            'finance',
            'finance.records.view'
        );

        try {
            if($section==='reports'){
                $workspace=
                    (new FinanceAccountingWorkspaceService())
                        ->workspace(
                            $section,
                            $_GET
                        );

                $list=null;
                $controls=[];
            } else {
                $entity=match($section){
                    'accounts'=>'accounts',
                    'ledger'=>'ledger',
                    'receivables'=>'ar-aging',
                    'payables'=>'payables',
                    'cash-bank'=>'cash-bank',
                    default=>throw new \RuntimeException(
                        'Unknown Finance workspace.'
                    ),
                };

                $lists=
                    new \App\Services\Lists\FinanceListService();

                $list=$lists->listing(
                    $entity,
                    $_GET
                );

                if(isset($_GET['download'])){
                    $authorization->requireModulePermission(
                        'finance',
                        'finance.export'
                    );

                    $filename=match($entity){
                        'accounts'=>'Finance_Chart_of_Accounts',
                        'ledger'=>'Finance_General_Ledger',
                        'ar-aging'=>'Finance_AR_Aging',
                        'payables'=>'Finance_AP_Aging',
                        'cash-bank'=>'Finance_Cash_Bank',
                    };

                    \App\Services\Lists\ListDownload::send(
                        $filename,
                        $list,
                        $lists->columns($entity),
                        $_GET['download']
                    );
                }

                $page=$list->page();

                $workspace=
                    (new FinanceAccountingWorkspaceService())
                        ->workspace(
                            $section,
                            $_GET,
                            $page['rows']
                        );

                $workspace['list']=$page;

                $controls=
                    $lists->controls($entity);
            }
        } catch (\RuntimeException $exception) {
            http_response_code(400);
            echo \e($exception->getMessage());
            return;
        }

        \view('layouts.app',[
            'applicationName'=>
                \config('name','OfficeApp ERP'),

            'environment'=>
                \config('environment','unknown'),

            'pageTitle'=>$workspace['title'],

            'pageDescription'=>
                'Posted accounting records for this company.',

            'contentView'=>
                'finance.accounting-workspace',

            'user'=>$_SESSION['auth'],

            'workspace'=>$workspace,

            'listControls'=>$controls,

            'canExport'=>in_array(
                'finance.export',
                $_SESSION['auth']['permissions']??[],
                true
            ),
        ]);
    }
    public function statement(string $kind): void
    {
        (new AuthorizationService())->requireModulePermission('finance','finance.records.view');
        try{$statement=(new FinanceStatementService())->statement($kind,$_GET);}catch(\RuntimeException $e){http_response_code(400);echo \e($e->getMessage());return;}
        if(isset($_GET['download'])) {
            (new AuthorizationService())->requireTenantPermission('finance.export');
            if(!isset($statement['exportList'])){http_response_code(400);echo 'Select a party to export.';return;}
            \App\Services\Lists\ListDownload::send($kind.'-statement',$statement['exportList'],\App\Services\Lists\FinanceStatementListService::columns(),$_GET['download']);
        }
        \view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>ucfirst($kind).' Statement','pageDescription'=>'Posted document and allocation history.','contentView'=>'finance.statement','user'=>$_SESSION['auth'],'statement'=>$statement]);
    }

    public function reconciliation(): void
    {
        (new AuthorizationService())->requireModulePermission('finance','finance.records.view');
        \view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>'Subledger Reconciliation','pageDescription'=>'Compare posted subledger balances with control accounts.','contentView'=>'finance.reconciliation','user'=>$_SESSION['auth'],'reconciliations'=>(new FinanceReconciliationService())->summary()]);
    }
}
