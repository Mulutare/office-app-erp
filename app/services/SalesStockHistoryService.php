<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/** Read-only daily inventory report derived from completed movement endpoint legs. */
final class SalesStockHistoryService
{
    public function report(int $actor,array $filters): array
    {
        $company=(new TenantContext())->companyId();
        if(!(new ModuleRoleService())->permissionAllowed($company,$actor,'inventory.stock.view'))throw new RuntimeException('Stock history permission is required.');
        $scope=new InventoryReadScope();$visible=$scope->warehouseIds($company,$actor);
        $warehouse=(int)($filters['warehouse_id']??($visible[0]??0));$location=(int)($filters['location_id']??0);$product=(int)($filters['product_id']??0);
        if($warehouse<1||!in_array($warehouse,$visible,true))throw new RuntimeException('Choose a warehouse in your reporting scope.');
        if($location>0&&!$scope->location($company,$actor,$warehouse,$location))throw new RuntimeException('Choose a location in your reporting scope.');
        $dateText=(string)($filters['date']??date('Y-m-d'));$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$dateText);
        if(!$date||$date->format('Y-m-d')!==$dateText||$dateText>date('Y-m-d'))throw new RuntimeException('Choose a valid current or past business date.');
        $end=$date->modify('+1 day')->format('Y-m-d').' 00:00:00';$start=$dateText.' 00:00:00';
        $warehouses=\db()->prepare('SELECT warehouse_id,code,name FROM inventory_warehouses WHERE company_id=? AND active=TRUE AND deleted_at IS NULL ORDER BY name');$warehouses->execute([$company]);
        $warehouseRows=array_values(array_filter($warehouses->fetchAll(PDO::FETCH_ASSOC),static fn(array $w):bool=>in_array((int)$w['warehouse_id'],$visible,true)));
        $locations=\db()->prepare("SELECT location_id,warehouse_id,code,name FROM inventory_warehouse_locations WHERE company_id=? AND warehouse_id=? AND location_usage IN('internal','transit') AND deleted_at IS NULL ORDER BY name");$locations->execute([$company,$warehouse]);
        $products=\db()->prepare('SELECT product_id,sku,name FROM sales_products WHERE company_id=? AND deleted_at IS NULL ORDER BY sku');$products->execute([$company]);$productRows=$products->fetchAll(PDO::FETCH_ASSOC);
        $nameById=[];foreach($productRows as $p)$nameById[(int)$p['product_id']]=$p;
        $sql="SELECT m.movement_id,m.product_id,m.movement_type,m.completed_quantity,m.quantity_delta,m.source_warehouse_id,m.source_location_id,m.destination_warehouse_id,m.destination_location_id,m.reference_type,m.reference_id,m.reference_number,m.notes,COALESCE(m.completed_at,m.occurred_at) business_at,sl.location_usage source_usage,dl.location_usage destination_usage FROM inventory_stock_movements m LEFT JOIN inventory_warehouse_locations sl ON sl.company_id=m.company_id AND sl.warehouse_id=m.source_warehouse_id AND sl.location_id=m.source_location_id LEFT JOIN inventory_warehouse_locations dl ON dl.company_id=m.company_id AND dl.warehouse_id=m.destination_warehouse_id AND dl.location_id=m.destination_location_id WHERE m.company_id=? AND m.status='completed' AND COALESCE(m.completed_at,m.occurred_at)<? AND (m.source_warehouse_id=? OR m.destination_warehouse_id=?)";
        $params=[$company,$end,$warehouse,$warehouse];if($product>0){$sql.=' AND m.product_id=?';$params[]=$product;}
        $sql.=' ORDER BY business_at,m.movement_id';$statement=\db()->prepare($sql);$statement->execute($params);
        $rows=[];$details=[];$unclassified=[];
        while($movement=$statement->fetch(PDO::FETCH_ASSOC)){
            $quantity=(float)($movement['completed_quantity']??0);
            if($quantity<=0){$unclassified[]=['movement_id'=>$movement['movement_id'],'reason'=>'No completed quantity'];continue;}
            foreach(['source'=>-1,'destination'=>1] as $side=>$sign){
                $warehouseId=(int)($movement[$side.'_warehouse_id']??0);$locationId=(int)($movement[$side.'_location_id']??0);
                if($warehouseId!==$warehouse||($location>0&&$locationId!==$location)||!in_array((string)($movement[$side.'_usage']??''),['internal','transit'],true))continue;
                $key=(int)$movement['product_id'];
                $rows[$key]??=['product_id'=>$key,'sku'=>$nameById[$key]['sku']??('#'.$key),'product_name'=>$nameById[$key]['name']??'Unknown','beginning'=>0.0,'received'=>0.0,'transfers_in'=>0.0,'returns_in'=>0.0,'sold_issued'=>0.0,'transfers_out'=>0.0,'returns_out'=>0.0,'adjustments'=>0.0,'unclassified'=>0.0,'ending'=>0.0,'reserved'=>null,'available'=>null,'balance_difference'=>null];
                $delta=$sign*$quantity;$category=$this->category($movement,$side);
                if($movement['business_at']<$start){$rows[$key]['beginning']+=$delta;$details[]=['movement_id'=>(int)$movement['movement_id'],'product_id'=>$key,'location_id'=>$locationId,'business_at'=>$movement['business_at'],'category'=>'beginning','quantity_delta'=>$delta,'movement_type'=>$movement['movement_type'],'reference_type'=>$movement['reference_type'],'reference_id'=>$movement['reference_id'],'reference_number'=>$movement['reference_number']];}
                else{
                    if($category==='unclassified'){$rows[$key]['unclassified']+=$delta;$unclassified[]=['movement_id'=>$movement['movement_id'],'reason'=>'Unmapped '.$movement['movement_type'].' '.$side];}
                    else $rows[$key][$category]+=abs($delta)*($category==='adjustments'?$sign:1);
                    $details[]=['movement_id'=>(int)$movement['movement_id'],'product_id'=>$key,'location_id'=>$locationId,'business_at'=>$movement['business_at'],'category'=>$category,'quantity_delta'=>$delta,'movement_type'=>$movement['movement_type'],'reference_type'=>$movement['reference_type'],'reference_id'=>$movement['reference_id'],'reference_number'=>$movement['reference_number']];
                }
            }
        }
        foreach($rows as &$row){$row['ending']=round($row['beginning']+$row['received']+$row['transfers_in']+$row['returns_in']-$row['sold_issued']-$row['transfers_out']-$row['returns_out']+$row['adjustments']+$row['unclassified'],3);foreach(['beginning','received','transfers_in','returns_in','sold_issued','transfers_out','returns_out','adjustments','unclassified'] as $field)$row[$field]=round($row[$field],3);}unset($row);
        return $this->finish($company,$warehouse,$location,$product,$dateText,$end,$warehouseRows,$locations->fetchAll(PDO::FETCH_ASSOC),$productRows,$rows,$details,$unclassified);
    }

    private function category(array $movement,string $side): string
    {
        $type=(string)$movement['movement_type'];$other=$side==='source'?'destination':'source';
        $otherInternal=in_array((string)($movement[$other.'_usage']??''),['internal','transit'],true);
        if($type==='adjustment_in'||$type==='adjustment_out'||$type==='opening')return 'adjustments';
        if($otherInternal)return $side==='source'?'transfers_out':'transfers_in';
        if($side==='destination')return match($type){'receipt'=>'received','return_in'=>'returns_in','transfer_in','transfer_out'=>'transfers_in',default=>'unclassified'};
        return match($type){'fulfilment','issue'=>'sold_issued','return_out'=>'returns_out','transfer_in','transfer_out'=>'transfers_out',default=>'unclassified'};
    }

    private function finish(int $company,int $warehouse,int $location,int $product,string $date,string $end,array $warehouses,array $locations,array $products,array $rows,array $details,array $unclassified): array
    {
        $cutover=\db()->prepare('SELECT cutover_at,cutover_event_id,label FROM inventory_reservation_cutovers WHERE company_id=?');$cutover->execute([$company]);$cutoverRow=$cutover->fetch(PDO::FETCH_ASSOC);
        $reservationExact=$cutoverRow&&$end>(string)$cutoverRow['cutover_at'];
        if($reservationExact){
            $sql="SELECT product_id,SUM(quantity_delta) reserved FROM inventory_reservation_events WHERE company_id=? AND warehouse_id=? AND occurred_at<? AND (event_type='088_cutover_baseline' OR reservation_event_id>?)";$params=[$company,$warehouse,$end,(int)$cutoverRow['cutover_event_id']];
            if($location>0){$sql.=' AND location_id=?';$params[]=$location;}
            if($product>0){$sql.=' AND product_id=?';$params[]=$product;}
            $sql.=' GROUP BY product_id';$query=\db()->prepare($sql);$query->execute($params);
            $reserved=array_column($query->fetchAll(PDO::FETCH_ASSOC),'reserved','product_id');
            foreach($reserved as $id=>$quantity){if(!isset($rows[(int)$id])){$rows[(int)$id]=['product_id'=>(int)$id,'sku'=>'#'.$id,'product_name'=>'Ledger movement missing','beginning'=>0.0,'received'=>0.0,'transfers_in'=>0.0,'returns_in'=>0.0,'sold_issued'=>0.0,'transfers_out'=>0.0,'returns_out'=>0.0,'adjustments'=>0.0,'unclassified'=>0.0,'ending'=>0.0,'reserved'=>null,'available'=>null,'balance_difference'=>null];}}
            foreach($rows as &$row){$row['reserved']=round((float)($reserved[$row['product_id']]??0),3);$row['available']=round($row['ending']-$row['reserved'],3);}unset($row);
        }
        if($date===date('Y-m-d')){
            $sql="SELECT b.product_id,SUM(b.quantity_on_hand) on_hand,SUM(b.quantity_reserved) reserved FROM inventory_stock_balances b JOIN inventory_warehouse_locations l ON l.company_id=b.company_id AND l.location_id=b.location_id AND l.location_usage IN('internal','transit') WHERE b.company_id=? AND b.warehouse_id=?";$params=[$company,$warehouse];
            if($location>0){$sql.=' AND b.location_id=?';$params[]=$location;}
            if($product>0){$sql.=' AND b.product_id=?';$params[]=$product;}
            $sql.=' GROUP BY b.product_id';$balances=\db()->prepare($sql);$balances->execute($params);
            foreach($balances->fetchAll(PDO::FETCH_ASSOC) as $balance){
                $id=(int)$balance['product_id'];
                $rows[$id]??=['product_id'=>$id,'sku'=>'#'.$id,'product_name'=>'Ledger movement missing','beginning'=>0.0,'received'=>0.0,'transfers_in'=>0.0,'returns_in'=>0.0,'sold_issued'=>0.0,'transfers_out'=>0.0,'returns_out'=>0.0,'adjustments'=>0.0,'unclassified'=>0.0,'ending'=>0.0,'reserved'=>null,'available'=>null,'balance_difference'=>null];
                if($reservationExact){$rows[$id]['reserved']??=0.0;$rows[$id]['available']=round($rows[$id]['ending']-$rows[$id]['reserved'],3);$rows[$id]['reservation_difference']=round((float)$balance['reserved']-$rows[$id]['reserved'],3);}
                $rows[$id]['balance_difference']=round((float)$balance['on_hand']-$rows[$id]['ending'],3);
            }
        }
        usort($rows,static fn(array $a,array $b):int=>strcmp($a['sku'],$b['sku']));
        return ['date'=>$date,'warehouseId'=>$warehouse,'locationId'=>$location,'productId'=>$product,'warehouses'=>$warehouses,'locations'=>$locations,'products'=>$products,'rows'=>array_values($rows),'details'=>$details,'unclassified'=>$unclassified,'reservationExact'=>(bool)$reservationExact,'cutover'=>$cutoverRow?:null];
    }
}
