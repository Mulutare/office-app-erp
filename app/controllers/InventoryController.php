<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\InventoryService;

final class InventoryController
{
    private AuthorizationService $authorization;
    private InventoryService $inventory;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();

        $this->inventory =
            new InventoryService();
    }

    public function index(): void
    {
        $this->authorize('inventory.view');

        \view('layouts.app', [
            'applicationName' =>
                \config(
                    'name',
                    'OfficeApp ERP'
                ),
            'environment' =>
                \config(
                    'environment',
                    'unknown'
                ),
            'pageTitle' => 'Inventory',
            'pageDescription' =>
                'Stock, warehouses, receipts and inventory controls.',
            'contentView' => 'inventory.index',
            'user' => $_SESSION['auth'],
        ] + $this->inventory->workspace());
    }

    public function receipts(): void{$this->authorize('inventory.receipts.view');$this->renderReceipt('list');}
    public function createReceipt(): void{$this->authorize('inventory.receipts.create');$this->renderReceipt('create');}
    public function showReceipt(string $id): void{$this->authorize('inventory.receipts.view');$this->renderReceipt('show',(int)$id);}
    private function renderReceipt(string $mode,?int $id=null): void
    {$receipt=$id===null?null:$this->inventory->receipt($id);if($id!==null&&$receipt===null){http_response_code(404);\view('errors.404',['applicationName'=>\config('name','OfficeApp ERP')]);return;}\view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>$mode==='create'?'New goods receipt':($mode==='list'?'Goods receipts':(string)$receipt['receipt_number']),'pageDescription'=>'Receive vendor goods through RCPT and automatic Input-to-Stock putaway.','contentView'=>'inventory.receipts','receiptMode'=>$mode,'receipt'=>$receipt,'receipts'=>$this->inventory->receipts(),'options'=>$this->inventory->receiptOptions(),'notice'=>\getFlash('inventory_receipt_notice'),'errors'=>\getFlash('inventory_receipt_errors',[]),'user'=>$_SESSION['auth'],'canCreate'=>in_array('inventory.receipts.create',$_SESSION['auth']['permissions']??[],true),'canApprove'=>in_array('inventory.receipts.approve',$_SESSION['auth']['permissions']??[],true),'canPost'=>in_array('inventory.receipts.post',$_SESSION['auth']['permissions']??[],true)]);}
    public function storeReceipt(): void{$this->authorize('inventory.receipts.create');if(!\verifyCsrfToken(\postString('_token'))){\flash('inventory_receipt_errors',['form'=>'The form session expired.']);\redirect('/inventory/receipts/create');}$result=$this->inventory->createGoodsReceipt($_POST,(int)($_SESSION['auth']['user_id']??0));if(empty($result['successful'])){\flash('inventory_receipt_errors',$result['errors']??[]);\redirect('/inventory/receipts/create');}\flash('inventory_receipt_notice',['message'=>'Goods receipt saved as draft.']);\redirect('/inventory/receipts/'.(int)$result['id']);}
    public function approveReceipt(string $id): void{$this->authorize('inventory.receipts.approve');if(!\verifyCsrfToken(\postString('_token'))){\flash('inventory_receipt_errors',['form'=>'The form session expired.']);\redirect('/inventory/receipts/'.(int)$id);}$result=$this->inventory->approveGoodsReceipt((int)$id,(int)($_SESSION['auth']['user_id']??0));if(empty($result['successful']))\flash('inventory_receipt_errors',$result['errors']??[]);else \flash('inventory_receipt_notice',['message'=>'Goods receipt approved and ready to post.']);\redirect('/inventory/receipts/'.(int)$id);}
    public function postReceipt(string $id): void{$this->authorize('inventory.receipts.post');if(!\verifyCsrfToken(\postString('_token'))){\flash('inventory_receipt_errors',['form'=>'The form session expired.']);\redirect('/inventory/receipts/'.(int)$id);}$result=$this->inventory->postGoodsReceipt((int)$id,(int)($_SESSION['auth']['user_id']??0));if(empty($result['successful']))\flash('inventory_receipt_errors',$result['errors']??[]);else \flash('inventory_receipt_notice',['message'=>'Receipt posted: goods moved Vendors → Input → Stock.']);\redirect('/inventory/receipts/'.(int)$id);}

    public function postTransfer(): void
    {
        $this->authorize('inventory.transfers.manage');

        if (!\verifyCsrfToken(\postString('_token'))) {
            \flash('inventory_notice', [
                'type' => 'error',
                'message' => 'The form session expired. Please try again.',
            ]);
            \redirect('/inventory/transfers');
        }

        $result = $this->inventory->postTransfer(
            (int) \postString('transfer_id'),
            (int) ($_SESSION['auth']['user_id'] ?? 0)
        );
        $successful = !empty($result['successful']);
        \flash('inventory_notice', [
            'type' => $successful ? 'success' : 'error',
            'message' => $successful
                ? 'The inventory transfer was posted.'
                : (string) ($result['errors']['form'] ?? 'The transfer could not be posted.'),
        ]);
        \redirect('/inventory/transfers');
    }

    public function transfers(): void{$this->authorize('inventory.transfers.view');$this->renderTransfers();}
    public function showTransfer(string $id): void{$this->authorize('inventory.transfers.view');$this->renderTransfers((int)$id);}
    public function createTransfer(): void{$this->transferMutation('inventory.transfers.create',function():void{$id=$this->inventory->createTransfer($_POST,(int)($_SESSION['auth']['user_id']??0));\flash('inventory_transfer_notice','Transfer draft created.');\redirect('/inventory/transfers/'.$id);});}
    public function transferAction(string $id): void{$action=\postString('action');$permission=['submit'=>'inventory.transfers.create','approve'=>'inventory.transfers.approve','cancel'=>'inventory.transfers.create'][$action]??'';if($permission===''){http_response_code(400);return;}$this->transferMutation($permission,function()use($id,$action):void{$this->inventory->transitionTransfer((int)$id,$action,(int)($_SESSION['auth']['user_id']??0));\flash('inventory_transfer_notice','Transfer status updated.');\redirect('/inventory/transfers/'.(int)$id);});}
    public function dispatchTransfer(string $id): void{$this->transferMutation('inventory.transfers.dispatch',function()use($id):void{$this->inventory->dispatchTransfer((int)$id,(int)($_SESSION['auth']['user_id']??0));\flash('inventory_transfer_notice','Transfer dispatched into transit.');\redirect('/inventory/transfers/'.(int)$id);});}
    public function receiveTransfer(string $id): void{$this->transferMutation('inventory.transfers.receive',function()use($id):void{$this->inventory->receiveTransfer((int)$id,(int)($_SESSION['auth']['user_id']??0));\flash('inventory_transfer_notice','Transfer received at its exact destination.');\redirect('/inventory/transfers/'.(int)$id);});}
    private function transferMutation(string $permission,callable $work): void{$this->authorize($permission);if(!\verifyCsrfToken(\postString('_token'))){\flash('inventory_transfer_error','The form session expired.');\redirect('/inventory/transfers');}try{$work();}catch(\Throwable $e){\flash('inventory_transfer_error',$e->getMessage());\redirect('/inventory/transfers');}}
    private function renderTransfers(?int $id=null): void{$workspace=$this->inventory->transferWorkspace($id);if($id!==null&&!is_array($workspace['transfer'])){http_response_code(404);\view('errors.404',['applicationName'=>\config('name','OfficeApp ERP')]);return;}\view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>$id?'Internal Transfer':'Inventory Transfers','pageDescription'=>'Controlled exact-location stock replenishment.','contentView'=>'inventory.transfers','user'=>$_SESSION['auth'],'notice'=>\getFlash('inventory_transfer_notice'),'error'=>\getFlash('inventory_transfer_error')]+$workspace);}

    private function authorize(
        string $permission
    ): void {
        $this->authorization->requireModulePermission(
            'inventory',
            $permission
        );
    }
}

