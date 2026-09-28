<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\FinanceBankReconciliationService;

final class FinanceBankReconciliationController
{
    private function permit(string $suffix): void
    { (new AuthorizationService())->requireModulePermission('finance','finance.bank_reconciliation.'.$suffix); }
    private function actor(): int { return (int)($_SESSION['auth']['user_id']??0); }
    private function service(): FinanceBankReconciliationService { return new FinanceBankReconciliationService(); }
    private function render(string $title,string $content,array $extra): void
    {
        \view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>$title,'pageDescription'=>'Entire-bank statement reconciliation against posted GL activity.','contentView'=>$content,'user'=>$_SESSION['auth'],'notice'=>\getFlash('bank_rec_notice'),'error'=>\getFlash('bank_rec_error')]+$extra);
    }
    public function index(): void
    {
        $this->permit('view');

        $lists=
            new \App\Services\Lists\FinanceListService();

        /*
         * Two independent smart lists share this page.
         * Namespaces prevent one register's page/filter
         * parameters from overwriting the other.
         */
        $mappingList=$lists->listing(
            'bank-mappings',
            $_GET,
            'mappings'
        );

        $statementList=$lists->listing(
            'bank-statements',
            $_GET,
            'statements'
        );

        if(isset($_GET['download'])){
            (new AuthorizationService())
                ->requireModulePermission(
                    'finance',
                    'finance.export'
                );

            $register=
                trim((string)($_GET['register']??''));

            if($register==='bank-mappings'){
                \App\Services\Lists\ListDownload::send(
                    'Finance_Bank_GL_Mappings',
                    $mappingList,
                    $lists->columns('bank-mappings'),
                    $_GET['download']
                );
            }

            if($register==='bank-statements'){
                \App\Services\Lists\ListDownload::send(
                    'Finance_Bank_Reconciliations',
                    $statementList,
                    $lists->columns('bank-statements'),
                    $_GET['download']
                );
            }

            http_response_code(400);
            echo \e(
                'Choose a valid Bank Reconciliation register.'
            );
            return;
        }

        $mappingPage=$mappingList->page();
        $statementPage=$statementList->page();

        /*
         * Keep the existing full option sources for workflow
         * forms. Only the two visible register tables are paged.
         */
        $bankData=$this->service()->register();

        $bankData['mappingOptions']=
            $bankData['mappings']??[];

        $bankData['mappings']=
            $mappingPage['rows'];

        $bankData['statements']=
            $statementPage['rows'];

        $bankData['mappingList']=
            $mappingPage;

        $bankData['statementList']=
            $statementPage;

        $this->render(
            'Bank Reconciliation',
            'finance.bank-reconciliation',
            [
                'bankData'=>$bankData,

                'mappingControls'=>
                    $lists->controls('bank-mappings'),

                'statementControls'=>
                    $lists->controls('bank-statements'),

                'canExport'=>in_array(
                    'finance.export',
                    $_SESSION['auth']['permissions']??[],
                    true
                ),
            ]
        );
    }
    public function show(string $id): void
    {
        $this->permit('view');
        try{$worksheet=$this->service()->worksheet((int)$id,false);}catch(\Throwable $e){http_response_code(404);echo \e($e->getMessage());return;}
        $worksheet['related']=(new \App\Services\Lists\DocumentListService())->workspace(['bank-matches','bank-events'],$_GET,(int)$id,\appBasePath().'/finance/bank-reconciliation/'.(int)$id,
            (new \App\Services\ModuleRoleService())->permissionAllowed((new \App\Services\TenantContext())->companyId(),$this->actor(),'finance.export'));
        if(isset($_GET['download']))(new AuthorizationService())->requireModulePermission('finance','finance.export');
        \App\Services\Lists\DocumentListService::download($worksheet['related'],$_GET);
        $worksheet['matches']=$worksheet['related']['lists']['bank-matches']['rows'];$worksheet['events']=$worksheet['related']['lists']['bank-events']['rows'];
        $this->render('Bank Reconciliation Worksheet' ,'finance.bank-reconciliation-worksheet',['worksheet'=>$worksheet]);
    }
    private function mutate(string $permission,callable $work,string $message,?int $id=null): void
    {
        $this->permit($permission);
        if(!\verifyCsrfToken(\postString('_token'))){\flash('bank_rec_error','The form session expired.');\redirect($id?'/finance/bank-reconciliation/'.$id:'/finance/bank-reconciliation');}
        try{$result=$work();\flash('bank_rec_notice',$message);if($id===null&&is_int($result)&&$permission==='prepare'&&isset($_POST['mapping_id']))$id=$result;}
        catch(\Throwable $e){\flash('bank_rec_error',$e->getMessage());}
        \redirect($id?'/finance/bank-reconciliation/'.$id:'/finance/bank-reconciliation');
    }
    public function createMapping(): void
    { $this->mutate('mapping',fn()=>$this->service()->createMapping($_POST,$this->actor()),'Mapping draft saved.'); }
    public function approveMapping(string $id): void
    { $this->mutate('mapping',fn()=>$this->service()->approveMapping((int)$id,$this->actor()),'Mapping approved.'); }
    public function createStatement(): void
    { $this->mutate('prepare',fn()=>$this->service()->createStatement($_POST,$this->actor()),'Statement created.'); }
    public function addLine(string $id): void
    { $this->mutate('prepare',fn()=>$this->service()->addLine((int)$id,$_POST,$this->actor()),'Statement line added.',(int)$id); }
    public function match(string $id): void
    { $this->mutate('prepare',fn()=>$this->service()->match((int)$id,(int)($_POST['statement_line_id']??0),(int)($_POST['journal_entry_id']??0),$_POST['applied_amount']??'', $this->actor()),'Items matched.',(int)$id); }
    public function unmatch(string $id,string $matchId): void
    { $this->mutate('prepare',fn()=>$this->service()->unmatch((int)$id,(int)$matchId,$this->actor()),'Match removed.',(int)$id); }
    public function sendForReview(string $id): void
    { $this->mutate('prepare',fn()=>$this->service()->sendForReview((int)$id,$this->actor()),'Sent for review.',(int)$id); }
    public function review(string $id): void
    { $this->mutate('review',fn()=>$this->service()->review((int)$id,\postString('action')==='complete',\postString('reason'),$this->actor()),'Review recorded.',(int)$id); }
}
