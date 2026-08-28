<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AssetService;
use App\Services\AuthorizationService;

final class AssetController
{
    public function __construct(private ?AuthorizationService $authorization=null,private ?AssetService $assets=null){$this->authorization??=new AuthorizationService();$this->assets??=new AssetService();}
    public function index(): void{$this->authorize('assets.view');$this->render('assets.index',$this->assets->workspace(),'Fixed Assets','Capitalization, depreciation, custody and disposal.');}
    public function show(string $id): void{$this->authorize('assets.view');$asset=$this->assets->asset((int)$id);if(!$asset){http_response_code(404);\view('errors.404',['applicationName'=>\config('name','OfficeApp ERP')]);return;}$workspace=$this->assets->workspace();$workspace['asset']=$asset;$this->render('assets.show',$workspace,(string)$asset['asset_number'],'Asset lifecycle, accounting and traceability.');}
    public function storeCategory(): void{$this->mutate('assets.manage','/assets-management?section=categories',fn()=>$this->assets->createCategory($_POST,$this->actor()),'Asset category created.');}
    public function storeAsset(): void{$this->mutate('assets.manage','/assets-management',fn()=>$this->assets->createAsset($_POST,$this->actor()),'Draft asset created.');}
    public function capitalize(): void{$this->mutate('assets.inventory.capitalize','/assets-management',fn()=>$this->assets->capitalizeFromInventory($_POST,$this->actor()),'Inventory issued and capitalized as a draft asset.');}
    public function activate(string $id): void{$this->mutate('assets.activate','/assets-management/'.$id,fn()=>$this->assets->activate((int)$id,\postString('in_service_date'),$this->actor()),'Asset activated and depreciation schedule generated.');}
    public function postDepreciation(string $id,string $lineId): void{$this->mutate('assets.depreciation.post','/assets-management/'.$id,fn()=>$this->assets->postDepreciation((int)$lineId,$this->actor()),'Depreciation posted to Finance.');}
    public function transfer(string $id): void{$this->mutate('assets.manage','/assets-management/'.$id,fn()=>$this->assets->transfer((int)$id,$_POST,$this->actor()),'Asset assignment transferred.');}
    public function maintenance(string $id): void{$this->mutate('assets.manage','/assets-management/'.$id,fn()=>$this->assets->addMaintenance((int)$id,$_POST,$this->actor()),'Routine maintenance recorded without capitalization.');}
    public function tracking(string $id): void{$this->mutate('assets.manage','/assets-management/'.$id,fn()=>$this->assets->updateTracking((int)$id,$_POST,$this->actor()),'Asset presence and health verified.');}
    public function dispose(string $id): void{$this->mutate('assets.dispose','/assets-management/'.$id,fn()=>$this->assets->dispose((int)$id,$_POST,$this->actor()),'Asset disposed and Finance journal posted.');}
    private function mutate(string $permission,string $redirect,callable $operation,string $message): void
    {
        $this->authorize($permission);
        if(!\verifyCsrfToken(\postString('_token'))){http_response_code(419);\flash('asset_errors',['form'=>'The form session expired. Please try again.']);\redirect($redirect);}
        try {
            $result=$operation();
        } catch (\Throwable $exception) {
            error_log('Asset operation failed: '.$exception::class);
            \flash('asset_errors',['form'=>'The asset operation could not be completed.']);
            \redirect($redirect);
        }
        if (!is_array($result) || empty($result['successful'])) {
            \flash(
                'asset_errors',
                is_array($result)
                    ? ($result['errors'] ?? ['form' => 'The operation failed.'])
                    : ['form' => 'The operation failed.']
            );
        } else {
            \flash('asset_notice', ['message' => $message]);
        }
        \redirect($redirect);
    }
    private function authorize(string $permission): void{$this->authorization->requireModule('assets');$this->authorization->requireTenantPermission($permission);}
    private function actor(): int{return(int)($_SESSION['auth']['user_id']??0);}
    private function render(string $content,array $payload,string $title,string $description): void{\view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>$title,'pageDescription'=>$description,'contentView'=>$content,'user'=>$_SESSION['auth'],'notice'=>\getFlash('asset_notice'),'errors'=>\getFlash('asset_errors',[])]+$payload);}
}
