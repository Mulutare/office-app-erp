<?php

declare(strict_types=1);

namespace App\Repositories;

interface FinanceRepository
{
    /** @return list<array<string, mixed>> */
    public function customerInvoices(int $companyId): array;

    /** @return array<string, mixed>|null */
    public function customerInvoice(int $companyId, int $invoiceId): ?array;

    /** @return list<array<string, mixed>> */
    public function customerPaymentJournals(int $companyId): array;

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

    /** @return array<string, int> */
    public function ensureSystemJournals(int $companyId, string $currency, ?int $actorId): array;

    public function createCustomerInvoiceFromOrder(
        int $companyId,
        int $orderId,
        string $invoicePolicy,
        int $actorId
    ): int;

    public function createCustomerCreditFromOrder(
        int $companyId,
        int $orderId,
        int $actorId
    ): int;

    /** @return array<string, mixed> */
    public function postInvoice(int $companyId, int $invoiceId, int $actorId): array;

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
    ): array;
    /**
     * @return list<array<string, mixed>>
     */
    public function salesReceivableSummary(
        int $companyId
    ): array;

    /**
     * @param array<string, mixed> $filters
     */
    public function countSalesReceivables(
        int $companyId,
        array $filters
    ): int;

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function salesReceivables(
        int $companyId,
        array $filters,
        int $limit,
        int $offset
    ): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function recentSalesReceipts(
        int $companyId,
        int $limit
    ): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function recentJournalBatches(
        int $companyId,
        int $limit
    ): array;
}
