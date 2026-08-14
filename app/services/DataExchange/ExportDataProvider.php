<?php

declare(strict_types=1);

namespace App\Services\DataExchange;

use App\Services\FinanceOperationsService;
use App\Services\FinanceDashboardService;
use App\Services\InventoryService;
use App\Services\ProcurementService;
use App\Services\SalesService;
use App\Services\TenantContext;
use App\Services\WarehouseLocationManagementService;
use App\Services\WarehouseManagementService;
use RuntimeException;

final class ExportDataProvider
{
    public function __construct(private ?ExternalIdService $ids=null,private ?TenantContext $tenant=null){$this->ids??=new ExternalIdService();$this->tenant??=new TenantContext();}
    /** @return list<array<string,mixed>> */
    public function rows(string $entity, array $filters = []): array
    {
        $primary=null;
        if($entity==='suppliers'){$rows=(array)((new ProcurementService())->workspace()['suppliers']??[]);$primary='supplier_id';}
        elseif($entity==='purchase-orders'){$rows=(array)((new ProcurementService())->workspace()['orders']??[]);$primary='purchase_order_id';}
        elseif($entity==='finance-journals'){$rows=(new FinanceDashboardService())->exportJournals(10000);$primary='journal_batch_id';}
        elseif($entity==='expenses'){$rows=(new FinanceDashboardService())->exportExpenses($filters,10000);$primary='expense_request_id';}
        elseif(in_array($entity,['customers','products','pricelists','sales-teams','quotations','sales-orders'],true)){
            $workspace=(new SalesService())->workspace();$map=['customers'=>['customers','customer_id'],'products'=>['products','product_id'],'pricelists'=>['pricelists','pricelist_id'],'sales-teams'=>['salesTeams','team_id'],'quotations'=>['quotations','quotation_id'],'sales-orders'=>['orders','order_id']];[$key,$primary]=$map[$entity];$rows=(array)($workspace[$key]??[]);
        }elseif($entity==='warehouses'){$data=(new WarehouseManagementService())->listing();$rows=(array)($data['warehouses']??$data);$primary='warehouse_id';}
        elseif($entity==='locations'){$data=(new WarehouseLocationManagementService())->listing();$rows=(array)($data['locations']??$data);$primary='location_id';}
        elseif($entity==='stock'){$rows=(array)((new InventoryService())->workspace()['stockBalances']??[]);$primary='stock_balance_id';foreach($rows as &$stock){$stock['warehouse']=$stock['warehouse_name']??'';$stock['location']=$stock['location_name']??'';$stock['product']=$stock['product_name']??'';$stock['on_hand']=$stock['quantity_on_hand']??0;$stock['reserved']=$stock['quantity_reserved']??0;$stock['available']=$stock['quantity_available']??0;}unset($stock);}
        elseif($entity==='receipts'){$rows=(new InventoryService())->receipts();$primary='goods_receipt_id';}
        elseif($entity==='deliveries'||$entity==='returns'){$rows=(new SalesService())->deliveries();$primary='picking_id';}
        elseif($entity==='invoices'){
            $source=(new FinanceOperationsService())->customerInvoices($filters);$primary='invoice_id';$rows=[];
            foreach($source as $invoice){$rows[]=[
                'invoice_id'=>$invoice['invoice_id']??null,
                'invoice'=>$invoice['invoice_number']??'', 'customer'=>$invoice['customer_name']??'',
                'sales_order'=>$invoice['order_number']??'', 'date'=>$invoice['invoice_date']??'',
                'due'=>$invoice['due_date']??'', 'total'=>$invoice['total_amount']??0,
                'residual'=>$invoice['residual_amount']??0,
                'state'=>strtoupper((string)($invoice['status']??'')),
                'payment'=>strtoupper(str_replace('_',' ',(string)($invoice['payment_status']??''))),
            ];}
        }
        elseif($entity==='credit-notes'){$rows=(new FinanceOperationsService())->customerInvoices();$primary='invoice_id';$rows=array_values(array_filter($rows,static fn(array $r):bool=>($r['document_type']??'')==='customer_credit'));}
        else{throw new RuntimeException('Authoritative export data is not connected for this object yet.');}
        if($entity!=='invoices')$rows=array_map(fn(array $row):array=>$this->businessRow($entity,$row),$rows);
        foreach($rows as &$row){if($primary!==null&&!empty($row[$primary]))$row['external_id']=$this->ids->ensure($this->tenant->companyId(),$entity,(int)$row[$primary]);}
        unset($row);return array_values($rows);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function businessRow(string $entity,array $r):array
    {
        $yes=static fn(mixed $v):string=>!empty($v)?'Yes':'No';$status=static fn(mixed $v):string=>!empty($v)?'Active':'Archived';
        return match($entity){
            'suppliers'=>['supplier_id'=>$r['supplier_id']??null,'supplier_code'=>$r['supplier_code']??'','business_name'=>$r['business_name']??'','currency'=>$r['currency']??'','payment_terms_days'=>$r['payment_terms_days']??0,'status'=>!empty($r['active'])?'Active':'Inactive'],
            'customers'=>['customer_id'=>$r['customer_id']??null,'customer'=>trim(($r['customer_number']??'').' - '.($r['name']??''),' -'),'type'=>ucfirst((string)($r['customer_type']??'')),'contact'=>trim(($r['email']??'').' '.($r['phone']??$r['mobile']??'')),'sales_assignment'=>trim(($r['agent_name']??'Unassigned').' / '.($r['team_name']??'No team').' / '.($r['pricelist_name']??'Standard price')),'credit_mode'=>$r['credit_mode']??'','credit_limit'=>$r['credit_limit']??0,'status'=>$status($r['active']??false)],
            'products'=>['product_id'=>$r['product_id']??null,'product'=>trim(($r['sku']??'').' - '.($r['name']??''),' -'),'category'=>$r['category']??'Uncategorised','semantics'=>str_replace('_',' ',$r['product_type']??''),'uom'=>$r['unit_of_measure']??'','sales_price'=>$r['unit_price']??0,'status'=>$status($r['active']??false)],
            'pricelists'=>['pricelist_id'=>$r['pricelist_id']??null,'name'=>$r['name']??'','currency'=>$r['currency']??'','valid_from'=>$r['valid_from']??'','valid_to'=>$r['valid_to']??'','rules'=>$r['rule_count']??0,'status'=>$status($r['active']??false)],
            'sales-teams'=>['team_id'=>$r['team_id']??null,'team'=>$r['name']??'','leader'=>$r['leader_name']??'Unassigned','members'=>$r['member_count']??0,'status'=>$status($r['active']??false)],
            'quotations'=>['quotation_id'=>$r['quotation_id']??null,'quotation'=>$r['quotation_number']??'','customer'=>$r['customer_name']??'','date'=>$r['quotation_date']??'','expiry'=>$r['expiration_date']??'','salesperson'=>$r['agent_name']??'Unassigned','sales_team'=>$r['team_name']??'No team','currency'=>$r['currency']??'','total'=>$r['total_amount']??0,'status'=>ucfirst((string)($r['status']??''))],
            'sales-orders'=>['order_id'=>$r['order_id']??null,'order'=>$r['order_number']??'','customer'=>$r['customer_name']??'','date'=>$r['order_date']??'','due'=>$r['due_date']??'','currency'=>$r['currency']??'','total'=>$r['total_amount']??0,'paid'=>$r['paid_amount']??0,'balance'=>$r['balance_due']??0,'status'=>ucfirst((string)($r['status']??''))],
            'warehouses'=>['warehouse_id'=>$r['warehouse_id']??null,'warehouse'=>trim(($r['code']??'').' - '.($r['name']??''),' -'),'type'=>str_replace('_',' ',$r['warehouse_type']??''),'branch'=>$r['branch_name']??'','manager'=>$r['manager_name']??'','default'=>$yes($r['is_default']??false),'negative_stock'=>$yes($r['allow_negative_stock']??false),'status'=>$status($r['active']??false),'operations'=>!empty($r['operational_ready'])?'Ready':'Setup required'],
            'locations'=>['location_id'=>$r['location_id']??null,'location'=>trim(($r['code']??'').' - '.($r['name']??''),' -'),'warehouse'=>trim(($r['warehouse_code']??'').' - '.($r['warehouse_name']??''),' -'),'parent'=>$r['parent_name']??'','type'=>str_replace('_',' ',$r['location_type']??''),'coordinates'=>implode(' / ',array_filter([$r['aisle']??'',$r['rack']??'',$r['shelf']??'',$r['bin']??''])),'receiving'=>$yes($r['receiving_allowed']??false),'picking'=>$yes($r['picking_allowed']??false),'priority'=>$r['pick_priority']??0,'status'=>$status($r['active']??false)],
            'stock'=>['stock_balance_id'=>$r['stock_balance_id']??null,'product'=>$r['product_name']??'','sku'=>$r['sku']??'','warehouse'=>$r['warehouse_name']??'','location'=>$r['location_name']??'','on_hand'=>$r['quantity_on_hand']??0,'reserved'=>$r['quantity_reserved']??0,'available'=>$r['quantity_available']??0,'average_cost'=>$r['average_unit_cost']??0],
            'receipts'=>['goods_receipt_id'=>$r['goods_receipt_id']??null,'receipt'=>$r['receipt_number']??'','warehouse'=>$r['warehouse_name']??'','vendor'=>$r['supplier_name']??'','date'=>$r['receipt_date']??'','quantity'=>$r['total_quantity']??0,'currency'=>$r['currency']??'','value'=>$r['total_value']??0,'status'=>ucfirst((string)($r['status']??''))],
            'deliveries'=>['picking_id'=>$r['picking_id']??null,'delivery'=>$r['picking_number']??$r['delivery_number']??'','order'=>$r['order_number']??'','customer'=>$r['customer_name']??'','date'=>$r['scheduled_date']??$r['completed_at']??'','status'=>ucfirst((string)($r['status']??''))],
            'returns'=>['picking_id'=>$r['picking_id']??null,'document'=>$r['picking_number']??'','reference'=>$r['origin_reference']??'','date'=>$r['completed_at']??'','status'=>ucfirst((string)($r['status']??''))],
            'credit-notes'=>['invoice_id'=>$r['invoice_id']??null,'reference'=>$r['invoice_number']??'','customer'=>$r['customer_name']??'','date'=>$r['invoice_date']??'','currency'=>$r['currency']??'','total'=>$r['total_amount']??0,'status'=>strtoupper((string)($r['status']??''))],
            'finance-journals'=>['journal_batch_id'=>$r['journal_batch_id']??null,'batch'=>$r['batch_number']??'','source'=>$r['source_number']??$r['source_type']??'','description'=>$r['description']??'','posting_date'=>$r['posting_date']??'','debit'=>$r['total_debit']??0,'credit'=>$r['total_credit']??0,'status'=>ucfirst((string)($r['status']??''))],
            'expenses'=>['expense_request_id'=>$r['expense_request_id']??null,'request'=>trim(($r['request_number']??'').' - '.($r['title']??''),' -'),'requester'=>$r['requester_name']??'','category'=>$r['category_name']??'','expense_date'=>$r['expense_date']??'','currency'=>$r['currency']??'','amount'=>$r['amount']??0,'status'=>ucfirst((string)($r['status']??'')),'submitted'=>$r['submitted_at']??''],
            'purchase-orders'=>['purchase_order_id'=>$r['purchase_order_id']??null,'po'=>$r['po_number']??'','supplier'=>$r['supplier_name']??'','date'=>$r['order_date']??'','expected'=>$r['expected_date']??'','status'=>ucwords(str_replace('_',' ',$r['status']??'')),'received'=>$r['received_quantity']??0,'billed'=>$r['billed_quantity']??0,'currency'=>$r['currency']??'','total'=>$r['total_amount']??0],
            default=>$r,
        };
    }
}
