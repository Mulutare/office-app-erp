<?php

declare(strict_types=1);

namespace App\Repositories;

interface SalesRepository
{
    /** @return array<string, mixed> */
    public function dashboard(int $companyId): array;

    /** @return list<array<string, mixed>> */
    public function orders(int $companyId, int $limit = 50): array;

    /** @return list<array<string, mixed>> */
    public function customers(int $companyId): array;

    /** @return list<array<string, mixed>> */
    public function products(int $companyId): array;

    /** @return list<array<string, mixed>> */
    public function agents(int $companyId): array;

    /** @return list<array<string, mixed>> */
    public function territories(int $companyId): array;

    /** @return list<array<string, mixed>> */
    public function targets(int $companyId): array;

    /** @return list<array<string, mixed>> */
    public function commissions(int $companyId): array;

    /** @return list<array<string, mixed>> */
    public function serialNumbers(int $companyId): array;

    public function customerOutstanding(
        int $companyId,
        int $customerId
    ): float;

    public function reserveDocumentNumber(
        int $companyId,
        ?int $branchId,
        string $documentType
    ): string;

    /** @param array<string, mixed> $values */
    public function createCustomer(int $companyId, array $values, int $actorId): int;

    /** @param array<string, mixed> $values */
    public function createProduct(int $companyId, array $values, int $actorId): int;

    /** @param array<string, mixed> $values */
    public function createTerritory(int $companyId, array $values, int $actorId): int;

    /** @param array<string, mixed> $values */
    public function createAgent(int $companyId, array $values, int $actorId): int;

    /** @param array<string, mixed> $values */
    public function createTarget(int $companyId, array $values, int $actorId): int;

    /** @param list<string> $serialNumbers */
    public function registerSerialNumbers(
        int $companyId,
        int $productId,
        array $serialNumbers,
        int $actorId
    ): int;

    /** @return array<string, mixed> */
    public function transitionOrder(
        int $companyId,
        int $orderId,
        string $action,
        ?string $reason,
        int $actorId,
        string $idempotencyKey
    ): array;

    /** @return array<string, mixed> */
    public function transitionCommission(
        int $companyId,
        int $commissionId,
        string $action,
        int $actorId
    ): array;

    /** @param array<string, mixed> $order @param list<array<string, mixed>> $lines */
    public function createOrder(int $companyId, array $order, array $lines, int $actorId): int;

    /** @param array<string, mixed> $payment */
    public function recordPayment(int $companyId, int $orderId, array $payment, int $actorId): void;
}
