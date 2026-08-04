<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\InventoryRepository as InventoryRepositoryContract;
use PDO;
use RuntimeException;
use Throwable;

final class InventoryRepository extends MySqlRepository implements InventoryRepositoryContract
{
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

            foreach ($lines as $line) {
                $warehouseId = (int) $line['warehouse_id'];
                $locationId = (int) $line['location_id'];
                $productId = (int) $line['product_id'];
                $quantity = (float) $line['quantity'];
                $unitCost = (float) $line['unit_cost'];
                $lineId = (int) $line['goods_receipt_line_id'];

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

                $balance = $this->stockBalanceForUpdate(
                    $companyId,
                    $warehouseId,
                    $locationId,
                    $productId
                );

                if ($balance === null) {
                    $stockBalanceId = $this->createStockBalance(
                        $companyId,
                        $warehouseId,
                        $locationId,
                        $productId
                    );
                } else {
                    $stockBalanceId = (int) $balance['stock_balance_id'];
                }

                $this->applyReceiptToBalance(
                    $companyId,
                    $stockBalanceId,
                    $quantity,
                    $unitCost,
                    $postedAt
                );

                $this->recordStockMovement(
                    $companyId,
                    $warehouseId,
                    $locationId,
                    $productId,
                    'receipt',
                    $quantity,
                    $unitCost,
                    (string) ($receipt['currency'] ?? 'ETB'),
                    'goods_receipt',
                    $goodsReceiptId,
                    (string) ($receipt['receipt_number'] ?? ''),
                    sprintf(
                        'goods-receipt:%d:line:%d',
                        $goodsReceiptId,
                        $lineId
                    ),
                    isset($line['notes'])
                        ? (string) $line['notes']
                        : null,
                    $postedAt,
                    $actorId
                );

                $movementCount++;
            }

            $this->markGoodsReceiptPosted(
                $companyId,
                $goodsReceiptId,
                $actorId,
                $postedAt
            );

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

    /**
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public function reserveSalesOrder(
        int $companyId,
        int $orderId,
        ?int $branchId,
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
                "SELECT order_id, branch_id, status
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

            $orderBranchId = isset($order['branch_id'])
                ? (int) $order['branch_id']
                : null;

            if ($branchId !== null && $branchId > 0) {
                if (
                    $orderBranchId !== null
                    && $orderBranchId !== $branchId
                ) {
                    throw new RuntimeException(
                        'The sales order branch does not match the reservation request.'
                    );
                }

                $orderBranchId = $branchId;
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

            if ($existing !== []) {
                $existingQuantities = [];
                $allReserved = true;

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

                throw new RuntimeException(
                    'The sales order already has a different inventory reservation. Release it before reserving again.'
                );
            }

            $warehouseStatement = $connection->prepare(
                "SELECT
                    warehouse_id,
                    branch_id,
                    allow_negative_stock,
                    is_default
                 FROM inventory_warehouses
                 WHERE company_id = :company_id
                   AND active = TRUE
                   AND deleted_at IS NULL
                   AND (
                        (
                            :has_branch = 1
                            AND branch_id = :branch_id
                        )
                        OR is_default = TRUE
                   )
                 ORDER BY
                    CASE
                        WHEN :has_branch_order = 1
                         AND branch_id = :branch_id_order
                            THEN 0
                        ELSE 1
                    END,
                    is_default DESC,
                    warehouse_id
                 LIMIT 1
                 FOR UPDATE"
            );
            $warehouseStatement->execute([
                'company_id' => $companyId,
                'has_branch' =>
                    $orderBranchId !== null ? 1 : 0,
                'branch_id' => $orderBranchId,
                'has_branch_order' =>
                    $orderBranchId !== null ? 1 : 0,
                'branch_id_order' => $orderBranchId,
            ]);
            $warehouse = $warehouseStatement->fetch(
                PDO::FETCH_ASSOC
            );

            if (!is_array($warehouse)) {
                throw new RuntimeException(
                    'No active fulfilment warehouse is configured for this sales order.'
                );
            }

            $warehouseId = (int) $warehouse[
                'warehouse_id'
            ];
            $allowNegative = !empty(
                $warehouse['allow_negative_stock']
            );

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
                 )"
            );

            $commitmentCount = 0;
            $allocationCount = 0;
            $totalReserved = 0.0;

            foreach ($normalized as $productId => $required) {
                $commitmentInsert->execute([
                    'company_id' => $companyId,
                    'order_id' => $orderId,
                    'product_id' => $productId,
                    'quantity' => $required,
                    'reserved_at' => $reservedAt,
                ]);

                $commitmentId = (int)
                    $connection->lastInsertId();
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
                       AND balances.product_id =
                            :product_id
                       AND locations.active = TRUE
                       AND locations.deleted_at IS NULL
                       AND locations.picking_allowed = TRUE
                       AND (
                            balances.quantity_available > 0
                            OR :allow_negative = 1
                       )
                     ORDER BY
                        locations.pick_priority,
                        locations.location_id,
                        balances.stock_balance_id
                     FOR UPDATE"
                );
                $candidateStatement->execute([
                    'company_id' => $companyId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'allow_negative' =>
                        $allowNegative ? 1 : 0,
                ]);
                $candidates = $candidateStatement->fetchAll(
                    PDO::FETCH_ASSOC
                );

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

            $balanceUpdate = $connection->prepare(
                "UPDATE inventory_stock_balances
                 SET quantity_on_hand =
                        quantity_on_hand - :quantity,
                     quantity_reserved =
                        quantity_reserved - :reserved_quantity,
                     version_number = version_number + 1,
                     last_movement_at = :fulfilled_at
                 WHERE company_id = :company_id
                   AND stock_balance_id = :stock_balance_id
                   AND quantity_reserved >= :required_reserved
                   AND (
                        :allow_negative = 1
                        OR quantity_on_hand >= :required_on_hand
                   )"
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

                $allowNegative = !empty(
                    $allocation['allow_negative_stock']
                );
                $unitCost = (float)
                    $balance['average_unit_cost'];

                $balanceUpdate->execute([
                    'quantity' => $quantity,
                    'reserved_quantity' => $quantity,
                    'fulfilled_at' => $fulfilledAt,
                    'company_id' => $companyId,
                    'stock_balance_id' => (int)
                        $allocation['stock_balance_id'],
                    'required_reserved' => $quantity,
                    'allow_negative' =>
                        $allowNegative ? 1 : 0,
                    'required_on_hand' => $quantity,
                ]);

                if ($balanceUpdate->rowCount() !== 1) {
                    throw new RuntimeException(
                        'The reserved stock could not be fulfilled safely.'
                    );
                }

                $this->recordStockMovement(
                    $companyId,
                    (int) $allocation['warehouse_id'],
                    (int) $allocation['location_id'],
                    (int) $allocation['product_id'],
                    'fulfilment',
                    -$quantity,
                    $unitCost,
                    (string) ($order['currency'] ?? 'ETB'),
                    'sales_order',
                    $orderId,
                    (string) (
                        $order['order_number'] ?? ''
                    ),
                    sprintf(
                        'sales-order:%d:allocation:%d:fulfilment',
                        $orderId,
                        (int) $allocation['allocation_id']
                    ),
                    'Sales order inventory fulfilment',
                    $fulfilledAt,
                    $actorId
                );

                $allocationUpdate->execute([
                    'quantity' => $quantity,
                    'quantity_for_status' => $quantity,
                    'fulfilled_at' => $fulfilledAt,
                    'company_id' => $companyId,
                    'allocation_id' => (int)
                        $allocation['allocation_id'],
                ]);

                $movementCount++;
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