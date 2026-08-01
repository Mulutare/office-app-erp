<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class FinanceSalesIntegrationHandler implements IntegrationEventHandler
{
    public function supports(string $eventType): bool
    {
        return in_array($eventType, [
            'sales.order.confirmed',
            'sales.payment.recorded',
        ], true);
    }

    public function handle(array $event): void
    {
        $payload = $event['payload'];
        if ($event['event_type'] === 'sales.order.confirmed') {
            $this->openReceivable((int) $event['company_id'], $payload);
            return;
        }
        $this->postReceipt((int) $event['company_id'], $payload);
    }

    /** @param array<string, mixed> $payload */
    private function openReceivable(int $companyId, array $payload): void
    {
        $statement = \db()->prepare(
            "INSERT INTO finance_sales_receivables
                (company_id, order_id, customer_id, order_number, currency,
                 original_amount, paid_amount, balance_amount, due_date, status)
             VALUES
                (:company_id, :order_id, :customer_id, :order_number, :currency,
                 :original_amount, 0, :balance_amount, :due_date, 'open')
             ON DUPLICATE KEY UPDATE
                order_number = VALUES(order_number), currency = VALUES(currency),
                original_amount = VALUES(original_amount),
                balance_amount = GREATEST(VALUES(original_amount) - paid_amount, 0),
                due_date = VALUES(due_date)"
        );
        $statement->execute([
            'company_id' => $companyId,
            'order_id' => $payload['order_id'],
            'customer_id' => $payload['customer_id'],
            'order_number' => $payload['order_number'],
            'currency' => $payload['currency'],
            'original_amount' => $payload['total_amount'],
            'balance_amount' => $payload['total_amount'],
            'due_date' => $payload['due_date'],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function postReceipt(int $companyId, array $payload): void
    {
        $connection = \db();
        $connection->beginTransaction();
        try {
            $receipt = $connection->prepare(
                'INSERT IGNORE INTO finance_sales_receipts
                    (company_id, payment_id, order_id, receipt_number, amount,
                     payment_date, payment_method, reference_number, posted_at)
                 VALUES
                    (:company_id, :payment_id, :order_id, :receipt_number, :amount,
                     :payment_date, :payment_method, :reference_number, NOW())'
            );
            $receipt->execute($payload + ['company_id' => $companyId]);
            if ($receipt->rowCount() > 0) {
                $receivable = $connection->prepare(
                    "UPDATE finance_sales_receivables
                     SET paid_amount = LEAST(original_amount, paid_amount + :paid_increment),
                         balance_amount = GREATEST(original_amount - (paid_amount + :balance_increment), 0),
                         status = CASE
                            WHEN paid_amount + :status_increment >= original_amount THEN 'paid'
                            ELSE 'partially_paid' END
                     WHERE company_id = :company_id AND order_id = :order_id"
                );
                $receivable->execute([
                    'paid_increment' => $payload['amount'],
                    'balance_increment' => $payload['amount'],
                    'status_increment' => $payload['amount'],
                    'company_id' => $companyId,
                    'order_id' => $payload['order_id'],
                ]);
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }
}
