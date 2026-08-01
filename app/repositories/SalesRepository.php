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

    /** @param array<string, mixed> $order @param list<array<string, mixed>> $lines */
    public function createOrder(int $companyId, array $order, array $lines, int $actorId): int;

    /** @param array<string, mixed> $payment */
    public function recordPayment(int $companyId, int $orderId, array $payment, int $actorId): void;
}
