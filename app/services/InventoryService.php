<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\InventoryRepository;
use App\Repositories\RepositoryFactory;
use RuntimeException;

final class InventoryService
{
    private InventoryRepository $inventory;
    private TenantContext $tenant;

    public function __construct(
        ?InventoryRepository $inventory = null,
        ?TenantContext $tenant = null
    ) {
        $this->inventory = $inventory
            ?? RepositoryFactory::inventory();

        $this->tenant = $tenant
            ?? new TenantContext();
    }

    /**
     * Post an approved goods receipt into inventory exactly once.
     *
     * @return array<string, mixed>
     */
    public function postGoodsReceipt(
        int $goodsReceiptId,
        int $actorId
    ): array {
        if ($goodsReceiptId < 1) {
            throw new \InvalidArgumentException(
                'A valid goods receipt ID is required.'
            );
        }

        if ($actorId < 1) {
            throw new \InvalidArgumentException(
                'A valid actor ID is required.'
            );
        }

        try {
            $result = $this->inventory->postGoodsReceipt(
                $this->tenant->companyId(),
                $goodsReceiptId,
                $actorId,
                date('Y-m-d H:i:s')
            );

            return [
                'successful' => true,
                'result' => $result,
            ];
        } catch (\Throwable $exception) {
            return [
                'successful' => false,
                'errors' => [
                    'form' => $exception->getMessage(),
                ],
            ];
        }
    }

