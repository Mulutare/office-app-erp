<?php

declare(strict_types=1);

namespace App\Repositories;

interface DepartmentRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public function activeOptions(int $companyId): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function listForCompany(int $companyId): array;

    /**
     * @return array<string, mixed>|null
     */
    public function find(
        int $companyId,
        int $departmentId
    ): ?array;

    public function codeExists(
        int $companyId,
        string $code,
        ?int $ignoreDepartmentId = null
    ): bool;

    public function nameExists(
        int $companyId,
        string $name,
        ?int $ignoreDepartmentId = null
    ): bool;

    public function activeExists(
        int $companyId,
        int $departmentId
    ): bool;

    public function currentEmployeeCount(
        int $companyId,
        int $departmentId
    ): int;

    public function activeChildCount(
        int $companyId,
        int $departmentId
    ): int;

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
        int $departmentId,
        array $values,
        int $updatedBy
    ): bool;
}
