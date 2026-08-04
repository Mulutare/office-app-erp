<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\InventoryRepository as InventoryRepositoryContract;
use PDO;
use RuntimeException;

final class InventoryRepository extends MySqlRepository implements InventoryRepositoryContract
{
    public function goodsReceiptForUpdate(
        int $companyId,
        int $goodsReceiptId
    ): array {
        $statement = $this->connection()->prepare(
            "SELECT *
             FROM inventory_goods_receipts
             WHERE company_id = :company_id
               AND goods_receipt_id = :goods_receipt_id
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
            "SELECT *
             FROM inventory_stock_balances
             WHERE company_id = :company_id
               AND warehouse_id = :warehouse_id
               AND location_id = :location_id
               AND product_id = :product_id
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

    public function applyReceiptToBalance(
        int $companyId,
        int $stockBalanceId,
        float $quantity,
        float $unitCost,
        string $occurredAt
    ): void {
        $statement = $this->connection()->prepare(
            "UPDATE inventory_stock_balances
             SET average_unit_cost = CASE
                    WHEN quantity_on_hand + :quantity = 0
                        THEN :unit_cost
                    ELSE (
                        (quantity_on_hand * average_unit_cost)
                        + (:quantity_for_cost * :unit_cost_for_cost)
                    ) / (quantity_on_hand + :quantity_for_total)
                 END,
                 quantity_on_hand =
                    quantity_on_hand + :quantity_for_stock,
                 version_number = version_number + 1,
                 last_movement_at = :occurred_at
             WHERE company_id = :company_id
               AND stock_balance_id = :stock_balance_id"
        );

        $statement->execute([
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'quantity_for_cost' => $quantity,
            'unit_cost_for_cost' => $unitCost,
            'quantity_for_total' => $quantity,
            'quantity_for_stock' => $quantity,
            'occurred_at' => $occurredAt,
            'company_id' => $companyId,
            'stock_balance_id' => $stockBalanceId,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException(
                'The inventory stock balance could not be updated.'
            );
        }
    }

    public function recordStockMovement(
        int $companyId,
        int $warehouseId,
        int $locationId,
        int $productId,
        string $movementType,
        float $quantityDelta,
        float $unitCost,
        string $currency,
        string $referenceType,
        ?int $referenceId,
        ?string $referenceNumber,
        string $idempotencyKey,
        ?string $notes,
        string $occurredAt,
        int $actorId
    ): int {
        $statement = $this->connection()->prepare(
            "INSERT INTO inventory_stock_movements (
                company_id,
                warehouse_id,
                location_id,
                product_id,
                movement_type,
                quantity_delta,
                unit_cost,
                currency,
                reference_type,
                reference_id,
                reference_number,
                idempotency_key,
                notes,
                occurred_at,
                recorded_by
             ) VALUES (
                :company_id,
                :warehouse_id,
                :location_id,
                :product_id,
                :movement_type,
                :quantity_delta,
                :unit_cost,
                :currency,
                :reference_type,
                :reference_id,
                :reference_number,
                :idempotency_key,
                :notes,
                :occurred_at,
                :recorded_by
             )"
        );

        $statement->execute([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
            'product_id' => $productId,
            'movement_type' => $movementType,
            'quantity_delta' => $quantityDelta,
            'unit_cost' => $unitCost,
            'currency' => strtoupper($currency),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reference_number' => $referenceNumber,
            'idempotency_key' => $idempotencyKey,
            'notes' => $notes,
            'occurred_at' => $occurredAt,
            'recorded_by' => $actorId,
        ]);

        return (int) $this->connection()->lastInsertId();
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