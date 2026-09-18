<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\SalesProductVariantService;

final class SalesProductVariantController
{
    private function actor(): int { return (int)($_SESSION['auth']['user_id']??0); }
    private function permit(string $permission): void { (new AuthorizationService())->requireModulePermission('sales',$permission); }
    public function index(): void
    {
        $this->permit('sales.view');
        $variants=(new SalesProductVariantService())->options();
        \view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>'Product Variants','pageDescription'=>'Company-scoped Mobile and MiFi classification for real SKUs.','contentView'=>'sales.product-variants','user'=>$_SESSION['auth'],'variants'=>$variants,'canManageVariants'=>(new \App\Services\ModuleRoleService())->permissionAllowed((new \App\Services\TenantContext())->companyId(),$this->actor(),'sales.catalogue.manage'),'notice'=>\getFlash('variant_notice'),'error'=>\getFlash('variant_error')]);
    }
    public function brand(): void { $this->mutate(fn()=>(new SalesProductVariantService())->createBrand(\postString('name'),$this->actor())); }
    public function model(): void { $this->mutate(fn()=>(new SalesProductVariantService())->createModel($_POST,$this->actor())); }
    public function assign(): void { $this->mutate(fn()=>(new SalesProductVariantService())->assignModel((int)($_POST['product_id']??0),($_POST['model_id']??'')===''?null:(int)$_POST['model_id'],$this->actor())); }
    private function mutate(callable $work): void
    {
        $this->permit('sales.catalogue.manage');
        if(!\verifyCsrfToken(\postString('_token'))){\flash('variant_error','The form session expired.');\redirect('/sales/product-variants');}
        try{$work();\flash('variant_notice','Product classification saved.');}catch(\Throwable $e){\flash('variant_error',$e->getMessage());}
        \redirect('/sales/product-variants');
    }
}
