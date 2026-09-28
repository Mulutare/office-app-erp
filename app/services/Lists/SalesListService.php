<?php
declare(strict_types=1);
namespace App\Services\Lists;

use App\Services\TenantContext;
use InvalidArgumentException;

final class SalesListService
{
    public static function secondaryColumns(string $entity): array
    {
        return match($entity){
            'serials'=>['serial_number'=>'Serial','sku'=>'SKU','product_name'=>'Product','status'=>'Status','registered_at'=>'Registered'],
            'commissions'=>['order_number'=>'Order','agent_code'=>'Agent code','agent_name'=>'Agent','commission_amount'=>'Commission','status'=>'Status','accrued_at'=>'Accrued'],
            'targets'=>['agent_name'=>'Agent','territory_name'=>'Territory','period_start'=>'From','period_end'=>'To','target_amount'=>'Target','achieved_amount'=>'Achieved'],
            default=>throw new InvalidArgumentException('Unknown Sales support register.'),
        };
    }
    public function listing(string $entity,array $input,string $prefix = ''): SqlList
    {
        $company=(new TenantContext())->companyId();$actor=(int)($_SESSION['auth']['user_id']??0);
        [$sql,$id,$search,$sorts,$filters,$default,$direction]=$this->definition($entity,$company,$actor);
        $query=new ListQuery($input,$sorts,$default,array_keys($filters),$direction,$prefix);
        return new SqlList(\db(),$sql,['company_id'=>$company],$query,$search,$sorts,$id,$filters);
    }

    public function controls(string $entity): array
    {
        [,,,$sorts,$filters]=$this->definition($entity,(new TenantContext())->companyId(),(int)($_SESSION['auth']['user_id']??0));
        $domains=['active'=>['1'=>'Active','0'=>'Archived']];
        $table=match($entity) {
            'sales-orders'=>'sales_orders','quotations'=>'sales_quotations','deliveries','returns'=>'inventory_pickings',
            'serials'=>'sales_serial_numbers','commissions'=>'sales_commissions',default=>null,
        };
        if($table)$domains['status']=[$table,'status'];
        if($entity==='customers')$domains['type']=['sales_customers','customer_type'];
        if($entity==='products')$domains['type']=['stockable'=>'Stockable','service'=>'Service','telecom_product'=>'Telecom product'];
        if(in_array($entity,['pricing','variants'],true))$domains['family']=['mobile'=>'Mobile','mifi'=>'MiFi','other'=>'Other'];
        return FilterOptions::controls($this->listing($entity,[]),$filters,$sorts,$domains,['warehouse'=>'warehouse_name']);
    }

