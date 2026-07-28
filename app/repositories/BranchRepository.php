<?php

declare(strict_types=1);

namespace App\Repositories;

interface BranchRepository
{
    public function lockCompany(int $companyId): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function listForCompany(
        int $companyId
    ): array;

    /**
     * @return array<string, mixed>|null
     */
    public function find(
        int $companyId,
        int $branchId
    ): ?array;

    public function codeExists(
        int $companyId,
        string $code,
        ?int $ignoreBranchId = null
    ): bool;

    public function nameExists(
        int $companyId,
        string $name,
        ?int $ignoreBranchId = null
    ): bool;

    public function headOfficeId(
        int $companyId,
        ?int $ignoreBranchId = null,
        bool $lock = false
    ): ?int;

    /**
     * @param array<string, mixed> $values
     */
    public function create(
        int $companyId,
        array $values,
        int $createdBy
    ): int;

    /**
     * @param array<string, mixed> $values
     */
    public function update(
        int $companyId,
        int $branchId,
        array $values,
        int $updatedBy
    ): bool;
}
