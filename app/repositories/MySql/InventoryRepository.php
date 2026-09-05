<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\InventoryRepository as InventoryRepositoryContract;
use PDO;
use RuntimeException;
use Throwable;

final class InventoryRepository extends MySqlRepository implements InventoryRepositoryContract
{
    public function goodsReceipts(int $companyId): array
    {$s=$this->connection()->prepare("SELECT r.goods_receipt_id,r.receipt_number,r.supplier_name,r.supplier_reference,r.receipt_date,r.currency,r.status,r.posted_at,w.name warehouse_name,d.name destination_location_name,COALESCE(SUM(l.quantity),0) total_quantity,COALESCE(SUM(l.line_value),0) total_value FROM inventory_goods_receipts r INNER JOIN inventory_warehouses w ON w.company_id=r.company_id AND w.warehouse_id=r.warehouse_id LEFT JOIN inventory_warehouse_locations d ON d.company_id=r.company_id AND d.warehouse_id=r.warehouse_id AND d.location_id=r.destination_location_id LEFT JOIN inventory_goods_receipt_lines l ON l.company_id=r.company_id AND l.goods_receipt_id=r.goods_receipt_id WHERE r.company_id=:company_id GROUP BY r.goods_receipt_id,r.receipt_number,r.supplier_name,r.supplier_reference,r.receipt_date,r.currency,r.status,r.posted_at,w.name,d.name ORDER BY r.goods_receipt_id DESC");$s->execute(['company_id'=>$companyId]);return$s->fetchAll(PDO::FETCH_ASSOC);}

    public function goodsReceipt(int $companyId,int $receiptId): ?array
    {$s=$this->connection()->prepare("SELECT r.*,w.name warehouse_name,o.code operation_code,src.name source_location_name,dst.name receipt_location_name FROM inventory_goods_receipts r INNER JOIN inventory_warehouses w ON w.company_id=r.company_id AND w.warehouse_id=r.warehouse_id INNER JOIN inventory_operation_types o ON o.company_id=r.company_id AND o.warehouse_id=r.warehouse_id AND o.operation_type_id=r.operation_type_id INNER JOIN inventory_warehouse_locations src ON src.company_id=o.company_id AND src.location_id=o.default_source_location_id LEFT JOIN inventory_warehouse_locations dst ON dst.company_id=r.company_id AND dst.warehouse_id=r.warehouse_id AND dst.location_id=r.destination_location_id WHERE r.company_id=:company_id AND r.goods_receipt_id=:id");$s->execute(['company_id'=>$companyId,'id'=>$receiptId]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($r))return null;$l=$this->connection()->prepare("SELECT l.*,p.sku,p.name product_name,p.unit_of_measure FROM inventory_goods_receipt_lines l INNER JOIN sales_products p ON p.company_id=l.company_id AND p.product_id=l.product_id WHERE l.company_id=:company_id AND l.goods_receipt_id=:id ORDER BY l.goods_receipt_line_id");$l->execute(['company_id'=>$companyId,'id'=>$receiptId]);$r['lines']=$l->fetchAll(PDO::FETCH_ASSOC);$m=$this->connection()->prepare("SELECT m.*,src.name source_location_name,dst.name destination_location_name FROM inventory_stock_movements m LEFT JOIN inventory_warehouse_locations src ON src.company_id=m.company_id AND src.location_id=m.source_location_id LEFT JOIN inventory_warehouse_locations dst ON dst.company_id=m.company_id AND dst.location_id=m.destination_location_id WHERE m.company_id=:company_id AND m.reference_id=:id AND m.reference_type='goods_receipt' ORDER BY m.movement_id");$m->execute(['company_id'=>$companyId,'id'=>$receiptId]);$r['movements']=$m->fetchAll(PDO::FETCH_ASSOC);return$r;}

