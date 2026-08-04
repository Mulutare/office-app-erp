<?php

declare(strict_types=1);

namespace App\Repositories;

interface FinanceRepository
{
    /**
     * @return array<string, int>
     */
    public function ensureSystemAccounts(
        int $companyId,
        string $currency,
        ?int $actorId
    ): array;

    /**
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public function postBalancedJournal(
        int $companyId,
        string $batchNumber,
        string $sourceType,
        ?string $sourceId,
        ?string $sourceNumber,
        string $postingDate,
        string $currency,
        string $description,
        string $idempotencyKey,
        array $lines,
        ?int $actorId
    ): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function trialBalance(
        int $companyId,
        string $currency
    ): array;

    /**
     * @return array<string, mixed>|null
     */
    public function accountBySystemKey(
        int $companyId,
        string $systemKey
    ): ?array;
}