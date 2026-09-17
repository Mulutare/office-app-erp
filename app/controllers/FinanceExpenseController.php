<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\FinanceExpenseService;

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
            'expenseData'=>$this->service()->workspace(),
            'notice'=>\getFlash('finance_expense_notice'),
            'expenseError'=>\getFlash('finance_expense_error'),
        ]);
    }
    public function create(): void { $this->mutate('finance.records.manage',fn()=> $this->service()->save($_POST,$this->actor()),'Expense draft saved.'); }
    public function edit(string $id): void { $this->mutate('finance.records.manage',fn()=> $this->service()->save($_POST,$this->actor(),(int)$id),'Expense draft updated.'); }
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
    private function mutate(string $permission,callable $operation,string $message): void
    {
        $this->permit($permission);
        if(!\verifyCsrfToken(\postString('_token'))){\flash('finance_expense_error','The form session expired.');\redirect('/finance/expenses');}
        try{$operation();\flash('finance_expense_notice',$message);}catch(\Throwable $e){\flash('finance_expense_error',$e->getMessage());}
        \redirect('/finance/expenses');
    }
}
