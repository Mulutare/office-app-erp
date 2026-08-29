<?php

declare(strict_types=1);

namespace App\Repositories;

interface SalesRepository
{
    /** @return array<string, mixed> */
    public function dashboard(int $companyId): array;

    /** @return list<array<string, mixed>> */
    public function orders(int $companyId, int $limit = 50): array;
    public function orderDetail(int $companyId, int $orderId): ?array;

    /** @return list<array<string, mixed>> */
    public function customers(int $companyId): array;
    public function customer(int $companyId, int $customerId): ?array;

    /** @return list<array<string, mixed>> */
    public function products(int $companyId): array;
    public function product(int $companyId, int $productId): ?array;

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
    public function updateCustomer(int $companyId, int $customerId, array $values, int $actorId): void;
    public function setCustomerActive(int $companyId, int $customerId, bool $active, int $actorId): void;

    /** @param array<string, mixed> $values */
    public function createProduct(int $companyId, array $values, int $actorId): int;
    public function updateProduct(int $companyId, int $productId, array $values, int $actorId): void;
    public function setProductActive(int $companyId, int $productId, bool $active, int $actorId): void;

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

    public function pricelists(int $companyId): array;
    public function teams(int $companyId): array;
    public function pricelist(int $companyId, int $pricelistId): ?array;
    public function team(int $companyId, int $teamId): ?array;
    public function quotations(int $companyId): array;
    public function quotation(int $companyId, int $quotationId): ?array;
    public function createPricelist(int $companyId, array $values, int $actorId): int;
    public function updatePricelist(int $companyId, int $pricelistId, array $values): void;
    public function createPricelistRule(int $companyId, int $pricelistId, array $values): int;
    public function updatePricelistRule(int $companyId, int $pricelistId, int $ruleId, array $values): void;
    public function setPricelistRuleActive(int $companyId, int $pricelistId, int $ruleId, bool $active): void;
    public function setPricelistActive(int $companyId, int $pricelistId, bool $active): void;
    public function resolvePrice(int $companyId, ?int $pricelistId, int $productId, float $quantity, string $date, float $basePrice): float;
    public function createTeam(int $companyId, array $values, array $memberIds, int $actorId): int;
    public function updateTeam(int $companyId, int $teamId, array $values, array $memberIds): void;
    public function setTeamActive(int $companyId, int $teamId, bool $active): void;
    public function createQuotation(int $companyId, array $quotation, array $lines, int $actorId): int;
    public function updateQuotation(int $companyId, int $quotationId, array $quotation, array $lines, int $actorId): void;
    /** @param array<string,int>|null $fulfilment */
    public function transitionQuotation(int $companyId, int $quotationId, string $action, int $actorId, ?array $fulfilment = null): array;
}
