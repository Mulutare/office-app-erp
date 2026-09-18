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
    { $this->permit('view');$this->render('Bank Reconciliation','finance.bank-reconciliation',['bankData'=>$this->service()->register()]); }
    public function show(string $id): void
    {
        $this->permit('view');
        try{$worksheet=$this->service()->worksheet((int)$id);}catch(\Throwable $e){http_response_code(404);echo \e($e->getMessage());return;}
        $this->render('Bank Reconciliation Worksheet','finance.bank-reconciliation-worksheet',['worksheet'=>$worksheet]);
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
