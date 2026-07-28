<?php

declare(strict_types=1);

namespace App\Repositories;

interface PositionRepository
{
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
        int $positionId
    ): ?array;

    public function codeExists(
        int $companyId,
        string $code,
        ?int $ignorePositionId = null
    ): bool;

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
        int $positionId,
        array $values,
        int $updatedBy
    ): bool;
}
