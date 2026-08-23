<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Services\AuthorizationService;use App\Services\CommercialDocumentService;use App\Services\TenantContext;
final class CommercialDocumentController
{
    private AuthorizationService $auth;private CommercialDocumentService $docs;public function __construct(){$this->auth=new AuthorizationService();$this->docs=new CommercialDocumentService();}
    public function invoice(string $id): void{$this->download($this->docs->invoice($this->company('finance','finance.records.view'),(int)$id));}public function receipt(string $id): void{$this->download($this->docs->receipt($this->company('finance','finance.records.view'),(int)$id));}public function proforma(string $id): void{$this->download($this->docs->proforma($this->company('sales','sales.orders.view'),(int)$id));}
    private function company(string $module,string $permission): int{$this->auth->requireAnyModulePermission([[$module,'commercial_documents.download'],[$module,$permission]]);return (new TenantContext())->companyId();}private function download(array $d): never{header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.rawurlencode($d['filename']).'"');header('Content-Length: '.strlen($d['content']));echo $d['content'];exit;}
}
