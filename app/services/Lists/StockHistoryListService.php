<?php
declare(strict_types=1);
namespace App\Services\Lists;

/** Called after SalesStockHistoryService validates the actor's warehouse/location scope. */
final class StockHistoryListService
{
    public const CATEGORIES=['beginning','received','transfers_in','returns_in','sold_issued','transfers_out','returns_out','adjustments','unclassified'];

    public function workspace(int $company,int $warehouse,int $location,int $product,string $date,string $end,array $input,array $options): array
    {
        $cutover=\db()->prepare('SELECT cutover_at,cutover_event_id,label FROM inventory_reservation_cutovers WHERE company_id=?');
        $cutover->execute([$company]);$cutoverRow=$cutover->fetch(\PDO::FETCH_ASSOC);
        $exact=$cutoverRow && $end>(string)$cutoverRow['cutover_at'];
        $params=[$company,$warehouse,$location,$product,$date.' 00:00:00',$end,(int)$exact,(int)($cutoverRow['cutover_event_id']??0),(int)($date===date('Y-m-d'))];
        $cte="WITH scope AS (SELECT ? company_id,? warehouse_id,? location_id,? product_id,? day_start,? day_end,? reservation_exact,? cutover_event_id,? is_today),
            movements AS (
                SELECT m.*,COALESCE(m.completed_at,m.occurred_at) business_at,sl.location_usage source_usage,dl.location_usage destination_usage
                FROM inventory_stock_movements m JOIN scope s ON s.company_id=m.company_id
                LEFT JOIN inventory_warehouse_locations sl ON sl.company_id=m.company_id AND sl.warehouse_id=m.source_warehouse_id AND sl.location_id=m.source_location_id
                LEFT JOIN inventory_warehouse_locations dl ON dl.company_id=m.company_id AND dl.warehouse_id=m.destination_warehouse_id AND dl.location_id=m.destination_location_id
                WHERE m.status='completed' AND COALESCE(m.completed_at,m.occurred_at)<s.day_end
                    AND (m.source_warehouse_id=s.warehouse_id OR m.destination_warehouse_id=s.warehouse_id)
                    AND (s.product_id=0 OR m.product_id=s.product_id)
            ), raw_legs AS (
                SELECT m.movement_id,m.product_id,m.business_at,m.movement_type,m.reference_type,m.reference_number,m.notes,
                    m.source_location_id location_id,'source' side,-m.completed_quantity quantity_delta,m.destination_usage other_usage
                FROM movements m JOIN scope s ON m.source_warehouse_id=s.warehouse_id
                WHERE m.completed_quantity>0 AND m.source_usage IN('internal','transit') AND (s.location_id=0 OR m.source_location_id=s.location_id)
                UNION ALL
                SELECT m.movement_id,m.product_id,m.business_at,m.movement_type,m.reference_type,m.reference_number,m.notes,
                    m.destination_location_id,'destination',m.completed_quantity,m.source_usage
                FROM movements m JOIN scope s ON m.destination_warehouse_id=s.warehouse_id
                WHERE m.completed_quantity>0 AND m.destination_usage IN('internal','transit') AND (s.location_id=0 OR m.destination_location_id=s.location_id)
            ), legs AS (
                SELECT r.*,CONCAT(r.movement_id,'-',r.side) leg_key,
                    CASE WHEN r.business_at<s.day_start THEN 'beginning'
                        WHEN r.movement_type IN('adjustment_in','adjustment_out','opening') THEN 'adjustments'
                        WHEN r.other_usage IN('internal','transit') THEN IF(r.side='source','transfers_out','transfers_in')
                        WHEN r.side='destination' THEN CASE r.movement_type WHEN 'receipt' THEN 'received' WHEN 'return_in' THEN 'returns_in'
                            WHEN 'transfer_in' THEN 'transfers_in' WHEN 'transfer_out' THEN 'transfers_in' ELSE 'unclassified' END
                        ELSE CASE r.movement_type WHEN 'fulfilment' THEN 'sold_issued' WHEN 'issue' THEN 'sold_issued' WHEN 'return_out' THEN 'returns_out'
                            WHEN 'transfer_in' THEN 'transfers_out' WHEN 'transfer_out' THEN 'transfers_out' ELSE 'unclassified' END END category
                FROM raw_legs r CROSS JOIN scope s
            ), daily AS (
                SELECT product_id,".implode(',',array_map(static fn(string $category):string=>
                    "SUM(CASE WHEN category='$category' THEN ".(in_array($category,['beginning','adjustments','unclassified'],true)?'quantity_delta':'ABS(quantity_delta)')." ELSE 0 END) $category",self::CATEGORIES)).",
                    SUM(quantity_delta) ending FROM legs GROUP BY product_id
            ), reservations AS (
                SELECT r.product_id,SUM(r.quantity_delta) reserved FROM inventory_reservation_events r JOIN scope s ON s.company_id=r.company_id AND s.warehouse_id=r.warehouse_id
                WHERE s.reservation_exact=1 AND r.occurred_at<s.day_end AND (r.event_type='088_cutover_baseline' OR r.reservation_event_id>s.cutover_event_id)
                    AND (s.location_id=0 OR r.location_id=s.location_id) AND (s.product_id=0 OR r.product_id=s.product_id) GROUP BY r.product_id
            ), balances AS (
                SELECT b.product_id,SUM(b.quantity_on_hand) on_hand,SUM(b.quantity_reserved) reserved
                FROM inventory_stock_balances b JOIN scope s ON s.company_id=b.company_id AND s.warehouse_id=b.warehouse_id
                JOIN inventory_warehouse_locations l ON l.company_id=b.company_id AND l.location_id=b.location_id AND l.location_usage IN('internal','transit')
                WHERE s.is_today=1 AND (s.location_id=0 OR b.location_id=s.location_id) AND (s.product_id=0 OR b.product_id=s.product_id) GROUP BY b.product_id
            ), products_used AS (SELECT product_id FROM daily UNION SELECT product_id FROM reservations UNION SELECT product_id FROM balances),
            product_totals AS (
                SELECT k.product_id,p.sku,COALESCE(p.name,'Product unavailable') product_name,
                    IF(d.product_id IS NULL,'missing','recorded') ledger_state,
                    ".implode(',',array_map(static fn(string $category):string=>"COALESCE(d.$category,0) $category",self::CATEGORIES)).",
                    COALESCE(d.ending,0) ending,
                    IF(s.reservation_exact=1,COALESCE(r.reserved,0),NULL) reserved,
                    IF(s.reservation_exact=1,COALESCE(d.ending,0)-COALESCE(r.reserved,0),NULL) available,
                    IF(b.product_id IS NULL,NULL,b.on_hand-COALESCE(d.ending,0)) balance_difference,
                    IF(s.reservation_exact=1 AND b.product_id IS NOT NULL,b.reserved-COALESCE(r.reserved,0),NULL) reservation_difference
                FROM products_used k CROSS JOIN scope s LEFT JOIN sales_products p ON p.company_id=s.company_id AND p.product_id=k.product_id
                LEFT JOIN daily d ON d.product_id=k.product_id LEFT JOIN reservations r ON r.product_id=k.product_id LEFT JOIN balances b ON b.product_id=k.product_id
            ) ";
        $queries=[
            'stock'=>$cte.'SELECT * FROM product_totals',
            'legs'=>$cte."SELECT l.*,p.sku,p.name product_name,w.code location_code,w.name location_name
                FROM legs l CROSS JOIN scope s LEFT JOIN sales_products p ON p.company_id=s.company_id AND p.product_id=l.product_id
                LEFT JOIN inventory_warehouse_locations w ON w.company_id=s.company_id AND w.location_id=l.location_id",
            'issues'=>$cte."SELECT CONCAT(m.movement_id,'-quantity') issue_key,m.business_at,p.sku,p.name product_name,m.movement_type,m.reference_number,'No completed quantity' reason
                FROM movements m CROSS JOIN scope s LEFT JOIN sales_products p ON p.company_id=s.company_id AND p.product_id=m.product_id WHERE COALESCE(m.completed_quantity,0)<=0
                UNION ALL SELECT l.leg_key,l.business_at,p.sku,p.name,l.movement_type,l.reference_number,CONCAT('Unmapped ',l.movement_type,' ',l.side)
                FROM legs l CROSS JOIN scope s LEFT JOIN sales_products p ON p.company_id=s.company_id AND p.product_id=l.product_id WHERE l.category='unclassified'",
        ];
        if(isset($input['drill']) && !isset($input['legs']['category']) && in_array(ListQuery::text($input['drill']),self::CATEGORIES,true))$input['legs']['category']=ListQuery::text($input['drill']);
        unset($input['drill']);
        $input=array_replace($input,['warehouse_id'=>$warehouse,'location_id'=>$location,'product_id'=>$product,'date'=>$date]);
        $result=$options+['date'=>$date,'warehouseId'=>$warehouse,'locationId'=>$location,'productId'=>$product,'reservationExact'=>(bool)$exact,'cutover'=>$cutoverRow?:null];
        foreach($queries as $entity=>$sql) {
            [$sorts,$filters,$search,$id]=$this->configuration($entity);
            $list=new SqlList(\db(),$sql,$params,new ListQuery($input,$sorts,$entity==='stock'?'sku':'date',array_keys($filters),'asc',$entity),$search,$sorts,$id,$filters);
            $result['lists'][$entity]=$list->page();$result['exportLists'][$entity]=$list;
            $result['controls'][$entity]=FilterOptions::controls($list,$filters,$sorts,
                ['category'=>FilterOptions::labels(self::CATEGORIES),'type'=>['inventory_stock_movements','movement_type'],'ledger'=>['missing'=>'Movement missing','recorded'=>'Movement recorded']],
                ['product'=>"CONCAT(sku,' — ',product_name)"]);
        }
        $result['rows']=$result['lists']['stock']['rows'];$result['details']=$result['lists']['legs']['rows'];
        $result['unclassified']=$result['lists']['issues']['rows'];
        $totalQuery=\db()->prepare('SELECT COUNT(*) FROM ('.$queries['issues'].') warnings');$totalQuery->execute($params);
        $result['issueTotal']=(int)$totalQuery->fetchColumn();
        return $result;
    }

    private function configuration(string $entity): array
    {
        return match($entity) {
            'stock'=>[['sku'=>'sku','product'=>'product_name','beginning'=>'beginning','ending'=>'ending','reserved'=>'reserved','available'=>'available','difference'=>'balance_difference'],
                ['ledger'=>'ledger_state'],['sku','product_name'],'product_id'],
            'legs'=>[['date'=>'business_at','sku'=>'sku','product'=>'product_name','location'=>'location_name','category'=>'category','quantity'=>'quantity_delta','reference'=>'reference_number'],
                ['category'=>'category','product'=>'sku','type'=>'movement_type','from'=>['DATE(business_at)','>='],'to'=>['DATE(business_at)','<=']],['sku','product_name','location_code','location_name','reference_number','notes'],'leg_key'],
            'issues'=>[['date'=>'business_at','sku'=>'sku','reason'=>'reason','reference'=>'reference_number'],['type'=>'movement_type'],['sku','product_name','reason','reference_number'],'issue_key'],
        };
    }

    public static function columns(string $entity): array
    {
        $product=['sku'=>'SKU','product_name'=>'Product'];
        return $product+match($entity) {
            'stock'=>array_combine(self::CATEGORIES,array_map(static fn(string $value):string=>ucwords(str_replace('_',' ',$value)),self::CATEGORIES))+
                ['ending'=>'Ending on-hand','reserved'=>'Reserved','available'=>'Available','balance_difference'=>'On-hand difference','reservation_difference'=>'Reservation difference','ledger_state'=>'Movement coverage'],
            'legs'=>['business_at'=>'Date','location_code'=>'Location code','location_name'=>'Location','category'=>'Category','quantity_delta'=>'Signed quantity','movement_type'=>'Movement type','reference_type'=>'Source type','reference_number'=>'Reference'],
            'issues'=>['business_at'=>'Date','movement_type'=>'Movement type','reference_number'=>'Reference','reason'=>'Reason'],
            default=>throw new \InvalidArgumentException('Unknown stock history register.'),
        };
    }
}
