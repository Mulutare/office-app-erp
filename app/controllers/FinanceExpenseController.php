<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\FinanceExpenseService;
use App\Services\FinanceExpenseEvidenceService;

final class FinanceExpenseController
{
    private function permit(string $permission): void { (new AuthorizationService())->requireModulePermission('finance',$permission); }
    private function actor(): int { return (int)($_SESSION['auth']['user_id'] ?? 0); }
    private function service(): FinanceExpenseService { return new FinanceExpenseService(); }

    public function index(): void
    {
        $this->permit('finance.records.view');
        \view('layouts.app', [
            'applicationName'=>\config('name','OfficeApp ERP'),
            'environment'=>\config('environment','unknown'),
            'pageTitle'=>'Expenses',
            'pageDescription'=>'Controlled expense requests, approvals and posted payments.',
            'contentView'=>'finance.expenses',
            'user'=>$_SESSION['auth'],
            'expenseData'=>$this->service()->workspace($_GET),
            'notice'=>\getFlash('finance_expense_notice'),
            'expenseError'=>\getFlash('finance_expense_error'),
        ]);
    }
    public function create(): void { $this->mutate('finance.records.manage',fn()=> $this->createWithEvidence(),'Expense draft saved.'); }
    public function edit(string $id): void { $this->mutate('finance.records.manage',fn()=> $this->editWithEvidence((int)$id),'Expense draft updated.'); }
    public function addEvidence(string $id): void { $this->mutate('finance.records.manage',fn()=> (new FinanceExpenseEvidenceService())->upload((int)$id,is_array($_FILES['evidence']??null)?$_FILES['evidence']:[],$this->actor()),'Expense evidence added.'); }
    public function removeEvidence(string $id,string $evidenceId): void { $this->mutate('finance.records.manage',fn()=> (new FinanceExpenseEvidenceService())->remove((int)$id,(int)$evidenceId,$this->actor()),'Expense evidence removed.'); }
    public function categoryDefaults(string $id): void { $this->mutate('finance.records.manage',fn()=> $this->service()->saveCategoryDefaults((int)$id,(int)($_POST['default_expense_account_id']??0),(int)($_POST['default_recoverable_tax_account_id']??0)),'Category defaults saved.'); }
    public function evidence(string $id,string $evidenceId): void
    {
        $this->permit('finance.records.view');
        $e=(new FinanceExpenseEvidenceService())->download((int)$id,(int)$evidenceId);
        if ($e===null) { http_response_code(404); return; }
        header('Content-Type: '.$e['mime_type']);
        header('Content-Length: '.(string)filesize($e['storage_path']));
        header('Content-Disposition: attachment; filename="'.rawurlencode(basename((string)$e['original_name'])).'"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($e['storage_path']);
        exit;
    }
    public function submit(string $id): void { $this->mutate('finance.records.manage',fn()=> $this->service()->transition((int)$id,'submit',$this->actor()),'Expense submitted.'); }
    public function cancel(string $id): void { $this->mutate('finance.records.manage',fn()=> $this->service()->transition((int)$id,'cancel',$this->actor()),'Expense cancelled.'); }
    public function review(string $id): void
    {
        $this->mutate('finance.requests.approve',fn()=> $this->service()->transition((int)$id,\postString('action'),$this->actor(),\postString('reason')),'Expense reviewed.');
    }
    public function pay(string $id): void
    {
        $this->mutate('finance.records.manage',fn()=> $this->service()->pay((int)$id,(int)($_POST['journal_id'] ?? 0),\postString('payment_date'),$this->actor()),'Expense paid and posted.');
    }
    public function recognize(string $id): void
    {
        $this->mutate('finance.records.manage',fn()=> $this->service()->recognize((int)$id,\postString('recognition_date'),$this->actor()),'Employee payable recognized.');
    }
    public function reverse(string $id): void
    {
        $this->mutate('finance.requests.approve',fn()=> $this->service()->reverse((int)$id,\postString('reversal_date'),\postString('reason'),$this->actor()),'Expense reversed with opposite journal entries.');
    }
    private function createWithEvidence(): void
    {
        $files=is_array($_FILES['evidence']??null)?$_FILES['evidence']:[];
        $evidence=new FinanceExpenseEvidenceService();
        $evidence->prevalidate($files);
        $id=$this->service()->save($_POST,$this->actor());
        try { $evidence->upload($id,$files,$this->actor()); }
        catch (\Throwable $error) {
            try { $discarded=$this->service()->discardNewDraftAfterEvidenceFailure($id,$this->actor()); }
            catch (\Throwable $cleanupError) {
                error_log(sprintf('Expense draft compensation failed: expense_request_id=%d actor_id=%d', $id,$this->actor()));
                $discarded=false;
            }
            throw new \RuntimeException($discarded
                ? 'No expense was saved because evidence upload failed: '.$error->getMessage()
                : 'Expense draft #'.$id.' was saved, but evidence upload failed. Review this draft before retrying.',0,$error);
        }
    }
    private function editWithEvidence(int $id): void
    {
        $files=is_array($_FILES['evidence']??null)?$_FILES['evidence']:[];
        $evidence=new FinanceExpenseEvidenceService();
        $evidence->prevalidate($files,$id,$this->actor());
        $this->service()->save($_POST,$this->actor(),$id);
        try { $evidence->upload($id,$files,$this->actor()); }
        catch (\Throwable $error) { throw new \RuntimeException('Draft changes were saved, but evidence upload failed: '.$error->getMessage(),0,$error); }
    }
    private function mutate(string $permission,callable $operation,string $message): void
    {
        $this->permit($permission);
        if(!\verifyCsrfToken(\postString('_token'))){\flash('finance_expense_error','The form session expired.');\redirect('/finance/expenses');}
        try{$operation();\flash('finance_expense_notice',$message);}catch(\Throwable $e){\flash('finance_expense_error',$e->getMessage());}
        \redirect('/finance/expenses');
    }
}
