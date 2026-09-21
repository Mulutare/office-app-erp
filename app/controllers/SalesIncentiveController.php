<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\SalesIncentiveService;

final class SalesIncentiveController
{
    private function actor(): int { return (int)($_SESSION['auth']['user_id']??0); }
    private function permit(string $suffix): void { (new AuthorizationService())->requireModulePermission('sales','sales.incentive.'.$suffix); }
    private function render(string $title,string $content,array $extra): void
    {
        $company=(new \App\Services\TenantContext())->companyId();$permissions=new \App\Services\ModuleRoleService();
        $agent=(new \App\Services\SalesHierarchyScope())->isAgent($company,$this->actor());
        \view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>$title,'pageDescription'=>'Cash float, manager-approved Safaricom incentive variance and external settlement.','contentView'=>$content,'user'=>$_SESSION['auth'],'simpleSalesUser'=>$agent,'moduleContext'=>['module'=>'sales','section'=>'incentives'],'notice'=>\getFlash('incentive_notice'),'error'=>\getFlash('incentive_error'),'canIssueFloat'=>$permissions->permissionAllowed($company,$this->actor(),'sales.incentive.approve'),'canSubmitIncentive'=>$permissions->permissionAllowed($company,$this->actor(),'sales.incentive.submit'),'canApproveIncentive'=>$permissions->permissionAllowed($company,$this->actor(),'sales.incentive.approve'),'canSettleIncentive'=>$permissions->permissionAllowed($company,$this->actor(),'sales.incentive.settle')]+$extra);
    }
    public function index(): void { $this->permit('view');$this->render('DSA/DSP Incentives','sales.incentives',['incentiveData'=>(new SalesIncentiveService())->register($this->actor(),$_GET)]); }
    public function show(string $id): void
    {
        $this->permit('view');
        try{$detail=(new SalesIncentiveService())->detail((int)$id,$this->actor());}catch(\Throwable $e){http_response_code(404);echo \e($e->getMessage());return;}
        $this->render('Safaricom Incentive','sales.incentive-detail',['incentiveDetail'=>$detail]);
    }
    public function issueFloat(): void { $this->mutate('approve',fn()=>(new SalesIncentiveService())->issueFloat($_POST,$this->actor()),'Cash float issued.'); }
    public function submit(): void { $this->mutate('submit',fn()=>(new SalesIncentiveService())->submitClaim($_POST,$this->actor()),'Incentive claim sent to the responsible manager.'); }
    public function decide(string $id): void { $this->mutate('approve',fn()=>(new SalesIncentiveService())->decideClaim((int)$id,\postString('decision')==='approve',$_POST['approved_amount']??null,\postString('reason'),$this->actor()),'Incentive decision recorded.',(int)$id); }
    public function settle(string $id): void { $this->mutate('settle',fn()=>(new SalesIncentiveService())->settle((int)$id,$_POST,$this->actor()),'Safaricom settlement recorded.',(int)$id); }
    private function mutate(string $permission,callable $work,string $message,?int $id=null): void
    {
        $this->permit('view');$this->permit($permission);$target=$id?'/sales/incentives/'.$id:'/sales/incentives';
        if(!\verifyCsrfToken(\postString('_token'))){\flash('incentive_error','The form session expired.');\redirect($target);}
        try{$work();\flash('incentive_notice',$message);}catch(\Throwable $e){\flash('incentive_error',$e->getMessage());}
        \redirect($target);
    }
}
