<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\SalesRepository as SalesRepositoryContract;
use PDO;
use RuntimeException;
use Throwable;

final class SalesRepository extends MySqlRepository implements SalesRepositoryContract
{
    public function dashboard(int $companyId): array
    {
        $statement = $this->connection()->prepare(
            "SELECT
                COUNT(*) AS order_count,
                COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total_amount ELSE 0 END), 0) AS sales_total,
                COALESCE(SUM(CASE WHEN status IN ('confirmed','partially_paid') THEN total_amount - paid_amount ELSE 0 END), 0) AS receivable_total,
                COALESCE(SUM(CASE WHEN due_date < CURRENT_DATE AND status IN ('confirmed','partially_paid') THEN total_amount - paid_amount ELSE 0 END), 0) AS overdue_total
             FROM sales_orders
             WHERE company_id = :company_id AND deleted_at IS NULL"
        );
        $statement->execute(['company_id' => $companyId]);
        $summary = $statement->fetch(PDO::FETCH_ASSOC);

        $commission = $this->connection()->prepare(
            "SELECT COALESCE(SUM(commission_amount), 0)
             FROM sales_commissions
             WHERE company_id = :company_id AND status IN ('accrued','approved')"
        );
        $commission->execute(['company_id' => $companyId]);

        return [
            'orderCount' => (int) ($summary['order_count'] ?? 0),
            'salesTotal' => (float) ($summary['sales_total'] ?? 0),
            'receivableTotal' => (float) ($summary['receivable_total'] ?? 0),
            'overdueTotal' => (float) ($summary['overdue_total'] ?? 0),
            'commissionTotal' => (float) $commission->fetchColumn(),
        ];
    }

    public function orders(int $companyId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));
        $statement = $this->connection()->prepare(
            "SELECT orders.*, customers.name AS customer_name,
                    agents.name AS agent_name,
                    (orders.total_amount - orders.paid_amount) AS balance_due
             FROM sales_orders orders
             INNER JOIN sales_customers customers ON customers.customer_id = orders.customer_id
             LEFT JOIN sales_agents agents ON agents.agent_id = orders.agent_id
             WHERE orders.company_id = :company_id AND orders.deleted_at IS NULL
             ORDER BY orders.order_date DESC, orders.order_id DESC
             LIMIT {$limit}"
        );
        $statement->execute(['company_id' => $companyId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function customers(int $companyId): array
    {
        return $this->catalogue(
            'SELECT customer_id, customer_number, name, customer_type, phone, credit_limit, payment_terms_days
             FROM sales_customers WHERE company_id = :company_id AND active = TRUE AND deleted_at IS NULL ORDER BY name',
            $companyId
        );
    }

    public function products(int $companyId): array
    {
        return $this->catalogue(
            'SELECT product_id, sku, name, category, product_type, unit_of_measure, unit_price, commission_rate, serial_tracking
             FROM sales_products WHERE company_id = :company_id AND active = TRUE AND deleted_at IS NULL ORDER BY name',
            $companyId
        );
    }

    public function agents(int $companyId): array
    {
        return $this->catalogue(
            'SELECT agent_id, agent_code, name, agent_type FROM sales_agents
             WHERE company_id = :company_id AND active = TRUE AND deleted_at IS NULL ORDER BY name',
            $companyId
        );
    }

    public function territories(int $companyId): array
    {
        return $this->catalogue(
            'SELECT territory_id, code, name FROM sales_territories
             WHERE company_id = :company_id AND active = TRUE AND deleted_at IS NULL ORDER BY name',
            $companyId
        );
    }

    public function targets(int $companyId): array
    {
        return $this->catalogue(
            "SELECT targets.*, territories.name AS territory_name,
                    agents.name AS agent_name,
                    COALESCE(SUM(CASE
                        WHEN orders.status <> 'cancelled'
                         AND orders.order_date BETWEEN targets.period_start AND targets.period_end
                        THEN orders.total_amount ELSE 0 END), 0) AS achieved_amount
             FROM sales_targets targets
             LEFT JOIN sales_territories territories ON territories.territory_id = targets.territory_id
             LEFT JOIN sales_agents agents ON agents.agent_id = targets.agent_id
             LEFT JOIN sales_orders orders
                ON orders.company_id = targets.company_id
               AND (targets.territory_id IS NULL OR orders.territory_id = targets.territory_id)
               AND (targets.agent_id IS NULL OR orders.agent_id = targets.agent_id)
               AND orders.deleted_at IS NULL
             WHERE targets.company_id = :company_id
             GROUP BY targets.target_id, targets.company_id, targets.territory_id,
                      targets.agent_id, targets.period_start, targets.period_end,
                      targets.target_amount, targets.target_quantity,
                      targets.created_by, targets.created_at,
                      territories.name, agents.name
             ORDER BY targets.period_start DESC, targets.target_id DESC",
            $companyId
        );
    }

    public function createCustomer(int $companyId, array $values, int $actorId): int
    {
        $statement = $this->connection()->prepare(
            'INSERT INTO sales_customers
                (company_id, territory_id, customer_number, name, customer_type, tax_number, email, phone, address, credit_limit, payment_terms_days, created_by)
             VALUES
                (:company_id, :territory_id, :customer_number, :name, :customer_type, :tax_number, :email, :phone, :address, :credit_limit, :payment_terms_days, :created_by)'
        );
        $statement->execute($values + ['company_id' => $companyId, 'created_by' => $actorId]);

        return (int) $this->connection()->lastInsertId();
    }

    public function createProduct(int $companyId, array $values, int $actorId): int
    {
        $statement = $this->connection()->prepare(
            'INSERT INTO sales_products
                (company_id, sku, name, category, product_type, unit_of_measure, unit_price, commission_rate, serial_tracking, created_by)
             VALUES
                (:company_id, :sku, :name, :category, :product_type, :unit_of_measure, :unit_price, :commission_rate, :serial_tracking, :created_by)'
        );
        $statement->execute($values + ['company_id' => $companyId, 'created_by' => $actorId]);

        return (int) $this->connection()->lastInsertId();
    }

    public function createTerritory(int $companyId, array $values, int $actorId): int
    {
        return $this->insertCatalogue(
            'INSERT INTO sales_territories
                (company_id, branch_id, code, name, created_by)
             VALUES (:company_id, :branch_id, :code, :name, :created_by)',
            $companyId,
            $values,
            $actorId
        );
    }

    public function createAgent(int $companyId, array $values, int $actorId): int
    {
        return $this->insertCatalogue(
            'INSERT INTO sales_agents
                (company_id, territory_id, employee_id, agent_code, name, agent_type, phone, created_by)
             VALUES
                (:company_id, :territory_id, :employee_id, :agent_code, :name, :agent_type, :phone, :created_by)',
            $companyId,
            $values,
            $actorId
        );
    }

    public function createTarget(int $companyId, array $values, int $actorId): int
    {
        return $this->insertCatalogue(
            'INSERT INTO sales_targets
                (company_id, territory_id, agent_id, period_start, period_end,
                 target_amount, target_quantity, created_by)
             VALUES
                (:company_id, :territory_id, :agent_id, :period_start, :period_end,
                 :target_amount, :target_quantity, :created_by)',
            $companyId,
            $values,
            $actorId
        );
    }

    public function createOrder(int $companyId, array $order, array $lines, int $actorId): int
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $statement = $connection->prepare(
                'INSERT INTO sales_orders
                    (company_id, branch_id, customer_id, territory_id, agent_id, order_number, order_date, due_date, status, currency, subtotal, discount_amount, tax_amount, total_amount, notes, confirmed_at, created_by, updated_by)
                 VALUES
                    (:company_id, :branch_id, :customer_id, :territory_id, :agent_id, :order_number, :order_date, :due_date, :status, :currency, :subtotal, :discount_amount, :tax_amount, :total_amount, :notes, :confirmed_at, :created_by, :updated_by)'
            );
            $orderValues = $order;
            unset($orderValues['commission_amount']);
            $statement->execute($orderValues + [
                'company_id' => $companyId,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            $orderId = (int) $connection->lastInsertId();
            $lineStatement = $connection->prepare(
                'INSERT INTO sales_order_lines
                    (company_id, order_id, product_id, description, quantity, unit_price, discount_amount, tax_rate, line_total, commission_rate)
                 VALUES
                    (:company_id, :order_id, :product_id, :description, :quantity, :unit_price, :discount_amount, :tax_rate, :line_total, :commission_rate)'
            );
            foreach ($lines as $line) {
                $lineStatement->execute($line + ['company_id' => $companyId, 'order_id' => $orderId]);
            }

            if ($order['agent_id'] !== null && (float) $order['commission_amount'] > 0) {
                $commission = $connection->prepare(
                    "INSERT INTO sales_commissions
                        (company_id, order_id, agent_id, commission_amount, status, accrued_at)
                     VALUES (:company_id, :order_id, :agent_id, :amount, 'accrued', NOW())"
                );
                $commission->execute([
                    'company_id' => $companyId, 'order_id' => $orderId,
                    'agent_id' => $order['agent_id'], 'amount' => $order['commission_amount'],
                ]);
            }

            if ($order['status'] === 'confirmed') {
                $this->enqueue(
                    $connection,
                    $companyId,
                    'sales.order.confirmed',
                    'sales_order',
                    (string) $orderId,
                    [
                        'order_id' => $orderId,
                        'order_number' => $order['order_number'],
                        'customer_id' => $order['customer_id'],
                        'currency' => $order['currency'],
                        'total_amount' => $order['total_amount'],
                        'due_date' => $order['due_date'],
                        'lines' => array_map(
                            static fn (array $line): array => [
                                'product_id' => $line['product_id'],
                                'quantity' => $line['quantity'],
                            ],
                            $lines
                        ),
                    ]
                );
            }

            $connection->commit();
            return $orderId;
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    public function recordPayment(int $companyId, int $orderId, array $payment, int $actorId): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $order = $connection->prepare(
                "SELECT total_amount, paid_amount, status FROM sales_orders
                 WHERE company_id = :company_id AND order_id = :order_id AND deleted_at IS NULL FOR UPDATE"
            );
            $order->execute(['company_id' => $companyId, 'order_id' => $orderId]);
            $row = $order->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || in_array($row['status'], ['draft', 'cancelled'], true)) {
                throw new RuntimeException('The order cannot receive a payment.');
            }
            $balance = (float) $row['total_amount'] - (float) $row['paid_amount'];
            if ((float) $payment['amount'] > $balance + 0.0001) {
                throw new RuntimeException('Payment exceeds the order balance.');
            }
            $insert = $connection->prepare(
                'INSERT INTO sales_payments
                    (company_id, order_id, receipt_number, payment_date, amount, payment_method, reference_number, notes, recorded_by)
                 VALUES
                    (:company_id, :order_id, :receipt_number, :payment_date, :amount, :payment_method, :reference_number, :notes, :recorded_by)'
            );
            $insert->execute($payment + [
                'company_id' => $companyId, 'order_id' => $orderId, 'recorded_by' => $actorId,
            ]);
            $paymentId = (int) $connection->lastInsertId();
            $newPaid = (float) $row['paid_amount'] + (float) $payment['amount'];
            $status = $newPaid + 0.0001 >= (float) $row['total_amount'] ? 'paid' : 'partially_paid';
            $update = $connection->prepare(
                'UPDATE sales_orders SET paid_amount = :paid_amount, status = :status, updated_by = :updated_by
                 WHERE company_id = :company_id AND order_id = :order_id'
            );
            $update->execute([
                'paid_amount' => $newPaid, 'status' => $status, 'updated_by' => $actorId,
                'company_id' => $companyId, 'order_id' => $orderId,
            ]);
            $this->enqueue(
                $connection,
                $companyId,
                'sales.payment.recorded',
                'sales_payment',
                (string) $paymentId,
                [
                    'payment_id' => $paymentId,
                    'order_id' => $orderId,
                    'receipt_number' => $payment['receipt_number'],
                    'payment_date' => $payment['payment_date'],
                    'amount' => $payment['amount'],
                    'payment_method' => $payment['payment_method'],
                    'reference_number' => $payment['reference_number'],
                ]
            );
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    private function catalogue(string $sql, int $companyId): array
    {
        $statement = $this->connection()->prepare($sql);
        $statement->execute(['company_id' => $companyId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $values */
    private function insertCatalogue(
        string $sql,
        int $companyId,
        array $values,
        int $actorId
    ): int {
        $statement = $this->connection()->prepare($sql);
        $statement->execute($values + [
            'company_id' => $companyId,
            'created_by' => $actorId,
        ]);

        return (int) $this->connection()->lastInsertId();
    }

    /** @param array<string, mixed> $payload */
    private function enqueue(
        PDO $connection,
        int $companyId,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload
    ): void {
        $eventId = sprintf(
            '%s-%s-4%s-%s%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            dechex(random_int(8, 11)),
            bin2hex(random_bytes(1)),
            bin2hex(random_bytes(6))
        );
        $statement = $connection->prepare(
            "INSERT INTO integration_outbox
                (event_id, company_id, event_type, aggregate_type,
                 aggregate_id, payload_json, status, available_at)
             VALUES
                (:event_id, :company_id, :event_type, :aggregate_type,
                 :aggregate_id, :payload_json, 'pending', NOW())"
        );
        $statement->execute([
            'event_id' => $eventId,
            'company_id' => $companyId,
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload_json' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            ),
        ]);
    }
}