    public function receipts(): array{return $this->inventory->goodsReceipts($this->tenant->companyId());}
    public function receipt(int $id): ?array{return $this->inventory->goodsReceipt($this->tenant->companyId(),$id);}
    public function receiptOptions(): array
    {$company=$this->tenant->companyId();$w=\db()->prepare("SELECT w.warehouse_id,w.code,w.name FROM inventory_warehouses w WHERE w.company_id=:company_id AND w.active=TRUE AND w.deleted_at IS NULL AND EXISTS(SELECT 1 FROM inventory_operation_types o WHERE o.company_id=w.company_id AND o.warehouse_id=w.warehouse_id AND o.operation_kind='receipt' AND o.active=TRUE AND o.is_default=TRUE AND o.default_source_location_id IS NOT NULL AND o.default_destination_location_id IS NOT NULL) ORDER BY w.is_default DESC,w.name");$w->execute(['company_id'=>$company]);$p=\db()->prepare("SELECT product_id,sku,name,unit_of_measure FROM sales_products WHERE company_id=:company_id AND active=TRUE AND deleted_at IS NULL AND product_type<>'service' ORDER BY name");$p->execute(['company_id'=>$company]);return['warehouses'=>$w->fetchAll(\PDO::FETCH_ASSOC),'products'=>$p->fetchAll(\PDO::FETCH_ASSOC)];}
    public function createGoodsReceipt(array $input,int $actorId): array
    {$warehouse=(int)($input['warehouse_id']??0);$supplier=trim((string)($input['supplier_name']??''));$date=trim((string)($input['receipt_date']??''));$currency=strtoupper(trim((string)($input['currency']??'ETB')));$productIds=(array)($input['product_id']??[]);$quantities=(array)($input['quantity']??[]);$costs=(array)($input['unit_cost']??[]);$lines=[];foreach($productIds as $i=>$productId){$pid=(int)$productId;$qty=(float)($quantities[$i]??0);$cost=(float)($costs[$i]??0);if($pid>0&&$qty>0)$lines[]=['product_id'=>$pid,'quantity'=>$qty,'unit_cost'=>$cost,'notes'=>null];}if($warehouse<1||$supplier===''||preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)!==1||preg_match('/^[A-Z]{3}$/',$currency)!==1||$lines===[])return['successful'=>false,'errors'=>['form'=>'Warehouse, supplier, receipt date, currency and at least one positive product quantity are required.']];try{$id=$this->inventory->createGoodsReceipt($this->tenant->companyId(),['warehouse_id'=>$warehouse,'supplier_name'=>$supplier,'supplier_reference'=>trim((string)($input['supplier_reference']??''))?:null,'receipt_date'=>$date,'currency'=>$currency,'notes'=>trim((string)($input['notes']??''))?:null],$lines,$actorId);return['successful'=>true,'id'=>$id];}catch(\Throwable $e){return['successful'=>false,'errors'=>['form'=>$e->getMessage()]];}}
    public function approveGoodsReceipt(int $id,int $actorId): array
    {try{$this->inventory->approveGoodsReceipt($this->tenant->companyId(),$id,$actorId,date('Y-m-d H:i:s'));return['successful'=>true,'id'=>$id];}catch(\Throwable $e){return['successful'=>false,'errors'=>['form'=>$e->getMessage()]];}}

    /** @return array<string, mixed> */
    public function postTransfer(int $transferId, int $actorId): array
    {
        if ($transferId < 1 || $actorId < 1) {
            return [
                'successful' => false,
                'errors' => ['form' => 'A valid transfer and actor are required.'],
            ];
        }

        try {
            return [
                'successful' => true,
                'result' => $this->inventory->postTransfer(
                    $this->tenant->companyId(),
                    $transferId,
                    $actorId,
                    date('Y-m-d H:i:s')
                ),
            ];
        } catch (\Throwable $exception) {
            return [
                'successful' => false,
                'errors' => ['form' => $exception->getMessage()],
            ];
        }
    }

    /** @return array<string,mixed> */
    public function capitalizeAssetStock(int $warehouseId,int $locationId,int $productId,float $quantity,int $assetId,string $assetNumber,int $actorId): array
    {
        if($warehouseId<1||$locationId<1||$productId<1||$quantity<=0||$assetId<1||$actorId<1)throw new RuntimeException('Valid source stock, asset, quantity and actor are required.');
        return $this->inventory->completeStockMovement([
            'companyId'=>$this->tenant->companyId(),'sourceWarehouseId'=>$warehouseId,'sourceLocationId'=>$locationId,
            'destinationWarehouseId'=>null,'destinationLocationId'=>null,'productId'=>$productId,'quantity'=>$quantity,
            'movementType'=>'issue','currency'=>(string)($_SESSION['auth']['company']['default_currency']??'ETB'),
            'referenceType'=>'asset_capitalization','referenceId'=>$assetId,'referenceNumber'=>$assetNumber,
            'idempotencyKey'=>'asset-capitalization-'.$this->tenant->companyId().'-'.$assetId,
            'notes'=>'Stock issued to Internal Asset Use','occurredAt'=>date('Y-m-d H:i:s'),'actorId'=>$actorId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function workspace(): array
    {
        $companyId = $this->tenant->companyId();
        $connection = \db();

        $summary = $connection->prepare(
            "SELECT
                (
                    SELECT COUNT(*)
                    FROM inventory_warehouses
                    WHERE company_id = :warehouse_company
                ) AS warehouse_count,
                (
                    SELECT COUNT(*)
                    FROM inventory_stock_balances balances
                    INNER JOIN inventory_warehouse_locations locations
                      ON locations.company_id = balances.company_id
                     AND locations.warehouse_id = balances.warehouse_id
                     AND locations.location_id = balances.location_id
                    WHERE balances.company_id = :balance_company
                      AND locations.location_usage IN ('internal', 'transit')
                ) AS stock_item_count,
                (
                    SELECT COALESCE(
                        SUM(quantity_on_hand),
                        0
                    )
                    FROM inventory_stock_balances balances
                    INNER JOIN inventory_warehouse_locations locations
                      ON locations.company_id = balances.company_id
                     AND locations.warehouse_id = balances.warehouse_id
                     AND locations.location_id = balances.location_id
                    WHERE balances.company_id = :quantity_company
                      AND locations.location_usage IN ('internal', 'transit')
                ) AS total_quantity,
                (
                    SELECT COUNT(*)
                    FROM inventory_goods_receipts
                    WHERE company_id = :receipt_company
                      AND status <> 'posted'
                ) AS pending_receipt_count"
        );

        $summary->execute([
            'warehouse_company' => $companyId,
            'balance_company' => $companyId,
            'quantity_company' => $companyId,
            'receipt_company' => $companyId,
        ]);

        $summaryRow = $summary->fetch(
            \PDO::FETCH_ASSOC
        );

        if (!is_array($summaryRow)) {
            $summaryRow = [];
        }

        $stock = $connection->prepare(
            "SELECT
                balances.stock_balance_id,
                balances.warehouse_id,
                balances.location_id,
                balances.product_id,
                products.sku,
                products.name AS product_name,
                balances.quantity_on_hand,
                balances.quantity_reserved,
                (
                    balances.quantity_on_hand
                    - balances.quantity_reserved
                ) AS quantity_available,
                balances.average_unit_cost,
                balances.last_movement_at,
                warehouses.name AS warehouse_name,
                locations.name AS location_name
             FROM inventory_stock_balances balances
             LEFT JOIN sales_products products
                ON products.company_id =
                    balances.company_id
               AND products.product_id =
                    balances.product_id
             LEFT JOIN inventory_warehouses warehouses
                ON warehouses.company_id = balances.company_id
               AND warehouses.warehouse_id = balances.warehouse_id
             LEFT JOIN inventory_warehouse_locations locations
                ON locations.company_id = balances.company_id
               AND locations.warehouse_id = balances.warehouse_id
               AND locations.location_id = balances.location_id
             WHERE balances.company_id =
                :company_id
               AND locations.location_usage IN ('internal', 'transit')
             ORDER BY
                products.name,
                balances.stock_balance_id
             LIMIT 100"
        );

        $stock->execute([
            'company_id' => $companyId,
        ]);

        $receipts = $connection->prepare(
            "SELECT
                goods_receipt_id,
                receipt_number,
                status,
                currency,
                receipt_date,
                posted_at
             FROM inventory_goods_receipts
             WHERE company_id = :company_id
             ORDER BY goods_receipt_id DESC
             LIMIT 25"
        );

        $receipts->execute([
            'company_id' => $companyId,
        ]);

        $movements = $connection->prepare(
            "SELECT
                movements.movement_id,
                movements.reference_number,
                movements.reference_type,
                movements.reference_id,
                movements.movement_type,
                movements.requested_quantity,
                movements.completed_quantity,
                movements.status,
                movements.occurred_at,
                products.sku,
                products.name AS product_name,
                source_locations.name AS source_location_name,
                destination_locations.name AS destination_location_name,
                operation_types.name AS operation_type_name
             FROM inventory_stock_movements movements
             INNER JOIN sales_products products
                ON products.company_id = movements.company_id
               AND products.product_id = movements.product_id
             LEFT JOIN inventory_warehouse_locations source_locations
                ON source_locations.company_id = movements.company_id
               AND source_locations.warehouse_id = movements.source_warehouse_id
               AND source_locations.location_id = movements.source_location_id
             LEFT JOIN inventory_warehouse_locations destination_locations
                ON destination_locations.company_id = movements.company_id
               AND destination_locations.warehouse_id = movements.destination_warehouse_id
               AND destination_locations.location_id = movements.destination_location_id
             LEFT JOIN inventory_operation_types operation_types
                ON operation_types.company_id = movements.company_id
               AND operation_types.warehouse_id = movements.warehouse_id
               AND operation_types.operation_type_id = movements.operation_type_id
             WHERE movements.company_id = :company_id
             ORDER BY movements.movement_id DESC
             LIMIT 50"
        );
        $movements->execute(['company_id' => $companyId]);

        return [
            'inventorySummary' => [
                'warehouseCount' => (int) (
                    $summaryRow['warehouse_count'] ?? 0
                ),
                'stockItemCount' => (int) (
                    $summaryRow['stock_item_count'] ?? 0
                ),
                'totalQuantity' => (float) (
                    $summaryRow['total_quantity'] ?? 0
                ),
                'pendingReceiptCount' => (int) (
                    $summaryRow[
                        'pending_receipt_count'
                    ] ?? 0
                ),
            ],
            'stockBalances' => $stock->fetchAll(
                \PDO::FETCH_ASSOC
            ),
            'goodsReceipts' => $receipts->fetchAll(
                \PDO::FETCH_ASSOC
            ),
            'stockMovements' => $movements->fetchAll(\PDO::FETCH_ASSOC),
        ];
    }
}
