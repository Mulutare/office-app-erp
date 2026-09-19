<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\SalesPricingService;

final class SalesPricingController
{
    private function actor(): int { return (int)($_SESSION['auth']['user_id']??0); }
    private function permit(string $suffix): void { (new AuthorizationService())->requireModulePermission('sales','sales.pricing.'.$suffix); }

    public function index(): void
    {
        $this->permit('view');
        $data=(new SalesPricingService())->register($this->actor());
        $company=(new \App\Services\TenantContext())->companyId();
        $permissions=new \App\Services\ModuleRoleService();
        $agent=(new \App\Services\SalesHierarchyScope())->isAgent($company,$this->actor());
        \view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>'Sales Pricing','pageDescription'=>'Effective SKU prices, discount percentages and tax percentages.','contentView'=>'sales.pricing','user'=>$_SESSION['auth'],'notice'=>\getFlash('sales_pricing_notice'),'error'=>\getFlash('sales_pricing_error'),'pricingData'=>$data,'canManagePricing'=>!$agent && $permissions->permissionAllowed($company,$this->actor(),'sales.pricing.manage')]);
    }

    public function submit(): void
    {
        $this->mutate('manage',fn()=>(new SalesPricingService())->submit($_POST,$this->actor()),'Product price, discount and tax saved.');
    }

    private function mutate(string $permission,callable $work,string $message): void
    {
        $this->permit($permission);
        $returnTo=\postString('return_to')==='pricelists'?'/sales/pricelists':'/sales/pricing';
        if(!\verifyCsrfToken(\postString('_token'))){\flash('sales_pricing_error','The form session expired.');\redirect($returnTo);}
        try{$work();\flash('sales_pricing_notice',$message);}catch(\Throwable $e){\flash('sales_pricing_error',$e->getMessage());}
        \redirect($returnTo);
    }
}
