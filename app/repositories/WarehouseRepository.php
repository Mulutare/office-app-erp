<?php

declare(strict_types=1);

namespace App\Repositories;

interface WarehouseRepository
{
    public function lockCompany(int $companyId): void;

    /** @return list<array<string, mixed>> */
    public function listForCompany(int $companyId): array;

    public function codeExists(
        int $companyId,
        string $code
    ): bool;

    public function defaultWarehouseId(
        int $companyId,
        bool $lock = false
    ): ?int;

    public function branchBelongsToCompany(
        int $companyId,
        int $branchId
    ): bool;

    public function managerBelongsToCompany(
        int $companyId,
        int $userId
    ): bool;

    /** @return list<array<string, mixed>> */
    public function activeBranchesForCompany(
        int $companyId
    ): array;

    /** @return list<array<string, mixed>> */
    public function activeManagersForCompany(
        int $companyId
    ): array;

    /** @param array<string, mixed> $values */
    public function create(
        int $companyId,
        array $values,
        int $createdBy
    ): int;

    public function createDefaultOperationTypes(
        int $companyId,
        int $warehouseId
    ): void;
}