    private function definition(string $entity,int $company,int $actor): array
    {
        $masterSorts=['name'=>'name','code'=>'business_code','status'=>'active'];
        $masterFilters=['active'=>'active'];
        if(in_array($entity,['deliveries','returns'],true)) {
            $warehouse=(new \App\Services\SalesHierarchyScope())->isAgent($company,$actor) ? '1=1'
                : (new \App\Services\InventoryReadScope())->predicate($company,$actor,'p.warehouse_id');
            $type=$entity==='deliveries'?'delivery':'customer_return';
            return ["SELECT p.*,DATE(p.created_at) document_date,o.order_number,o.order_number origin_reference,
                c.name customer_name,w.name warehouse_name,w.code warehouse_code,
                src.name source_location_name,dst.name destination_location_name,u.display_name responsible_name,
                COALESCE(totals.requested_quantity,0) requested_quantity,COALESCE(totals.reserved_quantity,0) reserved_quantity,
                COALESCE(totals.completed_quantity,0) completed_quantity
                FROM inventory_pickings p INNER JOIN sales_orders o ON o.company_id=p.company_id AND o.order_id=p.sales_order_id AND o.deleted_at IS NULL
                INNER JOIN sales_customers c ON c.company_id=o.company_id AND c.customer_id=o.customer_id
                LEFT JOIN inventory_warehouses w ON w.company_id=p.company_id AND w.warehouse_id=p.warehouse_id
                LEFT JOIN inventory_warehouse_locations src ON src.company_id=p.company_id AND src.location_id=p.source_location_id
                LEFT JOIN inventory_warehouse_locations dst ON dst.company_id=p.company_id AND dst.location_id=p.destination_location_id
                LEFT JOIN users u ON u.user_id=p.created_by
                LEFT JOIN (SELECT picking_id,SUM(requested_quantity) requested_quantity,SUM(reserved_quantity) reserved_quantity,
                    SUM(completed_quantity) completed_quantity FROM inventory_picking_lines WHERE company_id=".(int)$company." GROUP BY picking_id) totals ON totals.picking_id=p.picking_id
                WHERE p.company_id=:company_id AND p.picking_type='$type' AND ($warehouse) AND ".SalesScopeSql::row($company,$actor,'o'),
                'picking_id',['picking_number','order_number','customer_name','warehouse_name','warehouse_code','responsible_name','status','document_date'],
                ['date'=>'created_at','reference'=>'picking_number','customer'=>'customer_name','warehouse'=>'warehouse_name','status'=>'status'],
                ['status'=>'status','from'=>['document_date','>='],'to'=>['document_date','<='],'warehouse'=>'warehouse_code'],'date','desc'];
        }
        if($entity==='serials')return [
            'SELECT s.*,p.sku,p.name product_name FROM sales_serial_numbers s
             INNER JOIN sales_products p ON p.company_id=s.company_id AND p.product_id=s.product_id
             WHERE s.company_id=:company_id',
            'serial_id',['serial_number','sku','product_name','status'],
            ['date'=>'registered_at','serial'=>'serial_number','product'=>'product_name','status'=>'status'],
            ['status'=>'status'],'date','desc'];
        if($entity==='commissions')return [
            'SELECT c.*,o.order_number,a.agent_code,a.name agent_name FROM sales_commissions c
             INNER JOIN sales_orders o ON o.company_id=c.company_id AND o.order_id=c.order_id
             INNER JOIN sales_agents a ON a.company_id=c.company_id AND a.agent_id=c.agent_id
             WHERE c.company_id=:company_id AND o.deleted_at IS NULL AND '.SalesScopeSql::row($company,$actor,'o'),
            'commission_id',['order_number','agent_code','agent_name','status'],
            ['date'=>'accrued_at','reference'=>'order_number','agent'=>'agent_name','amount'=>'commission_amount','status'=>'status'],
            ['status'=>'status'],'date','desc'];
        if($entity==='targets') {
            $member=SalesScopeSql::member($company,$actor,'e');
            return ["SELECT t.*,tr.name territory_name,a.name agent_name,
                COALESCE((SELECT SUM(o.total_amount) FROM sales_orders o WHERE o.company_id=t.company_id AND o.deleted_at IS NULL
                    AND (t.territory_id IS NULL OR o.territory_id=t.territory_id) AND (t.agent_id IS NULL OR o.agent_id=t.agent_id)
                    AND o.order_date BETWEEN t.period_start AND t.period_end
                    AND o.status IN ('approved','confirmed','fulfilled','partially_paid','paid')),0) achieved_amount
                FROM sales_targets t LEFT JOIN sales_territories tr ON tr.company_id=t.company_id AND tr.territory_id=t.territory_id
                LEFT JOIN sales_agents a ON a.company_id=t.company_id AND a.agent_id=t.agent_id
                LEFT JOIN hr_employees e ON e.company_id=a.company_id AND e.employee_id=a.employee_id
                WHERE t.company_id=:company_id AND ($member)",
                'target_id',['territory_name','agent_name','period_start','period_end'],
                ['date'=>'period_start','agent'=>'agent_name','territory'=>'territory_name','amount'=>'target_amount'],
                ['from'=>['period_start','>='],'to'=>['period_start','<=']],'date','desc'];
        }
        if($entity==='customers')return [
            'SELECT c.*, c.customer_number business_code,a.name agent_name,t.name team_name,p.name pricelist_name,
                CASE WHEN c.active=1 THEN \'Active\' ELSE \'Archived\' END status_label
             FROM sales_customers c LEFT JOIN sales_agents a ON a.company_id=c.company_id AND a.agent_id=c.agent_id
             LEFT JOIN sales_teams t ON t.company_id=c.company_id AND t.team_id=c.team_id
             LEFT JOIN sales_pricelists p ON p.company_id=c.company_id AND p.pricelist_id=c.pricelist_id
             WHERE c.company_id=:company_id AND c.deleted_at IS NULL',
            'customer_id',['customer_number','name','legal_name','email','phone','mobile','tax_number','agent_name','team_name','status_label'],
            $masterSorts,$masterFilters+['type'=>'customer_type'],'name','asc'];
        if($entity==='products')return [
            $this->productSql(), 'product_id',['sku','name','category','model_name','brand_name','status_label'],
            $masterSorts+['category'=>'category','price'=>'unit_price'],$masterFilters+['category'=>'category','type'=>'product_type'],'name','asc'];
        if(in_array($entity,['pricing','variants'],true))return [
            $this->productSql(), 'product_id',['sku','name','category','model_name','brand_name','product_family','mifi_subtype','status_label'],
            $masterSorts+['family'=>'product_family','brand'=>'brand_name','model'=>'model_name','price'=>'unit_price'],
            $masterFilters+['family'=>"COALESCE(product_family,'other')",'brand'=>'brand_name'],'code','asc'];
        if($entity==='pricelists')return [
            'SELECT p.*,(SELECT COUNT(*) FROM sales_pricelist_rules r WHERE r.company_id=p.company_id AND r.pricelist_id=p.pricelist_id AND r.active=1) rule_count
             FROM sales_pricelists p WHERE p.company_id=:company_id',
            'pricelist_id',['name','currency'],['name'=>'name','currency'=>'currency','status'=>'active'],$masterFilters+['currency'=>'currency'],'name','asc'];
        if($entity==='sales-teams') {
            $member=SalesScopeSql::member($company,$actor,'e');
            $members="SELECT tm.team_id,COUNT(*) member_count,
                CASE COUNT(DISTINCT manager.user_id) WHEN 0 THEN 'Unassigned' WHEN 1 THEN MAX(manager.display_name) ELSE 'Multiple managers' END manager_name,
                GROUP_CONCAT(DISTINCT a.name SEPARATOR ' ') member_names
                FROM sales_team_members tm INNER JOIN sales_agents a ON a.company_id=tm.company_id AND a.agent_id=tm.agent_id
                LEFT JOIN hr_employees e ON e.company_id=a.company_id AND e.employee_id=a.employee_id AND e.deleted_at IS NULL
                LEFT JOIN company_users m ON m.company_id=e.company_id AND m.user_id=e.user_id AND m.active=1
                LEFT JOIN users manager ON manager.user_id=m.manager_user_id AND manager.deleted_at IS NULL
                WHERE tm.company_id=".(int)$company." AND ($member) GROUP BY tm.team_id";
            $wide=(new \App\Services\SalesHierarchyScope())->hasCompanyWideAccess($company,$actor);
            return ["SELECT t.*, COALESCE(m.member_count,0) member_count,COALESCE(m.manager_name,'Unassigned') manager_name,
                COALESCE(m.manager_name,'Unassigned') leader_name,m.member_names
                FROM sales_teams t LEFT JOIN ($members) m ON m.team_id=t.team_id
                WHERE t.company_id=:company_id".($wide?'':' AND m.team_id IS NOT NULL'),
                'team_id',['name','manager_name','member_names'],['name'=>'name','manager'=>'manager_name','status'=>'active'],$masterFilters,'name','asc'];
        }
        if(in_array($entity,['sales-orders','quotations'],true)) {
            $order=$entity==='sales-orders';$alias=$order?'o':'q';$table=$order?'sales_orders':'sales_quotations';
            $id=$order?'order_id':'quotation_id';$number=$order?'order_number':'quotation_number';$date=$order?'order_date':'quotation_date';
            $extra=$order?", (o.total_amount-o.paid_amount) balance_due,
                (SELECT MIN(ip.picking_id) FROM inventory_pickings ip WHERE ip.company_id=o.company_id AND ip.sales_order_id=o.order_id AND ip.picking_type='delivery' AND ip.status<>'cancelled') delivery_picking_id"
                :',t.name team_name,p.name pricelist_name,o.order_number';
            $joins=$order?'':' LEFT JOIN sales_teams t ON t.company_id=q.company_id AND t.team_id=q.team_id
                LEFT JOIN sales_pricelists p ON p.company_id=q.company_id AND p.pricelist_id=q.pricelist_id
                LEFT JOIN sales_orders o ON o.company_id=q.company_id AND o.order_id=q.sales_order_id';
            $sql="SELECT $alias.*,c.name customer_name,c.customer_number,a.name agent_name,u.display_name responsible_name $extra
                FROM $table $alias INNER JOIN sales_customers c ON c.company_id=$alias.company_id AND c.customer_id=$alias.customer_id
                LEFT JOIN sales_agents a ON a.company_id=$alias.company_id AND a.agent_id=$alias.agent_id
                LEFT JOIN users u ON u.user_id=$alias.created_by $joins
                WHERE $alias.company_id=:company_id".($order?' AND o.deleted_at IS NULL':'').
                ' AND '.SalesScopeSql::row($company,$actor,$alias);
            return [$sql,$id,[$number,'customer_name','customer_number','agent_name','responsible_name','status',$date],
                ['date'=>$date,'reference'=>$number,'customer'=>'customer_name','status'=>'status','total'=>'total_amount'],
                ['status'=>'status','from'=>[$date,'>='],'to'=>[$date,'<=']],'date','desc'];
        }
        throw new InvalidArgumentException('Unsupported Sales list.');
    }

