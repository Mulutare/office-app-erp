<?php

declare(strict_types=1);

namespace App\Repositories;

interface JobTitleRepository
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
        int $jobTitleId
    ): ?array;

    public function codeExists(
        int $companyId,
        string $code,
        ?int $ignoreJobTitleId = null
    ): bool;

    public function nameExists(
        int $companyId,
        string $name,
        ?int $ignoreJobTitleId = null
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
        int $jobTitleId,
        array $values,
        int $updatedBy
    ): bool;
}
