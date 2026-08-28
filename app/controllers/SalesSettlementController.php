<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Services\SalesWorkflowTraceService;

use App\Services\AuthorizationService;
use App\Services\SalesSettlementDocumentService;
use App\Services\SettlementService;
use App\Services\TenantContext;

final class SalesSettlementController
{
    private AuthorizationService $auth;private SettlementService $service;
    public function __construct(){$this->auth=new AuthorizationService();$this->service=new SettlementService();}
    public function index(): void{$this->permit('sales','sales.settlements.view');$data=$this->service->listing();\view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'pageTitle'=>'Sales Settlements','pageDescription'=>'Accountability and bank-deposit reconciliation over posted customer payments.','contentView'=>'sales.settlements','user'=>$_SESSION['auth'],'notice'=>\getFlash('settlement_notice'),'errors'=>\getFlash('settlement_errors',[])]+$data);}
    public function finance(): void{$this->permit('finance','finance.settlements.view');$data=$this->service->listing();\view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'pageTitle'=>'Settlement Reconciliation','pageDescription'=>'Finance bank confirmation, variance and maker/checker controls.','contentView'=>'sales.settlements','user'=>$_SESSION['auth'],'financeMode'=>true,'notice'=>\getFlash('settlement_notice'),'errors'=>\getFlash('settlement_errors',[])]+$data);}
    public function show(string $id): void{$this->permitSharedView();$s=$this->service->find((int)$id);if($s===null){http_response_code(404);\view('errors.404',['applicationName'=>\config('name','OfficeApp ERP')]);return;}\view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'pageTitle'=>$s['settlement_number'],'pageDescription'=>'Settlement evidence, variance, approvals and audit timeline.','contentView'=>'sales.settlement','user'=>$_SESSION['auth'],'settlement'=>$s,'notice'=>\getFlash('settlement_notice'),'errors'=>\getFlash('settlement_errors',[]),'workflowTrace'=>(new SalesWorkflowTraceService())->trace((new TenantContext())->companyId(),'settlement',(int)$s['settlement_id'],$_SESSION['auth']??[])]);}
    public function create(): void{$this->permit('sales','sales.settlements.create');$this->csrf();$this->finish($this->service->create($_POST,$this->actor()),null);}
    public function submit(string $id): void{$this->permit('sales','sales.settlements.submit');$this->csrf();$this->finish($this->service->transition((int)$id,'submit','',$this->actor()),(int)$id);}
    public function review(string $id): void{$this->permit('sales','sales.settlements.review');$this->csrf();$this->finish($this->service->transition((int)$id,'review',\postString('reason'),$this->actor()),(int)$id);}
    public function reconcile(string $id): void{$this->permit('finance','finance.settlements.reconcile');$this->csrf();$this->finish($this->service->transition((int)$id,'reconcile',\postString('reason'),$this->actor()),(int)$id);}
    public function approve(string $id): void{$this->permit('finance','finance.settlements.approve');$this->csrf();$this->finish($this->service->transition((int)$id,'approve',\postString('reason'),$this->actor()),(int)$id);}
    public function confirmation(string $id): void{$this->permit('finance','finance.bank_confirmations.create');$this->csrf();$this->finish($this->service->confirmation((int)$id,$_POST,is_array($_FILES['evidence']??null)?$_FILES['evidence']:[],$this->actor()),(int)$id);}
    public function bankAccount(): void{$this->permit('finance','finance.bank_accounts.manage');$this->csrf();$this->finish($this->service->bankAccount($_POST,$this->actor()),null);}
    public function depositAdvice(string $id): void{$this->document((int)$id,'advice');}
    public function reconciliation(string $id): void{$this->document((int)$id,'reconciliation');}
    public function evidence(string $id,string $confirmationId): void{$this->permitSharedView();$e=$this->service->evidence((int)$id,(int)$confirmationId);if($e===null||!is_file($e['evidence_path'])){http_response_code(404);return;}header('Content-Type: '.$e['evidence_mime']);header('Content-Length: '.(string)$e['evidence_size']);header('Content-Disposition: attachment; filename="'.rawurlencode($e['evidence_original_name']).'"');header('X-Content-Type-Options: nosniff');readfile($e['evidence_path']);exit;}
    private function permit(string $module,string $permission): void{$this->auth->requireModulePermission($module,$permission);}private function actor(): int{return (int)($_SESSION['auth']['user_id']??0);}private function csrf(): void{if(!\verifyCsrfToken(\postString('_token'))){\flash('settlement_errors',['form'=>'The form session expired.']);\redirect('/sales/settlements');}}
    private function finish(array $r,?int $id): never{if(empty($r['successful']))\flash('settlement_errors',$r['errors']??[]);else \flash('settlement_notice',['message'=>'Settlement action completed.']);\redirect($id?'/sales/settlements/'.$id:'/sales/settlements');}
    private function pdf(array $d): never{header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.rawurlencode($d['filename']).'"');header('Content-Length: '.strlen($d['content']));echo $d['content'];exit;}
    private function document(int $id,string $type): never{$this->permitSharedView();$doc=(new SalesSettlementDocumentService())->settlement((new TenantContext())->companyId(),$id,$type);$this->pdf($doc);}
    private function permitSharedView(): void{$this->auth->requireAnyModulePermission([['sales','sales.settlements.view'],['finance','finance.settlements.view']]);}
}
