<?php
declare(strict_types=1);
namespace App\Services\Lists;

use App\Services\InventoryReadScope;
use App\Services\TenantContext;
use InvalidArgumentException;

/** Reporting queries preserve InventoryReadScope; mutations retain operational access checks. */
final class InventoryListService
{
    public function columns(string $entity): array
    {
        return match($entity) {
            'stock'=>['sku'=>'SKU','product_name'=>'Product','warehouse_code'=>'Warehouse code','warehouse_name'=>'Warehouse','location_code'=>'Location code','location_name'=>'Location','quantity_on_hand'=>'On hand','quantity_reserved'=>'Reserved','quantity_available'=>'Available','average_unit_cost'=>'Average cost'],
            'movements'=>['reference_number'=>'Reference','sku'=>'SKU','product_name'=>'Product','movement_type'=>'Type','source_warehouse_name'=>'Source warehouse','source_location_name'=>'Source location','destination_warehouse_name'=>'Destination warehouse','destination_location_name'=>'Destination location','requested_quantity'=>'Requested','completed_quantity'=>'Completed','status'=>'Status','occurred_at'=>'Date'],
            'receipts'=>['receipt_number'=>'Receipt','supplier_name'=>'Supplier','supplier_reference'=>'Supplier reference','warehouse_name'=>'Warehouse','destination_location_name'=>'Location','receipt_date'=>'Date','currency'=>'Currency','total_quantity'=>'Quantity','total_value'=>'Value','status'=>'Status'],
            'transfers'=>['transfer_number'=>'Transfer','source_warehouse_name'=>'Source warehouse','source_location_name'=>'Source location','destination_warehouse_name'=>'Destination warehouse','destination_location_name'=>'Destination location','requested_quantity'=>'Requested','dispatched_quantity'=>'Dispatched','received_quantity'=>'Received','status'=>'Status','created_at'=>'Date'],
            default=>throw new InvalidArgumentException('Unknown Inventory export.'),
        };
    }

