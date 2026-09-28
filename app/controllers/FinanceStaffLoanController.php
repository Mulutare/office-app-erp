<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\FinanceStaffLoanService;

final class FinanceStaffLoanController
{
    private function permit(string $permission): void {(new AuthorizationService())->requireModulePermission('finance',$permission);}
    private function actor(): int {return(int)($_SESSION['auth']['user_id']??0);}
    private function service(): FinanceStaffLoanService {return new FinanceStaffLoanService();}
    public function index(): void
    {
        $this->permit('finance.records.view');

        $lists=
            new \App\Services\Lists\FinanceListService();

        $list=$lists->listing(
            'staff-loans',
            $_GET
        );

        if(isset($_GET['download'])){
            $this->permit('finance.export');

            \App\Services\Lists\ListDownload::send(
                'Finance_Staff_Loans',
                $list,
                $lists->columns('staff-loans'),
                $_GET['download']
            );
        }

        $page=$list->page();

        $loanData=
            $this->service()->workspace(
                trim((string)($_GET['status']??'')),
                $page['rows']
            );

        $loanData['list']=$page;

        $this->render(
            'Staff Loans & Advances',
            'finance.staff-loans',
            [
                'loanData'=>$loanData,

                'loanControls'=>
                    $lists->controls('staff-loans'),

                'canExport'=>in_array(
                    'finance.export',
                    $_SESSION['auth']['permissions']??[],
                    true
                ),
            ]
        );
    }
    public function detail(string $id): void
    {
        $this->permit('finance.records.view');$loan=$this->service()->detail((int)$id,$_GET);if($loan===null){http_response_code(404);\view('errors.404',['applicationName'=>\config('name','OfficeApp ERP')]);return;}if(isset($_GET['download'])) {
            $this->permit('finance.export');$entity=\App\Services\Lists\ListQuery::text($_GET['register']??'');
            if(!isset($loan['exportLists'][$entity])){http_response_code(400);echo 'Unknown loan register.';return;}
            \App\Services\Lists\ListDownload::send('loan-'.$entity,$loan['exportLists'][$entity],\App\Services\Lists\FinanceLoanListService::columns($entity),$_GET['download']);
        }
        $this->render('Staff Loan Detail','finance.staff-loan',['loan'=>$loan]);
    }
    public function create(): void{$this->mutate('finance.records.manage',fn()=>$this->service()->create($_POST,$this->actor()),'/finance/staff-loans','Loan draft created.');}
    public function submit(string $id): void{$this->mutate('finance.records.manage',fn()=>$this->service()->transition((int)$id,'submit',$this->actor()),'/finance/staff-loans/'.$id,'Loan submitted.');}
    public function cancel(string $id): void{$this->mutate('finance.records.manage',fn()=>$this->service()->transition((int)$id,'cancel',$this->actor()),'/finance/staff-loans/'.$id,'Loan cancelled.');}
    public function review(string $id): void{$this->mutate('finance.requests.approve',fn()=>$this->service()->transition((int)$id,\postString('action'),$this->actor(),\postString('reason')),'/finance/staff-loans/'.$id,'Loan reviewed.');}
    public function disburse(string $id): void{$this->mutate('finance.records.manage',fn()=>$this->service()->disburse((int)$id,(int)($_POST['journal_id']??0),\postString('disbursement_date'),$this->actor()),'/finance/staff-loans/'.$id,'Loan disbursed and posted.');}
    public function repay(string $id): void{$this->mutate('finance.records.manage',fn()=>$this->service()->repay((int)$id,$_POST,$this->actor()),'/finance/staff-loans/'.$id,'Loan payment allocated and posted.');}
    private function mutate(string $permission,callable $action,string $path,string $message): void
    {
        $this->permit($permission);if(!\verifyCsrfToken(\postString('_token'))){\flash('staff_loan_error','The form session expired.');\redirect($path);}try{$action();\flash('staff_loan_notice',$message);}catch(\Throwable $e){\flash('staff_loan_error',$e->getMessage());}\redirect($path);
    }
    private function render(string $title,string $view,array $extra): void
    {
        \view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>$title,'pageDescription'=>'Company staff loan register and installment accounting.','contentView'=>$view,'user'=>$_SESSION['auth'],'notice'=>\getFlash('staff_loan_notice'),'loanError'=>\getFlash('staff_loan_error')]+$extra);
    }
}
