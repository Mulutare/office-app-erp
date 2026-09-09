<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RepositoryFactory;
use PDO;
use RuntimeException;
use Throwable;

/** Reactive replenishment only. Callers retain the original Sales/SR workflow. */
final class CentralStockReplenishmentService
{
    private function rows(string $sql, array $parameters): array
    {
        $s = \db()->prepare($sql);
        $s->execute($parameters);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function central(int $company): array
    {
        $rows = $this->rows("SELECT * FROM inventory_warehouses WHERE company_id=? AND code='PT-CENTRAL' AND deleted_at IS NULL", [$company]);
        if (count($rows) !== 1 || empty($rows[0]['active']) || empty($rows[0]['is_default'])) {
            throw new RuntimeException('Configure exactly one active, non-deleted PT-CENTRAL warehouse as the company default.');
        }

        $central = $rows[0];
        $warehouse = (int) $central['warehouse_id'];
        $receipt = $this->rows(
            "SELECT * FROM inventory_operation_types
             WHERE company_id=? AND warehouse_id=? AND code='RCPT'
               AND operation_kind='receipt' AND active=TRUE AND is_default=TRUE",
            [$company, $warehouse]
        );
        $internal = $this->rows(
            "SELECT * FROM inventory_operation_types
             WHERE company_id=? AND warehouse_id=? AND operation_kind='internal_transfer'
               AND active=TRUE AND is_default=TRUE",
            [$company, $warehouse]
        );

        if (count($receipt) !== 1 || count($internal) !== 1) {
            throw new RuntimeException('PT-CENTRAL requires one active default RCPT receipt operation and one active default internal-transfer operation.');
        }

        // RCPT may have no internal source because the supplier is external.
        // The explicit receiving destination and the internal-transfer STOCK source are authoritative.
        $destination = (int) ($receipt[0]['default_destination_location_id'] ?? 0);
        $source = (int) ($internal[0]['default_source_location_id'] ?? 0);
        if ($destination < 1 || $source < 1) {
            throw new RuntimeException('PT-CENTRAL receipt destination and internal-transfer stock source must be configured.');
        }

        $stock = $this->rows(
            "SELECT location_id FROM inventory_warehouse_locations
             WHERE company_id=? AND warehouse_id=? AND code='PT-CENTRAL/STOCK'
               AND active=TRUE AND deleted_at IS NULL",
            [$company, $warehouse]
        );
        if (count($stock) !== 1 || (int) $stock[0]['location_id'] !== $source) {
            throw new RuntimeException('PT-CENTRAL default internal-transfer source must be its active PT-CENTRAL/STOCK location.');
        }

        $this->location($company, $warehouse, $destination, false);
        $this->location($company, $warehouse, $source, true);

        return $central + [
            'location_id' => $source,
            'receiving_location_id' => $destination,
            'operation_type_id' => (int) $internal[0]['operation_type_id'],
        ];
    }

    private function location(int $company, int $warehouse, int $location, bool $source): void
    {
        $flag = $source ? 'picking_allowed' : 'receiving_allowed';
        if ($this->rows("SELECT location_id FROM inventory_warehouse_locations WHERE company_id=? AND warehouse_id=? AND location_id=? AND active=TRUE AND deleted_at IS NULL AND location_usage='internal' AND is_virtual=FALSE AND $flag=TRUE", [$company, $warehouse, $location]) === []) {
            throw new RuntimeException('Configure valid active internal stock and receiving locations for the replenishment route.');
        }
    }

    public function regional(int $company, int $actor): array
    {
        $rows = $this->rows(
            "SELECT a.* FROM inventory_stock_authorities a
             JOIN inventory_warehouses w
               ON w.company_id=a.company_id AND w.warehouse_id=a.warehouse_id
             JOIN company_users cu
               ON cu.company_id=a.company_id AND cu.user_id=a.user_id AND cu.active=TRUE
             WHERE a.company_id=? AND a.user_id=? AND a.authority_level='regional'
               AND a.active=TRUE AND w.active=TRUE AND w.deleted_at IS NULL
               AND w.code<>'PT-CENTRAL'",
            [$company, $actor]
        );
        if (count($rows) !== 1) {
            throw new RuntimeException('Configure exactly one active Regional stock authority for the current Regional Manager.');
        }

        (new SalesHierarchyScope())->parentId($company, $actor);
        $row = $rows[0];
        $this->location($company, (int) $row['warehouse_id'], (int) $row['location_id'], false);
        $this->location($company, (int) $row['warehouse_id'], (int) $row['location_id'], true);
        return $row;
    }

    /** Must run in the caller's original-request transaction. */
    public function requestLocked(int $company, int $request, array $regional, array $remaining, int $actor): array
    {
        $this->regional($company, $actor);
        $legacy = $this->rows("SELECT p.requisition_id FROM inventory_stock_request_procurements p
            JOIN purchase_requisitions r ON r.company_id=p.company_id AND r.requisition_id=p.requisition_id
            WHERE p.company_id=? AND p.request_id=? AND r.status IN('draft','submitted','approved','converted')
            AND NOT EXISTS(SELECT 1 FROM inventory_central_procurement_links x WHERE x.company_id=p.company_id AND x.requisition_id=p.requisition_id)", [$company, $request]);
        if ($legacy !== []) throw new RuntimeException('This request has a legacy Regional-destination requisition. Procurement must reconcile that existing commitment before Central replenishment can create another requisition.');
        $required = [];
        foreach ($remaining as $line) $required[(int) $line['product_id']] = (float) $line['remaining_quantity'];
        return $this->replenishLocked($company, $request, null, $regional, $required, $actor);
    }

    public function quickSale(int $company, int $sale, int $actor): array
    {
        $c = \db();
        $c->beginTransaction();
        try {
            $rows = $this->rows('SELECT qs.*,q.sales_order_id FROM sales_quick_sales qs JOIN sales_quotations q ON q.company_id=qs.company_id AND q.quotation_id=qs.quotation_id WHERE qs.company_id=? AND qs.quick_sale_id=? FOR UPDATE', [$company, $sale]);
            $row = $rows[0] ?? null;
            if (!$row || (int) $row['manager_user_id'] !== $actor || $row['status'] !== 'submitted' || !empty($row['sales_order_id'])) throw new RuntimeException('Only the current responsible Regional Manager can replenish an unallocated Quick Sale.');
            $regional = $this->regional($company, $actor);
            if ((int) $regional['warehouse_id'] !== (int) $row['warehouse_id']) throw new RuntimeException('Quick Sale warehouse no longer matches its Regional authority.');
            $lines = $this->rows('SELECT product_id,SUM(quantity) quantity FROM sales_quotation_lines WHERE company_id=? AND quotation_id=? GROUP BY product_id ORDER BY product_id', [$company, (int) $row['quotation_id']]);
            $required = [];
            foreach ($lines as $line) {
                $balance = RepositoryFactory::inventory()->stockBalanceForUpdate($company, (int) $regional['warehouse_id'], (int) $regional['location_id'], (int) $line['product_id']);
                $available = max(0.0, (float) ($balance['quantity_on_hand'] ?? 0) - (float) ($balance['quantity_reserved'] ?? 0));
                $required[(int) $line['product_id']] = max(0.0, round((float) $line['quantity'] - $available, 3));
            }
            $result = $this->replenishLocked($company, null, $sale, $regional, $required, $actor);
            $c->commit();
            return $result;
        } catch (Throwable $e) {
            if ($c->inTransaction()) $c->rollBack();
            throw $e;
        }
    }

    private function replenishLocked(int $company, ?int $request, ?int $sale, array $regional, array $required, int $actor): array
    {
        $c = \db();
        if (!$c->inTransaction()) throw new RuntimeException('Replenishment requires an original-request transaction.');
        $central = $this->central($company);
        $column = $request !== null ? 'request_id' : 'quick_sale_id';
        $origin = $request ?? $sale;
        $c->prepare('INSERT INTO inventory_central_demands(company_id,request_id,quick_sale_id,regional_authority_id,created_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE demand_id=demand_id')
            ->execute([$company, $request, $sale, (int) $regional['authority_id'], $actor]);
        $demand = $this->rows("SELECT * FROM inventory_central_demands WHERE company_id=? AND $column=? FOR UPDATE", [$company, $origin])[0];
        $id = (int) $demand['demand_id'];
        if ((int) $demand['regional_authority_id'] !== (int) $regional['authority_id']) throw new RuntimeException('The linked replenishment Regional authority changed; reconcile outstanding transfers before retrying.');
        $pending = $this->rows("SELECT l.product_id,SUM(l.quantity) quantity FROM inventory_central_transfer_links x JOIN inventory_transfer_lines l ON l.company_id=x.company_id AND l.transfer_line_id=x.transfer_line_id WHERE x.company_id=? AND x.demand_id=? AND x.state IN('reserved','in_transit') GROUP BY l.product_id", [$company, $id]);
        foreach ($pending as $line) $required[(int) $line['product_id']] = max(0, ($required[(int) $line['product_id']] ?? 0) - (float) $line['quantity']);
        if ($request !== null) {
            $kind = $this->rows('SELECT request_kind FROM inventory_stock_requests WHERE company_id=? AND request_id=?', [$company,$request]);
            if ((string)($kind[0]['request_kind'] ?? 'employee_issue') === 'manager_replenishment') {
                // A proactive Regional request is for additional incoming stock. Central
                // quantities already received count toward that requested amount; existing
                // Regional stock never does.
                $received = $this->rows("SELECT l.product_id,SUM(l.quantity) quantity FROM inventory_central_transfer_links x JOIN inventory_transfer_lines l ON l.company_id=x.company_id AND l.transfer_line_id=x.transfer_line_id WHERE x.company_id=? AND x.demand_id=? AND x.state='received' GROUP BY l.product_id", [$company,$id]);
                foreach ($received as $line) $required[(int)$line['product_id']] = max(0, ($required[(int)$line['product_id']] ?? 0) - (float)$line['quantity']);
                $this->recordRegionalRequestReceiptsLocked($company, $request, $id, $regional, $actor);
            }
        }
        // Committed procurement is not purchased twice or displaced by unrelated new stock.
        // Only POSTED receipt quantities reduce this coverage.
        $coverage = $this->rows("SELECT l.product_id,SUM(GREATEST(l.quantity-COALESCE((SELECT SUM(gl.quantity) FROM purchase_orders po JOIN inventory_goods_receipts gr ON gr.company_id=po.company_id AND gr.purchase_order_id=po.purchase_order_id AND gr.status='posted' JOIN inventory_goods_receipt_lines gl ON gl.company_id=gr.company_id AND gl.goods_receipt_id=gr.goods_receipt_id WHERE po.company_id=r.company_id AND po.requisition_id=r.requisition_id AND gl.product_id=l.product_id),0),0)) quantity
            FROM inventory_central_procurement_links x JOIN purchase_requisitions r ON r.company_id=x.company_id AND r.requisition_id=x.requisition_id JOIN purchase_requisition_lines l ON l.company_id=r.company_id AND l.requisition_id=r.requisition_id
            WHERE x.company_id=? AND x.demand_id=? AND r.status IN('draft','submitted','approved','converted') AND NOT EXISTS(SELECT 1 FROM purchase_orders p WHERE p.company_id=r.company_id AND p.requisition_id=r.requisition_id AND p.status='cancelled') GROUP BY l.product_id HAVING quantity>0.0005", [$company, $id]);
        foreach ($coverage as $line) $required[(int) $line['product_id']] = max(0, ($required[(int) $line['product_id']] ?? 0) - (float) $line['quantity']);
        ksort($required);
        $transfer = null;
        $shortage = [];
        foreach ($required as $product => $quantity) {
            $quantity = round((float) $quantity, 3);
            if ($quantity <= 0.0005) continue;
            $inventory = RepositoryFactory::inventory();
            $balance = $inventory->stockBalanceForUpdate($company, (int) $central['warehouse_id'], (int) $central['location_id'], $product);
            $available = max(0, (float) ($balance['quantity_on_hand'] ?? 0) - (float) ($balance['quantity_reserved'] ?? 0));
            $supply = round(min($quantity, $available), 3);
            if ($supply > 0.0005) {
                if ($transfer === null) {
                    $number = 'TRF-CENTRAL-' . strtoupper(bin2hex(random_bytes(6)));
                    $c->prepare("INSERT INTO inventory_transfers(company_id,source_warehouse_id,destination_warehouse_id,operation_type_id,transfer_number,transfer_date,status,reason,notes,created_by) VALUES(?,?,?,?,?,CURRENT_DATE,'draft',?,?,?)")
                        ->execute([$company, (int) $central['warehouse_id'], (int) $regional['warehouse_id'], (int) $central['operation_type_id'], $number, 'Company stock replenishment', 'Linked original business demand', $actor]);
                    $transfer = (int) $c->lastInsertId();
                }
                $c->prepare('INSERT INTO inventory_transfer_lines(company_id,transfer_id,source_warehouse_id,source_location_id,destination_warehouse_id,destination_location_id,product_id,quantity,unit_cost) VALUES(?,?,?,?,?,?,?,?,?)')
                    ->execute([$company, $transfer, (int) $central['warehouse_id'], (int) $central['location_id'], (int) $regional['warehouse_id'], (int) $regional['location_id'], $product, $supply, (float) ($balance['average_unit_cost'] ?? 0)]);
                $lineId = (int) $c->lastInsertId();
                $inventory->changeReplenishmentReservation($company, (int) $central['warehouse_id'], (int) $central['location_id'], $product, $supply);
                $c->prepare('INSERT INTO inventory_central_transfer_links(company_id,demand_id,transfer_line_id) VALUES(?,?,?)')->execute([$company, $id, $lineId]);
            }
            if ($quantity - $supply > 0.0005) $shortage[$product] = round($quantity - $supply, 3);
        }
        $requisition = $shortage === [] ? null : $this->requisitionLocked($company, $id, $request, $central, $shortage, $actor);
        $state = $shortage !== [] || $coverage !== [] ? 'awaiting_procurement' : ($transfer !== null || $pending !== [] ? 'awaiting_transfer' : 'ready');
        $c->prepare('UPDATE inventory_central_demands SET state=? WHERE company_id=? AND demand_id=?')->execute([$state, $company, $id]);
        if ($request !== null) {
            $kind = $this->rows('SELECT request_kind FROM inventory_stock_requests WHERE company_id=? AND request_id=?', [$company,$request]);
            $managerRequest = (string)($kind[0]['request_kind'] ?? 'employee_issue') === 'manager_replenishment';
            $requestStatus = $state === 'ready'
                ? ($managerRequest ? 'closed' : 'pending_review')
                : $state;
            $c->prepare('UPDATE inventory_stock_requests SET status=?,current_handler_user_id=? WHERE company_id=? AND request_id=?')
                ->execute([$requestStatus, (int) $regional['user_id'], $company, $request]);
        }
        RepositoryFactory::auditLogs()->record($actor, 'central_replenishment.checked', 'inventory', 'inventory_central_demands', (string) $id, null, ['state' => $state, 'transfer_id' => $transfer, 'requisition_id' => $requisition], $company);
        return ['status' => $state, 'transfer_id' => $transfer, 'requisition_id' => $requisition];
    }

    private function recordRegionalRequestReceiptsLocked(
        int $company,
        int $request,
        int $demand,
        array $regional,
        int $actor
    ): void {
        $c = \db();

        $rows = $this->rows(
            "SELECT l.*,t.created_at transfer_created_at,
                    t.dispatched_at transfer_dispatched_at,
                    t.received_at transfer_received_at,
                    t.created_by transfer_created_by
             FROM inventory_central_transfer_links x
             JOIN inventory_transfer_lines l
               ON l.company_id=x.company_id
              AND l.transfer_line_id=x.transfer_line_id
             JOIN inventory_transfers t
               ON t.company_id=l.company_id
              AND t.transfer_id=l.transfer_id
             WHERE x.company_id=?
               AND x.demand_id=?
               AND x.state='received'
             ORDER BY l.transfer_line_id",
            [$company,$demand]
        );

        foreach ($rows as $line) {
            $transferLineId = (int)$line['transfer_line_id'];

            if ($this->rows(
                "SELECT allocation_id
                 FROM inventory_stock_request_allocations
                 WHERE company_id=? AND request_id=? AND transfer_line_id=?
                 LIMIT 1",
                [$company,$request,$transferLineId]
            ) !== []) continue;

            $requestLines = $this->rows(
                "SELECT request_line_id,requested_quantity
                 FROM inventory_stock_request_lines
                 WHERE company_id=? AND request_id=? AND product_id=?
                 FOR UPDATE",
                [$company,$request,(int)$line['product_id']]
            );

            if (count($requestLines) !== 1) {
                throw new RuntimeException('Central replenishment could not resolve exactly one matching stock-request line.');
            }

            $requestLine = $requestLines[0];

            $used = $this->rows(
                "SELECT COALESCE(SUM(quantity),0) quantity
                 FROM inventory_stock_request_allocations
                 WHERE company_id=? AND request_id=? AND request_line_id=?
                   AND status<>'released'",
                [$company,$request,(int)$requestLine['request_line_id']]
            );

            $needed = max(
                0.0,
                (float)$requestLine['requested_quantity']
                - (float)($used[0]['quantity'] ?? 0)
            );

            $quantity = round(min(
                $needed,
                (float)$line['received_quantity']
            ), 3);

            if ($quantity <= 0.0005) continue;

            $receivedAt = $line['transfer_received_at'] ?: date('Y-m-d H:i:s');

            $c->prepare(
                "INSERT INTO inventory_stock_request_allocations(
                    company_id,request_id,request_line_id,authority_id,
                    source_warehouse_id,source_location_id,
                    destination_warehouse_id,destination_location_id,
                    quantity,status,transfer_id,transfer_line_id,
                    reserved_at,dispatched_at,received_at,issued_at,created_by
                 ) VALUES(?,?,?,?,?,?,?,?,?,'issued',?,?,?,?,?,?,?)"
            )->execute([
                $company,
                $request,
                (int)$requestLine['request_line_id'],
                (int)$regional['authority_id'],
                (int)$line['source_warehouse_id'],
                (int)$line['source_location_id'],
                (int)$line['destination_warehouse_id'],
                (int)$line['destination_location_id'],
                $quantity,
                (int)$line['transfer_id'],
                $transferLineId,
                $line['transfer_created_at'] ?: $receivedAt,
                $line['transfer_dispatched_at'],
                $receivedAt,
                $receivedAt,
                (int)($line['transfer_created_by'] ?: $actor),
            ]);
        }
    }
    private function requisitionLocked(int $company, int $demand, ?int $request, array $central, array $shortage, int $actor): int
    {
        $c = \db();
        $employee = $this->rows('SELECT e.department_id FROM hr_employees e JOIN hr_departments d ON d.company_id=e.company_id AND d.department_id=e.department_id AND d.active=TRUE AND d.deleted_at IS NULL WHERE e.company_id=? AND e.user_id=? AND e.deleted_at IS NULL', [$company, $actor]);
        if (count($employee) !== 1) throw new RuntimeException('The Regional Manager requires an active HR department for a purchase requisition.');
        $number = 'PR-CENTRAL-' . strtoupper(bin2hex(random_bytes(6)));
        $c->prepare("INSERT INTO purchase_requisitions(company_id,requisition_number,requester_user_id,department_id,requested_date,justification,status) VALUES(?,?,?,?,CURRENT_DATE,?,'draft')")
            ->execute([$company, $number, $actor, (int) $employee[0]['department_id'], 'Company-stock replenishment for an unresolved business request. Receive into Passion Technologies Central Warehouse.']);
        $req = (int) $c->lastInsertId();
        foreach ($shortage as $product => $quantity) {
            // Automatic shortage demand must not guess a purchase price. Procurement
            // completes the estimated unit price explicitly before submitting the PR.
            $c->prepare('INSERT INTO purchase_requisition_lines(company_id,requisition_id,product_id,description,quantity,estimated_unit_price,warehouse_id) VALUES(?,?,?,?,?,?,?)')
                ->execute([$company, $req, $product, 'Central stock shortage', $quantity, 0, (int) $central['warehouse_id']]);
        }
        $c->prepare('INSERT INTO inventory_central_procurement_links(company_id,demand_id,requisition_id,receiving_warehouse_id,receiving_location_id) VALUES(?,?,?,?,?)')
            ->execute([$company, $demand, $req, (int) $central['warehouse_id'], (int) $central['receiving_location_id']]);
        if ($request !== null) $c->prepare('INSERT INTO inventory_stock_request_procurements(company_id,request_id,requisition_id,receiving_warehouse_id,receiving_location_id,created_by) VALUES(?,?,?,?,?,?)')
            ->execute([$company, $request, $req, (int) $central['warehouse_id'], (int) $central['receiving_location_id'], $actor]);
        return $req;
    }

    /** Reservation release and lifecycle changes share the Inventory movement transaction. */
    public function transferTransition(int $company, int $transfer, string $action): void
    {
        $c = \db();
        if (!$c->inTransaction()) throw new RuntimeException('Transfer callbacks require the Inventory transaction.');
        $links = $this->rows('SELECT x.*,l.* FROM inventory_central_transfer_links x JOIN inventory_transfer_lines l ON l.company_id=x.company_id AND l.transfer_line_id=x.transfer_line_id WHERE x.company_id=? AND l.transfer_id=? ORDER BY l.product_id FOR UPDATE', [$company, $transfer]);
        foreach ($links as $line) {
            $state = $line['state'];
            if (($action === 'dispatch' || $action === 'cancel') && $state === 'reserved') {
                RepositoryFactory::inventory()->changeReplenishmentReservation($company, (int) $line['source_warehouse_id'], (int) $line['source_location_id'], (int) $line['product_id'], -(float) $line['quantity']);
                $state = $action === 'dispatch' ? 'in_transit' : 'released';
            } elseif ($action === 'receive' && $state === 'in_transit') $state = 'received';
            $c->prepare('UPDATE inventory_central_transfer_links SET state=? WHERE company_id=? AND transfer_line_id=?')->execute([$state, $company, (int) $line['transfer_line_id']]);
            if ($action === 'receive' || $action === 'cancel') {
                $c->prepare("UPDATE inventory_central_demands SET state='pending' WHERE company_id=? AND demand_id=?")->execute([$company, (int) $line['demand_id']]);
                $c->prepare("UPDATE inventory_stock_requests r JOIN inventory_central_demands d ON d.company_id=r.company_id AND d.request_id=r.request_id JOIN inventory_stock_authorities a ON a.company_id=d.company_id AND a.authority_id=d.regional_authority_id SET r.status='pending_review',r.current_handler_user_id=a.user_id WHERE d.company_id=? AND d.demand_id=? AND r.status IN('awaiting_procurement','awaiting_transfer','pending_review')")->execute([$company, (int) $line['demand_id']]);
            }
        }
    }

    public function resumeFromGoodsReceipt(int $company, int $receipt, int $actor): void
    {
        $demands = $this->rows("SELECT d.* FROM inventory_goods_receipts gr JOIN purchase_orders po ON po.company_id=gr.company_id AND po.purchase_order_id=gr.purchase_order_id JOIN inventory_central_procurement_links p ON p.company_id=po.company_id AND p.requisition_id=po.requisition_id JOIN inventory_central_demands d ON d.company_id=p.company_id AND d.demand_id=p.demand_id WHERE gr.company_id=? AND gr.goods_receipt_id=? AND gr.status='posted' AND gr.warehouse_id=p.receiving_warehouse_id AND gr.destination_location_id=p.receiving_location_id", [$company, $receipt]);
        if ($demands !== []) $this->putawayPostedReceipt($company, $receipt, $actor);
        foreach ($demands as $demand) {
            $authority = $this->rows('SELECT user_id FROM inventory_stock_authorities WHERE company_id=? AND authority_id=? AND active=TRUE', [$company, (int) $demand['regional_authority_id']]);
            if ($authority === []) throw new RuntimeException('Replenishment Regional authority is no longer active.');
            $manager = (int) $authority[0]['user_id'];
            if (!empty($demand['quick_sale_id'])) $this->quickSale($company, (int) $demand['quick_sale_id'], $manager);
            else (new StockRequestService())->processRequest((int) $demand['request_id'], $manager);
        }
    }

    /** Narrow system putaway of a posted, linked Central receipt through Inventory movements. */
    private function putawayPostedReceipt(int $company, int $receipt, int $actor): void
    {
        $central = $this->central($company);
        if ((int) $central['location_id'] === (int) $central['receiving_location_id']) return;
        $c = \db();
        $c->beginTransaction();
        try {
            $headers = $this->rows("SELECT * FROM inventory_goods_receipts WHERE company_id=? AND goods_receipt_id=? AND status='posted' FOR UPDATE", [$company, $receipt]);
            $header = $headers[0] ?? null;
            if (!$header || (int) $header['warehouse_id'] !== (int) $central['warehouse_id'] || (int) $header['destination_location_id'] !== (int) $central['receiving_location_id']) throw new RuntimeException('The posted linked receipt no longer matches PT-CENTRAL receiving configuration.');
            foreach ($this->rows('SELECT * FROM inventory_goods_receipt_lines WHERE company_id=? AND goods_receipt_id=? ORDER BY product_id,goods_receipt_line_id', [$company, $receipt]) as $line) {
                $key = 'central-putaway:'.$company.':'.$receipt.':'.$line['goods_receipt_line_id'];
                if ($this->rows("SELECT movement_id FROM inventory_stock_movements WHERE company_id=? AND idempotency_key=? AND status='completed'", [$company, $key]) !== []) continue;
                $balance = RepositoryFactory::inventory()->stockBalanceForUpdate($company, (int) $central['warehouse_id'], (int) $central['receiving_location_id'], (int) $line['product_id']);
                if ((float) ($balance['quantity_on_hand'] ?? 0) - (float) ($balance['quantity_reserved'] ?? 0) + 0.0005 < (float) $line['quantity']) throw new RuntimeException('Posted Central receipt stock is unavailable at its receiving location. Reconcile putaway before resuming; negative stock is not permitted.');
                RepositoryFactory::inventory()->completeStockMovement([
                    'companyId'=>$company, 'productId'=>(int) $line['product_id'],
                    'sourceWarehouseId'=>(int) $central['warehouse_id'], 'sourceLocationId'=>(int) $central['receiving_location_id'],
                    'destinationWarehouseId'=>(int) $central['warehouse_id'], 'destinationLocationId'=>(int) $central['location_id'],
                    'quantity'=>(float) $line['quantity'], 'unitCost'=>(float) $line['unit_cost'],
                    'movementType'=>'transfer_in', 'operationTypeId'=>(int) $central['operation_type_id'],
                    'currency'=>$header['currency'], 'referenceType'=>'goods_receipt', 'referenceId'=>$receipt,
                    'referenceNumber'=>$header['receipt_number'],
                    'idempotencyKey'=>$key,
                    'notes'=>'Posted Central replenishment receipt putaway', 'occurredAt'=>date('Y-m-d H:i:s'), 'actorId'=>$actor,
                ]);
            }
            $c->commit();
        } catch (Throwable $e) {
            if ($c->inTransaction()) $c->rollBack();
            throw $e;
        }
    }

    public function destination(int $company, int $requisition): ?array
    {
        $rows = $this->rows('SELECT receiving_warehouse_id,receiving_location_id FROM inventory_central_procurement_links WHERE company_id=? AND requisition_id=?', [$company, $requisition]);
        if ($rows === []) return null;
        $central = $this->central($company);
        if ((int) $rows[0]['receiving_warehouse_id'] !== (int) $central['warehouse_id'] || (int) $rows[0]['receiving_location_id'] !== (int) $central['receiving_location_id']) throw new RuntimeException('PT-CENTRAL receiving configuration changed after requisition creation. Reconcile the linked requisition before proceeding.');
        return $rows[0];
    }

    /** Caller must first authorize the original Quick Sale. No Central balances are exposed. */
    public function quickSaleLinks(int $company, int $sale): array
    {
        return [
            'transfers' => $this->rows('SELECT DISTINCT t.transfer_number,t.status FROM inventory_central_demands d JOIN inventory_central_transfer_links x ON x.company_id=d.company_id AND x.demand_id=d.demand_id JOIN inventory_transfer_lines l ON l.company_id=x.company_id AND l.transfer_line_id=x.transfer_line_id JOIN inventory_transfers t ON t.company_id=l.company_id AND t.transfer_id=l.transfer_id WHERE d.company_id=? AND d.quick_sale_id=?', [$company, $sale]),
            'requisitions' => $this->rows('SELECT r.requisition_number,r.status FROM inventory_central_demands d JOIN inventory_central_procurement_links x ON x.company_id=d.company_id AND x.demand_id=d.demand_id JOIN purchase_requisitions r ON r.company_id=x.company_id AND r.requisition_id=x.requisition_id WHERE d.company_id=? AND d.quick_sale_id=?', [$company, $sale]),
        ];
    }
}
