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
        (new AuthorizationService())->requireModulePermission('finance', 'finance.records.view');
        try {
            $workspace = (new FinanceAccountingWorkspaceService())->workspace($section, $_GET);
        } catch (\RuntimeException $exception) {
            http_response_code(400);
            echo \e($exception->getMessage());
            return;
        }
        \view('layouts.app', [
            'applicationName' => \config('name', 'OfficeApp ERP'),
            'environment' => \config('environment', 'unknown'),
            'pageTitle' => $workspace['title'],
            'pageDescription' => 'Posted accounting records for this company.',
            'contentView' => 'finance.accounting-workspace',
            'user' => $_SESSION['auth'],
            'workspace' => $workspace,
        ]);
    }

    public function statement(string $kind): void
    {
        (new AuthorizationService())->requireModulePermission('finance','finance.records.view');
        try{$statement=(new FinanceStatementService())->statement($kind,$_GET);}catch(\RuntimeException $e){http_response_code(400);echo \e($e->getMessage());return;}
        \view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>ucfirst($kind).' Statement','pageDescription'=>'Posted document and allocation history.','contentView'=>'finance.statement','user'=>$_SESSION['auth'],'statement'=>$statement]);
    }

    public function reconciliation(): void
    {
        (new AuthorizationService())->requireModulePermission('finance','finance.records.view');
        \view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>'Subledger Reconciliation','pageDescription'=>'Compare posted subledger balances with control accounts.','contentView'=>'finance.reconciliation','user'=>$_SESSION['auth'],'reconciliations'=>(new FinanceReconciliationService())->summary()]);
    }
}
