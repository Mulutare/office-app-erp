<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\FinanceRepository;
use App\Repositories\RepositoryFactory;

final class FinanceOperationsService
{
    private FinanceRepository $finance;
    private TenantContext $tenant;

    public function __construct(
        ?FinanceRepository $finance = null,
        ?TenantContext $tenant = null
    ) {
        $this->finance = $finance ?? RepositoryFactory::finance();
        $this->tenant = $tenant ?? new TenantContext();
    }

    /** @return list<array<string, mixed>> */
    public function customerInvoices(array $filters = []): array
    {
        $rows = $this->finance->customerInvoices($this->tenant->companyId());
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $payment = strtolower(trim((string) ($filters['payment'] ?? '')));
        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        $customer = trim((string) ($filters['customer'] ?? ''));
        return array_values(array_filter($rows, static function (array $row) use ($search, $payment, $dateFrom, $dateTo, $customer): bool {
            if (($row['document_type'] ?? '') !== 'customer_invoice') return false;
            if ($payment !== '' && strtolower((string) ($row['payment_status'] ?? '')) !== $payment) return false;
            if ($dateFrom !== '' && (string) ($row['invoice_date'] ?? '') < $dateFrom) return false;
            if ($dateTo !== '' && (string) ($row['invoice_date'] ?? '') > $dateTo) return false;
            if ($customer !== '' && (string) ($row['customer_name'] ?? '') !== $customer) return false;
            if ($search !== '') {
                $haystack = strtolower(implode(' ', [
                    (string) ($row['invoice_number'] ?? ''),
                    (string) ($row['customer_name'] ?? ''),
                    (string) ($row['order_number'] ?? ''),
                ]));
                if (!str_contains($haystack, $search)) return false;
            }
            return true;
        }));
    }

    /** @return array<string, mixed>|null */
    public function customerInvoice(int $invoiceId): ?array
    {
        if ($invoiceId < 1) {
            return null;
        }

        return $this->finance->customerInvoice(
            $this->tenant->companyId(),
            $invoiceId
        );
    }

    /** @return list<array<string, mixed>> */
    public function paymentJournals(): array
    {
        return $this->finance->customerPaymentJournals(
            $this->tenant->companyId()
        );
    }

    /** @return array<string, mixed> */
    public function postInvoice(int $invoiceId, int $actorId): array
    {
        $invoice = $this->customerInvoice($invoiceId);
        if ($invoice === null) {
            return ['successful' => false, 'errors' => ['form' => 'Invoice was not found.']];
        }
        if ((string) $invoice['status'] !== 'draft') {
            return ['successful' => false, 'errors' => ['form' => 'Only a draft invoice can be posted.']];
        }
        try {
            $result = (new FinancePostingService())->postInvoice(
                $this->tenant->companyId(), $invoiceId, $actorId
            );
            return ['successful' => true, 'result' => $result];
        } catch (\Throwable $exception) {
            return ['successful' => false, 'errors' => ['form' => $exception->getMessage()]];
        }
    }

    /** @return array<string, mixed> */
    public function registerPayment(
        int $invoiceId,
        array $input,
        int $actorId
    ): array {
        $invoice = $this->customerInvoice($invoiceId);
        if ($invoice === null) {
            return ['successful' => false, 'errors' => ['form' => 'Invoice was not found.']];
        }
        if ((string) $invoice['status'] !== 'posted') {
            return ['successful' => false, 'errors' => ['form' => 'Only a posted invoice can receive payment.']];
        }
        if ((string) $invoice['payment_status'] === 'paid') {
            return ['successful' => false, 'errors' => ['form' => 'The invoice is already paid.']];
        }

        $amount = round((float) ($input['amount'] ?? 0), 2);
        $residual = round((float) $invoice['residual_amount'], 2);
        $journalId = (int) ($input['journal_id'] ?? 0);
        $paymentDate = trim((string) ($input['payment_date'] ?? ''));
        $method = trim((string) ($input['method'] ?? 'bank_transfer'));
        if (
            $actorId < 1 || $journalId < 1 || $amount <= 0
            || $amount > $residual
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate) !== 1
            || !in_array($method, ['bank_transfer', 'cash', 'check', 'card'], true)
        ) {
            return [
                'successful' => false,
                'errors' => ['form' => 'Select a journal and enter a positive payment no greater than the current residual.'],
            ];
        }

        try {
            $result = $this->finance->postCustomerPayment(
                $this->tenant->companyId(),
                (int) $invoice['customer_id'],
                $journalId,
                $paymentDate,
                (string) $invoice['currency'],
                $amount,
                $method,
                trim((string) ($input['reference_number'] ?? '')) ?: null,
                [['invoice_id' => $invoiceId, 'amount' => $amount]],
                $actorId
            );
            return ['successful' => true, 'result' => $result];
        } catch (\Throwable $exception) {
            return ['successful' => false, 'errors' => ['form' => $exception->getMessage()]];
        }
    }
}
