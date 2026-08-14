<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Services\AuthorizationService;
use App\Services\ProcurementService;
use PDOException;
use Throwable;
final class ProcurementController
{
    private AuthorizationService $auth; private ProcurementService $service;
    public function __construct(){ $this->auth=new AuthorizationService();$this->service=new ProcurementService(); }
    public function index():void{$this->auth->requireModulePermission('procurement','procurement.view');$this->render();}
    public function showOrder(string $id):void{$this->auth->requireModulePermission('procurement','procurement.view');$this->render((int)$id);}
    public function supplier():void{$this->mutate('procurement.suppliers.manage',fn()=>$this->service->createSupplier($_POST,$this->actor()),'/procurement');}
    public function updateSupplier(string $id):void{$this->mutate('procurement.suppliers.manage',fn()=>$this->service->updateSupplier((int)$id,$_POST,$this->actor()),'/procurement');}
    public function supplierActive(string $id):void{$this->mutate('procurement.suppliers.manage',fn()=>$this->service->setSupplierActive((int)$id,\postString('active')==='1',$this->actor()),'/procurement');}
    public function requisition():void{$this->mutate('procurement.requisitions.create',fn()=>$this->service->createRequisition($_POST,$this->actor()),'/procurement');}
    public function requisitionAction(string $id):void{$action=\postString('action');$permission=in_array($action,['approve','reject'],true)?'procurement.requisitions.approve':'procurement.requisitions.create';$this->mutate($permission,fn()=>$this->service->transitionRequisition((int)$id,$action,$this->actor(),\postString('reason')),'/procurement');}
    public function order():void{$this->mutate('procurement.orders.create',fn()=>$this->service->createOrder($_POST,$this->actor()),'/procurement');}
    public function orderAction(string $id):void{$action=\postString('action');$permission=['approve'=>'procurement.orders.approve','confirm'=>'procurement.orders.confirm'][$action]??'procurement.orders.create';$this->mutate($permission,fn()=>$this->service->transitionOrder((int)$id,$action,$this->actor()),'/procurement/'.(int)$id);}
    public function receipt(string $id):void{$this->mutate('procurement.receipts.create',fn()=>$this->service->createReceipt((int)$id,(array)($_POST['quantity']??[]),$this->actor()),'/procurement/'.(int)$id);}
    public function bill(string $id):void{$this->mutate('procurement.bills.create',fn()=>$this->service->createBill((int)$id,\postString('supplier_invoice_number'),$this->actor()),'/procurement/'.(int)$id);}
    public function postBill(string $id):void{$this->mutate('procurement.bills.post',fn()=>$this->service->postBill((int)$id,$this->actor()),'/procurement');}
    public function payBill(string $id):void{$this->mutate('procurement.payments.post',fn()=>$this->service->payBill((int)$id,$_POST,$this->actor()),'/procurement');}
    public function reverseBill(string $id):void{$this->mutate('procurement.bills.reverse',fn()=>$this->service->reverseBill((int)$id,$this->actor()),'/procurement');}
    public function vendorReturn(string $id):void{$this->mutate('procurement.returns.post',fn()=>$this->service->postVendorReturn((int)$id,$_POST,$this->actor()),'/procurement/'.(int)$id);}
    private function mutate(string $permission,callable $work,string $redirect):void{$this->auth->requireModulePermission('procurement',$permission);if(!\verifyCsrfToken(\postString('_token'))){\flash('procurement_error','The form session expired.');\redirect($redirect);}try{$work();\flash('procurement_notice','The procurement action completed successfully.');}catch(PDOException $e){error_log($e->__toString());\flash('procurement_error','The action could not be saved. Check for duplicate or invalid business data and try again.');}catch(Throwable $e){\flash('procurement_error',$e->getMessage());}\redirect($redirect);}
    private function render(?int $id=null):void{\view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>$id?'Purchase Order':'Purchase Management','pageDescription'=>'Controlled requisition, ordering, receiving and accounts payable workflow.','contentView'=>'procurement.index','user'=>$_SESSION['auth'],'permissions'=>$_SESSION['auth']['permissions']??[],'notice'=>\getFlash('procurement_notice'),'error'=>\getFlash('procurement_error')]+$this->service->workspace($id));}
    private function actor():int{return(int)($_SESSION['auth']['user_id']??0);}
}
