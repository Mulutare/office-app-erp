<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\FinanceRepository;
use App\Repositories\RepositoryFactory;

final class FinancePostingService
{
    public function __construct(
        private ?FinanceRepository $finance = null
    ) {
        $this->finance ??=
            RepositoryFactory::finance();
    }

    /**
     * @return array<string, int>
     */
    public function ensureSystemAccounts(
        int $companyId,
        string $currency,
        ?int $actorId = null
    ): array {
        return $this->finance->ensureSystemAccounts(
            $companyId,
            $currency,
            $actorId
        );
    }

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
        ?int $actorId = null
    ): array {
        return $this->finance->postBalancedJournal(
            $companyId,
            $batchNumber,
            $sourceType,
            $sourceId,
            $sourceNumber,
            $postingDate,
            $currency,
            $description,
            $idempotencyKey,
            $lines,
            $actorId
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function trialBalance(
        int $companyId,
        string $currency
    ): array {
        return $this->finance->trialBalance(
            $companyId,
            $currency
        );
    }
}