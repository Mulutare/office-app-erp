<?php

declare(strict_types=1);

namespace App\Services\DataExchange;

use App\Services\FinanceOperationsService;
use App\Services\InventoryService;
use App\Services\SalesService;
use App\Services\TenantContext;
use App\Services\WarehouseLocationManagementService;
use App\Services\WarehouseManagementService;
use RuntimeException;

final class ExportDataProvider
{
    public function __construct(private ?ExternalIdService $ids=null,private ?TenantContext $tenant=null){$this->ids??=new ExternalIdService();$this->tenant??=new TenantContext();}
    /** @return list<array<string,mixed>> */
    public function rows(string $entity): array
    {
        $primary=null;
        if(in_array($entity,['customers','products','pricelists','sales-teams','quotations','sales-orders'],true)){
            $workspace=(new SalesService())->workspace();$map=['customers'=>['customers','customer_id'],'products'=>['products','product_id'],'pricelists'=>['pricelists','pricelist_id'],'sales-teams'=>['salesTeams','team_id'],'quotations'=>['quotations','quotation_id'],'sales-orders'=>['orders','order_id']];[$key,$primary]=$map[$entity];$rows=(array)($workspace[$key]??[]);
        }elseif($entity==='warehouses'){$data=(new WarehouseManagementService())->listing();$rows=(array)($data['warehouses']??$data);$primary='warehouse_id';}
        elseif($entity==='locations'){$data=(new WarehouseLocationManagementService())->listing();$rows=(array)($data['locations']??$data);$primary='location_id';}
        elseif($entity==='stock'){$rows=(array)((new InventoryService())->workspace()['stockBalances']??[]);$primary='stock_balance_id';foreach($rows as &$stock){$stock['warehouse']=$stock['warehouse_name']??'';$stock['location']=$stock['location_name']??'';$stock['product']=$stock['product_name']??'';$stock['on_hand']=$stock['quantity_on_hand']??0;$stock['reserved']=$stock['quantity_reserved']??0;$stock['available']=$stock['quantity_available']??0;}unset($stock);}
        elseif($entity==='receipts'){$rows=(new InventoryService())->receipts();$primary='goods_receipt_id';}
        elseif($entity==='deliveries'||$entity==='returns'){$rows=(new SalesService())->deliveries();$primary='picking_id';}
        elseif($entity==='invoices'||$entity==='credit-notes'){$rows=(new FinanceOperationsService())->customerInvoices();$primary='invoice_id';$rows=array_values(array_filter($rows,static fn(array $r):bool=>($r['document_type']??'invoice')===($entity==='credit-notes'?'credit_note':'invoice')));}
        else{throw new RuntimeException('Authoritative export data is not connected for this object yet.');}
        foreach($rows as &$row){if($primary!==null&&!empty($row[$primary]))$row['external_id']=$this->ids->ensure($this->tenant->companyId(),$entity,(int)$row[$primary]);}
        unset($row);return array_values($rows);
    }
}
