<?php
declare(strict_types=1);
namespace App\Services;

use App\Repositories\MySql\SettlementRepository;
use App\Repositories\RepositoryFactory;

final class SettlementService
{
    private SettlementRepository $repo;private TenantContext $tenant;
    public function __construct(?SettlementRepository $repo=null,?TenantContext $tenant=null){$this->repo=$repo??new SettlementRepository();$this->tenant=$tenant??new TenantContext();}
    public function listing(): array
    {
        $company=$this->tenant->companyId();$actor=(int)($_SESSION['auth']['user_id']??0);
        return ['settlements'=>array_values(array_filter($this->repo->list($company),fn($row)=>$this->canRead($row,$actor))),
            'eligiblePayments'=>array_values(array_filter($this->repo->eligiblePayments($company),fn($row)=>$this->canReadOrder((int)$row['sales_order_id'],$actor))),
            'bankAccounts'=>$this->repo->bankAccounts($company)];
    }
    public function find(int $id): ?array
    {
        $row=$this->repo->find($this->tenant->companyId(),$id);
        return $row!==null && $this->canRead($row,(int)($_SESSION['auth']['user_id']??0)) ? $row : null;
    }
    private function canRead(array $row,int $actor): bool
    {
        $company=$this->tenant->companyId();$scope=new SalesHierarchyScope();
        if ((int)$row['company_id']!==$company) return false;
        if ($scope->hasPermission($company,$actor,'finance.settlements.view')) return true;
        return $scope->hasPermission($company,$actor,'sales.settlements.view')
            && ($scope->hasCompanyWideAccess($company,$actor)||in_array((int)$row['created_by'],$scope->userIds($company,$actor),true));
    }
    private function canReadOrder(int $id,int $actor): bool
    {
        $q=\db()->prepare('SELECT company_id,created_by,agent_id FROM sales_orders WHERE company_id=? AND order_id=?');
        $q->execute([$this->tenant->companyId(),$id]);$row=$q->fetch(\PDO::FETCH_ASSOC);
        return $row && (new SalesHierarchyScope())->canReadSalesRow($this->tenant->companyId(),$actor,$row);
    }
    public function create(array $input,int $actor): array{try{$this->authorizeActor($actor,'sales.settlements.create');$ids=array_values(array_unique(array_filter(array_map('intval',is_array($input['payment_ids']??null)?$input['payment_ids']:[]))));if($actor<1||$ids===[]||(int)($input['bank_account_id']??0)<1)return $this->error('Select an active bank account and at least one eligible posted payment.');$eligible=array_column(array_filter($this->repo->eligiblePayments($this->tenant->companyId()),fn($row)=>$this->canReadOrder((int)$row['sales_order_id'],$actor)),'payment_id');if(array_diff($ids,$eligible)!==[])throw new \RuntimeException('Payment is outside your Sales hierarchy.');$id=$this->repo->create($this->tenant->companyId(),(int)$input['bank_account_id'],$ids,trim((string)($input['notes']??'')),$actor);$this->audit($actor,'settlement.created','sales_settlements',$id,['payment_ids'=>$ids]);return ['successful'=>true,'id'=>$id];}catch(\Throwable $e){return $this->error($e->getMessage());}}
    public function transition(int $id,string $action,string $reason,int $actor): array{try{$permission=match($action){'submit'=>'sales.settlements.submit','review'=>'sales.settlements.review','reconcile'=>'finance.settlements.reconcile','approve'=>'finance.settlements.approve',default=>throw new \RuntimeException('Unknown settlement action.')};$this->authorizeActor($actor,$permission);$row=$this->repo->find($this->tenant->companyId(),$id);if(!$row||!$this->canRead($row,$actor))throw new \RuntimeException('Settlement was not found in your scope.');$this->repo->transition($this->tenant->companyId(),$id,$action,$reason,$actor);$this->audit($actor,'settlement.'.$action,'sales_settlements',$id,['reason'=>$reason]);return ['successful'=>true];}catch(\Throwable $e){return $this->error($e->getMessage());}}
    public function confirmation(int $id,array $input,array $file,int $actor): array{$upload=new PrivateUploadService();$stored=null;try{$this->authorizeActor($actor,'finance.bank_confirmations.create');$amount=round((float)($input['confirmed_amount']??0),2);$date=trim((string)($input['transaction_date']??''));$reference=trim((string)($input['bank_reference']??''));if($amount<=0||$reference===''||preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)!==1)return $this->error('Enter a bank reference, transaction date and positive confirmed amount.');$stored=$upload->storeEvidence($this->tenant->companyId(),$file);$cid=$this->repo->addConfirmation($this->tenant->companyId(),$id,$stored+['confirmed_amount'=>$amount,'transaction_date'=>$date,'bank_reference'=>$reference],$actor);$this->audit($actor,'bank_confirmation.created','bank_confirmations',$cid,['settlement_id'=>$id,'bank_reference'=>$reference,'amount'=>$amount]);return ['successful'=>true];}catch(\Throwable $e){if(is_array($stored))$upload->remove((string)$stored['evidence_path']);return $this->error($e->getMessage());}}
    public function bankAccount(array $input,int $actor): array{try{$this->authorizeActor($actor,'finance.bank_accounts.manage');$v=['bank_name'=>trim((string)($input['bank_name']??'')),'account_name'=>trim((string)($input['account_name']??'')),'account_number'=>trim((string)($input['account_number']??'')),'branch'=>trim((string)($input['branch']??'')),'currency'=>strtoupper(trim((string)($input['currency']??'ETB'))),'swift_bic'=>strtoupper(trim((string)($input['swift_bic']??''))),'provider_code'=>trim((string)($input['provider_code']??'')),'is_default'=>!empty($input['is_default'])];if($v['bank_name']===''||$v['account_name']===''||$v['account_number']===''||preg_match('/^[A-Z]{3}$/',$v['currency'])!==1)return $this->error('Bank, account name, account number and a three-letter currency are required.');if($v['swift_bic']!==''&&preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/',$v['swift_bic'])!==1)return $this->error('SWIFT/BIC must contain 8 or 11 letters and digits.');$id=$this->repo->saveBankAccount($this->tenant->companyId(),$v,$actor);$this->audit($actor,'company_bank_account.created','company_bank_accounts',$id,['bank_name'=>$v['bank_name'],'currency'=>$v['currency'],'is_default'=>$v['is_default']]);return ['successful'=>true,'id'=>$id];}catch(\Throwable $e){return $this->error($e->getMessage());}}
    public function evidence(int $settlementId,int $confirmationId): ?array{if($this->find($settlementId)===null)return null;return $this->repo->confirmation($this->tenant->companyId(),$settlementId,$confirmationId);}
    private function authorizeActor(int $actor, string $permission): void
    {
        $scope = new SalesHierarchyScope();
        $company = $this->tenant->companyId();
        if ($scope->isAgent($company, $actor) || !$scope->hasPermission($company, $actor, $permission)) {
            throw new \RuntimeException('You are not authorized for this settlement action.');
        }
    }
    private function error(string $m): array{return ['successful'=>false,'errors'=>['form'=>$m]];}
    private function audit(int $actor,string $action,string $table,int $id,array $values): void{RepositoryFactory::auditLogs()->record($actor,$action,'sales_settlements',$table,(string)$id,null,$values,$this->tenant->companyId());}
}
