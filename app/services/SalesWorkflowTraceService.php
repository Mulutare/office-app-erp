<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class SalesWorkflowTraceService
{
    private const STAGES = [
        'quotation' => 'Quotation',
        'order' => 'Sales Order',
        'delivery' => 'Delivery',
        'invoice' => 'Customer Invoice',
        'payment' => 'Payment',
        'settlement' => 'Settlement',
        'complete' => 'Complete',
    ];

    /**
     * @param array<string, mixed> $auth
     * @return array<string, mixed>|null
     */
    public function trace(
        int $companyId,
        string $knownType,
        int $knownId,
        array $auth
    ): ?array {
        $knownType = strtolower(trim($knownType));
        if ($companyId < 1 || $knownId < 1 || !isset(self::STAGES[$knownType])) {
            return null;
        }

        $origin = $this->resolveOrigin($companyId, $knownType, $knownId);
        if ($origin === null) {
            return null;
        }

        $orderIds = $origin['order_ids'];
        $quotationRows = $this->quotations($companyId, $orderIds, $origin);
        $orders = $this->orders($companyId, $orderIds);
        $deliveries = $this->pickings($companyId, $orderIds, 'delivery');
        $returns = $this->pickings($companyId, $orderIds, 'customer_return');
        $invoices = $this->invoices($companyId, $orderIds, 'customer_invoice');
        $credits = $this->invoices($companyId, $orderIds, 'customer_credit');
        $payments = $this->payments($companyId, $orderIds);
        $settlements = $this->settlements($companyId, $orderIds);

        $orderedQuantity = $this->sumOrderQuantity($companyId, $orderIds);
        $deliveredQuantity = $this->sumDeliveredQuantity($companyId, $orderIds);
        $invoiceTotal = array_sum(array_map(
            static fn (array $row): float => (float) $row['total_amount'],
            $invoices
        ));
        $residual = array_sum(array_map(
            static fn (array $row): float => (float) $row['residual_amount'],
            $invoices
        ));
        $paid = max(0.0, $invoiceTotal - $residual);

        $canSales = $this->can($auth, 'sales', 'sales.view');
        $canFinance = $this->can($auth, 'finance', 'finance.records.view');
        $canSettlement = $this->can($auth, 'sales', 'sales.settlements.view')
            || $this->can($auth, 'finance', 'finance.settlements.view');

        $stages = [];
        $stages[] = $this->stage(
            'quotation',
            $quotationRows,
            $knownType,
            $canSales,
            $this->quotationStatus($quotationRows),
            $quotationRows === [] ? 'Not created' : null
        );
        $stages[] = $this->stage(
            'order',
            $orders,
            $knownType,
            $canSales,
            $this->orderStatus($orders),
            $orders === [] ? 'Not created' : null
        );
        $deliveryStatus = $deliveries === []
            ? 'not_started'
            : ($orderedQuantity > 0 && $deliveredQuantity + 0.0005 >= $orderedQuantity
                ? 'completed'
                : 'in_progress');
        $stages[] = $this->stage(
            'delivery',
            $deliveries,
            $knownType,
            $canSales,
            $deliveryStatus,
            $deliveries === []
                ? 'Not created'
                : $this->quantity($deliveredQuantity) . ' / '
                    . $this->quantity($orderedQuantity) . ' delivered'
        );
        $invoiceStatus = $invoices === []
            ? 'not_started'
            : ($this->allStatus($invoices, ['posted', 'reversed'])
                ? 'completed'
                : 'in_progress');
        $stages[] = $this->stage(
            'invoice',
            $invoices,
            $knownType,
            $canFinance,
            $invoiceStatus,
            $invoices === [] ? 'Not created' : null
        );
        $paymentStatus = $payments === []
            ? 'not_started'
            : ($invoiceTotal > 0 && $residual < 0.005
                ? 'completed'
                : 'partial');
        $stages[] = $this->stage(
            'payment',
            $payments,
            $knownType,
            $canFinance,
            $paymentStatus,
            $invoiceTotal > 0
                ? $this->money($invoices, $paid) . ' / '
                    . $this->money($invoices, $invoiceTotal)
                : ($payments === [] ? 'Pending' : null)
        );
        $settlementStatus = $settlements === []
            ? 'not_started'
            : ($this->allStatus($settlements, ['closed'], 'workflow_status')
                ? 'completed'
                : 'in_progress');
        $stages[] = $this->stage(
            'settlement',
            $settlements,
            $knownType,
            $canSettlement,
            $settlementStatus,
            $settlements === []
                ? ($payments === [] ? 'Not started' : 'Action required')
                : null
        );

        $complete = $orders !== []
            && $deliveryStatus === 'completed'
            && $invoiceStatus === 'completed'
            && $paymentStatus === 'completed'
            && $settlementStatus === 'completed';
        $stages[] = [
            'code' => 'complete',
            'label' => 'Complete',
            'status' => $complete ? 'completed' : 'not_started',
            'current' => false,
            'reference' => $complete ? 'No normal forward action' : 'In progress',
            'url' => null,
            'clickable' => false,
            'records' => [],
        ];

        $next = $this->nextPending($stages);

        return [
            'chain_reference' => $this->chainReference(
                $quotationRows,
                $orders,
                $origin
            ),
            'current_stage' => $knownType,
            'next_pending_stage' => $next['code'] ?? null,
            'next_action' => $this->nextAction($next, $orders, $invoices),
            'complete' => $complete,
            'stages' => $stages,
            'related_records' => array_merge(
                $this->records('return', $returns, $canSales),
                $this->records('credit', $credits, $canFinance)
            ),
        ];
    }

    /** @return array{order_ids:list<int>,quotation:?array<string,mixed>}|null */
    private function resolveOrigin(int $companyId, string $type, int $id): ?array
    {
        $quotation = null;
        $orderIds = [];

        if ($type === 'quotation') {
            $quotation = $this->one(
                'SELECT quotation_id,quotation_number,sales_order_id,status
                 FROM sales_quotations WHERE company_id=? AND quotation_id=?',
                [$companyId, $id]
            );
            if ($quotation === null) {
                return null;
            }
            if ((int) ($quotation['sales_order_id'] ?? 0) > 0) {
                $orderIds[] = (int) $quotation['sales_order_id'];
            }
        } elseif ($type === 'order') {
            $row = $this->one(
                'SELECT order_id FROM sales_orders
                 WHERE company_id=? AND order_id=? AND deleted_at IS NULL',
                [$companyId, $id]
            );
            if ($row === null) {
                return null;
            }
            $orderIds[] = (int) $row['order_id'];
        } elseif ($type === 'delivery') {
            $row = $this->one(
                'SELECT sales_order_id FROM inventory_pickings
                 WHERE company_id=? AND picking_id=?',
                [$companyId, $id]
            );
            if ($row === null || (int) $row['sales_order_id'] < 1) {
                return null;
            }
            $orderIds[] = (int) $row['sales_order_id'];
        } elseif ($type === 'invoice') {
            $row = $this->one(
                "SELECT sales_order_id FROM finance_invoices
                 WHERE company_id=? AND invoice_id=?
                   AND document_type LIKE 'customer_%'",
                [$companyId, $id]
            );
            if ($row === null || (int) $row['sales_order_id'] < 1) {
                return null;
            }
            $orderIds[] = (int) $row['sales_order_id'];
        } elseif ($type === 'payment') {
            $orderIds = $this->columnInts(
                'SELECT DISTINCT i.sales_order_id
                 FROM finance_payments p
                 INNER JOIN finance_payment_allocations a
                    ON a.company_id=p.company_id AND a.payment_id=p.payment_id
                 INNER JOIN finance_invoices i
                    ON i.company_id=a.company_id AND i.invoice_id=a.invoice_id
                 WHERE p.company_id=? AND p.payment_id=?
                   AND i.document_type=\'customer_invoice\'
                   AND i.sales_order_id IS NOT NULL',
                [$companyId, $id]
            );
            if ($orderIds === []) {
                return null;
            }
        } elseif ($type === 'settlement') {
            $orderIds = $this->columnInts(
                'SELECT DISTINCT sales_order_id FROM sales_settlement_lines
                 WHERE company_id=? AND settlement_id=?',
                [$companyId, $id]
            );
            if ($orderIds === []) {
                return null;
            }
        } else {
            return null;
        }

        sort($orderIds);
        return ['order_ids' => array_values(array_unique($orderIds)), 'quotation' => $quotation];
    }

    /** @return list<array<string,mixed>> */
    private function quotations(int $companyId, array $orderIds, array $origin): array
    {
        if ($origin['quotation'] !== null) {
            return [$origin['quotation'] + [
                'reference' => $origin['quotation']['quotation_number'],
                'url' => '/sales/quotations/' . $origin['quotation']['quotation_id'],
            ]];
        }
        if ($orderIds === []) {
            return [];
        }
        $rows = $this->inRows(
            'SELECT quotation_id,quotation_number,sales_order_id,status
             FROM sales_quotations WHERE company_id=? AND sales_order_id IN (%s)
             ORDER BY quotation_id',
            $companyId,
            $orderIds
        );
        foreach ($rows as &$row) {
            $row['reference'] = $row['quotation_number'];
            $row['url'] = '/sales/quotations/' . $row['quotation_id'];
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function orders(int $companyId, array $ids): array
    {
        $rows = $this->inRows(
            'SELECT order_id,order_number,status,currency,total_amount,paid_amount
             FROM sales_orders WHERE company_id=? AND deleted_at IS NULL
               AND order_id IN (%s) ORDER BY order_id',
            $companyId,
            $ids
        );
        foreach ($rows as &$row) {
            $row['reference'] = $row['order_number'];
            $row['url'] = '/sales/orders/' . $row['order_id'];
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function pickings(int $companyId, array $ids, string $type): array
    {
        $rows = $this->inRows(
            'SELECT picking_id,picking_number,sales_order_id,status,picking_type,
                    original_picking_id,backorder_of_id
             FROM inventory_pickings WHERE company_id=? AND sales_order_id IN (%s)
               AND picking_type=? AND status<>\'cancelled\' ORDER BY picking_id',
            $companyId,
            $ids,
            [$type]
        );
        foreach ($rows as &$row) {
            $row['reference'] = $row['picking_number'];
            $row['url'] = '/sales/deliveries/' . $row['picking_id'];
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function invoices(int $companyId, array $ids, string $type): array
    {
        $rows = $this->inRows(
            'SELECT invoice_id,invoice_number,sales_order_id,status,payment_status,
                    currency,total_amount,residual_amount,document_type
             FROM finance_invoices WHERE company_id=? AND sales_order_id IN (%s)
               AND document_type=? AND status<>\'cancelled\' ORDER BY invoice_id',
            $companyId,
            $ids,
            [$type]
        );
        foreach ($rows as &$row) {
            $row['reference'] = $row['invoice_number'];
            $row['url'] = '/finance/customer-invoices/' . $row['invoice_id'];
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function payments(int $companyId, array $ids): array
    {
        $rows = $this->inRows(
            'SELECT p.payment_id,p.payment_number,p.status,p.currency,
                    p.amount,p.allocated_amount,MIN(i.invoice_id) AS invoice_id
             FROM finance_payments p
             INNER JOIN finance_payment_allocations a
                ON a.company_id=p.company_id AND a.payment_id=p.payment_id
             INNER JOIN finance_invoices i
                ON i.company_id=a.company_id AND i.invoice_id=a.invoice_id
             WHERE p.company_id=? AND i.sales_order_id IN (%s)
               AND p.direction=\'inbound\' AND p.status<>\'cancelled\'
             GROUP BY p.payment_id,p.payment_number,p.status,p.currency,
                      p.amount,p.allocated_amount
             ORDER BY p.payment_id',
            $companyId,
            $ids
        );
        foreach ($rows as &$row) {
            $row['reference'] = $row['payment_number'];
            $row['url'] = '/finance/customer-invoices/' . $row['invoice_id'] . '#payments';
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function settlements(int $companyId, array $ids): array
    {
        $rows = $this->inRows(
            'SELECT DISTINCT s.settlement_id,s.settlement_number,
                    s.workflow_status,s.reconciliation_status
             FROM sales_settlements s
             INNER JOIN sales_settlement_lines l
                ON l.company_id=s.company_id AND l.settlement_id=s.settlement_id
             WHERE s.company_id=? AND l.sales_order_id IN (%s)
             ORDER BY s.settlement_id',
            $companyId,
            $ids
        );
        foreach ($rows as &$row) {
            $row['reference'] = $row['settlement_number'];
            $row['url'] = '/sales/settlements/' . $row['settlement_id'];
        }
        return $rows;
    }

    private function sumOrderQuantity(int $companyId, array $ids): float
    {
        return $this->sumIn(
            'SELECT COALESCE(SUM(quantity),0) FROM sales_order_lines
             WHERE company_id=? AND order_id IN (%s)',
            $companyId,
            $ids
        );
    }

    private function sumDeliveredQuantity(int $companyId, array $ids): float
    {
        return $this->sumIn(
            'SELECT COALESCE(SUM(l.completed_quantity-l.returned_quantity),0)
             FROM inventory_picking_lines l
             INNER JOIN inventory_pickings p
                ON p.company_id=l.company_id AND p.picking_id=l.picking_id
             WHERE p.company_id=? AND p.sales_order_id IN (%s)
               AND p.picking_type=\'delivery\'
               AND p.status IN (\'done\',\'partially_done\')',
            $companyId,
            $ids
        );
    }

    /** @param list<array<string,mixed>> $rows */
    private function stage(
        string $code,
        array $rows,
        string $current,
        bool $allowed,
        string $status,
        ?string $fallback
    ): array {
        $records = $this->records($code, $rows, $allowed);
        return [
            'code' => $code,
            'label' => self::STAGES[$code],
            'status' => $status,
            'current' => $current === $code,
            'reference' => count($records) > 1
                ? count($records) . ' records'
                : ($records[0]['reference'] ?? $fallback ?? ''),
            'url' => count($records) === 1 ? $records[0]['url'] : null,
            'clickable' => count($records) === 1 && $records[0]['clickable'],
            'records' => $records,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function records(string $type, array $rows, bool $allowed): array
    {
        return array_map(static function (array $row) use ($type, $allowed): array {
            $url = $allowed ? appBasePath() . (string) ($row['url'] ?? '') : null;
            return [
                'type' => $type,
                'reference' => (string) ($row['reference'] ?? ''),
                'status' => (string) ($row['status'] ?? $row['workflow_status'] ?? ''),
                'url' => $url,
                'clickable' => $url !== null && $url !== appBasePath(),
            ];
        }, $rows);
    }

    private function can(array $auth, string $module, string $permission): bool
    {
        $permissions = is_array($auth['permissions'] ?? null)
            ? $auth['permissions']
            : [];
        if (!in_array($permission, $permissions, true)) {
            return false;
        }
        if (!empty($auth['is_platform_admin'])) {
            return true;
        }
        $modules = is_array($auth['modules'] ?? null) ? $auth['modules'] : [];
        $codes = [];
        foreach ($modules as $entry) {
            if (is_array($entry) && is_string($entry['code'] ?? null)) {
                $codes[] = $entry['code'];
            } elseif (is_string($entry)) {
                $codes[] = $entry;
            }
        }
        return in_array($module, $codes, true);
    }

    private function quotationStatus(array $rows): string
    {
        if ($rows === []) return 'not_started';
        return $this->allStatus($rows, ['confirmed']) ? 'completed' : 'in_progress';
    }

    private function orderStatus(array $rows): string
    {
        if ($rows === []) return 'not_started';
        return $this->allStatus(
            $rows,
            ['confirmed', 'fulfilled', 'partially_paid', 'paid']
        ) ? 'completed' : 'in_progress';
    }

    private function allStatus(array $rows, array $statuses, string $field = 'status'): bool
    {
        foreach ($rows as $row) {
            if (!in_array((string) ($row[$field] ?? ''), $statuses, true)) {
                return false;
            }
        }
        return $rows !== [];
    }

    private function nextPending(array $stages): ?array
    {
        foreach ($stages as $stage) {
            if ($stage['code'] !== 'complete' && $stage['status'] !== 'completed') {
                return $stage;
            }
        }
        return null;
    }

    private function nextAction(?array $stage, array $orders, array $invoices): ?string
    {
        if ($stage === null) return null;
        return match ($stage['code']) {
            'quotation' => 'Progress quotation',
            'order' => 'Progress Sales Order',
            'delivery' => 'Prepare or validate delivery',
            'invoice' => $invoices === [] ? 'Create customer invoice' : 'Post customer invoice',
            'payment' => 'Record customer payment',
            'settlement' => 'Create or complete settlement',
            default => null,
        };
    }

    private function chainReference(array $quotations, array $orders, array $origin): string
    {
        if (count($orders) === 1) return (string) $orders[0]['order_number'];
        if (count($orders) > 1) return count($orders) . ' linked Sales Orders';
        if ($quotations !== []) return (string) $quotations[0]['quotation_number'];
        return 'Sales workflow';
    }

    private function money(array $invoices, float $amount): string
    {
        $currency = (string) ($invoices[0]['currency'] ?? 'ETB');
        return $currency . ' ' . number_format($amount, 2);
    }

    private function quantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
    }

    private function one(string $sql, array $parameters): ?array
    {
        $statement = \db()->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<int> */
    private function columnInts(string $sql, array $parameters): array
    {
        $statement = \db()->prepare($sql);
        $statement->execute($parameters);
        return array_values(array_filter(
            array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)),
            static fn (int $id): bool => $id > 0
        ));
    }

    /** @return list<array<string,mixed>> */
    private function inRows(
        string $sql,
        int $companyId,
        array $ids,
        array $tail = []
    ): array {
        if ($ids === []) return [];
        $statement = \db()->prepare(sprintf($sql, implode(',', array_fill(0, count($ids), '?'))));
        $statement->execute(array_merge([$companyId], $ids, $tail));
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    private function sumIn(string $sql, int $companyId, array $ids): float
    {
        if ($ids === []) return 0.0;
        $statement = \db()->prepare(sprintf($sql, implode(',', array_fill(0, count($ids), '?'))));
        $statement->execute(array_merge([$companyId], $ids));
        return (float) $statement->fetchColumn();
    }
}
