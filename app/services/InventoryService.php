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
