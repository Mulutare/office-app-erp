<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class ActionRequiredCountService
{
    /** @var array<string, array<string, array<string, int>>> */
    private static array $requestCache = [];

    /** @var array<string, list<array<string, int|string>>> */
    private static array $itemCache = [];

    /**
     * Returns the records behind one tab badge. Views may render these values, but
     * must not infer workflow state themselves.
     *
     * @param list<string> $permissions
     * @return list<array{id:int,entity:string,reference:string,next_action:string,action_key:string,url:string}>
     */
    public function itemsFor(
        int $companyId,
        int $userId,
        array $permissions,
        string $module,
        string $section
    ): array {
        $permissions = array_values(array_unique(array_filter($permissions, 'is_string')));
        sort($permissions);
        $cacheKey = implode(':', [$companyId, $userId, $module, $section, hash('sha256', implode("\n", $permissions))]);
        if (isset(self::$itemCache[$cacheKey])) {
            return self::$itemCache[$cacheKey];
        }
        if ($companyId < 1 || $userId < 1) {
            return self::$itemCache[$cacheKey] = [];
        }

        $can = static fn (string $permission): bool => in_array($permission, $permissions, true);
        $items = [];
        $add = function (string $sql, array $parameters, string $entity, string $action, string $key, string $declaredUrl) use (&$items): void {
            if ($declaredUrl !== $this->targetTemplate($key)) {
                throw new \LogicException('Action-required target does not match its registered workflow.');
            }
            foreach ($this->rows(\db(), $sql, $parameters) as $row) {
                $items[] = [
                    'id' => (int) $row['id'],
                    'entity' => $entity,
                    'reference' => (string) $row['reference'],
                    'next_action' => $action,
                    'action_key' => $key,
                    'url' => appBasePath() . str_replace('{id}', (string) $row['id'], $this->targetTemplate($key)),
                ];
            }
        };
        $parameters = ['company_id' => $companyId, 'user_id' => $userId];

        if ($module === 'sales' && $section === 'quotations' && $can('sales.orders.submit')) {
            $add("SELECT quotation_id id,quotation_number reference FROM sales_quotations WHERE company_id=:company_id AND status='draft' AND (expiration_date IS NULL OR expiration_date>=CURRENT_DATE)", $parameters, 'quotation', 'Mark sent or confirm', 'advance_quotation', '/sales/quotations/{id}');
            $add("SELECT quotation_id id,quotation_number reference FROM sales_quotations WHERE company_id=:company_id AND status='sent' AND (expiration_date IS NULL OR expiration_date>=CURRENT_DATE)", $parameters, 'quotation', 'Confirm quotation', 'confirm_quotation', '/sales/quotations/{id}');
        } elseif ($module === 'sales' && $section === 'orders') {
            if ($can('sales.orders.submit')) {
                $add("SELECT order_id id,order_number reference FROM sales_orders WHERE company_id=:company_id AND status='draft' AND created_by=:user_id AND deleted_at IS NULL", $parameters, 'sales_order', 'Submit order', 'submit_order', '/sales/orders/{id}');
            }
            if ($can('sales.orders.approve')) {
                $add("SELECT order_id id,order_number reference FROM sales_orders WHERE company_id=:company_id AND status='submitted' AND deleted_at IS NULL", $parameters, 'sales_order', 'Approve order', 'approve_order', '/sales/orders/{id}');
            }
            if ($can('sales.orders.confirm')) {
                $add("SELECT order_id id,order_number reference FROM sales_orders WHERE company_id=:company_id AND status='approved' AND deleted_at IS NULL", $parameters, 'sales_order', 'Confirm order', 'confirm_order', '/sales/orders/{id}');
                $add("SELECT o.order_id id,o.order_number reference FROM sales_orders o WHERE o.company_id=:company_id AND o.status='confirmed' AND o.deleted_at IS NULL AND NOT EXISTS(SELECT 1 FROM inventory_pickings p WHERE p.company_id=o.company_id AND p.sales_order_id=o.order_id AND p.picking_type='delivery' AND p.status<>'cancelled')", $parameters, 'sales_order', 'Prepare delivery', 'prepare_delivery', '/sales/orders/{id}');
            }
            if ($can('sales.view') && $can('finance.records.manage')) {
                $add("SELECT o.order_id id,o.order_number reference FROM sales_orders o WHERE o.company_id=:company_id AND o.status NOT IN('draft','submitted','cancelled') AND o.deleted_at IS NULL AND EXISTS(SELECT 1 FROM sales_order_lines ol WHERE ol.company_id=o.company_id AND ol.order_id=o.order_id AND COALESCE((SELECT SUM(pl.completed_quantity-pl.returned_quantity) FROM inventory_picking_lines pl INNER JOIN inventory_pickings p ON p.company_id=pl.company_id AND p.picking_id=pl.picking_id WHERE p.company_id=ol.company_id AND p.sales_order_id=ol.order_id AND p.picking_type='delivery' AND p.status IN('done','partially_done') AND pl.product_id=ol.product_id),0)-COALESCE((SELECT SUM(il.quantity) FROM finance_invoice_lines il INNER JOIN finance_invoices i ON i.company_id=il.company_id AND i.invoice_id=il.invoice_id WHERE i.company_id=ol.company_id AND i.sales_order_id=ol.order_id AND i.document_type='customer_invoice' AND i.status<>'cancelled' AND il.sales_order_line_id=ol.order_line_id),0)>0.0005)", $parameters, 'sales_order', 'Create customer invoice', 'create_invoice', '/sales/orders/{id}');
            }
            if ($can('sales.payments.record') && !$can('finance.records.manage')) {
                $add("SELECT DISTINCT o.order_id id,o.order_number reference FROM sales_orders o INNER JOIN finance_invoices i ON i.company_id=o.company_id AND i.sales_order_id=o.order_id WHERE o.company_id=:company_id AND i.document_type='customer_invoice' AND i.status='posted' AND i.residual_amount>0 AND o.deleted_at IS NULL", $parameters, 'sales_order', 'Record customer payment', 'record_payment', '/sales/orders/{id}');
            }
        } elseif ($module === 'sales' && $section === 'deliveries' && $can('inventory.transfers.manage')) {
            $add("SELECT picking_id id,picking_number reference FROM inventory_pickings WHERE company_id=:company_id AND picking_type='delivery' AND status IN('draft','ready')", $parameters, 'delivery', 'Validate delivery', 'validate_delivery', '/sales/deliveries/{id}');
        } elseif ($module === 'sales' && $section === 'settlements') {
            if ($can('sales.settlements.submit')) $add("SELECT settlement_id id,settlement_number reference FROM sales_settlements WHERE company_id=:company_id AND workflow_status='draft' AND created_by=:user_id", $parameters, 'settlement', 'Submit settlement', 'submit_settlement', '/sales/settlements/{id}');
            if ($can('sales.settlements.review')) $add("SELECT settlement_id id,settlement_number reference FROM sales_settlements WHERE company_id=:company_id AND workflow_status='submitted'", $parameters, 'settlement', 'Review settlement', 'review_settlement', '/sales/settlements/{id}');
            if ($can('sales.settlements.create')) $add("SELECT p.payment_id id,CONCAT(MIN(o.order_number),' · ',p.payment_number) reference FROM finance_payments p INNER JOIN finance_payment_allocations a ON a.company_id=p.company_id AND a.payment_id=p.payment_id INNER JOIN finance_invoices i ON i.company_id=a.company_id AND i.invoice_id=a.invoice_id INNER JOIN sales_orders o ON o.company_id=i.company_id AND o.order_id=i.sales_order_id WHERE p.company_id=:company_id AND p.direction='inbound' AND p.status='posted' AND i.document_type='customer_invoice' AND i.status='posted' AND o.status NOT IN('draft','cancelled') AND NOT EXISTS(SELECT 1 FROM sales_settlement_lines sl WHERE sl.company_id=p.company_id AND sl.finance_payment_id=p.payment_id) GROUP BY p.payment_id,p.payment_number", $parameters, 'payment', 'Create settlement', 'create_settlement', '/sales/settlements#create-settlement');
        } elseif ($module === 'procurement') {
            $this->addProcurementItems($items, $add, $parameters, $section, $can);
        } elseif ($module === 'finance') {
            $this->addFinanceItems($items, $add, $parameters, $section, $can);
        } elseif ($module === 'inventory') {
            if ($section === 'receipts') $this->addReceiptItems($items, $add, $parameters, $can);
            if ($section === 'movements' && $can('inventory.transfers.manage')) $add("SELECT transfer_id id,transfer_number reference FROM inventory_transfers WHERE company_id=:company_id AND status='draft'", $parameters, 'transfer', 'Process movement', 'process_movement', '/inventory?section=movements');
        } elseif ($module === 'hr' && $section === 'leave' && $can('hr.leave.approve')) {
            $add("SELECT DISTINCT r.leave_request_id id,CONCAT('Leave #',r.leave_request_id) reference FROM hr_leave_request_approvals a INNER JOIN hr_leave_requests r ON r.company_id=a.company_id AND r.leave_request_id=a.leave_request_id WHERE a.company_id=:company_id AND a.approver_user_id=:user_id AND a.approval_status='pending' AND r.request_status='pending'", $parameters, 'leave_request', 'Review leave request', 'review_leave', '/hr/leave');
        } elseif ($module === 'administration' && $section === 'integration_events' && $can('administration.integration_events.retry')) {
            $add("SELECT event_id id,CONCAT(event_type,' / ',aggregate_id) reference FROM integration_outbox WHERE company_id=:company_id AND status='failed'", $parameters, 'integration_event', 'Retry delivery', 'retry_event', '/administration/integration-events');
        }

        return self::$itemCache[$cacheKey] = $this->uniqueItems($items);
    }

    /**
     * @param list<string> $permissions
     * @return array<string, array<string, int>>
     */
    public function counts(int $companyId, int $userId, array $permissions): array
    {
        $permissions = array_values(array_unique(array_filter(
            $permissions,
            static fn ($permission): bool => is_string($permission)
        )));
        sort($permissions);
        $key = $companyId . ':' . $userId . ':' . hash('sha256', implode("\n", $permissions));

        if (isset(self::$requestCache[$key])) {
            return self::$requestCache[$key];
        }

        $counts = $this->emptyCounts();
        if ($companyId < 1 || $userId < 1) {
            return self::$requestCache[$key] = $counts;
        }

        $can = static fn (string $permission): bool => in_array($permission, $permissions, true);
        $connection = \db();

        $procurement = $this->row($connection, <<<'SQL'
SELECT
 (SELECT COUNT(*) FROM purchase_requisitions WHERE company_id=:c1 AND status='submitted' AND requester_user_id<>:a1) requisitions,
 (SELECT COUNT(*) FROM purchase_requisitions WHERE company_id=:c8 AND status='draft' AND requester_user_id=:a3) draft_requisitions,
 (SELECT COUNT(*) FROM purchase_orders po
   WHERE po.company_id=:c2 AND po.status='submitted'
   AND (
    COALESCE((
     SELECT policy.maker_checker_enabled FROM company_approval_policies policy
     WHERE policy.company_id=po.company_id AND policy.action_type='purchase_order.approve'
      AND policy.active=TRUE
      AND policy.minimum_amount<=po.total_amount
      AND (policy.maximum_amount IS NULL OR policy.maximum_amount>=po.total_amount)
     ORDER BY policy.minimum_amount DESC,policy.approval_policy_id DESC LIMIT 1
    ),FALSE)=FALSE
    OR (
     po.created_by<>:approval_actor
     AND EXISTS (
      SELECT 1 FROM company_user_roles roles
      INNER JOIN company_role_permissions grants ON grants.company_id=roles.company_id AND grants.role_id=roles.role_id
      INNER JOIN permissions permission ON permission.permission_id=grants.permission_id AND permission.active=TRUE
      WHERE roles.company_id=po.company_id AND roles.user_id=:approval_user
       AND permission.code=(
        SELECT policy.required_permission FROM company_approval_policies policy
        WHERE policy.company_id=po.company_id AND policy.action_type='purchase_order.approve'
         AND policy.active=TRUE
         AND policy.minimum_amount<=po.total_amount
         AND (policy.maximum_amount IS NULL OR policy.maximum_amount>=po.total_amount)
        ORDER BY policy.minimum_amount DESC,policy.approval_policy_id DESC LIMIT 1
       )
     )
    )
   )) submitted_orders,
 (SELECT COUNT(*) FROM purchase_orders WHERE company_id=:c3 AND status='approved') approved_orders,
 (SELECT COUNT(*) FROM purchase_orders WHERE company_id=:c9 AND status='draft' AND created_by=:a4) draft_orders,
 (SELECT COUNT(*) FROM purchase_orders WHERE company_id=:c13 AND status='billed') billed_orders_to_close,
 (SELECT COUNT(*) FROM purchase_requisitions r WHERE r.company_id=:c10 AND r.status='approved'
   AND NOT EXISTS(SELECT 1 FROM purchase_orders po WHERE po.company_id=r.company_id AND po.requisition_id=r.requisition_id)) requisitions_to_order,
 (SELECT COUNT(*) FROM inventory_goods_receipts WHERE company_id=:c4 AND status IN('draft','submitted') AND created_by<>:a2) receipts_to_approve,
 (SELECT COUNT(*) FROM inventory_goods_receipts WHERE company_id=:c5 AND status='approved') receipts_to_post,
 (SELECT COUNT(*) FROM finance_invoices WHERE company_id=:c6 AND document_type='vendor_bill' AND status='draft') supplier_bills,
 (SELECT COUNT(*) FROM finance_invoices WHERE company_id=:c7 AND document_type='vendor_bill' AND status='posted' AND residual_amount>0) payments
 ,(SELECT COUNT(*) FROM purchase_orders po WHERE po.company_id=:c11 AND po.status IN('confirmed','partially_received')
   AND EXISTS(SELECT 1 FROM purchase_order_lines pol WHERE pol.company_id=po.company_id AND pol.purchase_order_id=po.purchase_order_id AND pol.received_quantity<pol.ordered_quantity)) receipts_to_create
 ,(SELECT COUNT(*) FROM purchase_orders po WHERE po.company_id=:c12 AND po.status IN('partially_received','received','partially_billed')
   AND EXISTS(SELECT 1 FROM purchase_order_lines pol WHERE pol.company_id=po.company_id AND pol.purchase_order_id=po.purchase_order_id AND pol.billed_quantity<pol.received_quantity-pol.returned_quantity)) bills_to_create
SQL, ['c1'=>$companyId,'a1'=>$userId,'c8'=>$companyId,'a3'=>$userId,'c2'=>$companyId,'approval_actor'=>$userId,'approval_user'=>$userId,'c3'=>$companyId,'c9'=>$companyId,'a4'=>$userId,'c13'=>$companyId,'c10'=>$companyId,'c4'=>$companyId,'a2'=>$userId,'c5'=>$companyId,'c6'=>$companyId,'c7'=>$companyId,'c11'=>$companyId,'c12'=>$companyId]);

        $counts['procurement']['requisitions'] = ($can('procurement.requisitions.create') ? $procurement['draft_requisitions'] : 0)
            + ($can('procurement.requisitions.approve') ? $procurement['requisitions'] : 0);
        $counts['procurement']['orders'] = ($can('procurement.orders.approve') ? $procurement['submitted_orders'] : 0)
            + ($can('procurement.orders.confirm') ? $procurement['approved_orders'] : 0)
            + ($can('procurement.orders.create') ? $procurement['draft_orders'] + $procurement['requisitions_to_order'] + $procurement['billed_orders_to_close'] : 0);
        $counts['procurement']['receipts'] = ($can('inventory.receipts.approve') ? $procurement['receipts_to_approve'] : 0)
            + ($can('inventory.receipts.post') ? $procurement['receipts_to_post'] : 0)
            + ($can('procurement.receipts.create') ? $procurement['receipts_to_create'] : 0);
        $counts['procurement']['bills'] = ($can('procurement.bills.post') ? $procurement['supplier_bills'] : 0)
            + ($can('procurement.bills.create') ? $procurement['bills_to_create'] : 0);
        $counts['procurement']['payments'] = $can('procurement.payments.post') ? $procurement['payments'] : 0;

        $finance = $this->row($connection, <<<'SQL'
SELECT
 (SELECT COUNT(*) FROM finance_invoices WHERE company_id=:c1 AND document_type='customer_invoice' AND (status='draft' OR (status='posted' AND residual_amount>0))) invoices,
 (SELECT COUNT(*) FROM finance_expense_requests WHERE company_id=:c2 AND status='submitted' AND deleted_at IS NULL) expenses,
 (SELECT COUNT(*) FROM sales_settlements WHERE company_id=:c3 AND workflow_status='supervisor_reviewed' AND reconciliation_status IN('matched','partial','mismatch','review_required')) reconcile_settlements,
 (SELECT COUNT(*) FROM sales_settlements WHERE company_id=:c4 AND workflow_status='finance_reconciled' AND reconciliation_status='matched' AND created_by<>:actor) approve_settlements,
 (SELECT COUNT(*) FROM sales_settlements WHERE company_id=:c5 AND workflow_status IN('submitted','supervisor_reviewed') AND reconciliation_status='awaiting_confirmation') confirm_settlements
SQL, ['c1'=>$companyId,'c2'=>$companyId,'c3'=>$companyId,'c4'=>$companyId,'actor'=>$userId,'c5'=>$companyId]);
        $counts['finance']['invoices'] = $can('finance.records.manage') ? $finance['invoices'] : 0;
        $counts['finance']['expenses'] = $can('finance.requests.approve') ? $finance['expenses'] : 0;
        $counts['finance']['settlements'] = ($can('finance.settlements.reconcile') ? $finance['reconcile_settlements'] : 0)
            + ($can('finance.settlements.approve') ? $finance['approve_settlements'] : 0)
            + ($can('finance.bank_confirmations.create') ? $finance['confirm_settlements'] : 0);

        $counts['inventory']['receipts'] = $counts['procurement']['receipts'];
        if ($can('inventory.transfers.manage')) {
            $counts['inventory']['movements'] = $this->scalar(
                $connection,
                "SELECT COUNT(*) FROM inventory_transfers WHERE company_id=:company_id AND status='draft'",
                ['company_id'=>$companyId]
            );
        }

        $sales = $this->row($connection, <<<'SQL'
SELECT
 (SELECT COUNT(*) FROM sales_quotations WHERE company_id=:c1 AND status IN('draft','sent') AND (expiration_date IS NULL OR expiration_date>=CURRENT_DATE)) quotations,
 (SELECT COUNT(*) FROM sales_orders WHERE company_id=:c2 AND status='submitted' AND deleted_at IS NULL) submitted_orders,
 (SELECT COUNT(*) FROM sales_orders WHERE company_id=:c3 AND status='approved' AND deleted_at IS NULL) approved_orders,
 (SELECT COUNT(*) FROM sales_orders WHERE company_id=:c5 AND status='draft' AND created_by=:actor AND deleted_at IS NULL) draft_orders,
 (SELECT COUNT(*) FROM sales_orders o WHERE o.company_id=:c8 AND o.status='confirmed' AND o.deleted_at IS NULL
   AND NOT EXISTS(SELECT 1 FROM inventory_pickings p WHERE p.company_id=o.company_id AND p.sales_order_id=o.order_id AND p.picking_type='delivery' AND p.status<>'cancelled')) orders_to_deliver,
 (SELECT COUNT(*) FROM sales_orders o WHERE o.company_id=:c9 AND o.status NOT IN('draft','submitted','cancelled') AND o.deleted_at IS NULL
   AND EXISTS(
    SELECT 1 FROM sales_order_lines ol WHERE ol.company_id=o.company_id AND ol.order_id=o.order_id
     AND COALESCE((SELECT SUM(pl.completed_quantity-pl.returned_quantity) FROM inventory_picking_lines pl
      INNER JOIN inventory_pickings p ON p.company_id=pl.company_id AND p.picking_id=pl.picking_id
      WHERE p.company_id=ol.company_id AND p.sales_order_id=ol.order_id AND p.picking_type='delivery'
       AND p.status IN('done','partially_done') AND pl.product_id=ol.product_id),0)
      - COALESCE((SELECT SUM(il.quantity) FROM finance_invoice_lines il
       INNER JOIN finance_invoices i ON i.company_id=il.company_id AND i.invoice_id=il.invoice_id
       WHERE i.company_id=ol.company_id AND i.sales_order_id=ol.order_id AND i.document_type='customer_invoice'
        AND i.status<>'cancelled' AND il.sales_order_line_id=ol.order_line_id),0)>0.0005
   )) orders_to_invoice,
 (SELECT COUNT(DISTINCT o.order_id) FROM sales_orders o
   INNER JOIN finance_invoices i ON i.company_id=o.company_id AND i.sales_order_id=o.order_id
   WHERE o.company_id=:c10 AND i.document_type='customer_invoice' AND i.status='posted' AND i.residual_amount>0
    AND o.deleted_at IS NULL) orders_to_collect,
 (SELECT COUNT(*) FROM sales_settlements WHERE company_id=:c4 AND workflow_status='submitted') settlements,
 (SELECT COUNT(*) FROM sales_settlements WHERE company_id=:c6 AND workflow_status='draft' AND created_by=:settlement_actor) draft_settlements,
 (SELECT COUNT(DISTINCT p.payment_id) FROM finance_payments p
   INNER JOIN finance_payment_allocations a ON a.company_id=p.company_id AND a.payment_id=p.payment_id
   INNER JOIN finance_invoices i ON i.company_id=a.company_id AND i.invoice_id=a.invoice_id
   INNER JOIN sales_orders paid_order ON paid_order.company_id=i.company_id AND paid_order.order_id=i.sales_order_id
   WHERE p.company_id=:c11 AND p.direction='inbound' AND p.status='posted'
    AND i.document_type='customer_invoice' AND i.status='posted' AND paid_order.status NOT IN('draft','cancelled')
    AND NOT EXISTS(SELECT 1 FROM sales_settlement_lines sl WHERE sl.company_id=p.company_id AND sl.finance_payment_id=p.payment_id)) payments_to_settle,
 (SELECT COUNT(*) FROM inventory_pickings WHERE company_id=:c7 AND picking_type='delivery' AND status IN('draft','ready')) deliveries
SQL, ['c1'=>$companyId,'c2'=>$companyId,'c3'=>$companyId,'c5'=>$companyId,'actor'=>$userId,'c8'=>$companyId,'c9'=>$companyId,'c10'=>$companyId,'c4'=>$companyId,'c6'=>$companyId,'settlement_actor'=>$userId,'c11'=>$companyId,'c7'=>$companyId]);
        $counts['sales']['quotations'] = $can('sales.orders.submit') ? $sales['quotations'] : 0;
        $counts['sales']['orders'] = ($can('sales.orders.approve') ? $sales['submitted_orders'] : 0)
            + ($can('sales.orders.confirm') ? $sales['approved_orders'] : 0)
            + ($can('sales.orders.submit') ? $sales['draft_orders'] : 0)
            + ($can('sales.orders.confirm') ? $sales['orders_to_deliver'] : 0)
            + ($can('sales.view') && $can('finance.records.manage') ? $sales['orders_to_invoice'] : 0)
            + ($can('sales.payments.record') && !$can('finance.records.manage') ? $sales['orders_to_collect'] : 0);
        $counts['sales']['settlements'] = ($can('sales.settlements.review') ? $sales['settlements'] : 0)
            + ($can('sales.settlements.submit') ? $sales['draft_settlements'] : 0)
            + ($can('sales.settlements.create') ? $sales['payments_to_settle'] : 0);
        $counts['sales']['deliveries'] = $can('inventory.transfers.manage') ? $sales['deliveries'] : 0;

        if ($can('hr.leave.approve')) {
            $counts['hr']['leave'] = $this->scalar($connection, <<<'SQL'
SELECT COUNT(DISTINCT approvals.leave_request_id)
FROM hr_leave_request_approvals approvals
INNER JOIN hr_leave_requests requests
 ON requests.company_id=approvals.company_id
 AND requests.leave_request_id=approvals.leave_request_id
WHERE approvals.company_id=:company_id
 AND approvals.approver_user_id=:user_id
 AND approvals.approval_status='pending'
 AND requests.request_status='pending'
SQL, ['company_id'=>$companyId,'user_id'=>$userId]);
        }

        if ($can('administration.integration_events.retry')) {
            $counts['administration']['integration_events'] = $this->scalar(
                $connection,
                "SELECT COUNT(*) FROM integration_outbox WHERE company_id=:company_id AND status='failed'",
                ['company_id'=>$companyId]
            );
        }

        foreach ($counts as &$module) {
            $module['total'] = array_sum($module);
        }
        unset($module);

        return self::$requestCache[$key] = $counts;
    }

    /** @return array<string, array<string, int>> */
    private function emptyCounts(): array
    {
        return [
            'dashboard'=>['total'=>0],
            'hr'=>['leave'=>0,'total'=>0],
            'finance'=>['receivables'=>0,'invoices'=>0,'journals'=>0,'receipts'=>0,'settlements'=>0,'expenses'=>0,'periods'=>0,'total'=>0],
            'procurement'=>['requisitions'=>0,'orders'=>0,'receipts'=>0,'bills'=>0,'payments'=>0,'returns'=>0,'total'=>0],
            'inventory'=>['stock'=>0,'movements'=>0,'receipts'=>0,'warehouses'=>0,'locations'=>0,'total'=>0],
            'assets'=>['total'=>0],
            'sales'=>['orders'=>0,'quotations'=>0,'customers'=>0,'products'=>0,'pricelists'=>0,'teams'=>0,'deliveries'=>0,'settlements'=>0,'total'=>0],
            'analytics'=>['total'=>0],
            'attendance'=>['total'=>0],
            'administration'=>['integration_events'=>0,'total'=>0],
        ];
    }

    /** @param list<array<string, int|string>> $items */
    private function addProcurementItems(array &$items, callable $add, array $parameters, string $section, callable $can): void
    {
        if ($section === 'requisitions') {
            if ($can('procurement.requisitions.create')) $add("SELECT requisition_id id,requisition_number reference FROM purchase_requisitions WHERE company_id=:company_id AND status='draft' AND requester_user_id=:user_id", $parameters, 'requisition', 'Submit requisition', 'submit_requisition', '/procurement?section=requisitions');
            if ($can('procurement.requisitions.approve')) $add("SELECT requisition_id id,requisition_number reference FROM purchase_requisitions WHERE company_id=:company_id AND status='submitted' AND requester_user_id<>:user_id", $parameters, 'requisition', 'Approve requisition', 'approve_requisition', '/procurement?section=requisitions');
            return;
        }
        if ($section === 'orders') {
            if ($can('procurement.orders.create')) {
                $add("SELECT purchase_order_id id,po_number reference FROM purchase_orders WHERE company_id=:company_id AND status='draft' AND created_by=:user_id", $parameters, 'purchase_order', 'Submit purchase order', 'submit_purchase_order', '/procurement/{id}');
                $add("SELECT r.requisition_id id,r.requisition_number reference FROM purchase_requisitions r WHERE r.company_id=:company_id AND r.status='approved' AND NOT EXISTS(SELECT 1 FROM purchase_orders po WHERE po.company_id=r.company_id AND po.requisition_id=r.requisition_id)", $parameters, 'requisition', 'Create purchase order', 'create_purchase_order', '/procurement?section=orders');
                $add("SELECT purchase_order_id id,po_number reference FROM purchase_orders WHERE company_id=:company_id AND status='billed'", $parameters, 'purchase_order', 'Close PO', 'close_purchase_order', '/procurement/{id}');
            }
            if ($can('procurement.orders.approve')) $add("SELECT purchase_order_id id,po_number reference FROM purchase_orders po WHERE po.company_id=:company_id AND po.status='submitted' AND (COALESCE((SELECT p.maker_checker_enabled FROM company_approval_policies p WHERE p.company_id=po.company_id AND p.action_type='purchase_order.approve' AND p.active=TRUE AND p.minimum_amount<=po.total_amount AND (p.maximum_amount IS NULL OR p.maximum_amount>=po.total_amount) ORDER BY p.minimum_amount DESC,p.approval_policy_id DESC LIMIT 1),FALSE)=FALSE OR (po.created_by<>:approval_actor AND EXISTS(SELECT 1 FROM company_user_roles ur INNER JOIN company_role_permissions rp ON rp.company_id=ur.company_id AND rp.role_id=ur.role_id INNER JOIN permissions pm ON pm.permission_id=rp.permission_id AND pm.active=TRUE WHERE ur.company_id=po.company_id AND ur.user_id=:approval_user AND pm.code=(SELECT p.required_permission FROM company_approval_policies p WHERE p.company_id=po.company_id AND p.action_type='purchase_order.approve' AND p.active=TRUE AND p.minimum_amount<=po.total_amount AND (p.maximum_amount IS NULL OR p.maximum_amount>=po.total_amount) ORDER BY p.minimum_amount DESC,p.approval_policy_id DESC LIMIT 1))))", $parameters + ['approval_actor'=>$parameters['user_id'],'approval_user'=>$parameters['user_id']], 'purchase_order', 'Approve purchase order', 'approve_purchase_order', '/procurement/{id}');
            if ($can('procurement.orders.confirm')) $add("SELECT purchase_order_id id,po_number reference FROM purchase_orders WHERE company_id=:company_id AND status='approved'", $parameters, 'purchase_order', 'Confirm purchase order', 'confirm_purchase_order', '/procurement/{id}');
            return;
        }
        if ($section === 'receipts') { $this->addReceiptItems($items, $add, $parameters, $can); return; }
        if ($section === 'bills') {
            if ($can('procurement.bills.post')) $add("SELECT invoice_id id,invoice_number reference FROM finance_invoices WHERE company_id=:company_id AND document_type='vendor_bill' AND status='draft'", $parameters, 'supplier_bill', 'Post supplier bill', 'post_supplier_bill', '/procurement?section=bills');
            if ($can('procurement.bills.create')) $add("SELECT po.purchase_order_id id,po.po_number reference FROM purchase_orders po WHERE po.company_id=:company_id AND po.status IN('partially_received','received','partially_billed') AND EXISTS(SELECT 1 FROM purchase_order_lines l WHERE l.company_id=po.company_id AND l.purchase_order_id=po.purchase_order_id AND l.billed_quantity<l.received_quantity-l.returned_quantity)", $parameters, 'purchase_order', 'Create supplier bill', 'create_supplier_bill', '/procurement/{id}');
            return;
        }
        if ($section === 'payments' && $can('procurement.payments.post')) $add("SELECT invoice_id id,invoice_number reference FROM finance_invoices WHERE company_id=:company_id AND document_type='vendor_bill' AND status='posted' AND residual_amount>0", $parameters, 'supplier_bill', 'Post supplier payment', 'post_supplier_payment', '/procurement?section=payments');
    }

    /** @param list<array<string, int|string>> $items */
    private function addReceiptItems(array &$items, callable $add, array $parameters, callable $can): void
    {
        if ($can('inventory.receipts.approve')) $add("SELECT goods_receipt_id id,receipt_number reference FROM inventory_goods_receipts WHERE company_id=:company_id AND status IN('draft','submitted') AND created_by<>:user_id", $parameters, 'goods_receipt', 'Approve receipt', 'approve_receipt', '/inventory/receipts/{id}');
        if ($can('inventory.receipts.post')) $add("SELECT goods_receipt_id id,receipt_number reference FROM inventory_goods_receipts WHERE company_id=:company_id AND status='approved'", $parameters, 'goods_receipt', 'Post receipt', 'post_receipt', '/inventory/receipts/{id}');
        if ($can('procurement.receipts.create')) $add("SELECT po.purchase_order_id id,po.po_number reference FROM purchase_orders po WHERE po.company_id=:company_id AND po.status IN('confirmed','partially_received') AND EXISTS(SELECT 1 FROM purchase_order_lines l WHERE l.company_id=po.company_id AND l.purchase_order_id=po.purchase_order_id AND l.received_quantity<l.ordered_quantity)", $parameters, 'purchase_order', 'Create goods receipt', 'create_receipt', '/procurement/{id}');
    }

    /** @param list<array<string, int|string>> $items */
    private function addFinanceItems(array &$items, callable $add, array $parameters, string $section, callable $can): void
    {
        if ($section === 'invoices' && $can('finance.records.manage')) {
            $add("SELECT invoice_id id,invoice_number reference FROM finance_invoices WHERE company_id=:company_id AND document_type='customer_invoice' AND status='draft'", $parameters, 'customer_invoice', 'Post customer invoice', 'post_customer_invoice', '/finance/customer-invoices/{id}');
            $add("SELECT invoice_id id,invoice_number reference FROM finance_invoices WHERE company_id=:company_id AND document_type='customer_invoice' AND status='posted' AND residual_amount>0", $parameters, 'customer_invoice', 'Record customer payment', 'record_customer_payment', '/finance/customer-invoices/{id}');
        } elseif ($section === 'expenses' && $can('finance.requests.approve')) {
            $add("SELECT expense_request_id id,CONCAT('Expense #',expense_request_id) reference FROM finance_expense_requests WHERE company_id=:company_id AND status='submitted' AND deleted_at IS NULL", $parameters, 'expense', 'Approve expense', 'approve_expense', '/finance?section=expenses');
        } elseif ($section === 'settlements') {
            if ($can('finance.bank_confirmations.create')) $add("SELECT settlement_id id,settlement_number reference FROM sales_settlements WHERE company_id=:company_id AND workflow_status IN('submitted','supervisor_reviewed') AND reconciliation_status='awaiting_confirmation'", $parameters, 'settlement', 'Add bank confirmation', 'add_bank_confirmation', '/sales/settlements/{id}');
            if ($can('finance.settlements.reconcile')) $add("SELECT settlement_id id,settlement_number reference FROM sales_settlements WHERE company_id=:company_id AND workflow_status='supervisor_reviewed' AND reconciliation_status IN('matched','partial','mismatch','review_required')", $parameters, 'settlement', 'Reconcile settlement', 'reconcile_settlement', '/sales/settlements/{id}');
            if ($can('finance.settlements.approve')) $add("SELECT settlement_id id,settlement_number reference FROM sales_settlements WHERE company_id=:company_id AND workflow_status='finance_reconciled' AND reconciliation_status='matched' AND created_by<>:user_id", $parameters, 'settlement', 'Approve settlement', 'approve_settlement', '/sales/settlements/{id}');
        }
    }

    private function targetTemplate(string $actionKey): string
    {
        return match ($actionKey) {
            'advance_quotation', 'confirm_quotation' => '/sales/quotations/{id}',
            'submit_order', 'approve_order', 'confirm_order', 'prepare_delivery', 'create_invoice', 'record_payment' => '/sales/orders/{id}',
            'validate_delivery' => '/sales/deliveries/{id}',
            'submit_settlement', 'review_settlement', 'add_bank_confirmation', 'reconcile_settlement', 'approve_settlement' => '/sales/settlements/{id}',
            'create_settlement' => '/sales/settlements#create-settlement',
            'submit_requisition', 'approve_requisition' => '/procurement?section=requisitions',
            'create_purchase_order' => '/procurement?section=orders',
            'submit_purchase_order', 'approve_purchase_order', 'confirm_purchase_order', 'close_purchase_order', 'create_supplier_bill', 'create_receipt' => '/procurement/{id}',
            'approve_receipt', 'post_receipt' => '/inventory/receipts/{id}',
            'post_supplier_bill' => '/procurement?section=bills',
            'post_supplier_payment' => '/procurement?section=payments',
            'post_customer_invoice', 'record_customer_payment' => '/finance/customer-invoices/{id}',
            'approve_expense' => '/finance?section=expenses',
            'process_movement' => '/inventory?section=movements',
            'review_leave' => '/hr/leave',
            'retry_event' => '/administration/integration-events',
            default => throw new \LogicException('Unknown action-required workflow target.'),
        };
    }

    /** @param list<array<string, int|string>> $items @return list<array<string, int|string>> */
    private function uniqueItems(array $items): array
    {
        $unique = [];
        foreach ($items as $item) {
            $key = $item['entity'] . ':' . $item['id'] . ':' . $item['action_key'];
            $unique[$key] = $item;
        }
        return array_values($unique);
    }

    /** @param array<string, int|string> $parameters @return list<array<string, mixed>> */
    private function rows(PDO $connection, string $sql, array $parameters): array
    {
        $parameters = array_filter(
            $parameters,
            static fn (string $name): bool => str_contains($sql, ':' . $name),
            ARRAY_FILTER_USE_KEY
        );
        $statement = $connection->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /** @param array<string, int|string> $parameters @return array<string, int> */
    private function row(PDO $connection, string $sql, array $parameters): array
    {
        $statement = $connection->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $result = [];
        foreach (is_array($row) ? $row : [] as $key => $value) {
            $result[(string) $key] = (int) $value;
        }
        return $result;
    }

    /** @param array<string, int|string> $parameters */
    private function scalar(PDO $connection, string $sql, array $parameters): int
    {
        $statement = $connection->prepare($sql);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    }
}
