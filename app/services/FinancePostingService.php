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

    /** @return array<string, int> */
    public function ensureSystemJournals(int $companyId, string $currency, ?int $actorId = null): array
    {
        return $this->finance->ensureSystemJournals($companyId, $currency, $actorId);
    }

    public function createCustomerInvoiceFromOrder(
        int $companyId,
        int $orderId,
        string $invoicePolicy,
        int $actorId
    ): int {
        return $this->finance->createCustomerInvoiceFromOrder(
            $companyId, $orderId, $invoicePolicy, $actorId
        );
    }

    public function createCustomerCreditFromOrder(
        int $companyId, int $orderId, int $actorId
    ): int {
        return $this->finance->createCustomerCreditFromOrder(
            $companyId, $orderId, $actorId
        );
    }

    /** @return array<string, mixed> */
    public function postInvoice(int $companyId, int $invoiceId, int $actorId): array
    {
        return $this->finance->postInvoice($companyId, $invoiceId, $actorId);
    }

    /** @param list<array{invoice_id:int,amount:mixed}> $allocations @return array<string, mixed> */
    public function postCustomerPayment(
        int $companyId,
        int $customerId,
        int $journalId,
        string $paymentDate,
        string $currency,
        mixed $amount,
        string $method,
        ?string $reference,
        array $allocations,
        int $actorId
    ): array {
        return $this->finance->postCustomerPayment(
            $companyId, $customerId, $journalId, $paymentDate, $currency,
            $amount, $method, $reference, $allocations, $actorId
        );
    }
}