    public function warehouseOptions(): array
    {
        $company=(new TenantContext())->companyId();$actor=(int)($_SESSION['auth']['user_id']??0);
        $query=\db()->prepare('SELECT warehouse_id,code,name FROM inventory_warehouses WHERE company_id=? AND '.(new InventoryReadScope())->predicate($company,$actor,'warehouse_id').' ORDER BY name,warehouse_id');
        $query->execute([$company]);return $query->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function listing(string $entity,array $input,string $prefix=''): SqlList
    {
        $company=(new TenantContext())->companyId();$actor=(int)($_SESSION['auth']['user_id']??0);
        [$sql,$id,$search,$sorts,$filters,$default]=$this->definition($entity,$company,$actor);
        $params=['company_id'=>$company];
        if($entity==='warehouses')$params['readiness_company']=$company;
        $query=new ListQuery($input,$sorts,$default,array_keys($filters),in_array($entity,['stock','warehouses','locations'],true)?'asc':'desc',$prefix);
        if($entity==='transfers' && $query->q!=='') {
            $pattern='%'.strtr($query->q,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $params+=['line_sku'=>$pattern,'line_name'=>$pattern,'line_match'=>$query->q];
            $search[]="CASE WHEN EXISTS(SELECT 1 FROM inventory_transfer_lines detail
                JOIN sales_products product ON product.company_id=detail.company_id AND product.product_id=detail.product_id
                WHERE detail.company_id=listed.company_id AND detail.transfer_id=listed.transfer_id
                    AND (product.sku LIKE :line_sku ESCAPE '!' OR product.name LIKE :line_name ESCAPE '!')) THEN :line_match ELSE '' END";
        }
        return new SqlList(\db(),$sql,$params,$query,$search,$sorts,$id,$filters);
    }

    public function controls(string $entity): array
    {
        [,,,$sorts,$filters]=$this->definition($entity,(new TenantContext())->companyId(),(int)($_SESSION['auth']['user_id']??0));
        $domains=['active'=>['1'=>'Active','0'=>'Inactive']];
        $table=match($entity) {'receipts'=>'inventory_goods_receipts','transfers'=>'inventory_transfers','movements'=>'inventory_stock_movements',default=>null};
        if($table)$domains['status']=[$table,'status'];
        if($entity==='movements')$domains['type']=['inventory_stock_movements','movement_type'];
        if($entity==='warehouses')$domains['type']=['inventory_warehouses','warehouse_type'];
        if($entity==='locations')$domains['type']=['inventory_warehouse_locations','location_type'];
        return FilterOptions::controls($this->listing($entity,[]),$filters,$sorts,$domains,[
            'warehouse'=>'warehouse_name','source'=>'source_warehouse_name','destination'=>'destination_warehouse_name',
            'location'=>$entity==='receipts'?'destination_location_name':'location_name',
        ]);
    }

    private function definition(string $entity,int $company,int $actor): array
    {
        $read=new InventoryReadScope();
        $warehouse=$read->predicate($company,$actor,'warehouse_id');
        if($entity==='warehouses') {
            $readiness=str_replace(':company_id',':readiness_company',InventoryListSql::readiness());
            $sql='SELECT configured.*,(active_operation_type_count=4 AND active_default_operation_type_count=4) operation_types_ready,
                (active_operation_type_count=4 AND active_default_operation_type_count=4 AND operational_location_count=6 AND mapped_operation_type_count=4) operational_ready
                FROM (SELECT w.*,r.operational_location_count,r.mapped_operation_type_count FROM ('.InventoryListSql::warehouses().') w
                LEFT JOIN ('.$readiness.') r ON r.warehouse_id=w.warehouse_id) configured WHERE '.$warehouse;
            return [$sql,'warehouse_id',['code','name','warehouse_type','branch_name','manager_name','phone','email'],
                ['name'=>'name','code'=>'code','branch'=>'branch_name','type'=>'warehouse_type','status'=>'active'],['active'=>'active','type'=>'warehouse_type','branch'=>'branch_name'],'name'];
        }
        if($entity==='locations')return [
            'SELECT configured.* FROM ('.InventoryListSql::locations().') configured WHERE '.$warehouse,
            'location_id',['code','name','warehouse_code','warehouse_name','parent_code','parent_name','barcode','location_type'],
            ['name'=>'name','code'=>'code','warehouse'=>'warehouse_name','priority'=>'pick_priority','status'=>'active'],
            ['active'=>'active','warehouse'=>'warehouse_code','type'=>'location_type'],'code'];
        if($entity==='stock')return ["SELECT b.*,p.sku,p.name product_name,w.code warehouse_code,w.name warehouse_name,
            l.code location_code,l.name location_name,l.location_usage,DATE(b.last_movement_at) movement_date
            FROM inventory_stock_balances b LEFT JOIN sales_products p ON p.company_id=b.company_id AND p.product_id=b.product_id
            LEFT JOIN inventory_warehouses w ON w.company_id=b.company_id AND w.warehouse_id=b.warehouse_id
            INNER JOIN inventory_warehouse_locations l ON l.company_id=b.company_id AND l.warehouse_id=b.warehouse_id AND l.location_id=b.location_id
            WHERE b.company_id=:company_id AND l.location_usage IN ('internal','transit') AND ".$read->predicate($company,$actor,'b.warehouse_id'),
            'stock_balance_id',['sku','product_name','warehouse_code','warehouse_name','location_code','location_name'],
            ['product'=>'product_name','sku'=>'sku','warehouse'=>'warehouse_name','location'=>'location_name','quantity'=>'quantity_on_hand','date'=>'last_movement_at'],
            ['warehouse'=>'warehouse_code','location'=>'location_code','from'=>['movement_date','>='],'to'=>['movement_date','<=']],'product'];
        if($entity==='movements')return ["SELECT m.*,DATE(m.occurred_at) movement_date,p.sku,p.name product_name,
            sw.name source_warehouse_name,sw.code source_warehouse_code,dw.name destination_warehouse_name,dw.code destination_warehouse_code,
            sl.name source_location_name,dl.name destination_location_name,operation.name operation_type_name
            FROM inventory_stock_movements m INNER JOIN sales_products p ON p.company_id=m.company_id AND p.product_id=m.product_id
            LEFT JOIN inventory_warehouses sw ON sw.company_id=m.company_id AND sw.warehouse_id=m.source_warehouse_id
            LEFT JOIN inventory_warehouses dw ON dw.company_id=m.company_id AND dw.warehouse_id=m.destination_warehouse_id
            LEFT JOIN inventory_warehouse_locations sl ON sl.company_id=m.company_id AND sl.warehouse_id=m.source_warehouse_id AND sl.location_id=m.source_location_id
            LEFT JOIN inventory_warehouse_locations dl ON dl.company_id=m.company_id AND dl.warehouse_id=m.destination_warehouse_id AND dl.location_id=m.destination_location_id
            LEFT JOIN inventory_operation_types operation ON operation.company_id=m.company_id AND operation.warehouse_id=m.warehouse_id AND operation.operation_type_id=m.operation_type_id
            WHERE m.company_id=:company_id AND (".$read->predicate($company,$actor,'m.source_warehouse_id').' OR '.$read->predicate($company,$actor,'m.destination_warehouse_id').')',
            'movement_id',['sku','product_name','reference_number','reference_type','movement_type','status','source_warehouse_code','source_warehouse_name','destination_warehouse_code','destination_warehouse_name','source_location_name','destination_location_name'],
            ['date'=>'occurred_at','reference'=>'reference_number','sku'=>'sku','product'=>'product_name','status'=>'status'],
            ['status'=>'status','type'=>'movement_type','source'=>'source_warehouse_code','destination'=>'destination_warehouse_code','from'=>['movement_date','>='],'to'=>['movement_date','<=']],'date'];
        if($entity==='receipts')return ["SELECT r.*,w.name warehouse_name,w.code warehouse_code,d.name destination_location_name,d.code location_code,
            COALESCE((SELECT SUM(quantity) FROM inventory_goods_receipt_lines WHERE company_id=r.company_id AND goods_receipt_id=r.goods_receipt_id),0) total_quantity,
            COALESCE((SELECT SUM(line_value) FROM inventory_goods_receipt_lines WHERE company_id=r.company_id AND goods_receipt_id=r.goods_receipt_id),0) total_value
            FROM inventory_goods_receipts r INNER JOIN inventory_warehouses w ON w.company_id=r.company_id AND w.warehouse_id=r.warehouse_id
            INNER JOIN inventory_operation_types operation ON operation.company_id=r.company_id AND operation.warehouse_id=r.warehouse_id AND operation.operation_type_id=r.operation_type_id
            INNER JOIN inventory_warehouse_locations d ON d.company_id=r.company_id AND d.warehouse_id=r.warehouse_id AND d.location_id=r.destination_location_id AND d.deleted_at IS NULL
            WHERE r.company_id=:company_id AND ".$read->predicate($company,$actor,'r.warehouse_id'),
            'goods_receipt_id',['receipt_number','supplier_name','supplier_reference','receipt_date','status','warehouse_code','warehouse_name','location_code','destination_location_name'],
            ['date'=>'receipt_date','reference'=>'receipt_number','supplier'=>'supplier_name','warehouse'=>'warehouse_name','status'=>'status'],
            ['status'=>'status','warehouse'=>'warehouse_code','location'=>'location_code','from'=>['receipt_date','>='],'to'=>['receipt_date','<=']],'date'];
        if($entity==='transfers')return ["SELECT t.*,DATE(t.created_at) document_date,sw.name source_warehouse_name,sw.code source_warehouse_code,
            dw.name destination_warehouse_name,dw.code destination_warehouse_code,MAX(sl.name) source_location_name,MAX(dl.name) destination_location_name,
            COALESCE(SUM(l.quantity),0) requested_quantity,COALESCE(SUM(l.dispatched_quantity),0) dispatched_quantity,COALESCE(SUM(l.received_quantity),0) received_quantity,
            GROUP_CONCAT(CONCAT(p.name,' x ',l.quantity) ORDER BY l.transfer_line_id SEPARATOR ', ') product_summary
            FROM inventory_transfers t INNER JOIN inventory_warehouses sw ON sw.company_id=t.company_id AND sw.warehouse_id=t.source_warehouse_id
            INNER JOIN inventory_warehouses dw ON dw.company_id=t.company_id AND dw.warehouse_id=t.destination_warehouse_id
            LEFT JOIN inventory_transfer_lines l ON l.company_id=t.company_id AND l.transfer_id=t.transfer_id
            LEFT JOIN inventory_warehouse_locations sl ON sl.company_id=l.company_id AND sl.warehouse_id=l.source_warehouse_id AND sl.location_id=l.source_location_id
            LEFT JOIN inventory_warehouse_locations dl ON dl.company_id=l.company_id AND dl.warehouse_id=l.destination_warehouse_id AND dl.location_id=l.destination_location_id
            LEFT JOIN sales_products p ON p.company_id=l.company_id AND p.product_id=l.product_id
            WHERE t.company_id=:company_id AND (".$read->predicate($company,$actor,'t.source_warehouse_id').' OR '.$read->predicate($company,$actor,'t.destination_warehouse_id').') GROUP BY t.transfer_id',
            'transfer_id',['transfer_number','source_warehouse_name','source_warehouse_code','destination_warehouse_name','destination_warehouse_code','status','document_date'],
            ['date'=>'created_at','reference'=>'transfer_number','source'=>'source_warehouse_name','destination'=>'destination_warehouse_name','status'=>'status'],
            ['status'=>'status','source'=>'source_warehouse_code','destination'=>'destination_warehouse_code','from'=>['document_date','>='],'to'=>['document_date','<=']],'date'];
        throw new InvalidArgumentException('Unknown Inventory register.');
    }
}
