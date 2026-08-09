<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class FinanceSalesIntegrationHandler
    implements IntegrationEventHandler
{
    public function __construct(
        private ?FinancePostingService $posting = null
    ) {
        $this->posting ??=
            new FinancePostingService();
    }

    public function supports(string $eventType): bool
    {
        return in_array(
            $eventType,
            [
                'sales.order.confirmed',
                'sales.payment.recorded',
                'inventory.sales-order.fulfilled',
            ],
            true
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    public function handle(array $event): void
    {
        $companyId = (int) (
            $event['company_id'] ?? 0
        );
        $eventType = (string) (
            $event['event_type'] ?? ''
        );
        $payload = $event['payload'] ?? null;

        if (
            $companyId <= 0
            || !is_array($payload)
        ) {
            throw new RuntimeException(
                'The finance integration event is invalid.'
            );
        }

        match ($eventType) {
            'sales.order.confirmed' =>
                $this->handleOrderConfirmed(
                    $companyId,
                    $event,
                    $payload
                ),

            'sales.payment.recorded' =>
                $this->handlePaymentRecorded(
                    $companyId,
                    $event,
                    $payload
                ),

            'inventory.sales-order.fulfilled' =>
                $this->handleInventoryFulfilled(
                    $companyId,
                    $event,
                    $payload
                ),

            default => throw new RuntimeException(
                'The finance integration event is unsupported.'
            ),
        };
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $payload
     */
    private function handleOrderConfirmed(
        int $companyId,
        array $event,
        array $payload
    ): void {
        $orderId = $this->positiveInt(
            $payload['order_id'] ?? null,
            'The confirmed sales order ID is invalid.'
        );
        $actorId = $this->nullablePositiveInt($payload['actor_id'] ?? null)
            ?? $this->salesOrderActor($companyId, $orderId);
        $invoiceId = $this->posting->createCustomerInvoiceFromOrder(
            $companyId,
            $orderId,
            'ordered',
            $actorId
        );
        $this->posting->postInvoice($companyId, $invoiceId, $actorId);
        /* Compatibility cache: its values are sourced from the posted invoice. */
        $this->openReceivable($companyId, $payload);
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $payload
     */
    private function handlePaymentRecorded(
        int $companyId,
        array $event,
        array $payload
    ): void {
        $paymentId = $this->positiveInt(
            $payload['payment_id'] ?? null,
            'The sales payment ID is invalid.'
        );
        $orderId = $this->positiveInt(
            $payload['order_id'] ?? null,
            'The paid sales order ID is invalid.'
        );
        $amount = $this->positiveAmount(
            $payload['amount'] ?? null,
            'The payment amount must be positive.'
        );

        $salesContext = $this->salesContext(
            $companyId,
            $orderId
        );
        $currency = $this->currency(
            $salesContext['currency'] ?? null
        );
        $orderNumber = (string) (
            $salesContext['order_number']
            ?? $orderId
        );
        $actorId = $this->nullablePositiveInt($payload['actor_id'] ?? null)
            ?? $this->salesPaymentActor($companyId, $paymentId);
        $invoice = \db()->prepare(
            "SELECT invoice_id, customer_id, residual_amount FROM finance_invoices
             WHERE company_id=:company_id AND sales_order_id=:order_id
               AND document_type='customer_invoice' AND status='posted'
             ORDER BY invoice_id LIMIT 1"
        );
        $invoice->execute(['company_id'=>$companyId,'order_id'=>$orderId]);
        $invoiceRow = $invoice->fetch(PDO::FETCH_ASSOC);
        if (!is_array($invoiceRow)) {
            throw new RuntimeException('The posted customer invoice was not found.');
        }
        $journals = $this->posting->ensureSystemJournals($companyId,$currency,$actorId);
        $allocation = min($amount, round((float)$invoiceRow['residual_amount'],2));
        $this->posting->postCustomerPayment(
            $companyId,(int)$invoiceRow['customer_id'],$journals['bank'],
            $this->dateValue($payload['payment_date'] ?? $this->eventDate($event)),
            $currency,$amount,(string)($payload['payment_method'] ?? 'bank_transfer'),
            isset($payload['reference_number']) ? (string)$payload['reference_number'] : null,
            $allocation > 0 ? [['invoice_id'=>(int)$invoiceRow['invoice_id'],'amount'=>$allocation]] : [],
            $actorId
        );
        $this->postReceipt($companyId,$payload);
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $payload
     */
    private function handleInventoryFulfilled(
        int $companyId,
        array $event,
        array $payload
    ): void {
        $orderId = $this->positiveInt(
            $payload['order_id'] ?? null,
            'The fulfilled sales order ID is invalid.'
        );
        $inventoryCost = round(
            (float) (
                $payload['inventory_cost'] ?? 0
            ),
            2
        );

        /*
         * Zero-cost fulfilments do not create empty journals.
         * This supports legitimate zero-cost stock while
         * preserving the balanced-journal invariant.
         */
        if ($inventoryCost <= 0) {
            return;
        }

        $salesContext = $this->salesContext(
            $companyId,
            $orderId
        );
        $currency = $this->currency(
            $salesContext['currency'] ?? null
        );
        $orderNumber = (string) (
            $salesContext['order_number']
            ?? $orderId
        );
        $branchId = $this->nullablePositiveInt(
            $salesContext['branch_id'] ?? null
        );
        $actorId = $this->nullablePositiveInt(
            $payload['actor_id'] ?? null
        );

        $accounts =
            $this->posting->ensureSystemAccounts(
                $companyId,
                $currency,
                $actorId
            );

        $this->posting->postBalancedJournal(
            $companyId,
            'COGS-' . $orderId,
            'inventory_fulfilment',
            (string) $orderId,
            $orderNumber,
            $this->dateValue(
                $payload['fulfilled_at']
                ?? $this->eventDate($event)
            ),
            $currency,
            'Inventory fulfilled for sales order '
                . $orderNumber,
            'finance-inventory-fulfilled-'
                . $companyId . '-' . $orderId,
            [
                [
                    'account_id' =>
                        $accounts[
                            'cost_of_goods_sold'
                        ],
                    'branch_id' => $branchId,
                    'debit' => $inventoryCost,
                    'credit' => 0,
                    'description' =>
                        'Cost of goods sold',
                ],
                [
                    'account_id' =>
                        $accounts[
                            'inventory_asset'
                        ],
                    'branch_id' => $branchId,
                    'debit' => 0,
                    'credit' => $inventoryCost,
                    'description' =>
                        'Inventory asset reduction',
                ],
            ],
            $actorId
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function openReceivable(
        int $companyId,
        array $payload
    ): void {
        $statement = \db()->prepare(
            "INSERT INTO finance_sales_receivables
                (
                    company_id,
                    order_id,
                    customer_id,
                    order_number,
                    currency,
                    original_amount,
                    paid_amount,
                    balance_amount,
                    due_date,
                    status
                )
             VALUES
                (
                    :company_id,
                    :order_id,
                    :customer_id,
                    :order_number,
                    :currency,
                    :original_amount,
                    0,
                    :balance_amount,
                    :due_date,
                    'open'
                )
             ON DUPLICATE KEY UPDATE
                order_number =
                    VALUES(order_number),
                currency =
                    VALUES(currency),
                original_amount =
                    VALUES(original_amount),
                balance_amount =
                    GREATEST(
                        VALUES(original_amount)
                        - paid_amount,
                        0
                    ),
                due_date =
                    VALUES(due_date)"
        );

        $statement->execute([
            'company_id' => $companyId,
            'order_id' => $payload['order_id'],
            'customer_id' =>
                $payload['customer_id'],
            'order_number' =>
                $payload['order_number'],
            'currency' => $payload['currency'],
            'original_amount' =>
                $payload['total_amount'],
            'balance_amount' =>
                $payload['total_amount'],
            'due_date' => $payload['due_date'],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postReceipt(
        int $companyId,
        array $payload
    ): void {
        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            $receipt = $connection->prepare(
                'INSERT IGNORE INTO
                    finance_sales_receipts
                    (
                        company_id,
                        payment_id,
                        order_id,
                        receipt_number,
                        amount,
                        payment_date,
                        payment_method,
                        reference_number,
                        posted_at
                    )
                 VALUES
                    (
                        :company_id,
                        :payment_id,
                        :order_id,
                        :receipt_number,
                        :amount,
                        :payment_date,
                        :payment_method,
                        :reference_number,
                        NOW()
                    )'
            );

            $receipt->execute(
                $payload + [
                    'company_id' => $companyId,
                ]
            );

            if ($receipt->rowCount() > 0) {
                $receivable =
                    $connection->prepare(
                        "UPDATE
                            finance_sales_receivables
                         SET
                            paid_amount = LEAST(
                                original_amount,
                                paid_amount
                                    + :paid_increment
                            ),
                            balance_amount =
                                GREATEST(
                                    original_amount
                                    - (
                                        paid_amount
                                        + :balance_increment
                                    ),
                                    0
                                ),
                            status = CASE
                                WHEN paid_amount
                                    + :status_increment
                                    >= original_amount
                                THEN 'paid'
                                ELSE 'partially_paid'
                            END
                         WHERE company_id =
                                :company_id
                           AND order_id =
                                :order_id"
                    );

                $receivable->execute([
                    'paid_increment' =>
                        $payload['amount'],
                    'balance_increment' =>
                        $payload['amount'],
                    'status_increment' =>
                        $payload['amount'],
                    'company_id' => $companyId,
                    'order_id' =>
                        $payload['order_id'],
                ]);
            }

            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (Throwable $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function salesContext(
        int $companyId,
        int $orderId
    ): array {
        $statement = \db()->prepare(
            'SELECT
                order_number,
                currency,
                branch_id
             FROM sales_orders
             WHERE company_id = :company_id
               AND order_id = :order_id
               AND deleted_at IS NULL
             LIMIT 1'
        );

        $statement->execute([
            'company_id' => $companyId,
            'order_id' => $orderId,
        ]);

        $order = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($order)) {
            throw new RuntimeException(
                'The related sales order was not found.'
            );
        }

        return $order;
    }

    private function salesOrderActor(int $companyId, int $orderId): int
    {
        $statement = \db()->prepare(
            'SELECT created_by FROM sales_orders WHERE company_id=:company_id AND order_id=:order_id'
        );
        $statement->execute(['company_id'=>$companyId,'order_id'=>$orderId]);
        return $this->positiveInt($statement->fetchColumn(), 'The sales order actor is unavailable.');
    }

    private function salesPaymentActor(int $companyId, int $paymentId): int
    {
        $statement = \db()->prepare(
            'SELECT recorded_by FROM sales_payments WHERE company_id=:company_id AND payment_id=:payment_id'
        );
        $statement->execute(['company_id'=>$companyId,'payment_id'=>$paymentId]);
        return $this->positiveInt($statement->fetchColumn(), 'The sales payment actor is unavailable.');
    }

    /**
     * @param array<string, mixed> $event
     */
    private function eventDate(array $event): string
    {
        return $this->dateValue(
            $event['created_at']
            ?? date('Y-m-d')
        );
    }

    private function dateValue(mixed $value): string
    {
        $text = trim((string) $value);

        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}/',
                $text,
                $matches
            ) !== 1
        ) {
            throw new RuntimeException(
                'The finance posting date is invalid.'
            );
        }

        return $matches[0];
    }

    private function currency(mixed $value): string
    {
        $currency = strtoupper(
            trim((string) $value)
        );

        if (
            preg_match(
                '/^[A-Z]{3}$/',
                $currency
            ) !== 1
        ) {
            throw new RuntimeException(
                'The finance currency is invalid.'
            );
        }

        return $currency;
    }

    private function positiveAmount(
        mixed $value,
        string $message
    ): float {
        $amount = round(
            (float) $value,
            2
        );

        if ($amount <= 0) {
            throw new RuntimeException($message);
        }

        return $amount;
    }

    private function positiveInt(
        mixed $value,
        string $message
    ): int {
        $number = (int) $value;

        if ($number <= 0) {
            throw new RuntimeException($message);
        }

        return $number;
    }

    private function nullablePositiveInt(
        mixed $value
    ): ?int {
        $number = (int) $value;

        return $number > 0
            ? $number
            : null;
    }

    private function requiredString(
        mixed $value,
        string $message
    ): string {
        $text = trim((string) $value);

        if ($text === '') {
            throw new RuntimeException($message);
        }

        return $text;
    }
}
