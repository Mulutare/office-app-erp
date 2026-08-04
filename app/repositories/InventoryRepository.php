<?php

declare(strict_types=1);

namespace App\Repositories;

interface InventoryRepository
{
    /** @return array<string, mixed> */
    public function postGoodsReceipt(
        int $companyId,
        int $goodsReceiptId,
        int $actorId,
        string $postedAt
    ): array;

    /** @return array<string, mixed> */
    public function goodsReceiptForUpdate(
        int $companyId,
        int $goodsReceiptId
    ): array;

    /** @return list<array<string, mixed>> */
    public function goodsReceiptLines(
        int $companyId,
        int $goodsReceiptId
    ): array;

    /** @return array<string, mixed>|null */
    public function stockBalanceForUpdate(
        int $companyId,
        int $warehouseId,
        int $locationId,
        int $productId
    ): ?array;

    public function createStockBalance(
        int $companyId,
        int $warehouseId,
        int $locationId,
        int $productId
    ): int;

    public function applyReceiptToBalance(
        int $companyId,
        int $stockBalanceId,
        float $quantity,
        float $unitCost,
        string $occurredAt
    ): void;

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
    ): int;

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
    ): array;


    /** @return array<string, mixed> */
    public function releaseSalesOrderReservation(
        int $companyId,
        int $orderId,
        string $releasedAt
    ): array;

    /** @return array<string, mixed> */
    public function fulfilSalesOrder(
        int $companyId,
        int $orderId,
        int $actorId,
        string $fulfilledAt
    ): array;

    public function markGoodsReceiptPosted(
        int $companyId,
        int $goodsReceiptId,
        int $actorId,
        string $postedAt
    ): void;
}