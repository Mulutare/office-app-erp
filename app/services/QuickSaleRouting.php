<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RepositoryFactory;
use PDO;
use RuntimeException;
use Throwable;

/** Routing extends the existing Quick Sale; no second stock request is created. */
trait QuickSaleRouting
{
    /** Read-only overview; action authority remains with the assigned manager. */
    private function hierarchySales(int $company, int $actor): array
    {
        $scope = new SalesHierarchyScope();
        $ids = $scope->userIds($company, $actor);
        if ($ids === []) return [];
        $owners = implode(',', array_map('intval', $ids));
        $filter = $scope->hasCompanyWideAccess($company, $actor)
            ? '1=1' : "(qs.user_id IN ($owners) OR qs.manager_user_id=?)";
        $statement = \db()->prepare("SELECT qs.quick_sale_id,qs.status,q.quotation_number,
            u.display_name AS agent_name,m.display_name AS manager_name
            FROM sales_quick_sales qs
            INNER JOIN sales_quotations q ON q.company_id=qs.company_id AND q.quotation_id=qs.quotation_id
            INNER JOIN users u ON u.user_id=qs.user_id
            INNER JOIN users m ON m.user_id=qs.manager_user_id
            WHERE qs.company_id=? AND $filter ORDER BY qs.quick_sale_id DESC LIMIT 100");
        $statement->execute($filter === '1=1' ? [$company] : [$company, $actor]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function routingAudit(int $company, int $sale, int $actor, string $action, array $values): void
    {
        RepositoryFactory::auditLogs()->record($actor, 'quick_sale.' . $action, 'sales',
            'sales_quick_sales', (string) $sale, null, $values, $company);
    }

    private function routingHistory(int $company, int $sale): array
    {
        $statement = \db()->prepare(
            "SELECT a.action,a.created_at,a.new_values,u.display_name AS actor_name
             FROM audit_logs a LEFT JOIN users u ON u.user_id=a.user_id
             WHERE a.company_id=? AND a.table_name='sales_quick_sales' AND a.record_id=?
             ORDER BY a.audit_log_id"
        );
        $statement->execute([$company, (string) $sale]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The same lock protects allocation and escalation across their service transactions. */
    private function routingLock(int $company, int $sale, callable $operation): array
    {
        $name = 'quick-sale-routing:' . $company . ':' . $sale;
        $lock = \db()->prepare('SELECT GET_LOCK(?,10)');
        $held = false;
        try {
            $lock->execute([$name]);
            $held = (int) $lock->fetchColumn() === 1;
            if (!$held) throw new RuntimeException('This request is being processed. Please try again.');
            return $operation();
        } catch (Throwable $e) {
            return ['successful' => false, 'errors' => ['form' => $e->getMessage()]];
        } finally {
            if ($held) \db()->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }
    }

    /** Stock is evaluated per operational source, matching the single-source allocator. */
    private function stockCheck(int $company, int $actor, int $warehouse, array $lines): array
    {
        $required = [];
        foreach ($lines as $line) {
            $id = (int) $line['product_id'];
            $required[$id] = ($required[$id] ?? 0) + (float) $line['quantity'];
        }
        $checked = [];
        $sufficient = [];
        foreach ($this->operationalAccess->locationsForUser($company, $actor, $warehouse) as $location) {
            $id = (int) $location['location_id'];
            $balances = $this->operationalAccess->availability($company, $actor, $warehouse, $id, array_keys($required));
            $enough = $required !== [];
            foreach ($required as $product => $quantity) {
                $available = (float) ($balances[$product]['quantity_available'] ?? 0);
                $checked[] = ['warehouse_id' => $warehouse, 'location_id' => $id,
                    'product_id' => $product, 'required' => $quantity, 'available' => $available];
                if ($available + 0.0005 < $quantity) $enough = false;
            }
            if ($enough) $sufficient[] = $id;
        }
        return ['sufficient_locations' => $sufficient, 'checked' => $checked];
    }

    public function escalate(int $saleId, int $actor, string $reason): array
    {
        $company = $this->tenant->companyId();
        return $this->routingLock($company, $saleId, function () use ($company, $saleId, $actor, $reason): array {
            $scope = new SalesHierarchyScope();
            $sale = $this->quickSaleRecord($company, $saleId);
            if (!$scope->canManage($company, $actor) || !$sale || (int) $sale['manager_user_id'] !== $actor) {
                throw new RuntimeException('Only the current responsible manager can escalate this request.');
            }
            if ($sale['status'] !== 'submitted' || !empty($sale['sales_order_id'])) {
                throw new RuntimeException('An allocated or already converted request cannot be escalated.');
            }
            $reason = trim($reason);
            if ($reason === '' || mb_strlen($reason) > 2000) throw new RuntimeException('Enter a stock insufficiency reason (up to 2000 characters).');
            $quotation = $this->sales->quotation($company, (int) $sale['quotation_id']);
            if (!$quotation) throw new RuntimeException('Quotation was not found.');
            $stock = $this->stockCheck($company, $actor, (int) $sale['warehouse_id'], $quotation['lines']);
            if ($stock['sufficient_locations'] !== []) throw new RuntimeException('Available stock can satisfy this request. Allocate it at this level.');
            $parent = $scope->parentId($company, $actor);
            if ($parent === null) {
                $history = $this->routingHistory($company, $saleId);
                if (($history[count($history) - 1]['action'] ?? '') !== 'quick_sale.stock_unavailable') {
                    $this->routingAudit($company, $saleId, $actor, 'stock_unavailable', $stock + [
                        'from_manager_id' => $actor, 'to_manager_id' => null,
                        'warehouse_id' => (int) $sale['warehouse_id'], 'reason' => $reason]);
                }
                return ['successful' => true, 'id' => $saleId, 'unresolved' => true];
            }
            if (!$scope->canManage($company, $parent)) throw new RuntimeException('The direct parent manager needs the Sales confirmation permission before escalation.');
            $warehouse = $this->resolveShopWarehouse($company, $parent);
            // A reconfigured hierarchy must not send a request back to a previous processor.
            foreach ($this->routingHistory($company, $saleId) as $event) {
                $values = json_decode((string) $event['new_values'], true) ?: [];
                if ((int) ($values['from_manager_id'] ?? 0) === $parent) throw new RuntimeException('This request has already passed through that manager. Correct the hierarchy.');
            }
            $db = \db();
            $db->beginTransaction();
            try {
                $update = $db->prepare("UPDATE sales_quick_sales SET manager_user_id=?,warehouse_id=?
                    WHERE company_id=? AND quick_sale_id=? AND manager_user_id=? AND status='submitted'");
                $update->execute([$parent, (int) $warehouse['warehouse_id'], $company, $saleId, $actor]);
                if ($update->rowCount() !== 1) throw new RuntimeException('Request ownership changed. Refresh the page.');
                $this->routingAudit($company, $saleId, $actor, 'escalated', $stock + [
                    'from_manager_id' => $actor, 'to_manager_id' => $parent,
                    'to_warehouse_id' => (int) $warehouse['warehouse_id'], 'reason' => $reason]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            return ['successful' => true, 'id' => $saleId];
        });
    }

    public function handoffToFinance(int $saleId, int $reportId, int $actor): array
    {
        $db = \db();
        try {
            $company = $this->tenant->companyId();
            if (!(new SalesHierarchyScope())->canManage($company, $actor)) throw new RuntimeException('Sales confirmation permission is required.');
            $db->beginTransaction();
            $statement = $db->prepare(
                "SELECT r.finance_invoice_id,r.finance_handoff_at FROM sales_quick_sales qs
                 INNER JOIN sales_quick_sale_reports r ON r.company_id=qs.company_id AND r.quick_sale_id=qs.quick_sale_id
                 INNER JOIN sales_quotations q ON q.company_id=qs.company_id AND q.quotation_id=qs.quotation_id
                 INNER JOIN finance_invoices i ON i.company_id=r.company_id AND i.invoice_id=r.finance_invoice_id
                    AND i.sales_order_id=q.sales_order_id AND i.document_type='customer_invoice'
                 WHERE qs.company_id=? AND qs.quick_sale_id=? AND qs.manager_user_id=?
                   AND qs.status='closed' AND r.report_id=? AND r.status='confirmed' FOR UPDATE"
            );
            $statement->execute([$company, $saleId, $actor, $reportId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('A closed Quick Sale, confirmed report and linked Finance invoice assigned to you are required.');
            $replayed = !empty($row['finance_handoff_at']);
            if (!$replayed) {
                $db->prepare('UPDATE sales_quick_sale_reports SET finance_handoff_by_user_id=?,finance_handoff_at=NOW() WHERE company_id=? AND report_id=? AND finance_handoff_at IS NULL')
                    ->execute([$actor, $company, $reportId]);
                $this->routingAudit($company, $saleId, $actor, 'finance_handoff', ['report_id' => $reportId, 'finance_invoice_id' => (int) $row['finance_invoice_id']]);
            }
            $db->commit();
            return ['successful' => true, 'id' => $saleId, 'replayed' => $replayed];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['successful' => false, 'errors' => ['form' => $e->getMessage()]];
        }
    }

    private function financeReader(int $company, int $actor): bool
    {
        $scope = new SalesHierarchyScope();
        if ($scope->isAgent($company, $actor)) return false;
        foreach (['finance.records.view', 'finance.settlements.view', 'finance.settlements.reconcile', 'finance.settlements.approve'] as $permission) {
            if ($scope->hasPermission($company, $actor, $permission)) return true;
        }
        return false;
    }

    public function financeQueue(int $actor): array
    {
        $company = $this->tenant->companyId();
        if (!$this->financeReader($company, $actor)) return [];
        $statement = \db()->prepare(
            "SELECT qs.quick_sale_id,r.report_id,r.finance_handoff_at,i.invoice_id,i.invoice_number,
                    i.status AS invoice_status,i.payment_status,i.total_amount,i.currency,
                    q.quotation_number,q.sales_order_id,o.order_number,c.name AS customer_name,
                    r.invoice_reference,r.payment_method,r.payment_reference,
                    agent.display_name AS agent_name,manager.display_name AS manager_name,
                    (r.evidence_path IS NOT NULL AND r.evidence_path<>'') AS has_evidence
             FROM sales_quick_sale_reports r
             INNER JOIN sales_quick_sales qs ON qs.company_id=r.company_id AND qs.quick_sale_id=r.quick_sale_id
             INNER JOIN sales_quotations q ON q.company_id=qs.company_id AND q.quotation_id=qs.quotation_id
             INNER JOIN sales_orders o ON o.company_id=q.company_id AND o.order_id=q.sales_order_id
             INNER JOIN finance_invoices i ON i.company_id=r.company_id AND i.invoice_id=r.finance_invoice_id AND i.sales_order_id=o.order_id
             INNER JOIN sales_customers c ON c.company_id=i.company_id AND c.customer_id=i.customer_id
             INNER JOIN users agent ON agent.user_id=qs.user_id
             INNER JOIN users manager ON manager.user_id=qs.manager_user_id
             WHERE r.company_id=? AND r.status='confirmed' AND qs.status='closed' AND r.finance_handoff_at IS NOT NULL
             ORDER BY r.finance_handoff_at DESC,r.report_id DESC"
        );
        $statement->execute([$company]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