    public function createGoodsReceipt(int $companyId,array $header,array $lines,int $actorId): int
    {$c=$this->connection();$owns=!$c->inTransaction();if($owns)$c->beginTransaction();try{$warehouse=(int)($header['warehouse_id']??0);$destination=(int)($header['destination_location_id']??0);if($warehouse<1||$destination<1)throw new RuntimeException('An explicit receipt warehouse and receiving location are required; no default fallback is permitted.');$location=$c->prepare("SELECT 1 FROM inventory_warehouse_locations l INNER JOIN inventory_warehouses w ON w.company_id=l.company_id AND w.warehouse_id=l.warehouse_id WHERE l.company_id=:company_id AND l.warehouse_id=:warehouse_id AND l.location_id=:location_id AND w.active=TRUE AND w.deleted_at IS NULL AND l.active=TRUE AND l.deleted_at IS NULL AND l.receiving_allowed=TRUE AND l.location_usage='internal' AND l.is_virtual=FALSE");$location->execute(['company_id'=>$companyId,'warehouse_id'=>$warehouse,'location_id'=>$destination]);if(!$location->fetchColumn())throw new RuntimeException('The selected receipt destination is not an active receiving location in this warehouse.');$route=$c->prepare("SELECT operation_type_id FROM inventory_operation_types WHERE company_id=:company_id AND warehouse_id=:warehouse_id AND operation_kind='receipt' AND is_default=TRUE AND active=TRUE LIMIT 1");$route->execute(['company_id'=>$companyId,'warehouse_id'=>$warehouse]);$rcpt=$route->fetch(PDO::FETCH_ASSOC);if(!is_array($rcpt))throw new RuntimeException('The warehouse RCPT operation is not configured.');$number='GR-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8));$s=$c->prepare("INSERT INTO inventory_goods_receipts(company_id,warehouse_id,destination_location_id,operation_type_id,receipt_number,supplier_name,supplier_reference,purchase_order_id,receipt_date,currency,status,notes,created_by) VALUES(:company_id,:warehouse_id,:destination_location_id,:operation_type_id,:number,:supplier,:reference,:purchase_order_id,:receipt_date,:currency,'draft',:notes,:actor)");$s->execute(['company_id'=>$companyId,'warehouse_id'=>$warehouse,'destination_location_id'=>$destination,'operation_type_id'=>$rcpt['operation_type_id'],'number'=>$number,'supplier'=>$header['supplier_name'],'reference'=>$header['supplier_reference'],'purchase_order_id'=>$header['purchase_order_id']??null,'receipt_date'=>$header['receipt_date'],'currency'=>$header['currency'],'notes'=>$header['notes'],'actor'=>$actorId]);$id=(int)$c->lastInsertId();$insert=$c->prepare('INSERT INTO inventory_goods_receipt_lines(company_id,goods_receipt_id,warehouse_id,location_id,product_id,purchase_order_line_id,quantity,unit_cost,notes) VALUES(:company_id,:receipt_id,:warehouse_id,:location_id,:product_id,:purchase_order_line_id,:quantity,:unit_cost,:notes)');foreach($lines as $line)$insert->execute(['company_id'=>$companyId,'receipt_id'=>$id,'warehouse_id'=>$warehouse,'location_id'=>$destination,'product_id'=>$line['product_id'],'purchase_order_line_id'=>$line['purchase_order_line_id']??null,'quantity'=>$line['quantity'],'unit_cost'=>$line['unit_cost'],'notes'=>$line['notes']]);if($owns)$c->commit();return$id;}catch(Throwable $e){if($owns&&$c->inTransaction())$c->rollBack();throw$e;}}

    public function approveGoodsReceipt(int $companyId,int $receiptId,int $actorId,string $approvedAt): void
    {$s=$this->connection()->prepare("UPDATE inventory_goods_receipts SET status='approved',approved_by=:actor,approved_at=:approved_at WHERE company_id=:company_id AND goods_receipt_id=:id AND status IN('draft','submitted') AND created_by<>:separation_actor");$s->execute(['actor'=>$actorId,'separation_actor'=>$actorId,'approved_at'=>$approvedAt,'company_id'=>$companyId,'id'=>$receiptId]);if($s->rowCount()!==1)throw new RuntimeException('The receipt cannot be approved because it is no longer eligible or the creator cannot approve their own receipt.');}
    public function deliveryPickings(int $companyId): array
    {
        $statement = $this->connection()->prepare(
            "SELECT p.picking_id,p.picking_number,p.sales_order_id,p.backorder_of_id,
                    p.warehouse_id,p.source_location_id,p.destination_location_id,
                    p.status,p.reserved_at,p.completed_at,o.order_number,c.name customer_name,
                    w.name warehouse_name,src.name source_location_name,dst.name destination_location_name,
                    COALESCE(SUM(l.requested_quantity),0) requested_quantity,
                    COALESCE(SUM(l.reserved_quantity),0) reserved_quantity,
                    COALESCE(SUM(l.completed_quantity),0) completed_quantity
             FROM inventory_pickings p
             INNER JOIN sales_orders o ON o.company_id=p.company_id AND o.order_id=p.sales_order_id
             INNER JOIN sales_customers c ON c.company_id=o.company_id AND c.customer_id=o.customer_id
             LEFT JOIN inventory_warehouses w ON w.company_id=p.company_id AND w.warehouse_id=p.warehouse_id
             LEFT JOIN inventory_warehouse_locations src ON src.company_id=p.company_id AND src.location_id=p.source_location_id
             LEFT JOIN inventory_warehouse_locations dst ON dst.company_id=p.company_id AND dst.location_id=p.destination_location_id
             LEFT JOIN inventory_picking_lines l ON l.company_id=p.company_id AND l.picking_id=p.picking_id
             WHERE p.company_id=:company_id AND p.picking_type='delivery'
             GROUP BY p.picking_id,p.picking_number,p.sales_order_id,p.backorder_of_id,
                      p.warehouse_id,p.source_location_id,p.destination_location_id,p.status,
                      p.reserved_at,p.completed_at,o.order_number,c.name,w.name,src.name,dst.name
             ORDER BY p.picking_id DESC"
        );
        $statement->execute(['company_id'=>$companyId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deliveryPicking(int $companyId, int $pickingId): ?array
    {
        $statement=$this->connection()->prepare(
            "SELECT p.*,o.order_number,c.name customer_name,w.name warehouse_name,
                    src.name source_location_name,dst.name destination_location_name
             FROM inventory_pickings p
             INNER JOIN sales_orders o ON o.company_id=p.company_id AND o.order_id=p.sales_order_id
             INNER JOIN sales_customers c ON c.company_id=o.company_id AND c.customer_id=o.customer_id
             LEFT JOIN inventory_warehouses w ON w.company_id=p.company_id AND w.warehouse_id=p.warehouse_id
             LEFT JOIN inventory_warehouse_locations src ON src.company_id=p.company_id AND src.location_id=p.source_location_id
             LEFT JOIN inventory_warehouse_locations dst ON dst.company_id=p.company_id AND dst.location_id=p.destination_location_id
             WHERE p.company_id=:company_id AND p.picking_id=:picking_id
               AND p.picking_type IN ('delivery','customer_return')"
        );
        $statement->execute(['company_id'=>$companyId,'picking_id'=>$pickingId]);
        $picking=$statement->fetch(PDO::FETCH_ASSOC);
        if(!is_array($picking))return null;
        $lines=$this->connection()->prepare(
            "SELECT l.*,p.sku,p.name product_name,p.unit_of_measure,
                    GREATEST(l.requested_quantity-l.completed_quantity,0) remaining_quantity,
                    GREATEST(l.completed_quantity-l.returned_quantity,0) returnable_quantity
             FROM inventory_picking_lines l
             INNER JOIN sales_products p ON p.company_id=l.company_id AND p.product_id=l.product_id
             WHERE l.company_id=:company_id AND l.picking_id=:picking_id
             ORDER BY l.picking_line_id"
        );
        $lines->execute(['company_id'=>$companyId,'picking_id'=>$pickingId]);
        $picking['lines']=$lines->fetchAll(PDO::FETCH_ASSOC);
        $returns=$this->connection()->prepare(
            "SELECT picking_id,picking_number,status,created_at,completed_at
             FROM inventory_pickings
             WHERE company_id=:company_id AND original_picking_id=:picking_id
               AND picking_type='customer_return'
             ORDER BY picking_id"
        );
        $returns->execute(['company_id'=>$companyId,'picking_id'=>$pickingId]);
        $picking['returns']=$returns->fetchAll(PDO::FETCH_ASSOC);
        if(!empty($picking['original_picking_id'])){
            $original=$this->connection()->prepare(
                "SELECT picking_id,picking_number,status
                 FROM inventory_pickings
                 WHERE company_id=:company_id AND picking_id=:picking_id"
            );
            $original->execute(['company_id'=>$companyId,'picking_id'=>(int)$picking['original_picking_id']]);
            $picking['original']=$original->fetch(PDO::FETCH_ASSOC)?:null;
        }else{$picking['original']=null;}
        return $picking;
    }

    public function reserveDeliveryPicking(int $companyId,int $pickingId,int $actorId,string $reservedAt): array
    {
        $connection=$this->connection();$connection->beginTransaction();
        try{
            $header=$connection->prepare("SELECT p.*,o.branch_id FROM inventory_pickings p INNER JOIN sales_orders o ON o.company_id=p.company_id AND o.order_id=p.sales_order_id WHERE p.company_id=:company_id AND p.picking_id=:picking_id AND p.picking_type='delivery' FOR UPDATE");
            $header->execute(['company_id'=>$companyId,'picking_id'=>$pickingId]);$picking=$header->fetch(PDO::FETCH_ASSOC);
            if(!is_array($picking))throw new RuntimeException('The delivery picking was not found.');
            if((string)$picking['status']!=='waiting_stock')throw new RuntimeException('Only a delivery waiting for stock can be reserved.');
            $lines=$connection->prepare('SELECT product_id,requested_quantity quantity FROM inventory_picking_lines WHERE company_id=:company_id AND picking_id=:picking_id ORDER BY picking_line_id');
            $lines->execute(['company_id'=>$companyId,'picking_id'=>$pickingId]);$orderLines=$lines->fetchAll(PDO::FETCH_ASSOC);
            $this->reserveSalesOrder($companyId,(int)$picking['sales_order_id'],(int)$picking['warehouse_id'],(int)$picking['source_location_id'],$orderLines,$reservedAt);
            $allocations=$connection->prepare("SELECT allocations.*
                FROM inventory_sales_reservation_allocations allocations
                INNER JOIN inventory_warehouse_locations locations
                  ON locations.company_id=allocations.company_id
                 AND locations.warehouse_id=allocations.warehouse_id
                 AND locations.location_id=allocations.location_id
                WHERE allocations.company_id=:company_id
                  AND allocations.order_id=:order_id
                  AND allocations.quantity_reserved-allocations.quantity_released-allocations.quantity_fulfilled>0.0005
                  AND locations.active=TRUE
                  AND locations.deleted_at IS NULL
                  AND locations.picking_allowed=TRUE
                  AND locations.location_usage='internal'
                  AND locations.is_virtual=FALSE
                ORDER BY allocations.allocation_id");
            $allocations->execute(['company_id'=>$companyId,'order_id'=>(int)$picking['sales_order_id']]);$reserved=$allocations->fetchAll(PDO::FETCH_ASSOC);
            if($reserved===[])throw new RuntimeException('No stock quantity is available to reserve for this delivery.');
            $existingLines=$connection->prepare("SELECT picking_line_id,product_id FROM inventory_picking_lines WHERE company_id=:company_id AND picking_id=:picking_id ORDER BY picking_line_id FOR UPDATE");
            $existingLines->execute(['company_id'=>$companyId,'picking_id'=>$pickingId]);
            $availableLines=[];
            foreach($existingLines->fetchAll(PDO::FETCH_ASSOC) as $existingLine){$availableLines[(int)$existingLine['product_id']][]=(int)$existingLine['picking_line_id'];}
            $update=$connection->prepare("UPDATE inventory_picking_lines SET source_location_id=:source,destination_location_id=:destination,reservation_allocation_id=:allocation_id,requested_quantity=:quantity,reserved_quantity=:quantity2,status='ready' WHERE company_id=:company_id AND picking_id=:picking_id AND picking_line_id=:line_id");
            $insert=$connection->prepare("INSERT INTO inventory_picking_lines(company_id,picking_id,product_id,source_location_id,destination_location_id,reservation_allocation_id,requested_quantity,reserved_quantity,status) VALUES(:company_id,:picking_id,:product_id,:source,:destination,:allocation_id,:quantity,:quantity2,'ready')");
            $usedLines=[];
            foreach($reserved as $allocation){
                $quantity=(float)$allocation['quantity_reserved']-(float)$allocation['quantity_released']-(float)$allocation['quantity_fulfilled'];
                $source=(int)$allocation['location_id'];$destination=(int)$picking['destination_location_id'];
                if($source===$destination)throw new RuntimeException('Reserved stock must come from an internal warehouse location distinct from the delivery destination.');
                $productId=(int)$allocation['product_id'];$lineId=isset($availableLines[$productId])?array_shift($availableLines[$productId]):null;
                $parameters=['company_id'=>$companyId,'picking_id'=>$pickingId,'product_id'=>$productId,'source'=>$source,'destination'=>$destination,'allocation_id'=>(int)$allocation['allocation_id'],'quantity'=>$quantity,'quantity2'=>$quantity];
                if(is_int($lineId)){$updateParameters=$parameters;unset($updateParameters['product_id']);$update->execute($updateParameters+['line_id'=>$lineId]);$usedLines[]=$lineId;}else{$insert->execute($parameters);$usedLines[]=(int)$connection->lastInsertId();}
            }
            if($usedLines!==[]){$placeholders=implode(',',array_fill(0,count($usedLines),'?'));$delete=$connection->prepare("DELETE FROM inventory_picking_lines WHERE company_id=? AND picking_id=? AND picking_line_id NOT IN ($placeholders)");$delete->execute(array_merge([$companyId,$pickingId],$usedLines));}
            $connection->prepare("UPDATE inventory_pickings SET status='ready',source_location_id=:source,reserved_at=:reserved_at WHERE company_id=:company_id AND picking_id=:picking_id")->execute(['source'=>(int)$reserved[0]['location_id'],'reserved_at'=>$reservedAt,'company_id'=>$companyId,'picking_id'=>$pickingId]);
            $connection->commit();return['pickingId'=>$pickingId,'status'=>'ready','reservedQuantity'=>array_sum(array_map(static fn(array $a)=>(float)$a['quantity_reserved']-(float)$a['quantity_released']-(float)$a['quantity_fulfilled'],$reserved))];
        }catch(Throwable $e){if($connection->inTransaction())$connection->rollBack();throw $e;}
    }

    public function completeStockMovement(array $movement): array
    {
        $companyId = (int) ($movement['companyId'] ?? 0);
        $productId = (int) ($movement['productId'] ?? 0);
        $sourceWarehouseId = $this->positiveOrNull(
            $movement['sourceWarehouseId'] ?? null
        );
        $sourceLocationId = $this->positiveOrNull(
            $movement['sourceLocationId'] ?? null
        );
        $destinationWarehouseId = $this->positiveOrNull(
            $movement['destinationWarehouseId'] ?? null
        );
        $destinationLocationId = $this->positiveOrNull(
            $movement['destinationLocationId'] ?? null
        );
        $quantity = (float) ($movement['quantity'] ?? 0);
        $unitCost = (float) ($movement['unitCost'] ?? 0);
        $actorId = (int) ($movement['actorId'] ?? 0);
        $idempotencyKey = trim((string) (
            $movement['idempotencyKey'] ?? ''
        ));
        $occurredAt = (string) (
            $movement['occurredAt'] ?? date('Y-m-d H:i:s')
        );

        if ($companyId < 1 || $productId < 1 || $actorId < 1) {
            throw new RuntimeException(
                'A valid company, product and actor are required.'
            );
        }

        if ($quantity <= 0 || $unitCost < 0 || $idempotencyKey === '') {
            throw new RuntimeException(
                'Movement quantity must be positive and its key is required.'
            );
        }

        if (
            ($sourceLocationId === null) !== ($sourceWarehouseId === null)
            || ($destinationLocationId === null)
                !== ($destinationWarehouseId === null)
            || ($sourceLocationId === null && $destinationLocationId === null)
        ) {
            throw new RuntimeException(
                'Each internal movement endpoint requires a warehouse and location.'
            );
        }

        if (
            $sourceWarehouseId === $destinationWarehouseId
            && $sourceLocationId === $destinationLocationId
            && $sourceLocationId !== null
        ) {
            throw new RuntimeException(
                'Source and destination locations must be different.'
            );
        }

        $connection = $this->connection();
        $ownsTransaction = !$connection->inTransaction();

        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            $existingStatement = $connection->prepare(
                "SELECT movement_id, status, completed_quantity
                 FROM inventory_stock_movements
                 WHERE company_id = :company_id
                   AND idempotency_key = :idempotency_key
                 FOR UPDATE"
            );
            $existingStatement->execute([
                'company_id' => $companyId,
                'idempotency_key' => $idempotencyKey,
            ]);
            $existing = $existingStatement->fetch(PDO::FETCH_ASSOC);

            if (is_array($existing)) {
                if ((string) $existing['status'] !== 'completed') {
                    throw new RuntimeException(
                        'The existing movement is not in a completed state.'
                    );
                }

                if ($ownsTransaction) {
                    $connection->commit();
                }

                return [
                    'movementId' => (int) $existing['movement_id'],
                    'status' => 'completed',
                    'completedQuantity' => (float)
                        $existing['completed_quantity'],
                    'replayed' => true,
                ];
            }

            $productStatement = $connection->prepare(
                "SELECT product_id
                 FROM sales_products
                 WHERE company_id = :company_id
                   AND product_id = :product_id
                   AND deleted_at IS NULL
                 FOR UPDATE"
            );
            $productStatement->execute([
                'company_id' => $companyId,
                'product_id' => $productId,
            ]);

            if ($productStatement->fetchColumn() === false) {
                throw new RuntimeException(
                    'The product does not belong to the active company.'
                );
            }

            $sourceBalance = null;
            if ($sourceLocationId !== null && $sourceWarehouseId !== null) {
                $sourceLocation = $this->assertLocation(
                    $companyId,
                    $sourceWarehouseId,
                    $sourceLocationId,
                    true
                );
                $sourceBalance = $this->stockBalanceForUpdate(
                    $companyId,
                    $sourceWarehouseId,
                    $sourceLocationId,
                    $productId
                );

                $sourceIsInternal = in_array(
                    (string) ($sourceLocation['location_usage'] ?? 'internal'),
                    ['internal', 'transit'],
                    true
                );
                if ($sourceBalance === null && !$sourceIsInternal) {
                    $sourceBalanceId = $this->createStockBalance(
                        $companyId,
                        $sourceWarehouseId,
                        $sourceLocationId,
                        $productId
                    );
                    $sourceBalance = $this->stockBalanceForUpdate(
                        $companyId,
                        $sourceWarehouseId,
                        $sourceLocationId,
                        $productId
                    );
                }

                if ($sourceBalance === null) {
                    throw new RuntimeException(
                        'The source location has no stock for this product.'
                    );
                }

                $allowNegative = !empty(
                    $sourceBalance['allow_negative_stock']
                );
                if (
                    $sourceIsInternal
                    && !$allowNegative
                    && (float) $sourceBalance['quantity_on_hand'] + 0.0005
                        < $quantity
                ) {
                    throw new RuntimeException(
                        'The source location has insufficient stock.'
                    );
                }

                if ($sourceIsInternal) {
                    $unitCost = (float) $sourceBalance['average_unit_cost'];
                }
                $this->applyBalanceDelta(
                    $companyId,
                    (int) $sourceBalance['stock_balance_id'],
                    -$quantity,
                    $unitCost,
                    $occurredAt
                );
            }

            if (
                $destinationLocationId !== null
                && $destinationWarehouseId !== null
            ) {
                $this->assertLocation(
                    $companyId,
                    $destinationWarehouseId,
                    $destinationLocationId,
                    false
                );
                $destinationBalance = $this->stockBalanceForUpdate(
                    $companyId,
                    $destinationWarehouseId,
                    $destinationLocationId,
                    $productId
                );
                $destinationBalanceId = $destinationBalance === null
                    ? $this->createStockBalance(
                        $companyId,
                        $destinationWarehouseId,
                        $destinationLocationId,
                        $productId
                    )
                    : (int) $destinationBalance['stock_balance_id'];
                $this->applyBalanceDelta(
                    $companyId,
                    $destinationBalanceId,
                    $quantity,
                    $unitCost,
                    $occurredAt
                );
            }

            $anchorWarehouseId = $sourceWarehouseId
                ?? $destinationWarehouseId;
            $anchorLocationId = $sourceLocationId
                ?? $destinationLocationId;
            $quantityDelta = $sourceLocationId === null
                ? $quantity
                : ($destinationLocationId === null ? -$quantity : 0.0);
            $insert = $connection->prepare(
                "INSERT INTO inventory_stock_movements (
                    company_id, warehouse_id, location_id, product_id,
                    source_warehouse_id, source_location_id,
                    destination_warehouse_id, destination_location_id,
                    movement_type, requested_quantity, completed_quantity,
                    operation_type_id, status, quantity_delta, unit_cost,
                    currency, reference_type, reference_id,
                    reference_number, idempotency_key, notes, occurred_at,
                    recorded_by, completed_at, completed_by
                 ) VALUES (
                    :company_id, :warehouse_id, :location_id, :product_id,
                    :source_warehouse_id, :source_location_id,
                    :destination_warehouse_id, :destination_location_id,
                    :movement_type, :requested_quantity, :completed_quantity,
                    :operation_type_id, 'completed', :quantity_delta,
                    :unit_cost, :currency, :reference_type, :reference_id,
                    :reference_number, :idempotency_key, :notes,
                    :occurred_at, :recorded_by, :completed_at, :completed_by
                 )"
            );
            $insert->execute([
                'company_id' => $companyId,
                'warehouse_id' => $anchorWarehouseId,
                'location_id' => $anchorLocationId,
                'product_id' => $productId,
                'source_warehouse_id' => $sourceWarehouseId,
                'source_location_id' => $sourceLocationId,
                'destination_warehouse_id' => $destinationWarehouseId,
                'destination_location_id' => $destinationLocationId,
                'movement_type' => (string) ($movement['movementType'] ?? 'transfer_in'),
                'requested_quantity' => $quantity,
                'completed_quantity' => $quantity,
                'operation_type_id' => $this->positiveOrNull(
                    $movement['operationTypeId'] ?? null
                ),
                'quantity_delta' => $quantityDelta,
                'unit_cost' => $unitCost,
                'currency' => strtoupper((string) ($movement['currency'] ?? 'ETB')),
                'reference_type' => (string) ($movement['referenceType'] ?? 'manual'),
                'reference_id' => $this->positiveOrNull($movement['referenceId'] ?? null),
                'reference_number' => $movement['referenceNumber'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'notes' => $movement['notes'] ?? null,
                'occurred_at' => $occurredAt,
                'recorded_by' => $actorId,
                'completed_at' => $occurredAt,
                'completed_by' => $actorId,
            ]);
            $movementId = (int) $connection->lastInsertId();

            $signedQuantity = match ((string) ($movement['movementType'] ?? '')) {
                'receipt', 'return_in', 'adjustment_in' => $quantity,
                'fulfilment', 'return_out', 'adjustment_out', 'issue' => -$quantity,
                default => 0.0,
            };
            if (abs($signedQuantity) > 0.0005) {
                $reversalLayerId = null;
                $relatedMovementId = $this->positiveOrNull(
                    $movement['relatedMovementId'] ?? null
                );
                if ($relatedMovementId !== null) {
                    $relatedLayer = $connection->prepare(
                        'SELECT valuation_layer_id FROM inventory_valuation_layers
                         WHERE company_id=:company_id AND stock_movement_id=:movement_id'
                    );
                    $relatedLayer->execute([
                        'company_id' => $companyId,
                        'movement_id' => $relatedMovementId,
                    ]);
                    $resolved = $relatedLayer->fetchColumn();
                    $reversalLayerId = $resolved === false ? null : (int) $resolved;
                }
                $valuation = $connection->prepare(
                    'INSERT INTO inventory_valuation_layers
                        (company_id,product_id,warehouse_id,location_id,stock_movement_id,
                         movement_type,source_document_type,source_document_id,
                         source_document_reference,quantity,unit_cost,total_value,currency,
                         posting_date,reversal_of_layer_id,idempotency_key,created_by)
                     VALUES
                        (:company_id,:product_id,:warehouse_id,:location_id,:movement_id,
                         :movement_type,:source_type,:source_id,:source_reference,:quantity,
                         :unit_cost,:total_value,:currency,:posting_date,:reversal_id,
                         :idempotency_key,:created_by)'
                );
                $valuation->execute([
                    'company_id' => $companyId,
                    'product_id' => $productId,
                    'warehouse_id' => $anchorWarehouseId,
                    'location_id' => $anchorLocationId,
                    'movement_id' => $movementId,
                    'movement_type' => (string) ($movement['movementType'] ?? ''),
                    'source_type' => (string) ($movement['referenceType'] ?? 'manual'),
                    'source_id' => $this->positiveOrNull($movement['referenceId'] ?? null),
                    'source_reference' => $movement['referenceNumber'] ?? null,
                    'quantity' => $signedQuantity,
                    'unit_cost' => $unitCost,
                    'total_value' => round($signedQuantity * $unitCost, 2),
                    'currency' => strtoupper((string) ($movement['currency'] ?? 'ETB')),
                    'posting_date' => substr($occurredAt, 0, 10),
                    'reversal_id' => $reversalLayerId,
                    'idempotency_key' => 'valuation:' . $idempotencyKey,
                    'created_by' => $actorId,
                ]);
            }

            if ($ownsTransaction) {
                $connection->commit();
            }

            return [
                'movementId' => $movementId,
                'status' => 'completed',
                'completedQuantity' => $quantity,
                'replayed' => false,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    public function postGoodsReceipt(
        int $companyId,
        int $goodsReceiptId,
        int $actorId,
        string $postedAt
    ): array {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $receipt = $this->goodsReceiptForUpdate(
                $companyId,
                $goodsReceiptId
            );

            $status = (string) ($receipt['status'] ?? '');

            if ($status === 'posted') {
                $connection->commit();

                return [
                    'goodsReceiptId' => $goodsReceiptId,
                    'status' => 'posted',
                    'replayed' => true,
                    'movementCount' => 0,
                ];
            }

            if ($status !== 'approved') {
                throw new RuntimeException(
                    'Only an approved goods receipt can be posted.'
                );
            }

            $lines = $this->goodsReceiptLines(
                $companyId,
                $goodsReceiptId
            );

            if ($lines === []) {
                throw new RuntimeException(
                    'The goods receipt must contain at least one line.'
                );
            }

            $movementCount = 0;
            $valuationMovementIds = [];
            $receiptValue = 0.0;

            if (empty($receipt['destination_location_id'])) {
                throw new RuntimeException('The receipt has no explicit destination; no default fallback is permitted.');
            }
            if (!empty($receipt['purchase_order_id'])) {
                $identity=$connection->prepare('SELECT 1 FROM purchase_orders WHERE company_id=:company_id AND purchase_order_id=:purchase_order_id AND warehouse_id=:warehouse_id AND destination_location_id=:location_id');
                $identity->execute(['company_id'=>$companyId,'purchase_order_id'=>$receipt['purchase_order_id'],'warehouse_id'=>$receipt['warehouse_id'],'location_id'=>$receipt['destination_location_id']]);
                if(!$identity->fetchColumn())throw new RuntimeException('Receipt destination drift from the Purchase Order was detected.');
            }

            foreach ($lines as $line) {
                $warehouseId = (int) $line['warehouse_id'];
                $locationId = (int) $line['location_id'];
                $productId = (int) $line['product_id'];
                $quantity = (float) $line['quantity'];
                $unitCost = (float) $line['unit_cost'];
                $lineId = (int) $line['goods_receipt_line_id'];

                if($warehouseId!==(int)$receipt['warehouse_id']||$locationId!==(int)$receipt['destination_location_id'])throw new RuntimeException('Receipt line destination drift was detected.');

                if ($quantity <= 0) {
                    throw new RuntimeException(
                        'Goods receipt quantities must be positive.'
                    );
                }

                if ($unitCost < 0) {
                    throw new RuntimeException(
                        'Goods receipt unit costs cannot be negative.'
                    );
                }

                $result = $this->completeStockMovement([
                    'companyId' => $companyId,
                    'productId' => $productId,
                    'sourceWarehouseId' => $warehouseId,
                    'sourceLocationId' => (int) (
                        $receipt['default_source_location_id'] ?? 0
                    ),
                    'destinationWarehouseId' => $warehouseId,
                    'destinationLocationId' => $locationId,
                    'quantity' => $quantity,
                    'unitCost' => $unitCost,
                    'movementType' => 'receipt',
                    'operationTypeId' => (int) ($receipt['operation_type_id'] ?? 0),
                    'currency' => (string) ($receipt['currency'] ?? 'ETB'),
                    'referenceType' => 'goods_receipt',
                    'referenceId' => $goodsReceiptId,
                    'referenceNumber' => (string) ($receipt['receipt_number'] ?? ''),
                    'idempotencyKey' => sprintf(
                        'goods-receipt:%d:line:%d',
                        $goodsReceiptId,
                        $lineId
                    ),
                    'notes' => isset($line['notes'])
                        ? (string) $line['notes']
                        : null,
                    'occurredAt' => $postedAt,
                    'actorId' => $actorId,
                ]);

                if (empty($result['replayed'])) {
                    $movementCount++;
                }
                $valuationMovementIds[] = (int) $result['movementId'];
                $receiptValue += $quantity * $unitCost;
            }

            $receiptValue = round($receiptValue, 2);
            if ($receiptValue > 0) {
                $finance = new FinanceRepository();
                $accounts = $finance->ensureSystemAccounts(
                    $companyId,
                    (string) ($receipt['currency'] ?? 'ETB'),
                    $actorId
                );
                $journal = $finance->postBalancedJournal(
                    $companyId,
                    'GRNI-' . $goodsReceiptId,
                    'goods_receipt',
                    (string) $goodsReceiptId,
                    (string) ($receipt['receipt_number'] ?? ''),
                    substr($postedAt, 0, 10),
                    (string) ($receipt['currency'] ?? 'ETB'),
                    'Inventory received before supplier billing',
                    'goods-receipt-valuation-' . $companyId . '-' . $goodsReceiptId,
                    [
                        ['account_id' => $accounts['inventory_asset'], 'debit' => $receiptValue, 'credit' => 0, 'description' => 'Inventory received'],
                        ['account_id' => $accounts['goods_received_not_invoiced'], 'debit' => 0, 'credit' => $receiptValue, 'description' => 'Goods received not invoiced'],
                    ],
                    $actorId
                );
                $this->linkValuationJournal(
                    $companyId,
                    $valuationMovementIds,
                    (int) $journal['journalBatchId']
                );
            }
            if (strtoupper((string) ($receipt['currency'] ?? '')) !== $this->companyCurrency($companyId)) {
                throw new RuntimeException(
                    'Inventory receipts must use the company base currency until foreign-currency valuation is enabled.'
                );
            }

            $this->markGoodsReceiptPosted(
                $companyId,
                $goodsReceiptId,
                $actorId,
                $postedAt
            );

            if (!empty($receipt['purchase_order_id'])) {
                foreach ($lines as $line) {
                    if (empty($line['purchase_order_line_id'])) continue;
                    $update=$connection->prepare("UPDATE purchase_order_lines SET received_quantity=received_quantity+:quantity WHERE company_id=:company_id AND purchase_order_id=:purchase_order_id AND purchase_order_line_id=:line_id AND received_quantity+:required_quantity<=ordered_quantity");
                    $update->execute(['quantity'=>$line['quantity'],'required_quantity'=>$line['quantity'],'company_id'=>$companyId,'purchase_order_id'=>$receipt['purchase_order_id'],'line_id'=>$line['purchase_order_line_id']]);
                    if($update->rowCount()!==1)throw new RuntimeException('Receipt quantity exceeds the outstanding purchase order quantity.');
                }
                $connection->prepare("UPDATE purchase_orders o SET status=CASE WHEN NOT EXISTS(SELECT 1 FROM purchase_order_lines l WHERE l.company_id=o.company_id AND l.purchase_order_id=o.purchase_order_id AND l.received_quantity<l.ordered_quantity) THEN 'received' ELSE 'partially_received' END WHERE o.company_id=:company_id AND o.purchase_order_id=:purchase_order_id AND o.status IN('confirmed','partially_received')")->execute(['company_id'=>$companyId,'purchase_order_id'=>$receipt['purchase_order_id']]);
            }

            $connection->commit();

            return [
                'goodsReceiptId' => $goodsReceiptId,
                'status' => 'posted',
                'replayed' => false,
                'movementCount' => $movementCount,
            ];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    public function goodsReceiptForUpdate(
        int $companyId,
        int $goodsReceiptId
    ): array {
        $statement = $this->connection()->prepare(
            "SELECT receipts.*,
                    operation_types.default_source_location_id,
                    operation_types.default_destination_location_id
             FROM inventory_goods_receipts receipts
             INNER JOIN inventory_operation_types operation_types
                ON operation_types.company_id = receipts.company_id
               AND operation_types.warehouse_id = receipts.warehouse_id
               AND operation_types.operation_type_id = receipts.operation_type_id
             WHERE receipts.company_id = :company_id
               AND receipts.goods_receipt_id = :goods_receipt_id
             FOR UPDATE"
        );

        $statement->execute([
            'company_id' => $companyId,
            'goods_receipt_id' => $goodsReceiptId,
        ]);

        $receipt = $statement->fetch(PDO::FETCH_ASSOC);

        if ($receipt === false) {
            throw new RuntimeException('Goods receipt was not found.');
        }

        return $receipt;
    }

    public function goodsReceiptLines(
        int $companyId,
        int $goodsReceiptId
    ): array {
        $statement = $this->connection()->prepare(
            "SELECT
                goods_receipt_line_id,
                warehouse_id,
                location_id,
                product_id,
                purchase_order_line_id,
                quantity,
                unit_cost,
                line_value,
                notes
             FROM inventory_goods_receipt_lines
             WHERE company_id = :company_id
               AND goods_receipt_id = :goods_receipt_id
             ORDER BY goods_receipt_line_id"
        );

        $statement->execute([
            'company_id' => $companyId,
            'goods_receipt_id' => $goodsReceiptId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function stockBalanceForUpdate(
        int $companyId,
        int $warehouseId,
        int $locationId,
        int $productId
    ): ?array {
        $statement = $this->connection()->prepare(
            "SELECT balances.*, warehouses.allow_negative_stock,
                    locations.location_usage
             FROM inventory_stock_balances balances
             INNER JOIN inventory_warehouses warehouses
               ON warehouses.company_id = balances.company_id
               AND warehouses.warehouse_id = balances.warehouse_id
             INNER JOIN inventory_warehouse_locations locations
                ON locations.company_id = balances.company_id
               AND locations.warehouse_id = balances.warehouse_id
               AND locations.location_id = balances.location_id
             WHERE balances.company_id = :company_id
               AND balances.warehouse_id = :warehouse_id
               AND balances.location_id = :location_id
               AND balances.product_id = :product_id
             FOR UPDATE"
        );

        $statement->execute([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
            'product_id' => $productId,
        ]);

        $balance = $statement->fetch(PDO::FETCH_ASSOC);

        return $balance === false ? null : $balance;
    }

    public function createStockBalance(
        int $companyId,
        int $warehouseId,
        int $locationId,
        int $productId
    ): int {
        $statement = $this->connection()->prepare(
            "INSERT INTO inventory_stock_balances (
                company_id,
                warehouse_id,
                location_id,
                product_id
             ) VALUES (
                :company_id,
                :warehouse_id,
                :location_id,
                :product_id
             )"
        );

        $statement->execute([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
            'product_id' => $productId,
        ]);

        return (int) $this->connection()->lastInsertId();
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public function reserveSalesOrder(
        int $companyId,
        int $orderId,
        int $warehouseId,
        int $sourceLocationId,
        array $lines,
        string $reservedAt
    ): array {
        if ($companyId <= 0 || $orderId <= 0) {
            throw new RuntimeException(
                'A valid company and sales order are required.'
            );
        }

        $normalized = [];

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $quantity = (float) ($line['quantity'] ?? 0);

            if ($productId <= 0 || $quantity <= 0) {
                throw new RuntimeException(
                    'Sales reservation lines require valid products and positive quantities.'
                );
            }

            $normalized[$productId] =
                ($normalized[$productId] ?? 0.0)
                + $quantity;
        }

        if ($normalized === []) {
            throw new RuntimeException(
                'The sales order must contain at least one reservable line.'
            );
        }

        ksort($normalized);

        $connection = $this->connection();
        $ownsTransaction = !$connection->inTransaction();

        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            $orderStatement = $connection->prepare(
                "SELECT order_id, branch_id, warehouse_id, source_location_id, status
                 FROM sales_orders
                 WHERE company_id = :company_id
                   AND order_id = :order_id
                   AND deleted_at IS NULL
                 FOR UPDATE"
            );
            $orderStatement->execute([
                'company_id' => $companyId,
                'order_id' => $orderId,
            ]);
            $order = $orderStatement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($order)) {
                throw new RuntimeException(
                    'The sales order was not found in the current company.'
                );
            }

            if ($warehouseId <= 0 || $sourceLocationId <= 0
                || (int) ($order['warehouse_id'] ?? 0) !== $warehouseId
                || (int) ($order['source_location_id'] ?? 0) !== $sourceLocationId) {
                throw new RuntimeException('The reservation source must exactly match the Sales Order warehouse and source location.');
            }

            $existingStatement = $connection->prepare(
                "SELECT
                    commitment_id,
                    product_id,
                    quantity,
                    status
                 FROM inventory_sales_commitments
                 WHERE company_id = :company_id
                   AND order_id = :order_id
                 ORDER BY product_id
                 FOR UPDATE"
            );
            $existingStatement->execute([
                'company_id' => $companyId,
                'order_id' => $orderId,
            ]);
            $existing = $existingStatement->fetchAll(
                PDO::FETCH_ASSOC
            );
            $releasedCommitments = [];

            if ($existing !== []) {
                $existingQuantities = [];
                $allReserved = true;
                $allReleased = true;

                foreach ($existing as $commitment) {
                    $existingQuantities[
                        (int) $commitment['product_id']
                    ] = (float) $commitment['quantity'];

                    if (
                        ($commitment['status'] ?? '')
                        !== 'reserved'
                    ) {
                        $allReserved = false;
                    }
                    if (($commitment['status'] ?? '') !== 'released') {
                        $allReleased = false;
                    }
                    $releasedCommitments[(int) $commitment['product_id']] =
                        (int) $commitment['commitment_id'];
                }

                ksort($existingQuantities);

                $same = $allReserved
                    && count($existingQuantities)
                        === count($normalized);

                if ($same) {
                    foreach ($normalized as $productId => $quantity) {
                        if (
                            !isset($existingQuantities[$productId])
                            || abs(
                                $existingQuantities[$productId]
                                - $quantity
                            ) > 0.0005
                        ) {
                            $same = false;
                            break;
                        }
                    }
                }

                if ($same) {
                    $sourceMismatch=$connection->prepare("SELECT COUNT(*) FROM inventory_sales_reservation_allocations WHERE company_id=:company_id AND order_id=:order_id AND status IN('reserved','partially_released','partially_fulfilled') AND (warehouse_id<>:warehouse_id OR location_id<>:location_id)");
                    $sourceMismatch->execute(['company_id'=>$companyId,'order_id'=>$orderId,'warehouse_id'=>$warehouseId,'location_id'=>$sourceLocationId]);
                    if((int)$sourceMismatch->fetchColumn()>0)throw new RuntimeException('The existing reservation does not match the selected Sales Order fulfillment source. Release it before confirming.');
                    $allocationCountStatement =
                        $connection->prepare(
                            "SELECT COUNT(*)
                             FROM inventory_sales_reservation_allocations
                             WHERE company_id = :company_id
                               AND order_id = :order_id
                               AND status IN (
                                    'reserved',
                                    'partially_released',
                                    'partially_fulfilled'
                               )"
                        );
                    $allocationCountStatement->execute([
                        'company_id' => $companyId,
                        'order_id' => $orderId,
                    ]);

                    if ($ownsTransaction) {
                        $connection->commit();
                    }

                    return [
                        'orderId' => $orderId,
                        'status' => 'reserved',
                        'replayed' => true,
                        'commitmentCount' =>
                            count($existing),
                        'allocationCount' => (int)
                            $allocationCountStatement
                                ->fetchColumn(),
                        'totalReserved' =>
                            array_sum($normalized),
                    ];
                }

                if (!$allReleased || count($existingQuantities) !== count($normalized)) {
                    throw new RuntimeException(
                        'The sales order already has a different inventory reservation. Release it before reserving again.'
                    );
                }
                foreach ($normalized as $productId => $quantity) {
                    if (!isset($existingQuantities[$productId]) || abs($existingQuantities[$productId] - $quantity) > 0.0005) {
                        throw new RuntimeException(
                            'The released inventory reservation does not match the current sales order quantities.'
                        );
                    }
                }
            }

            $warehouseStatement = $connection->prepare(
                "SELECT w.warehouse_id,w.allow_negative_stock
                 FROM inventory_warehouses w
                 INNER JOIN inventory_warehouse_locations l
                   ON l.company_id=w.company_id AND l.warehouse_id=w.warehouse_id
                 WHERE w.company_id=:company_id AND w.warehouse_id=:warehouse_id
                   AND l.location_id=:location_id
                   AND w.active=TRUE AND w.deleted_at IS NULL
                   AND l.active=TRUE AND l.deleted_at IS NULL
                   AND l.picking_allowed=TRUE AND l.location_usage='internal' AND l.is_virtual=FALSE
                 FOR UPDATE"
            );
            $warehouseStatement->execute([
                'company_id' => $companyId,
                'warehouse_id' => $warehouseId,
                'location_id' => $sourceLocationId,
            ]);
            $warehouse = $warehouseStatement->fetch(
                PDO::FETCH_ASSOC
            );

            if (!is_array($warehouse)) {
                throw new RuntimeException(
                    'No active fulfilment warehouse is configured for this sales order.'
                );
            }

            $allowNegative = !empty(
                $warehouse['allow_negative_stock']
            );

            // Quick Sales must be backed by available stock even at warehouses
            // that permit negative stock for other workflows. Candidate balances
            // below are locked FOR UPDATE before availability is consumed.
            $quickSale = $connection->prepare('SELECT COUNT(*) FROM sales_quick_sales qs
                INNER JOIN sales_quotations q ON q.company_id=qs.company_id AND q.quotation_id=qs.quotation_id
                WHERE qs.company_id=? AND q.sales_order_id=?');
            $quickSale->execute([$companyId, $orderId]);
            if ((int) $quickSale->fetchColumn() > 0) $allowNegative = false;

            $commitmentInsert = $connection->prepare(
                "INSERT INTO inventory_sales_commitments (
                    company_id,
                    order_id,
                    product_id,
                    quantity,
                    status,
                    reserved_at
                 ) VALUES (
                    :company_id,
                    :order_id,
                    :product_id,
                    :quantity,
                    'reserved',
                    :reserved_at
                 )"
            );
            $commitmentReuse = $connection->prepare(
                "UPDATE inventory_sales_commitments
                 SET status='reserved', reserved_at=:reserved_at,
                     released_at=NULL
                 WHERE company_id=:company_id
                   AND commitment_id=:commitment_id"
            );

            $balanceUpdate = $connection->prepare(
                "UPDATE inventory_stock_balances
                 SET quantity_reserved =
                        quantity_reserved + :quantity,
                     version_number =
                        version_number + 1
                 WHERE company_id = :company_id
                   AND stock_balance_id =
                        :stock_balance_id"
            );

            $allocationInsert = $connection->prepare(
                "INSERT INTO inventory_sales_reservation_allocations (
                    company_id,
                    commitment_id,
                    order_id,
                    product_id,
                    warehouse_id,
                    location_id,
                    stock_balance_id,
                    quantity_reserved,
                    status,
                    reserved_at
                 ) VALUES (
                    :company_id,
                    :commitment_id,
                    :order_id,
                    :product_id,
                    :warehouse_id,
                    :location_id,
                    :stock_balance_id,
                    :quantity_reserved,
                    'reserved',
                    :reserved_at
                 ) ON DUPLICATE KEY UPDATE
                    location_id=VALUES(location_id),
                    quantity_reserved=VALUES(quantity_reserved),
                    quantity_released=0,
                    quantity_fulfilled=0,
                    status='reserved',
                    reserved_at=VALUES(reserved_at),
                    released_at=NULL,
                    fulfilled_at=NULL"
            );

            $commitmentCount = 0;
            $allocationCount = 0;
            $totalReserved = 0.0;

            foreach ($normalized as $productId => $required) {
                if (isset($releasedCommitments[$productId])) {
                    $commitmentId = $releasedCommitments[$productId];
                    $commitmentReuse->execute([
                        'reserved_at' => $reservedAt,
                        'company_id' => $companyId,
                        'commitment_id' => $commitmentId,
                    ]);
                } else {
                    $commitmentInsert->execute([
                        'company_id' => $companyId,
                        'order_id' => $orderId,
                        'product_id' => $productId,
                        'quantity' => $required,
                        'reserved_at' => $reservedAt,
                    ]);

                    $commitmentId = (int)
                        $connection->lastInsertId();
                }
                $commitmentCount++;
                $remaining = $required;

                $candidateStatement = $connection->prepare(
                    "SELECT
                        balances.stock_balance_id,
                        balances.location_id,
                        balances.quantity_available,
                        locations.pick_priority
                     FROM inventory_stock_balances balances
                     INNER JOIN inventory_warehouse_locations locations
                        ON locations.company_id =
                            balances.company_id
                       AND locations.warehouse_id =
                            balances.warehouse_id
                       AND locations.location_id =
                            balances.location_id
                     WHERE balances.company_id =
                            :company_id
                       AND balances.warehouse_id =
                            :warehouse_id
                       AND balances.location_id = :location_id
                       AND balances.product_id =
                            :product_id
                       AND locations.active = TRUE
                       AND locations.deleted_at IS NULL
                       AND locations.picking_allowed = TRUE
                       AND locations.location_usage = 'internal'
                       AND locations.is_virtual = FALSE
                       AND (
                            balances.quantity_available > 0
                            OR :allow_negative = 1
                       )
                     ORDER BY balances.stock_balance_id
                     FOR UPDATE"
                );
                $candidateStatement->execute([
                    'company_id' => $companyId,
                    'warehouse_id' => $warehouseId,
                    'location_id' => $sourceLocationId,
                    'product_id' => $productId,
                    'allow_negative' =>
                        $allowNegative ? 1 : 0,
                ]);
                $candidates = $candidateStatement->fetchAll(
                    PDO::FETCH_ASSOC
                );

                if($candidates===[]&&$allowNegative){
                    $connection->prepare('INSERT IGNORE INTO inventory_stock_balances(company_id,warehouse_id,location_id,product_id) VALUES(:company_id,:warehouse_id,:location_id,:product_id)')->execute(['company_id'=>$companyId,'warehouse_id'=>$warehouseId,'location_id'=>$sourceLocationId,'product_id'=>$productId]);
                    $candidateStatement->execute(['company_id'=>$companyId,'warehouse_id'=>$warehouseId,'location_id'=>$sourceLocationId,'product_id'=>$productId,'allow_negative'=>1]);
                    $candidates=$candidateStatement->fetchAll(PDO::FETCH_ASSOC);
                }

                if ($candidates === []) {
                    throw new RuntimeException(
                        sprintf(
                            'No picking stock is available for product %d.',
                            $productId
                        )
                    );
                }

                foreach ($candidates as $candidate) {
                    if ($remaining <= 0.0005) {
                        break;
                    }

                    $available = max(
                        0.0,
                        (float) $candidate[
                            'quantity_available'
                        ]
                    );

                    $allocation = $allowNegative
                        ? $remaining
                        : min($remaining, $available);

                    if ($allocation <= 0.0005) {
                        continue;
                    }

                    $balanceUpdate->execute([
                        'quantity' => $allocation,
                        'company_id' => $companyId,
                        'stock_balance_id' => (int)
                            $candidate[
                                'stock_balance_id'
                            ],
                    ]);

                    if ($balanceUpdate->rowCount() !== 1) {
                        throw new RuntimeException(
                            'The inventory reservation balance could not be updated.'
                        );
                    }

                    $allocationInsert->execute([
                        'company_id' => $companyId,
                        'commitment_id' => $commitmentId,
                        'order_id' => $orderId,
                        'product_id' => $productId,
                        'warehouse_id' => $warehouseId,
                        'location_id' => (int)
                            $candidate['location_id'],
                        'stock_balance_id' => (int)
                            $candidate[
                                'stock_balance_id'
                            ],
                        'quantity_reserved' =>
                            $allocation,
                        'reserved_at' => $reservedAt,
                    ]);

                    $allocationCount++;
                    $totalReserved += $allocation;
                    $remaining -= $allocation;

                    if ($allowNegative) {
                        break;
                    }
                }

                if ($remaining > 0.0005) {
                    throw new RuntimeException(
                        sprintf(
                            'Insufficient available stock for product %d. Missing quantity: %.3f.',
                            $productId,
                            $remaining
                        )
                    );
                }
            }

            if ($ownsTransaction) {
                $connection->commit();
            }

            return [
                'orderId' => $orderId,
                'status' => 'reserved',
                'replayed' => false,
                'commitmentCount' => $commitmentCount,
                'allocationCount' => $allocationCount,
                'totalReserved' => $totalReserved,
            ];
        } catch (Throwable $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function releaseSalesOrderReservation(
        int $companyId,
        int $orderId,
        string $releasedAt
    ): array {
        if ($companyId <= 0 || $orderId <= 0) {
            throw new RuntimeException(
                'A valid company and sales order are required.'
            );
        }

        $connection = $this->connection();
        $ownsTransaction = !$connection->inTransaction();

        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            $allocationsStatement = $connection->prepare(
                "SELECT
                    allocations.allocation_id,
                    allocations.commitment_id,
                    allocations.stock_balance_id,
                    allocations.quantity_reserved,
                    allocations.quantity_released,
                    allocations.quantity_fulfilled,
                    allocations.status
                 FROM inventory_sales_reservation_allocations allocations
                 WHERE allocations.company_id = :company_id
                   AND allocations.order_id = :order_id
                 ORDER BY
                    allocations.stock_balance_id,
                    allocations.allocation_id
                 FOR UPDATE"
            );
            $allocationsStatement->execute([
                'company_id' => $companyId,
                'order_id' => $orderId,
            ]);
            $allocations = $allocationsStatement->fetchAll(
                PDO::FETCH_ASSOC
            );

            if ($allocations === []) {
                if ($ownsTransaction) {
                    $connection->commit();
                }

                return [
                    'orderId' => $orderId,
                    'status' => 'released',
                    'replayed' => true,
                    'allocationCount' => 0,
                    'totalReleased' => 0.0,
                ];
            }

            $balanceLock = $connection->prepare(
                "SELECT
                    stock_balance_id,
                    quantity_reserved
                 FROM inventory_stock_balances
                 WHERE company_id = :company_id
                   AND stock_balance_id = :stock_balance_id
                 FOR UPDATE"
            );

            $balanceUpdate = $connection->prepare(
                "UPDATE inventory_stock_balances
                 SET quantity_reserved =
                        quantity_reserved - :quantity,
                     version_number = version_number + 1
                 WHERE company_id = :company_id
                   AND stock_balance_id = :stock_balance_id
                   AND quantity_reserved >= :required_quantity"
            );

            $allocationUpdate = $connection->prepare(
                "UPDATE inventory_sales_reservation_allocations
                 SET quantity_released =
                        quantity_released + :quantity,
                     status = CASE
                        WHEN quantity_released
                             + :quantity_for_status
                             + quantity_fulfilled
                             >= quantity_reserved
                            THEN 'released'
                        ELSE 'partially_released'
                     END,
                     released_at = :released_at
                 WHERE company_id = :company_id
                   AND allocation_id = :allocation_id"
            );

            $releasedCount = 0;
            $totalReleased = 0.0;
            $commitmentIds = [];

            foreach ($allocations as $allocation) {
                $reserved = (float)
                    $allocation['quantity_reserved'];
                $released = (float)
                    $allocation['quantity_released'];
                $fulfilled = (float)
                    $allocation['quantity_fulfilled'];
                $outstanding = max(
                    0.0,
                    $reserved - $released - $fulfilled
                );

                $commitmentIds[
                    (int) $allocation['commitment_id']
                ] = true;

                if ($outstanding <= 0.0005) {
                    continue;
                }

                $stockBalanceId = (int)
                    $allocation['stock_balance_id'];

                $balanceLock->execute([
                    'company_id' => $companyId,
                    'stock_balance_id' => $stockBalanceId,
                ]);

                if (!is_array(
                    $balanceLock->fetch(PDO::FETCH_ASSOC)
                )) {
                    throw new RuntimeException(
                        'A reserved inventory balance no longer exists.'
                    );
                }

                $balanceUpdate->execute([
                    'quantity' => $outstanding,
                    'company_id' => $companyId,
                    'stock_balance_id' => $stockBalanceId,
                    'required_quantity' => $outstanding,
                ]);

                if ($balanceUpdate->rowCount() !== 1) {
                    throw new RuntimeException(
                        'The reserved stock quantity could not be released safely.'
                    );
                }

                $allocationUpdate->execute([
                    'quantity' => $outstanding,
                    'quantity_for_status' => $outstanding,
                    'released_at' => $releasedAt,
                    'company_id' => $companyId,
                    'allocation_id' => (int)
                        $allocation['allocation_id'],
                ]);

                $releasedCount++;
                $totalReleased += $outstanding;
            }

            $commitmentUpdate = $connection->prepare(
                "UPDATE inventory_sales_commitments
                 SET status = 'released',
                     released_at = :released_at
                 WHERE company_id = :company_id
                   AND commitment_id = :commitment_id
                   AND status NOT IN (
                        'fulfilled',
                        'cancelled'
                   )"
            );

            foreach (array_keys($commitmentIds) as $commitmentId) {
                $commitmentUpdate->execute([
                    'released_at' => $releasedAt,
                    'company_id' => $companyId,
                    'commitment_id' => $commitmentId,
                ]);
            }

            if ($ownsTransaction) {
                $connection->commit();
            }

            return [
                'orderId' => $orderId,
                'status' => 'released',
                'replayed' => $releasedCount === 0,
                'allocationCount' => $releasedCount,
                'totalReleased' => $totalReleased,
            ];
        } catch (Throwable $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function fulfilSalesOrder(
        int $companyId,
        int $orderId,
        int $actorId,
        string $fulfilledAt
    ): array {
        if (
            $companyId <= 0
            || $orderId <= 0
            || $actorId <= 0
        ) {
            throw new RuntimeException(
                'A valid company, sales order and posting user are required.'
            );
        }

        $connection = $this->connection();
        $ownsTransaction = !$connection->inTransaction();

        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            $orderStatement = $connection->prepare(
                "SELECT
                    order_number,
                    currency
                 FROM sales_orders
                 WHERE company_id = :company_id
                   AND order_id = :order_id
                   AND deleted_at IS NULL
                 FOR UPDATE"
            );
            $orderStatement->execute([
                'company_id' => $companyId,
                'order_id' => $orderId,
            ]);
            $order = $orderStatement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($order)) {
                throw new RuntimeException(
                    'The sales order was not found.'
                );
            }

            $allocationsStatement = $connection->prepare(
                "SELECT
                    allocations.allocation_id,
                    allocations.commitment_id,
                    allocations.product_id,
                    allocations.warehouse_id,
                    allocations.location_id,
                    allocations.stock_balance_id,
                    allocations.quantity_reserved,
                    allocations.quantity_released,
                    allocations.quantity_fulfilled,
                    warehouses.allow_negative_stock
                 FROM inventory_sales_reservation_allocations allocations
                 INNER JOIN inventory_warehouses warehouses
                    ON warehouses.company_id =
                        allocations.company_id
                   AND warehouses.warehouse_id =
                        allocations.warehouse_id
                 WHERE allocations.company_id = :company_id
                   AND allocations.order_id = :order_id
                 ORDER BY
                    allocations.stock_balance_id,
                    allocations.allocation_id
                 FOR UPDATE"
            );
            $allocationsStatement->execute([
                'company_id' => $companyId,
                'order_id' => $orderId,
            ]);
            $allocations = $allocationsStatement->fetchAll(
                PDO::FETCH_ASSOC
            );

            if ($allocations === []) {
                throw new RuntimeException(
                    'The sales order has no inventory reservation to fulfil.'
                );
            }

            $balanceStatement = $connection->prepare(
                "SELECT
                    stock_balance_id,
                    quantity_on_hand,
                    quantity_reserved,
                    average_unit_cost
                 FROM inventory_stock_balances
                 WHERE company_id = :company_id
                   AND stock_balance_id = :stock_balance_id
                 FOR UPDATE"
            );

            $reservationBalanceUpdate = $connection->prepare(
                "UPDATE inventory_stock_balances
                 SET quantity_reserved =
                        quantity_reserved - :reserved_quantity,
                     version_number = version_number + 1
                 WHERE company_id = :company_id
                   AND stock_balance_id = :stock_balance_id
                   AND quantity_reserved >= :required_reserved"
            );

            $allocationUpdate = $connection->prepare(
                "UPDATE inventory_sales_reservation_allocations
                 SET quantity_fulfilled =
                        quantity_fulfilled + :quantity,
                     status = CASE
                        WHEN quantity_fulfilled
                             + :quantity_for_status
                             + quantity_released
                             >= quantity_reserved
                            THEN 'fulfilled'
                        ELSE 'partially_fulfilled'
                     END,
                     fulfilled_at = :fulfilled_at
                 WHERE company_id = :company_id
                   AND allocation_id = :allocation_id"
            );

            $movementCount = 0;
            $totalFulfilled = 0.0;
            $inventoryCost = 0.0;
            $commitmentIds = [];

            foreach ($allocations as $allocation) {
                $reserved = (float)
                    $allocation['quantity_reserved'];
                $released = (float)
                    $allocation['quantity_released'];
                $alreadyFulfilled = (float)
                    $allocation['quantity_fulfilled'];
                $quantity = max(
                    0.0,
                    $reserved
                    - $released
                    - $alreadyFulfilled
                );

                $commitmentIds[
                    (int) $allocation['commitment_id']
                ] = true;

                if ($quantity <= 0.0005) {
                    continue;
                }

                $balanceStatement->execute([
                    'company_id' => $companyId,
                    'stock_balance_id' => (int)
                        $allocation['stock_balance_id'],
                ]);
                $balance = $balanceStatement->fetch(
                    PDO::FETCH_ASSOC
                );

                if (!is_array($balance)) {
                    throw new RuntimeException(
                        'A reserved inventory balance no longer exists.'
                    );
                }

                $unitCost = (float)
                    $balance['average_unit_cost'];

                $movement = $this->completeStockMovement([
                    'companyId' => $companyId,
                    'productId' => (int) $allocation['product_id'],
                    'sourceWarehouseId' => (int) $allocation['warehouse_id'],
                    'sourceLocationId' => (int) $allocation['location_id'],
                    'destinationWarehouseId' => null,
                    'destinationLocationId' => null,
                    'quantity' => $quantity,
                    'unitCost' => $unitCost,
                    'movementType' => 'fulfilment',
                    'currency' => (string) ($order['currency'] ?? 'ETB'),
                    'referenceType' => 'sales_order',
                    'referenceId' => $orderId,
                    'referenceNumber' => (string) ($order['order_number'] ?? ''),
                    'idempotencyKey' => sprintf(
                        'sales-order:%d:allocation:%d:fulfilment',
                        $orderId,
                        (int) $allocation['allocation_id']
                    ),
                    'notes' => 'Sales order inventory fulfilment',
                    'occurredAt' => $fulfilledAt,
                    'actorId' => $actorId,
                ]);

                $reservationBalanceUpdate->execute([
                    'reserved_quantity' => $quantity,
                    'company_id' => $companyId,
                    'stock_balance_id' => (int)
                        $allocation['stock_balance_id'],
                    'required_reserved' => $quantity,
                ]);

                if ($reservationBalanceUpdate->rowCount() !== 1) {
                    throw new RuntimeException(
                        'The reserved stock could not be fulfilled safely.'
                    );
                }

                $allocationUpdate->execute([
                    'quantity' => $quantity,
                    'quantity_for_status' => $quantity,
                    'fulfilled_at' => $fulfilledAt,
                    'company_id' => $companyId,
                    'allocation_id' => (int)
                        $allocation['allocation_id'],
                ]);

                if (empty($movement['replayed'])) {
                    $movementCount++;
                }
                $totalFulfilled += $quantity;
                $inventoryCost += $quantity * $unitCost;
            }

            $commitmentUpdate = $connection->prepare(
                "UPDATE inventory_sales_commitments
                 SET status = 'fulfilled',
                     fulfilled_at = :fulfilled_at
                 WHERE company_id = :company_id
                   AND commitment_id = :commitment_id
                   AND status <> 'cancelled'"
            );

            foreach (array_keys($commitmentIds) as $commitmentId) {
                $commitmentUpdate->execute([
                    'fulfilled_at' => $fulfilledAt,
                    'company_id' => $companyId,
                    'commitment_id' => $commitmentId,
                ]);
            }

            if ($movementCount > 0) {
                $this->enqueueIntegrationEvent(
                    $connection,
                    $companyId,
                    'inventory.sales-order.fulfilled',
                    'sales_order',
                    (string) $orderId,
                    [
                        'order_id' => $orderId,
                        'actor_id' => $actorId,
                        'inventory_cost' =>
                            round($inventoryCost, 2),
                        'fulfilled_at' => $fulfilledAt,
                    ]
                );
            }

            if ($ownsTransaction) {
                $connection->commit();
            }

            return [
                'orderId' => $orderId,
                'status' => 'fulfilled',
                'replayed' => $movementCount === 0,
                'movementCount' => $movementCount,
                'totalFulfilled' => $totalFulfilled,
                'inventoryCost' => $inventoryCost,
            ];
        } catch (Throwable $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function enqueueIntegrationEvent(
        PDO $connection,
        int $companyId,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload
    ): void {
        $eventId = sprintf(
            '%s-%s-4%s-%s%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            dechex(random_int(8, 11)),
            bin2hex(random_bytes(1)),
            bin2hex(random_bytes(6))
        );

        $statement = $connection->prepare(
            "INSERT INTO integration_outbox (
                event_id,
                company_id,
                event_type,
                aggregate_type,
                aggregate_id,
                payload_json,
                status,
                available_at
             ) VALUES (
                :event_id,
                :company_id,
                :event_type,
                :aggregate_type,
                :aggregate_id,
                :payload_json,
                'pending',
                NOW()
             )"
        );

        $statement->execute([
            'event_id' => $eventId,
            'company_id' => $companyId,
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload_json' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
            ),
        ]);
    }

    public function ensureDeliveryPickings(
        int $companyId,
        int $orderId,
        int $actorId,
        string $createdAt
    ): array {
        $connection = $this->connection();
        $ownsTransaction = !$connection->inTransaction();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }
        try {
            $existing = $connection->prepare(
                "SELECT picking_id FROM inventory_pickings
                 WHERE company_id = :company_id
                   AND sales_order_id = :order_id
                   AND picking_type = 'delivery'
                   AND backorder_of_id IS NULL
                 ORDER BY picking_id"
            );
            $existing->execute(['company_id' => $companyId, 'order_id' => $orderId]);
            $existingIds = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));
            if ($existingIds !== []) {
                if ($ownsTransaction) {
                    $connection->commit();
                }
                return $existingIds;
            }

            $allocations = $connection->prepare(
                "SELECT allocations.*
                 FROM inventory_sales_reservation_allocations allocations
                 WHERE allocations.company_id = :company_id
                   AND allocations.order_id = :order_id
                   AND allocations.quantity_reserved
                       - allocations.quantity_released
                       - allocations.quantity_fulfilled > 0.0005
                 ORDER BY allocations.warehouse_id, allocations.allocation_id
                 FOR UPDATE"
            );
            $allocations->execute(['company_id' => $companyId, 'order_id' => $orderId]);
            $rows = $allocations->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                throw new RuntimeException('The Sales Order has no exact-location reservation from which to create a delivery.');
            }

            $orderSource=$connection->prepare('SELECT warehouse_id,source_location_id FROM sales_orders WHERE company_id=:company_id AND order_id=:order_id FOR UPDATE');
            $orderSource->execute(['company_id'=>$companyId,'order_id'=>$orderId]);
            $selected=$orderSource->fetch(PDO::FETCH_ASSOC);
            if(!is_array($selected)||(int)($selected['warehouse_id']??0)<=0||(int)($selected['source_location_id']??0)<=0){
                throw new RuntimeException('The Sales Order has no explicit fulfillment source.');
            }
            foreach($rows as $row){
                if((int)$row['warehouse_id']!==(int)$selected['warehouse_id']||(int)$row['location_id']!==(int)$selected['source_location_id']){
                    throw new RuntimeException('Reservation metadata does not match the Sales Order fulfillment source.');
                }
            }

            $groups = [];
            foreach ($rows as $row) {
                $groups[(int) $row['warehouse_id']][] = $row;
            }
            if(count($groups)!==1){
                throw new RuntimeException('A Sales Order delivery cannot be split across warehouses.');
            }
            $ids = [];
            foreach ($groups as $warehouseId => $lines) {
                $first = $lines[0];
                $operationType = $this->defaultOperationType(
                    $companyId,
                    $warehouseId,
                    'delivery'
                );
                $customerLocation = $this->virtualLocation(
                    $companyId,
                    $warehouseId,
                    'customer'
                );
                $number = sprintf('DLV-%d-%d-%s', $orderId, $warehouseId, substr(hash('sha256', $companyId . ':' . $orderId . ':' . $warehouseId), 0, 8));
                $header = $connection->prepare(
                    "INSERT INTO inventory_pickings (
                        company_id, warehouse_id, operation_type_id,
                        sales_order_id, picking_type, picking_number,
                        source_location_id, destination_location_id,
                        status, reserved_at, created_by
                     ) VALUES (
                        :company_id, :warehouse_id, :operation_type_id,
                        :sales_order_id, 'delivery', :picking_number,
                        :source_location_id, :destination_location_id,
                        'ready', :reserved_at, :created_by
                     )"
                );
                $header->execute([
                    'company_id' => $companyId,
                    'warehouse_id' => $warehouseId,
                    'operation_type_id' => (int) $operationType['operation_type_id'],
                    'sales_order_id' => $orderId,
                    'picking_number' => $number,
                    'source_location_id' => (int) $first['location_id'],
                    'destination_location_id' => (int) $customerLocation['location_id'],
                    'reserved_at' => $createdAt,
                    'created_by' => $this->positiveOrNull($actorId),
                ]);
                $pickingId = (int) $connection->lastInsertId();
                $ids[] = $pickingId;
                $lineInsert = $connection->prepare(
                    "INSERT INTO inventory_picking_lines (
                        company_id, picking_id, product_id,
                        source_location_id, destination_location_id,
                        reservation_allocation_id, requested_quantity,
                        reserved_quantity, status
                     ) VALUES (
                        :company_id, :picking_id, :product_id,
                        :source_location_id, :destination_location_id,
                        :allocation_id, :requested_quantity,
                        :reserved_quantity, 'ready'
                     )"
                );
                foreach ($lines as $line) {
                    $remaining = (float) $line['quantity_reserved']
                        - (float) $line['quantity_released']
                        - (float) $line['quantity_fulfilled'];
                    if ($remaining <= 0.0005) {
                        continue;
                    }
                    $lineInsert->execute([
                        'company_id' => $companyId,
                        'picking_id' => $pickingId,
                        'product_id' => (int) $line['product_id'],
                        'source_location_id' => (int) $line['location_id'],
                        'destination_location_id' => (int) $customerLocation['location_id'],
                        'allocation_id' => (int) $line['allocation_id'],
                        'requested_quantity' => $remaining,
                        'reserved_quantity' => $remaining,
                    ]);
                }
            }
            if ($ownsTransaction) {
                $connection->commit();
            }
            return $ids;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    public function completePicking(
        int $companyId,
        int $pickingId,
        array $quantities,
        bool $createBackorder,
        string $idempotencyKey,
        int $actorId,
        string $completedAt
    ): array {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $replay = $connection->prepare(
                "SELECT completed_quantity, backorder_picking_id
                 FROM inventory_picking_completions
                 WHERE company_id = :company_id AND idempotency_key = :idempotency_key
                 FOR UPDATE"
            );
            $replay->execute(['company_id' => $companyId, 'idempotency_key' => $idempotencyKey]);
            $prior = $replay->fetch(PDO::FETCH_ASSOC);
            if (is_array($prior)) {
                $connection->commit();
                return ['pickingId' => $pickingId, 'replayed' => true,
                    'completedQuantity' => (float) $prior['completed_quantity'],
                    'backorderPickingId' => $this->positiveOrNull($prior['backorder_picking_id'])];
            }
            $headerStatement = $connection->prepare(
                "SELECT p.*, o.currency AS document_currency
                 FROM inventory_pickings p
                 LEFT JOIN sales_orders o
                   ON o.company_id = p.company_id
                  AND o.order_id = p.sales_order_id
                 WHERE p.company_id = :company_id AND p.picking_id = :picking_id
                 FOR UPDATE"
            );
            $headerStatement->execute(['company_id' => $companyId, 'picking_id' => $pickingId]);
            $header = $headerStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($header)) {
                throw new RuntimeException('The picking was not found.');
            }
            if((string)$header['status']==='waiting_stock')throw new RuntimeException('Insufficient stock: this delivery is waiting for an available reservation.');
            if (!in_array((string) $header['status'], ['ready', 'partially_done'], true)) {
                throw new RuntimeException('Only a reserved, ready picking can be completed.');
            }
            if ((string) $header['status'] === 'partially_done') {
                $hasBackorder = $connection->prepare(
                    'SELECT 1 FROM inventory_pickings WHERE company_id = :company_id AND backorder_of_id = :picking_id LIMIT 1'
                );
                $hasBackorder->execute(['company_id' => $companyId, 'picking_id' => $pickingId]);
                if ($hasBackorder->fetchColumn() !== false) {
                    throw new RuntimeException('Complete the linked backorder instead.');
                }
            }
            $lineStatement = $connection->prepare(
                'SELECT * FROM inventory_picking_lines
                 WHERE company_id = :company_id AND picking_id = :picking_id
                   AND status <> \'cancelled\'
                 ORDER BY picking_line_id FOR UPDATE'
            );
            $lineStatement->execute(['company_id' => $companyId, 'picking_id' => $pickingId]);
            $lines = $lineStatement->fetchAll(PDO::FETCH_ASSOC);
            $totalDone = 0.0;
            $remainingLines = [];

            /*
             * Accumulate the exact cost recorded by the authoritative
             * stock movements created by this picking completion.
             */
            $accountingCost = 0.0;

            $movementCostStatement = $connection->prepare(
                "SELECT unit_cost
                 FROM inventory_stock_movements
                 WHERE company_id = :company_id
                   AND idempotency_key = :idempotency_key
                 LIMIT 1"
            );
            foreach ($lines as $line) {
                $lineId = (int) $line['picking_line_id'];
                $quantity = (float) ($quantities[$lineId] ?? 0);
                $remaining = (float) $line['requested_quantity'] - (float) $line['completed_quantity'];
                if ($quantity < 0 || $quantity > $remaining + 0.0005) {
                    throw new RuntimeException('A completed quantity exceeds the picking line remainder.');
                }
                if ($quantity > 0.0005) {
                    $movementType = (string) $header['picking_type'] === 'customer_return'
                        ? 'return_in' : ((string) $header['picking_type'] === 'vendor_return' ? 'return_out' : 'fulfilment');
                    $unitCost = 0.0;
                    $relatedMovementId = null;

                    if ((string) $header['picking_type'] === 'customer_return') {
                        $originalLineId = (int) (
                            $line['original_picking_line_id'] ?? 0
                        );

                        if ($originalLineId <= 0) {
                            throw new RuntimeException(
                                'A customer return line is missing its original delivery line.'
                            );
                        }

                        $originalMovementStatement = $connection->prepare(
                            "SELECT
                                movements.movement_id,
                                movements.unit_cost
                             FROM inventory_picking_lines original_line
                             INNER JOIN inventory_stock_movements movements
                                ON movements.company_id =
                                   original_line.company_id
                               AND movements.reference_type =
                                   'inventory_picking'
                               AND movements.reference_id =
                                   original_line.picking_id
                               AND movements.product_id =
                                   original_line.product_id
                               AND movements.source_location_id =
                                   original_line.source_location_id
                               AND movements.destination_location_id =
                                   original_line.destination_location_id
                               AND movements.movement_type =
                                   'fulfilment'
                               AND movements.status =
                                   'completed'
                             WHERE original_line.company_id =
                                   :company_id
                               AND original_line.picking_line_id =
                                   :original_picking_line_id
                             ORDER BY movements.movement_id DESC
                             LIMIT 1
                             FOR UPDATE"
                        );

                        $originalMovementStatement->execute([
                            'company_id' => $companyId,
                            'original_picking_line_id' => $originalLineId,
                        ]);

                        $originalMovement =
                            $originalMovementStatement->fetch(
                                PDO::FETCH_ASSOC
                            );

                        if ($originalMovement === false) {
                            throw new RuntimeException(
                                'The original delivery valuation could not be found for the customer return.'
                            );
                        }

                        $unitCost =
                            (float) $originalMovement['unit_cost'];

                        $relatedMovementId =
                            (int) $originalMovement['movement_id'];
                    }

                    $movementKey =
                        $idempotencyKey . ':line:' . $lineId;
                    $this->completeStockMovement([
                        'companyId' => $companyId,
                        'productId' => (int) $line['product_id'],
                        'sourceWarehouseId' => (int) $header['warehouse_id'],
                        'sourceLocationId' => (int) $line['source_location_id'],
                        'destinationWarehouseId' => (int) $header['warehouse_id'],
                        'destinationLocationId' => (int) $line['destination_location_id'],
                        'quantity' => $quantity,
                        'unitCost' => $unitCost,
                        'movementType' => $movementType,
                        'operationTypeId' => (int) $header['operation_type_id'],
                        'currency' => (string) ($header['document_currency'] ?? 'ETB'),
                        'referenceType' => 'inventory_picking',
                        'referenceId' => $pickingId,
                        'referenceNumber' => (string) $header['picking_number'],
                        'idempotencyKey' => $movementKey,
                        'notes' => $line['notes'] ?? null,
                        'occurredAt' => $completedAt,
                        'actorId' => $actorId,
                        'relatedMovementId' => $relatedMovementId,
                    ]);

                    /*
                     * completeStockMovement() is authoritative for
                     * the final posted unit cost. Resolve that exact
                     * value for Finance rather than recalculating it.
                     */
                    $movementCostStatement->execute([
                        'company_id' => $companyId,
                        'idempotency_key' => $movementKey,
                    ]);

                    $postedUnitCost =
                        $movementCostStatement->fetchColumn();

                    if ($postedUnitCost === false) {
                        throw new RuntimeException(
                            'The completed stock movement cost could not be resolved.'
                        );
                    }

                    $accountingCost +=
                        $quantity * (float) $postedUnitCost;

                    if ($relatedMovementId !== null) {
                        $movementLinkStatement =
                            $connection->prepare(
                                "UPDATE inventory_stock_movements
                                 SET related_movement_id =
                                     :related_movement_id
                                 WHERE company_id = :company_id
                                   AND idempotency_key =
                                       :idempotency_key
                                   AND related_movement_id IS NULL"
                            );

                        $movementLinkStatement->execute([
                            'related_movement_id' =>
                                $relatedMovementId,
                            'company_id' => $companyId,
                            'idempotency_key' => $movementKey,
                        ]);
                    }
                    if ((string) $header['picking_type'] === 'delivery' && !empty($line['reservation_allocation_id'])) {
                        $this->consumePickingReservation($line, $quantity, $completedAt);
                    } elseif ((string) $header['picking_type'] === 'customer_return') {
                        $this->applyReturnedQuantity($companyId, (int) $line['original_picking_line_id'], $quantity);
                    }
                    $totalDone += $quantity;
                }
                $newCompleted = (float) $line['completed_quantity'] + $quantity;
                $lineRemaining = max(0.0, (float) $line['requested_quantity'] - $newCompleted);
                $lineUpdate = $connection->prepare(
                    "UPDATE inventory_picking_lines
                     SET completed_quantity = :completed_quantity,
                         status = CASE WHEN :remaining <= 0.0005 THEN 'done'
                                       WHEN :completed > 0 THEN 'partially_done' ELSE status END
                     WHERE company_id = :company_id AND picking_line_id = :line_id"
                );
                $lineUpdate->execute(['completed_quantity' => $newCompleted, 'remaining' => $lineRemaining,
                    'completed' => $newCompleted, 'company_id' => $companyId, 'line_id' => $lineId]);
                if ($lineRemaining > 0.0005) {
                    $remainingLines[] = $line + ['remaining_quantity' => $lineRemaining];
                }
            }
            if ($totalDone <= 0.0005) {
                throw new RuntimeException('At least one positive completed quantity is required.');
            }
            $backorderId = null;
            if ($remainingLines !== [] && $createBackorder) {
                $backorderId = $this->createBackorderPicking($header, $remainingLines, $actorId, $completedAt);
            } elseif ($remainingLines !== []) {
                foreach ($remainingLines as $line) {
                    if ((string) $header['picking_type'] === 'delivery' && !empty($line['reservation_allocation_id'])) {
                        $this->releasePickingReservation($line, (float) $line['remaining_quantity'], $completedAt);
                    }
                }
            }
            $status = $remainingLines === [] || !$createBackorder ? 'done' : 'partially_done';
            $updateHeader = $connection->prepare(
                'UPDATE inventory_pickings SET status = :status, completed_at = :completed_at,
                    completed_by = :completed_by WHERE company_id = :company_id AND picking_id = :picking_id'
            );
            $updateHeader->execute(['status' => $status, 'completed_at' => $completedAt,
                'completed_by' => $actorId, 'company_id' => $companyId, 'picking_id' => $pickingId]);
            $completion = $connection->prepare(
                'INSERT INTO inventory_picking_completions
                    (company_id, picking_id, idempotency_key, completed_quantity,
                     backorder_picking_id, completed_by, completed_at)
                 VALUES (:company_id, :picking_id, :idempotency_key, :completed_quantity,
                         :backorder_picking_id, :completed_by, :completed_at)'
            );
            $completion->execute(['company_id' => $companyId, 'picking_id' => $pickingId,
                'idempotency_key' => $idempotencyKey, 'completed_quantity' => $totalDone,
                'backorder_picking_id' => $backorderId, 'completed_by' => $actorId,
                'completed_at' => $completedAt]);
            if (!empty($header['sales_order_id'])) {
                $this->synchronizeSalesDeliveryStatus($companyId, (int) $header['sales_order_id']);
            }
            /*
         * Physical stock and its accounting event are committed
         * atomically. A picking completion therefore cannot exist
         * without its corresponding durable Finance event.
         */
        $accountingOrderId = (int) (
            $header['sales_order_id'] ?? 0
        );

        $pickingType = (string) (
            $header['picking_type'] ?? ''
        );

        if (
            $accountingOrderId > 0
            && $accountingCost > 0
            && in_array(
                $pickingType,
                ['delivery', 'customer_return'],
                true
            )
        ) {
            $isCustomerReturn =
                $pickingType === 'customer_return';

            $this->enqueueIntegrationEvent(
                $connection,
                $companyId,
                $isCustomerReturn
                    ? 'inventory.customer-return.completed'
                    : 'inventory.sales-order.fulfilled',
                'inventory_picking',
                (string) $pickingId,
                [
                    'order_id' =>
                        $accountingOrderId,
                    'picking_id' =>
                        $pickingId,
                    'picking_number' =>
                        (string) $header['picking_number'],
                    'actor_id' =>
                        $actorId,
                    'inventory_cost' =>
                        round($accountingCost, 2),
                    'completion_key' =>
                        $idempotencyKey,
                    (
                        $isCustomerReturn
                            ? 'returned_at'
                            : 'fulfilled_at'
                    ) => $completedAt,
                ]
            );
        }
        $connection->commit();
            return ['pickingId' => $pickingId, 'status' => $status, 'replayed' => false,
                'completedQuantity' => $totalDone, 'backorderPickingId' => $backorderId];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    public function completeSalesOrderDeliveries(
        int $companyId,
        int $orderId,
        int $actorId,
        string $completedAt
    ): array {
        $pickingIds = $this->ensureDeliveryPickings(
            $companyId,
            $orderId,
            $actorId,
            $completedAt
        );
        $completed = 0.0;
        foreach ($pickingIds as $pickingId) {
            $statement = $this->connection()->prepare(
                "SELECT picking_line_id,
                        requested_quantity - completed_quantity AS remaining_quantity
                 FROM inventory_picking_lines
                 WHERE company_id = :company_id AND picking_id = :picking_id
                   AND status IN ('ready', 'partially_done')"
            );
            $statement->execute(['company_id' => $companyId, 'picking_id' => $pickingId]);
            $quantities = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $line) {
                $quantities[(int) $line['picking_line_id']] = (float) $line['remaining_quantity'];
            }
            if ($quantities === []) {
                continue;
            }
            $result = $this->completePicking(
                $companyId,
                $pickingId,
                $quantities,
                false,
                'sales-order:' . $orderId . ':picking:' . $pickingId . ':complete',
                $actorId,
                $completedAt
            );
            $completed += (float) ($result['completedQuantity'] ?? 0);
        }
        return ['orderId' => $orderId, 'status' => 'fulfilled',
            'completedQuantity' => $completed, 'pickingCount' => count($pickingIds)];
    }

    public function postTransfer(
        int $companyId,
        int $transferId,
        int $actorId,
        string $postedAt
    ): array {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $header = $connection->prepare(
                "SELECT * FROM inventory_transfers
                 WHERE company_id = :company_id
                   AND transfer_id = :transfer_id
                 FOR UPDATE"
            );
            $header->execute([
                'company_id' => $companyId,
                'transfer_id' => $transferId,
            ]);
            $transfer = $header->fetch(PDO::FETCH_ASSOC);

            if (!is_array($transfer)) {
                throw new RuntimeException('The inventory transfer was not found.');
            }

            if ((string) $transfer['status'] === 'posted') {
                $connection->commit();
                return [
                    'transferId' => $transferId,
                    'status' => 'posted',
                    'replayed' => true,
                    'movementCount' => 0,
                ];
            }

            if ((string) $transfer['status'] !== 'approved') {
                throw new RuntimeException(
                    'Only an approved inventory transfer can be posted.'
                );
            }

            $linesStatement = $connection->prepare(
                "SELECT * FROM inventory_transfer_lines
                 WHERE company_id = :company_id
                   AND transfer_id = :transfer_id
                 ORDER BY transfer_line_id
                 FOR UPDATE"
            );
            $linesStatement->execute([
                'company_id' => $companyId,
                'transfer_id' => $transferId,
            ]);
            $lines = $linesStatement->fetchAll(PDO::FETCH_ASSOC);

            if ($lines === []) {
                throw new RuntimeException(
                    'The inventory transfer must contain at least one line.'
                );
            }

            $movementCount = 0;
            foreach ($lines as $line) {
                $result = $this->completeStockMovement([
                    'companyId' => $companyId,
                    'productId' => (int) $line['product_id'],
                    'sourceWarehouseId' => (int) $line['source_warehouse_id'],
                    'sourceLocationId' => (int) $line['source_location_id'],
                    'destinationWarehouseId' => (int) $line['destination_warehouse_id'],
                    'destinationLocationId' => (int) $line['destination_location_id'],
                    'quantity' => (float) $line['quantity'],
                    'unitCost' => (float) $line['unit_cost'],
                    'movementType' => 'transfer_in',
                    'operationTypeId' => (int) $transfer['operation_type_id'],
                    'currency' => 'ETB',
                    'referenceType' => 'inventory_transfer',
                    'referenceId' => $transferId,
                    'referenceNumber' => (string) $transfer['transfer_number'],
                    'idempotencyKey' => sprintf(
                        'inventory-transfer:%d:line:%d',
                        $transferId,
                        (int) $line['transfer_line_id']
                    ),
                    'notes' => $line['notes'] ?? null,
                    'occurredAt' => $postedAt,
                    'actorId' => $actorId,
                ]);
                if (empty($result['replayed'])) {
                    $movementCount++;
                }
            }

            $update = $connection->prepare(
                "UPDATE inventory_transfers
                 SET status = 'posted', posted_by = :actor_id,
                     posted_at = :posted_at
                 WHERE company_id = :company_id
                   AND transfer_id = :transfer_id
                   AND status = 'approved'"
            );
            $update->execute([
                'actor_id' => $actorId,
                'posted_at' => $postedAt,
                'company_id' => $companyId,
                'transfer_id' => $transferId,
            ]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException(
                    'The inventory transfer could not be marked as posted.'
                );
            }

            $connection->commit();
            return [
                'transferId' => $transferId,
                'status' => 'posted',
                'replayed' => false,
                'movementCount' => $movementCount,
            ];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    public function createReturnPicking(
        int $companyId,
        int $originalPickingId,
        array $quantities,
        int $actorId,
        string $createdAt
    ): int {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $headerStatement = $connection->prepare(
                "SELECT * FROM inventory_pickings
                 WHERE company_id = :company_id AND picking_id = :picking_id
                   AND picking_type = 'delivery'
                   AND status IN ('done', 'partially_done') FOR UPDATE"
            );
            $headerStatement->execute(['company_id' => $companyId, 'picking_id' => $originalPickingId]);
            $original = $headerStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($original)) {
                throw new RuntimeException('Only a completed delivery can be returned.');
            }
            $linesStatement = $connection->prepare(
                'SELECT * FROM inventory_picking_lines
                 WHERE company_id = :company_id AND picking_id = :picking_id FOR UPDATE'
            );
            $linesStatement->execute(['company_id' => $companyId, 'picking_id' => $originalPickingId]);
            $lines = $linesStatement->fetchAll(PDO::FETCH_ASSOC);
            $selected = [];
            foreach ($lines as $line) {
                $lineId = (int) $line['picking_line_id'];
                $quantity = (float) ($quantities[$lineId] ?? 0);
                $returnable = (float) $line['completed_quantity'] - (float) $line['returned_quantity'];
                if ($quantity < 0 || $quantity > $returnable + 0.0005) {
                    throw new RuntimeException('Return quantity exceeds the net delivered quantity.');
                }
                if ($quantity > 0.0005) {
                    $selected[] = $line + ['return_quantity' => $quantity];
                }
            }
            if ($selected === []) {
                throw new RuntimeException('At least one positive return quantity is required.');
            }
            $number = sprintf('RET-%d-%s', $originalPickingId, substr(hash('sha256', $companyId . ':' . $originalPickingId . ':' . microtime(true)), 0, 10));
            $insert = $connection->prepare(
                "INSERT INTO inventory_pickings (
                    company_id, warehouse_id, operation_type_id, sales_order_id,
                    original_picking_id, picking_type, picking_number,
                    source_location_id, destination_location_id, status,
                    reserved_at, created_by
                 ) VALUES (
                    :company_id, :warehouse_id, :operation_type_id, :sales_order_id,
                    :original_picking_id, 'customer_return', :picking_number,
                    :source_location_id, :destination_location_id, 'ready',
                    :reserved_at, :created_by)"
            );
            $insert->execute(['company_id' => $companyId, 'warehouse_id' => (int) $original['warehouse_id'],
                'operation_type_id' => (int) $original['operation_type_id'],
                'sales_order_id' => $original['sales_order_id'], 'original_picking_id' => $originalPickingId,
                'picking_number' => $number, 'source_location_id' => (int) $original['destination_location_id'],
                'destination_location_id' => (int) $original['source_location_id'],
                'reserved_at' => $createdAt, 'created_by' => $actorId]);
            $returnId = (int) $connection->lastInsertId();
            $lineInsert = $connection->prepare(
                "INSERT INTO inventory_picking_lines (
                    company_id, picking_id, product_id, source_location_id,
                    destination_location_id, original_picking_line_id,
                    requested_quantity, reserved_quantity, status
                 ) VALUES (:company_id, :picking_id, :product_id, :source_location_id,
                    :destination_location_id, :original_line_id,
                    :requested_quantity, 0, 'ready')"
            );
            foreach ($selected as $line) {
                $lineInsert->execute(['company_id' => $companyId, 'picking_id' => $returnId,
                    'product_id' => (int) $line['product_id'],
                    'source_location_id' => (int) $line['destination_location_id'],
                    'destination_location_id' => (int) $line['source_location_id'],
                    'original_line_id' => (int) $line['picking_line_id'],
                    'requested_quantity' => (float) $line['return_quantity']]);
            }
            $connection->commit();
            return $returnId;
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    public function cancelPicking(
        int $companyId,
        int $pickingId,
        string $reason,
        int $actorId,
        string $cancelledAt
    ): void {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $statement = $connection->prepare(
                'SELECT * FROM inventory_pickings WHERE company_id = :company_id
                 AND picking_id = :picking_id FOR UPDATE'
            );
            $statement->execute(['company_id' => $companyId, 'picking_id' => $pickingId]);
            $header = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($header) || !in_array((string) $header['status'], ['draft', 'ready'], true)) {
                throw new RuntimeException('Only an uncompleted picking can be cancelled.');
            }
            if (mb_strlen(trim($reason)) < 3) {
                throw new RuntimeException('A cancellation reason is required.');
            }
            if ((string) $header['picking_type'] === 'delivery') {
                $lines = $connection->prepare(
                    'SELECT * FROM inventory_picking_lines WHERE company_id = :company_id
                     AND picking_id = :picking_id AND status <> \'cancelled\' FOR UPDATE'
                );
                $lines->execute(['company_id' => $companyId, 'picking_id' => $pickingId]);
                foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $line) {
                    $remaining = (float) $line['reserved_quantity'] - (float) $line['completed_quantity'];
                    if ($remaining > 0.0005) {
                        $this->releasePickingReservation($line, $remaining, $cancelledAt);
                    }
                }
            }
            $update = $connection->prepare(
                "UPDATE inventory_pickings SET status = 'cancelled', cancelled_at = :cancelled_at,
                    cancelled_by = :cancelled_by, cancellation_reason = :reason
                 WHERE company_id = :company_id AND picking_id = :picking_id"
            );
            $update->execute(['cancelled_at' => $cancelledAt, 'cancelled_by' => $actorId,
                'reason' => trim($reason), 'company_id' => $companyId, 'picking_id' => $pickingId]);
            $connection->prepare(
                "UPDATE inventory_picking_lines SET status = 'cancelled'
                 WHERE company_id = :company_id AND picking_id = :picking_id AND status <> 'done'"
            )->execute(['company_id' => $companyId, 'picking_id' => $pickingId]);
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    public function createStockAdjustment(array $document): int
    {
        $companyId = (int) ($document['companyId'] ?? 0);
        $warehouseId = (int) ($document['warehouseId'] ?? 0);
        $locationId = (int) ($document['locationId'] ?? 0);
        $productId = (int) ($document['productId'] ?? 0);
        $counted = (float) ($document['countedQuantity'] ?? -1);
        $actorId = (int) ($document['actorId'] ?? 0);
        if ($counted < 0) {
            throw new RuntimeException('Counted quantity cannot be negative.');
        }
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $this->assertLocation($companyId, $warehouseId, $locationId, true);
            $balance = $this->stockBalanceForUpdate($companyId, $warehouseId, $locationId, $productId);
            $expected = (float) ($balance['quantity_on_hand'] ?? 0);
            $difference = $counted - $expected;
            if (abs($difference) <= 0.0005) {
                throw new RuntimeException('The counted quantity has no difference to post.');
            }
            $operation = $this->defaultOperationType($companyId, $warehouseId, 'adjustment');
            $number = 'ADJ-' . $warehouseId . '-' . substr(hash('sha256', $companyId . ':' . microtime(true)), 0, 10);
            $header = $connection->prepare(
                "INSERT INTO inventory_stock_adjustments
                    (company_id, warehouse_id, operation_type_id, adjustment_number,
                     adjustment_date, reason_code, status, notes, created_by, approved_by, approved_at)
                 VALUES (:company_id, :warehouse_id, :operation_type_id, :number,
                         CURRENT_DATE, :reason, 'approved', :notes, :actor, :approver, NOW())"
            );
            $reason = trim((string) ($document['reason'] ?? ''));
            if ($reason === '') {
                throw new RuntimeException('An adjustment reason is required.');
            }
            $header->execute(['company_id' => $companyId, 'warehouse_id' => $warehouseId,
                'operation_type_id' => (int) $operation['operation_type_id'], 'number' => $number,
                'reason' => mb_substr($reason, 0, 40), 'notes' => $reason,
                'actor' => $actorId, 'approver' => $actorId]);
            $adjustmentId = (int) $connection->lastInsertId();
            $line = $connection->prepare(
                'INSERT INTO inventory_stock_adjustment_lines
                    (company_id, adjustment_id, warehouse_id, location_id, product_id,
                     expected_quantity, counted_quantity, quantity_delta, unit_cost, notes)
                 VALUES (:company_id, :adjustment_id, :warehouse_id, :location_id, :product_id,
                         :expected, :counted, :difference, :unit_cost, :notes)'
            );
            $line->execute(['company_id' => $companyId, 'adjustment_id' => $adjustmentId,
                'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'product_id' => $productId,
                'expected' => $expected, 'counted' => $counted, 'difference' => $difference,
                'unit_cost' => (float) ($balance['average_unit_cost'] ?? 0), 'notes' => $reason]);
            $connection->commit();
            return $adjustmentId;
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    public function postStockAdjustment(int $companyId, int $adjustmentId, int $actorId, string $postedAt): array
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $header = $connection->prepare(
                'SELECT * FROM inventory_stock_adjustments WHERE company_id = :company_id
                 AND adjustment_id = :adjustment_id FOR UPDATE'
            );
            $header->execute(['company_id' => $companyId, 'adjustment_id' => $adjustmentId]);
            $adjustment = $header->fetch(PDO::FETCH_ASSOC);
            if (!is_array($adjustment)) {
                throw new RuntimeException('The adjustment was not found.');
            }
            if ((string) $adjustment['status'] === 'posted') {
                $connection->commit();
                return ['adjustmentId' => $adjustmentId, 'status' => 'posted', 'replayed' => true];
            }
            if ((string) $adjustment['status'] !== 'approved') {
                throw new RuntimeException('Only an approved adjustment can be posted.');
            }
            $lines = $connection->prepare(
                'SELECT * FROM inventory_stock_adjustment_lines WHERE company_id = :company_id
                 AND adjustment_id = :adjustment_id ORDER BY adjustment_line_id FOR UPDATE'
            );
            $lines->execute(['company_id' => $companyId, 'adjustment_id' => $adjustmentId]);
            $operation = $this->defaultOperationType($companyId, (int) $adjustment['warehouse_id'], 'adjustment');
            $count = 0;
            $movementIds = [];
            $gainValue = 0.0;
            $lossValue = 0.0;
            foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $line) {
                $difference = (float) $line['quantity_delta'];
                $positive = $difference > 0;
                $movement = $this->completeStockMovement([
                    'companyId' => $companyId, 'productId' => (int) $line['product_id'],
                    'sourceWarehouseId' => (int) $adjustment['warehouse_id'],
                    'sourceLocationId' => $positive ? (int) $operation['default_source_location_id'] : (int) $line['location_id'],
                    'destinationWarehouseId' => (int) $adjustment['warehouse_id'],
                    'destinationLocationId' => $positive ? (int) $line['location_id'] : (int) $operation['default_destination_location_id'],
                    'quantity' => abs($difference), 'unitCost' => (float) $line['unit_cost'],
                    'movementType' => $positive ? 'adjustment_in' : 'adjustment_out',
                    'operationTypeId' => (int) $adjustment['operation_type_id'],
                    'referenceType' => 'stock_adjustment', 'referenceId' => $adjustmentId,
                    'referenceNumber' => (string) $adjustment['adjustment_number'],
                    'idempotencyKey' => 'stock-adjustment:' . $adjustmentId . ':line:' . $line['adjustment_line_id'],
                    'notes' => $line['notes'] ?? null, 'occurredAt' => $postedAt, 'actorId' => $actorId,
                ]);
                $movementIds[] = (int) $movement['movementId'];
                if ($positive) {
                    $gainValue += abs($difference) * (float) $line['unit_cost'];
                } else {
                    $movementCost = $connection->prepare(
                        'SELECT unit_cost FROM inventory_stock_movements WHERE company_id=:company_id AND movement_id=:movement_id'
                    );
                    $movementCost->execute(['company_id' => $companyId, 'movement_id' => $movement['movementId']]);
                    $lossValue += abs($difference) * (float) $movementCost->fetchColumn();
                }
                $count++;
            }
            $gainValue = round($gainValue, 2);
            $lossValue = round($lossValue, 2);
            $finance = new FinanceRepository();
            $currency = $this->companyCurrency($companyId);
            $accounts = $finance->ensureSystemAccounts($companyId, $currency, $actorId);
            $journalLines = [];
            if ($gainValue > 0) {
                $journalLines[] = ['account_id' => $accounts['inventory_asset'], 'debit' => $gainValue, 'credit' => 0, 'description' => 'Positive inventory adjustment'];
                $journalLines[] = ['account_id' => $accounts['inventory_gain'], 'debit' => 0, 'credit' => $gainValue, 'description' => 'Inventory adjustment gain'];
            }
            if ($lossValue > 0) {
                $journalLines[] = ['account_id' => $accounts['inventory_loss'], 'debit' => $lossValue, 'credit' => 0, 'description' => 'Inventory adjustment loss'];
                $journalLines[] = ['account_id' => $accounts['inventory_asset'], 'debit' => 0, 'credit' => $lossValue, 'description' => 'Negative inventory adjustment'];
            }
            if ($journalLines !== []) {
                $journal = $finance->postBalancedJournal(
                    $companyId,
                    'ADJ-' . $adjustmentId,
                    'inventory_adjustment',
                    (string) $adjustmentId,
                    (string) $adjustment['adjustment_number'],
                    substr($postedAt, 0, 10),
                    $currency,
                    'Inventory adjustment ' . $adjustment['adjustment_number'],
                    'inventory-adjustment-' . $companyId . '-' . $adjustmentId,
                    $journalLines,
                    $actorId
                );
                $this->linkValuationJournal($companyId, $movementIds, (int) $journal['journalBatchId']);
            }
            $connection->prepare(
                "UPDATE inventory_stock_adjustments SET status = 'posted', posted_by = :actor,
                    posted_at = :posted_at WHERE company_id = :company_id AND adjustment_id = :adjustment_id"
            )->execute(['actor' => $actorId, 'posted_at' => $postedAt,
                'company_id' => $companyId, 'adjustment_id' => $adjustmentId]);
            $connection->commit();
            return ['adjustmentId' => $adjustmentId, 'status' => 'posted', 'replayed' => false, 'movementCount' => $count];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    public function createScrap(array $document): int
    {
        $companyId = (int) ($document['companyId'] ?? 0);
        $warehouseId = (int) ($document['warehouseId'] ?? 0);
        $sourceId = (int) ($document['sourceLocationId'] ?? 0);
        $productId = (int) ($document['productId'] ?? 0);
        $quantity = (float) ($document['quantity'] ?? 0);
        $actorId = (int) ($document['actorId'] ?? 0);
        $reason = trim((string) ($document['reason'] ?? ''));
        if ($quantity <= 0 || $reason === '') {
            throw new RuntimeException('Positive scrap quantity and reason are required.');
        }
        $this->assertLocation($companyId, $warehouseId, $sourceId, true);
        $scrapLocation = $this->virtualLocation($companyId, $warehouseId, 'scrap');
        $number = 'SCRAP-' . $warehouseId . '-' . substr(hash('sha256', $companyId . ':' . microtime(true)), 0, 10);
        $statement = $this->connection()->prepare(
            "INSERT INTO inventory_scrap_orders
                (company_id, warehouse_id, source_location_id, scrap_location_id,
                 product_id, scrap_number, quantity, reason, status, created_by)
             VALUES (:company_id, :warehouse_id, :source_location_id, :scrap_location_id,
                     :product_id, :number, :quantity, :reason, 'draft', :created_by)"
        );
        $statement->execute(['company_id' => $companyId, 'warehouse_id' => $warehouseId,
            'source_location_id' => $sourceId, 'scrap_location_id' => (int) $scrapLocation['location_id'],
            'product_id' => $productId, 'number' => $number, 'quantity' => $quantity,
            'reason' => $reason, 'created_by' => $actorId]);
        return (int) $this->connection()->lastInsertId();
    }

    public function postScrap(int $companyId, int $scrapId, int $actorId, string $postedAt): array
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $statement = $connection->prepare(
                'SELECT * FROM inventory_scrap_orders WHERE company_id = :company_id
                 AND scrap_id = :scrap_id FOR UPDATE'
            );
            $statement->execute(['company_id' => $companyId, 'scrap_id' => $scrapId]);
            $scrap = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($scrap)) {
                throw new RuntimeException('The scrap document was not found.');
            }
            if ((string) $scrap['status'] === 'done') {
                $connection->commit();
                return ['scrapId' => $scrapId, 'status' => 'done', 'replayed' => true];
            }
            if ((string) $scrap['status'] !== 'draft') {
                throw new RuntimeException('Only a draft scrap document can be posted.');
            }
            $movement = $this->completeStockMovement([
                'companyId' => $companyId, 'productId' => (int) $scrap['product_id'],
                'sourceWarehouseId' => (int) $scrap['warehouse_id'], 'sourceLocationId' => (int) $scrap['source_location_id'],
                'destinationWarehouseId' => (int) $scrap['warehouse_id'], 'destinationLocationId' => (int) $scrap['scrap_location_id'],
                'quantity' => (float) $scrap['quantity'], 'unitCost' => 0, 'movementType' => 'adjustment_out',
                'referenceType' => 'scrap', 'referenceId' => $scrapId, 'referenceNumber' => (string) $scrap['scrap_number'],
                'idempotencyKey' => 'scrap:' . $scrapId, 'notes' => (string) $scrap['reason'],
                'occurredAt' => $postedAt, 'actorId' => $actorId,
            ]);
            $movementCost = $connection->prepare(
                'SELECT unit_cost FROM inventory_stock_movements WHERE company_id=:company_id AND movement_id=:movement_id'
            );
            $movementCost->execute(['company_id' => $companyId, 'movement_id' => $movement['movementId']]);
            $scrapValue = round((float) $scrap['quantity'] * (float) $movementCost->fetchColumn(), 2);
            if ($scrapValue > 0) {
                $finance = new FinanceRepository();
                $currency = $this->companyCurrency($companyId);
                $accounts = $finance->ensureSystemAccounts($companyId, $currency, $actorId);
                $journal = $finance->postBalancedJournal(
                    $companyId,
                    'SCRAP-' . $scrapId,
                    'inventory_scrap',
                    (string) $scrapId,
                    (string) $scrap['scrap_number'],
                    substr($postedAt, 0, 10),
                    $currency,
                    'Inventory scrapped: ' . $scrap['reason'],
                    'inventory-scrap-' . $companyId . '-' . $scrapId,
                    [
                        ['account_id' => $accounts['inventory_loss'], 'debit' => $scrapValue, 'credit' => 0, 'description' => 'Scrap expense'],
                        ['account_id' => $accounts['inventory_asset'], 'debit' => 0, 'credit' => $scrapValue, 'description' => 'Inventory scrapped'],
                    ],
                    $actorId
                );
                $this->linkValuationJournal($companyId, [(int) $movement['movementId']], (int) $journal['journalBatchId']);
            }
            $connection->prepare(
                "UPDATE inventory_scrap_orders SET status = 'done', posted_at = :posted_at,
                    posted_by = :actor WHERE company_id = :company_id AND scrap_id = :scrap_id"
            )->execute(['posted_at' => $postedAt, 'actor' => $actorId,
                'company_id' => $companyId, 'scrap_id' => $scrapId]);
            $connection->commit();
            return ['scrapId' => $scrapId, 'status' => 'done', 'replayed' => false];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $header @param list<array<string, mixed>> $lines */
    private function createBackorderPicking(array $header, array $lines, int $actorId, string $createdAt): int
    {
        $number = (string) $header['picking_number'] . '-BO-' . substr(hash('sha256', $header['picking_id'] . ':' . microtime(true)), 0, 6);
        $insert = $this->connection()->prepare(
            "INSERT INTO inventory_pickings (
                company_id, warehouse_id, operation_type_id, sales_order_id,
                original_picking_id, backorder_of_id, picking_type, picking_number,
                source_location_id, destination_location_id, status, reserved_at, created_by
             ) VALUES (:company_id, :warehouse_id, :operation_type_id, :sales_order_id,
                :original_picking_id, :backorder_of_id, :picking_type, :number,
                :source_location_id, :destination_location_id, 'ready', :reserved_at, :created_by)"
        );
        $insert->execute(['company_id' => (int) $header['company_id'], 'warehouse_id' => (int) $header['warehouse_id'],
            'operation_type_id' => (int) $header['operation_type_id'], 'sales_order_id' => $header['sales_order_id'],
            'original_picking_id' => $header['original_picking_id'], 'backorder_of_id' => (int) $header['picking_id'],
            'picking_type' => (string) $header['picking_type'], 'number' => $number,
            'source_location_id' => (int) $header['source_location_id'],
            'destination_location_id' => (int) $header['destination_location_id'],
            'reserved_at' => $createdAt, 'created_by' => $actorId]);
        $id = (int) $this->connection()->lastInsertId();
        $lineInsert = $this->connection()->prepare(
            "INSERT INTO inventory_picking_lines (
                company_id, picking_id, product_id, source_location_id,
                destination_location_id, reservation_allocation_id,
                original_picking_line_id, requested_quantity, reserved_quantity, status
             ) VALUES (:company_id, :picking_id, :product_id, :source_location_id,
                :destination_location_id, :allocation_id, :original_line_id,
                :quantity, :reserved_quantity, 'ready')"
        );
        foreach ($lines as $line) {
            $quantity = (float) $line['remaining_quantity'];
            $lineInsert->execute(['company_id' => (int) $header['company_id'], 'picking_id' => $id,
                'product_id' => (int) $line['product_id'], 'source_location_id' => (int) $line['source_location_id'],
                'destination_location_id' => (int) $line['destination_location_id'],
                'allocation_id' => $line['reservation_allocation_id'], 'original_line_id' => null,
                'quantity' => $quantity,
                'reserved_quantity' => (string) $header['picking_type'] === 'delivery' ? $quantity : 0]);
        }
        return $id;
    }

    /** @param array<string, mixed> $line */
    private function consumePickingReservation(array $line, float $quantity, string $completedAt): void
    {
        $allocationId = (int) ($line['reservation_allocation_id'] ?? 0);
        if ($allocationId < 1) {
            throw new RuntimeException('The delivery line has no reservation allocation.');
        }
        $allocation = $this->connection()->prepare(
            'SELECT * FROM inventory_sales_reservation_allocations
             WHERE company_id = :company_id AND allocation_id = :allocation_id FOR UPDATE'
        );
        $allocation->execute(['company_id' => (int) $line['company_id'], 'allocation_id' => $allocationId]);
        $row = $allocation->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('The delivery reservation no longer exists.');
        }
        $balance = $this->connection()->prepare(
            'UPDATE inventory_stock_balances SET quantity_reserved = quantity_reserved - :quantity,
                version_number = version_number + 1
             WHERE company_id = :company_id AND stock_balance_id = :balance_id
               AND quantity_reserved >= :required'
        );
        $balance->execute(['quantity' => $quantity, 'company_id' => (int) $line['company_id'],
            'balance_id' => (int) $row['stock_balance_id'], 'required' => $quantity]);
        if ($balance->rowCount() !== 1) {
            throw new RuntimeException('The completed reservation quantity is inconsistent.');
        }
        $update = $this->connection()->prepare(
            "UPDATE inventory_sales_reservation_allocations
             SET quantity_fulfilled = quantity_fulfilled + :quantity,
                 status = CASE WHEN quantity_fulfilled + :for_status + quantity_released >= quantity_reserved
                               THEN 'fulfilled' ELSE 'partially_fulfilled' END,
                 fulfilled_at = :completed_at
             WHERE company_id = :company_id AND allocation_id = :allocation_id"
        );
        $update->execute(['quantity' => $quantity, 'for_status' => $quantity, 'completed_at' => $completedAt,
            'company_id' => (int) $line['company_id'], 'allocation_id' => $allocationId]);
    }

    /** @param array<string, mixed> $line */
    private function releasePickingReservation(array $line, float $quantity, string $releasedAt): void
    {
        $allocationId = (int) ($line['reservation_allocation_id'] ?? 0);
        if ($allocationId < 1) {
            return;
        }
        $allocation = $this->connection()->prepare(
            'SELECT * FROM inventory_sales_reservation_allocations
             WHERE company_id = :company_id AND allocation_id = :allocation_id FOR UPDATE'
        );
        $allocation->execute(['company_id' => (int) $line['company_id'], 'allocation_id' => $allocationId]);
        $row = $allocation->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('The reservation allocation no longer exists.');
        }
        $this->connection()->prepare(
            'UPDATE inventory_stock_balances SET quantity_reserved = quantity_reserved - :quantity,
                version_number = version_number + 1
             WHERE company_id = :company_id AND stock_balance_id = :balance_id
               AND quantity_reserved >= :required'
        )->execute(['quantity' => $quantity, 'company_id' => (int) $line['company_id'],
            'balance_id' => (int) $row['stock_balance_id'], 'required' => $quantity]);
        $this->connection()->prepare(
            "UPDATE inventory_sales_reservation_allocations
             SET quantity_released = quantity_released + :quantity,
                 status = CASE WHEN quantity_released + :for_status + quantity_fulfilled >= quantity_reserved
                               THEN 'released' ELSE 'partially_released' END,
                 released_at = :released_at
             WHERE company_id = :company_id AND allocation_id = :allocation_id"
        )->execute(['quantity' => $quantity, 'for_status' => $quantity, 'released_at' => $releasedAt,
            'company_id' => (int) $line['company_id'], 'allocation_id' => $allocationId]);
    }

    private function applyReturnedQuantity(int $companyId, int $originalLineId, float $quantity): void
    {
        $statement = $this->connection()->prepare(
            'UPDATE inventory_picking_lines
             SET returned_quantity = returned_quantity + :quantity
             WHERE company_id = :company_id AND picking_line_id = :line_id
               AND returned_quantity + :required <= completed_quantity'
        );
        $statement->execute(['quantity' => $quantity, 'company_id' => $companyId,
            'line_id' => $originalLineId, 'required' => $quantity]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The return exceeds the net delivered quantity.');
        }
    }

    private function synchronizeSalesDeliveryStatus(int $companyId, int $orderId): void
    {
        $statement = $this->connection()->prepare(
            "SELECT COUNT(*) FROM inventory_sales_reservation_allocations
             WHERE company_id = :company_id AND order_id = :order_id
               AND status NOT IN ('fulfilled', 'released')"
        );
        $statement->execute(['company_id' => $companyId, 'order_id' => $orderId]);
        $remaining = (int) $statement->fetchColumn();
        $delivered = $this->connection()->prepare(
            'SELECT COALESCE(SUM(quantity_fulfilled), 0)
             FROM inventory_sales_reservation_allocations
             WHERE company_id = :company_id AND order_id = :order_id'
        );
        $delivered->execute(['company_id' => $companyId, 'order_id' => $orderId]);
        $deliveredQuantity = (float) $delivered->fetchColumn();
        $status = $remaining === 0 ? 'fulfilled' : ($deliveredQuantity > 0 ? 'partially_fulfilled' : 'approved');
        $this->connection()->prepare(
            'UPDATE sales_orders SET status = :status, updated_at = CURRENT_TIMESTAMP
             WHERE company_id = :company_id AND order_id = :order_id
               AND status NOT IN (\'cancelled\', \'paid\')'
        )->execute(['status' => $status, 'company_id' => $companyId, 'order_id' => $orderId]);
    }

    /** @return array<string, mixed> */
    private function defaultOperationType(int $companyId, int $warehouseId, string $kind): array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM inventory_operation_types WHERE company_id = :company_id
             AND warehouse_id = :warehouse_id AND operation_kind = :kind
             AND is_default = TRUE AND active = TRUE LIMIT 1'
        );
        $statement->execute(['company_id' => $companyId, 'warehouse_id' => $warehouseId, 'kind' => $kind]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('The required inventory operation type is not configured.');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function virtualLocation(int $companyId, int $warehouseId, string $usage): array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM inventory_warehouse_locations WHERE company_id = :company_id
             AND warehouse_id = :warehouse_id AND location_usage = :usage
             AND active = TRUE AND deleted_at IS NULL LIMIT 1'
        );
        $statement->execute(['company_id' => $companyId, 'warehouse_id' => $warehouseId, 'usage' => $usage]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('The required virtual location is not configured.');
        }
        return $row;
    }

    private function assertLocation(
        int $companyId,
        int $warehouseId,
        int $locationId,
        bool $source
    ): array {
        $statement = $this->connection()->prepare(
            "SELECT receiving_allowed, picking_allowed, location_usage
             FROM inventory_warehouse_locations
             WHERE company_id = :company_id
               AND warehouse_id = :warehouse_id
               AND location_id = :location_id
               AND active = TRUE
               AND deleted_at IS NULL
             FOR UPDATE"
        );
        $statement->execute([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
        ]);
        $location = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($location)) {
            throw new RuntimeException(
                'The movement location is unavailable to the active company.'
            );
        }

        if ($source && empty($location['picking_allowed'])) {
            throw new RuntimeException('The source location does not allow picking.');
        }

        if (!$source && empty($location['receiving_allowed'])) {
            throw new RuntimeException(
                'The destination location does not allow receiving.'
            );
        }

        return $location;
    }

    private function applyBalanceDelta(
        int $companyId,
        int $stockBalanceId,
        float $quantityDelta,
        float $unitCost,
        string $occurredAt
    ): void {
        $statement = $this->connection()->prepare(
            "UPDATE inventory_stock_balances
             SET average_unit_cost = CASE
                    WHEN :quantity_delta > 0
                     AND quantity_on_hand + :quantity_for_total > 0
                    THEN ((quantity_on_hand * average_unit_cost)
                         + (:quantity_for_cost * :unit_cost))
                         / (quantity_on_hand + :quantity_for_average)
                    ELSE average_unit_cost
                 END,
                 quantity_on_hand = quantity_on_hand + :quantity_for_stock,
                 version_number = version_number + 1,
                 last_movement_at = :occurred_at
             WHERE company_id = :company_id
               AND stock_balance_id = :stock_balance_id"
        );
        $positiveQuantity = max(0.0, $quantityDelta);
        $statement->execute([
            'quantity_delta' => $quantityDelta,
            'quantity_for_total' => $positiveQuantity,
            'quantity_for_cost' => $positiveQuantity,
            'unit_cost' => $unitCost,
            'quantity_for_average' => $positiveQuantity,
            'quantity_for_stock' => $quantityDelta,
            'occurred_at' => $occurredAt,
            'company_id' => $companyId,
            'stock_balance_id' => $stockBalanceId,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The stock balance could not be moved.');
        }
    }

    /** @param list<int> $movementIds */
    private function linkValuationJournal(
        int $companyId,
        array $movementIds,
        int $journalBatchId
    ): void {
        if ($movementIds === [] || $journalBatchId <= 0) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($movementIds), '?'));
        $statement = $this->connection()->prepare(
            "UPDATE inventory_valuation_layers SET journal_batch_id=?
             WHERE company_id=? AND stock_movement_id IN ($placeholders)
               AND journal_batch_id IS NULL"
        );
        $statement->execute(array_merge([$journalBatchId, $companyId], $movementIds));
        if ($statement->rowCount() !== count($movementIds)) {
            throw new RuntimeException('Every inventory valuation must link to its accounting journal.');
        }
    }

    private function companyCurrency(int $companyId): string
    {
        $statement = $this->connection()->prepare(
            'SELECT UPPER(default_currency) FROM companies WHERE company_id=:company_id AND deleted_at IS NULL'
        );
        $statement->execute(['company_id' => $companyId]);
        $currency = (string) $statement->fetchColumn();
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new RuntimeException('The company base currency is not configured.');
        }
        return $currency;
    }

    private function positiveOrNull(mixed $value): ?int
    {
        $integer = (int) ($value ?? 0);
        return $integer > 0 ? $integer : null;
    }

    public function markGoodsReceiptPosted(
        int $companyId,
        int $goodsReceiptId,
        int $actorId,
        string $postedAt
    ): void {
        $statement = $this->connection()->prepare(
            "UPDATE inventory_goods_receipts
             SET status = 'posted',
                 posted_by = :posted_by,
                 posted_at = :posted_at
             WHERE company_id = :company_id
               AND goods_receipt_id = :goods_receipt_id
               AND status = 'approved'"
        );

        $statement->execute([
            'posted_by' => $actorId,
            'posted_at' => $postedAt,
            'company_id' => $companyId,
            'goods_receipt_id' => $goodsReceiptId,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException(
                'The goods receipt could not be marked as posted.'
            );
        }
    }
}