    private function productSql(): string
    {
        return "SELECT p.product_id,p.sku,p.sku business_code,p.name,p.category,p.product_type,p.unit_of_measure,
            COALESCE(pc.proposed_price,0) unit_price,COALESCE(pc.approved_discount_percent,0) discount_percent,
            COALESCE(pc.proposed_price,0) approved_price,COALESCE(pc.approved_discount_percent,0) approved_discount_percent,
            COALESCE(pc.approved_tax_percent,0) approved_tax_percent,pc.effective_from,pc.requested_at updated_at,
            (SELECT display_name FROM users WHERE user_id=pc.requested_by) updated_by,
            COALESCE(pc.approved_tax_percent,0) tax_percent,p.commission_rate,p.serial_tracking,p.active,
            p.model_id,m.brand_id,m.product_family,m.mifi_subtype,m.model_name,b.name brand_name,
            m.active model_active,b.active brand_active,CASE WHEN p.active=1 THEN 'Active' ELSE 'Archived' END status_label
            FROM sales_products p
            LEFT JOIN sales_product_models m ON m.company_id=p.company_id AND m.model_id=p.model_id
            LEFT JOIN sales_product_brands b ON b.company_id=m.company_id AND b.brand_id=m.brand_id
            LEFT JOIN sales_product_price_changes pc ON pc.company_id=p.company_id AND pc.price_change_id=(
                SELECT approved.price_change_id FROM sales_product_price_changes approved
                WHERE approved.company_id=p.company_id AND approved.product_id=p.product_id AND approved.status='approved'
                    AND approved.currency=(SELECT default_currency FROM companies WHERE company_id=p.company_id)
                    AND approved.effective_from<=NOW() ORDER BY approved.effective_from DESC,approved.price_change_id DESC LIMIT 1)
            WHERE p.company_id=:company_id AND p.deleted_at IS NULL";
    }
}
