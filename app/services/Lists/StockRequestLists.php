<?php
declare(strict_types=1);
namespace App\Services\Lists;

/** List configuration only; stock workflow services supply the authorized SQL. */
final class StockRequestLists
{
    public static function listing(string $entity,string $sql,array $parameters,array $input): SqlList
    {
        [$id,$search,$sorts,$filters,$default]=self::definition($entity);
        $query=new ListQuery($input,$sorts,$default,array_keys($filters),in_array($entity,['requests','peers'],true)?'desc':'asc',$entity);
        if($entity==='requests' && $query->q!=='') {
            $pattern='%'.strtr($query->q,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $parameters+=['detail_sku'=>$pattern,'detail_product'=>$pattern,'detail_match'=>$query->q];
            $search[]="CASE WHEN EXISTS(SELECT 1 FROM inventory_stock_request_lines line
                JOIN sales_products product ON product.company_id=line.company_id AND product.product_id=line.product_id
                WHERE line.company_id=listed.company_id AND line.request_id=listed.request_id
                AND (product.sku LIKE :detail_sku ESCAPE '!' OR product.name LIKE :detail_product ESCAPE '!')) THEN :detail_match ELSE '' END";
        }
        return new SqlList(\db(),$sql,$parameters,$query,$search,$sorts,$id,$filters);
    }

    public static function controls(string $entity,?SqlList $list=null): array
    {
        [,,$sorts,$filters]=self::definition($entity);$controls=[];
        foreach($filters as $key=>$unused)$controls[$key]=match($key) {
            'from','to'=>['label'=>ucfirst($key),'type'=>'date'],
            'active','low_stock'=>['label'=>$key==='active'?'Active':'Low stock','options'=>['1'=>'Yes','0'=>'No']],
            'status'=>['label'=>'Status','options'=>FilterOptions::domain(match($entity){'peers'=>'inventory_peer_proposals','allocations'=>'inventory_stock_request_allocations','procurements'=>'purchase_requisitions',default=>'inventory_stock_requests'},$entity==='peers'?'state':'status')],
            'event'=>['label'=>'Event','options'=>$list?->options('event_type')??[]],
            'purchase_status'=>['label'=>'Purchase order status','options'=>FilterOptions::domain('purchase_orders','status')],
            'kind'=>['label'=>'Kind','options'=>['employee_issue'=>'Employee issue','manager_replenishment'=>'Manager replenishment']],
            'level'=>['label'=>'Level','options'=>FilterOptions::domain('inventory_stock_authorities','authority_level')],
        };
        return ['sorts'=>FilterOptions::labels(array_keys($sorts)),'filters'=>$controls];
    }

    public static function columns(string $entity): array
    {
        return match($entity) {
            'events'=>['occurred_at'=>'Date','event_type'=>'Event','actor_name'=>'Actor','from_status'=>'Previous status','to_status'=>'Status','reason'=>'Reason'],
            'allocations'=>['sku'=>'SKU','product_name'=>'Product','authority_level'=>'Level','authority_name'=>'Manager','quantity'=>'Quantity','source_warehouse_name'=>'Source warehouse','source_location_name'=>'Source location','destination_warehouse_name'=>'Destination warehouse','destination_location_name'=>'Destination location','status'=>'Status','transfer_number'=>'Transfer','transfer_status'=>'Transfer status'],
            'procurements'=>['requisition_number'=>'Requisition','requisition_status'=>'Status','po_number'=>'Purchase order','purchase_order_status'=>'Purchase order status'],
            'authorities'=>['display_name'=>'Manager','job_title'=>'HR job title','authority_level'=>'Level','warehouse_code'=>'Warehouse code','warehouse_name'=>'Warehouse','location_code'=>'Location code','location_name'=>'Location','manager_name'=>'Reports to','active'=>'Active'],
            'reorder'=>['sku'=>'SKU','name'=>'Product','unit_of_measure'=>'Unit','quantity_on_hand'=>'On hand','quantity_reserved'=>'Reserved','quantity_available'=>'Available','notification_quantity'=>'Notification quantity','threshold_active'=>'Threshold active','low_stock'=>'Low stock'],
            'requests'=>['request_number'=>'Request','requester_name'=>'Requester','current_handler_name'=>'Handler','serving_warehouse_name'=>'Serving warehouse','serving_location_name'=>'Serving location','request_kind'=>'Kind','requested_at'=>'Requested','status'=>'Status','requested_quantity'=>'Requested quantity','allocated_quantity'=>'Allocated','ready_quantity'=>'Ready'],
            'peers'=>['proposal_number'=>'Proposal','request_number'=>'Request','sku'=>'SKU','product_name'=>'Product','source_name'=>'Source','destination_name'=>'Destination','source_owner_name'=>'Source manager','destination_owner_name'=>'Destination manager','proposer_name'=>'Proposed by','quantity'=>'Quantity','state'=>'Status','created_at'=>'Proposed','transfer_number'=>'Transfer','transfer_status'=>'Transfer status'],
            default=>throw new \InvalidArgumentException('This configuration list is not an export register.'),
        };
    }

    private static function definition(string $entity): array
    {
        return match($entity) {
            'events'=>['request_event_id',['event_type','actor_name','reason'],['date'=>'occurred_at','event'=>'event_type','actor'=>'actor_name'],['status'=>'to_status','event'=>'event_type','from'=>['DATE(occurred_at)','>='],'to'=>['DATE(occurred_at)','<=']],'date'],
            'allocations'=>['allocation_id',['sku','product_name','authority_name','source_warehouse_name','source_location_name','destination_warehouse_name','destination_location_name','transfer_number'],['product'=>'product_name','manager'=>'authority_name','quantity'=>'quantity','status'=>'status'],['status'=>'status','level'=>'authority_level'],'product'],
            'procurements'=>['link_id ASC, purchase_order_id',['requisition_number','po_number'],['requisition'=>'requisition_number','purchase'=>'po_number','status'=>'requisition_status'],['status'=>'requisition_status','purchase_status'=>'purchase_order_status'],'requisition'],
            'requests'=>['request_id',['request_number','requester_name','current_handler_name','serving_warehouse_name','serving_location_name','status','request_kind'],['date'=>'requested_at','reference'=>'request_number','requester'=>'requester_name','handler'=>'current_handler_name','status'=>'status'],['status'=>'status','kind'=>'request_kind','from'=>['DATE(requested_at)','>='],'to'=>['DATE(requested_at)','<=']],'date'],
            'peers'=>['proposal_id',['proposal_number','request_number','sku','product_name','source_name','destination_name','source_owner_name','destination_owner_name','proposer_name','state','transfer_number'],['date'=>'created_at','reference'=>'proposal_number','product'=>'product_name','status'=>'state'],['status'=>'state','from'=>['DATE(created_at)','>='],'to'=>['DATE(created_at)','<=']],'date'],
            'authorities'=>['authority_id',['display_name','job_title','warehouse_code','warehouse_name','location_code','location_name','manager_name','parent_warehouse_name'],['manager'=>'display_name','level'=>'authority_level','warehouse'=>'warehouse_name','status'=>'active'],['active'=>'active','level'=>'authority_level'],'manager'],
            'reorder'=>['product_id',['sku','name'],['product'=>'name','sku'=>'sku','available'=>'quantity_available','threshold'=>'notification_quantity'],['low_stock'=>'low_stock'],'product'],
            default=>throw new \InvalidArgumentException('Unknown stock request register.'),
        };
    }
}
