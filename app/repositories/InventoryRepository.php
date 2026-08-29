<?php

declare(strict_types=1);

namespace App\Repositories;

interface InventoryRepository
{
    /** @return list<array<string,mixed>> */
    public function goodsReceipts(int $companyId): array;
    /** @return array<string,mixed>|null */
    public function goodsReceipt(int $companyId,int $receiptId): ?array;
    /** @param array<string,mixed> $header @param list<array<string,mixed>> $lines */
    public function createGoodsReceipt(int $companyId,array $header,array $lines,int $actorId): int;
    public function approveGoodsReceipt(int $companyId,int $receiptId,int $actorId,string $approvedAt): void;
    /** @return list<array<string, mixed>> */
    public function deliveryPickings(int $companyId): array;

    /** @return array<string, mixed>|null */
    public function deliveryPicking(int $companyId, int $pickingId): ?array;

    /** @return array<string,mixed> */
    public function reserveDeliveryPicking(int $companyId,int $pickingId,int $actorId,string $reservedAt): array;

    /** @param array<string, mixed> $movement @return array<string, mixed> */
    public function completeStockMovement(array $movement): array;

    /** @return array<string, mixed> */
    public function postTransfer(
        int $companyId,
        int $transferId,
        int $actorId,
        string $postedAt
    ): array;

    /** @return list<int> */
    public function ensureDeliveryPickings(
        int $companyId,
        int $orderId,
        int $actorId,
        string $createdAt
    ): array;

    /** @return array<string, mixed> */
    public function completeSalesOrderDeliveries(
        int $companyId,
        int $orderId,
        int $actorId,
        string $completedAt
    ): array;

    /** @param array<int, float> $quantities @return array<string, mixed> */
    public function completePicking(
        int $companyId,
        int $pickingId,
        array $quantities,
        bool $createBackorder,
        string $idempotencyKey,
        int $actorId,
        string $completedAt
    ): array;

    /** @param array<int, float> $quantities */
    public function createReturnPicking(
        int $companyId,
        int $originalPickingId,
        array $quantities,
        int $actorId,
        string $createdAt
    ): int;

    public function cancelPicking(
        int $companyId,
        int $pickingId,
        string $reason,
        int $actorId,
        string $cancelledAt
    ): void;

    /** @param array<string, mixed> $document */
    public function createStockAdjustment(array $document): int;

    /** @return array<string, mixed> */
    public function postStockAdjustment(
        int $companyId,
        int $adjustmentId,
        int $actorId,
        string $postedAt
    ): array;

    /** @param array<string, mixed> $document */
    public function createScrap(array $document): int;

    /** @return array<string, mixed> */
    public function postScrap(
        int $companyId,
        int $scrapId,
        int $actorId,
        string $postedAt
    ): array;

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
