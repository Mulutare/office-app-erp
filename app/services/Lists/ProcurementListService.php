<?php

declare(strict_types=1);

namespace App\Services\Lists;

use App\Services\TenantContext;
use InvalidArgumentException;
use PDO;

final class ProcurementListService
{
    public function columns(string $entity): array
    {
        return match ($entity) {
            'suppliers' => [
                'supplier_code' => 'Supplier code',
                'business_name' => 'Business name',
                'contact_person' => 'Contact person',
                'phone' => 'Phone',
                'email' => 'Email',
                'tax_number' => 'TIN / Tax',
                'currency' => 'Currency',
                'payment_terms_days' => 'Terms (days)',
                'active' => 'Active',
            ],
            'requisitions' => [
                'requisition_number' => 'Requisition',
                'stock_request_number' => 'Stock request',
                'requester_name' => 'Requester',
                'requested_date' => 'Requested',
                'required_by_date' => 'Required by',
                'justification' => 'Justification',
                'status' => 'Status',
            ],
            'purchase-orders' => [
                'po_number' => 'PO',
                'supplier_code' => 'Supplier code',
                'supplier_name' => 'Supplier',
                'warehouse_name' => 'Warehouse',
                'destination_location_name' => 'Receiving location',
                'order_date' => 'Order date',
                'expected_date' => 'Expected',
                'currency' => 'Currency',
                'received_quantity' => 'Received',
                'billed_quantity' => 'Billed',
                'total_amount' => 'Total',
                'status' => 'Status',
            ],
            'bills' => [
                'invoice_number' => 'Bill',
                'supplier_invoice_number' => 'Supplier invoice',
                'po_number' => 'PO',
                'supplier_name' => 'Supplier',
                'invoice_date' => 'Invoice date',
                'due_date' => 'Due date',
                'currency' => 'Currency',
                'total_amount' => 'Total',
                'residual_amount' => 'Outstanding',
                'status' => 'Status',
                'payment_status' => 'Payment status',
            ],
            'returns' => [
                'return_number' => 'Return',
                'po_number' => 'PO',
                'supplier_name' => 'Supplier',
                'warehouse_name' => 'Warehouse',
                'return_date' => 'Date',
                'reason' => 'Reason',
                'status' => 'Status',
            ],
            default => throw new InvalidArgumentException(
                'Unsupported Procurement export.'
            ),
        };
    }
    public function listing(
        string $entity,
        array $input,
        string $prefix = ''
    ): SqlList {
        $company = (new TenantContext())->companyId();
        $actor = (int) ($_SESSION['auth']['user_id'] ?? 0);

        [
            $sql,
            $id,
            $search,
            $sorts,
            $filters,
            $defaultSort,
            $direction,
            $extraParameters,
        ] = $this->definition($entity, $company, $actor);

        $query = new ListQuery(
            $input,
            $sorts,
            $defaultSort,
            array_keys($filters),
            $direction,
            $prefix
        );

        return new SqlList(
            \db(),
            $sql,
            ['company_id' => $company] + $extraParameters,
            $query,
            $search,
            $sorts,
            $id,
            $filters
        );
    }

    public function controls(string $entity): array
    {
        [,,,$sorts,$filters]=$this->definition($entity,(new TenantContext())->companyId(),(int)($_SESSION['auth']['user_id']??0));
        $domains=['active'=>['1'=>'Active','0'=>'Inactive'],'payment'=>['finance_invoices','payment_status']];
        $table=match($entity) {'requisitions'=>'purchase_requisitions','purchase-orders'=>'purchase_orders','bills'=>'finance_invoices','returns'=>'procurement_vendor_returns',default=>null};
        if($table)$domains['status']=[$table,'status'];
        if($entity==='returns')$domains['status']=['posted'=>'Posted']; // Returns are created atomically by postVendorReturn.
        return FilterOptions::controls($this->listing($entity,[]),$filters,$sorts,$domains);
    }

    /**
     * Full active supplier catalogue used by Purchase Order forms.
     *
     * The visible supplier register itself remains paginated.
     */
    public function supplierOptions(): array
    {
        return $this->listing(
            'suppliers',
            [
                'active' => '1',
                'sort' => 'name',
                'direction' => 'asc',
            ]
        )->export();
    }

    /**
     * Approved requisitions available for PO conversion.
     *
     * This is intentionally separate from the visible paginated
     * requisition register.
     */
    public function approvedRequisitionOptions(): array
    {
        $rows = $this->listing(
            'requisitions',
            [
                'status' => 'approved',
                'sort' => 'date',
                'direction' => 'asc',
            ]
        )->export();

        $rows = $this->hydrateRequisitions($rows);

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool =>
                empty($row['pricing_required'])
        ));
    }

    /**
     * Attach only the line details required by the existing
     * requisition workflow UI.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function hydrateRequisitions(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $company = (new TenantContext())->companyId();
        $connection = \db();

        $lineStatement = $connection->prepare(
            'SELECT
                l.requisition_line_id,
                l.product_id,
                l.description,
                l.quantity,
                l.estimated_unit_price,
                p.sku,
                p.name AS product_name,
                p.unit_of_measure
             FROM purchase_requisition_lines l
             INNER JOIN sales_products p
               ON p.company_id=l.company_id
              AND p.product_id=l.product_id
             WHERE l.company_id=?
               AND l.requisition_id=?
             ORDER BY l.requisition_line_id'
        );

        $centralStatement = $connection->prepare(
            'SELECT receiving_warehouse_id,receiving_location_id
             FROM inventory_central_procurement_links
             WHERE company_id=?
               AND requisition_id=?'
        );

        foreach ($rows as &$row) {
            $id = (int) ($row['requisition_id'] ?? 0);

            $lineStatement->execute([$company, $id]);

            $row['lines'] =
                $lineStatement->fetchAll(PDO::FETCH_ASSOC);

            $row['pricing_required'] = false;

            foreach ($row['lines'] as $line) {
                if (
                    (float) (
                        $line['estimated_unit_price'] ?? 0
                    ) <= 0
                ) {
                    $row['pricing_required'] = true;
                    break;
                }
            }

            $centralStatement->execute([$company, $id]);

            $central =
                $centralStatement->fetch(PDO::FETCH_ASSOC);

            if (is_array($central)) {
                $row['stock_request_receiving_warehouse_id'] =
                    $central['receiving_warehouse_id'];

                $row['stock_request_receiving_location_id'] =
                    $central['receiving_location_id'];

                $row['central_replenishment'] = true;
            } else {
                $row['central_replenishment'] = false;
            }
        }

        unset($row);

        return $rows;
    }

    /**
     * Full-register summary, not a summary of page 1.
     */
    public function summary(): array
    {
        $requisition = $this
            ->listing('requisitions', [])
            ->aggregate([
                'awaiting_approval' =>
                    "SUM(CASE WHEN status='submitted' THEN 1 ELSE 0 END)",
            ]);

        $orders = $this
            ->listing('purchase-orders', [])
            ->aggregate([
                'open_orders' =>
                    "SUM(CASE WHEN status NOT IN('closed','cancelled') THEN 1 ELSE 0 END)",

                'partially_received' =>
                    "SUM(CASE WHEN status='partially_received' THEN 1 ELSE 0 END)",

                'awaiting_bill' =>
                    "SUM(CASE WHEN status IN('received','partially_billed') THEN 1 ELSE 0 END)",
            ]);

        $bills = $this
            ->listing('bills', [])
            ->aggregate([
                'outstanding_bills' =>
                    'SUM(CASE WHEN residual_amount>0 THEN 1 ELSE 0 END)',
            ]);

        return [
            'awaitingApproval' =>
                (int) ($requisition['awaiting_approval'] ?? 0),

            'openOrders' =>
                (int) ($orders['open_orders'] ?? 0),

            'partiallyReceived' =>
                (int) ($orders['partially_received'] ?? 0),

            'awaitingBill' =>
                (int) ($orders['awaiting_bill'] ?? 0),

            'outstandingBills' =>
                (int) ($bills['outstanding_bills'] ?? 0),
        ];
    }

    private function definition(
        string $entity,
        int $company,
        int $actor
    ): array {
        if ($entity === 'suppliers') {
            return [
                'SELECT
                    s.*,
                    DATE(s.created_at) AS document_date
                 FROM purchase_suppliers s
                 WHERE s.company_id=:company_id',

                'supplier_id',

                [
                    'supplier_code',
                    'business_name',
                    'contact_person',
                    'phone',
                    'email',
                    'tax_number',
                    'currency',
                ],

                [
                    'name' => 'business_name',
                    'code' => 'supplier_code',
                    'currency' => 'currency',
                    'status' => 'active',
                    'date' => 'created_at',
                ],

                [
                    'active' => 'active',
                    'currency' => 'currency',
                ],

                'name',
                'asc',
                [],
            ];
        }

        if ($entity === 'requisitions') {
            return [
                'SELECT
                    r.*,
                    DATE(r.requested_date) AS document_date,
                    u.display_name AS requester_name,
                    sr.request_id AS stock_request_id,
                    sr.request_number AS stock_request_number,
                    srp.receiving_warehouse_id
                        AS stock_request_receiving_warehouse_id,
                    srp.receiving_location_id
                        AS stock_request_receiving_location_id
                 FROM purchase_requisitions r
                 INNER JOIN users u
                   ON u.user_id=r.requester_user_id
                 LEFT JOIN inventory_stock_request_procurements srp
                   ON srp.company_id=r.company_id
                  AND srp.requisition_id=r.requisition_id
                 LEFT JOIN inventory_stock_requests sr
                   ON sr.company_id=srp.company_id
                  AND sr.request_id=srp.request_id
                 WHERE r.company_id=:company_id',

                'requisition_id',

                [
                    'requisition_number',
                    'requester_name',
                    'stock_request_number',
                    'justification',
                    'status',
                    'document_date',
                ],

                [
                    'date' => 'requested_date',
                    'reference' => 'requisition_number',
                    'requester' => 'requester_name',
                    'required_by' => 'required_by_date',
                    'status' => 'status',
                ],

                [
                    'status' => 'status',
                    'from' => ['document_date', '>='],
                    'to' => ['document_date', '<='],
                ],

                'date',
                'desc',
                [],
            ];
        }

        if ($entity === 'purchase-orders') {
            [$scope, $scopeParameters] =
                $this->orderScope($company, $actor, 'o');

            return [
                "SELECT
                    o.*,
                    DATE(o.order_date) AS document_date,
                    s.supplier_code,
                    s.business_name AS supplier_name,
                    w.code AS warehouse_code,
                    w.name AS warehouse_name,
                    d.code AS destination_location_code,
                    d.name AS destination_location_name,

                    COALESCE(
                        (
                            SELECT SUM(line.received_quantity)
                            FROM purchase_order_lines line
                            WHERE line.company_id=o.company_id
                              AND line.purchase_order_id=o.purchase_order_id
                        ),
                        0
                    ) AS received_quantity,

                    COALESCE(
                        (
                            SELECT SUM(line.billed_quantity)
                            FROM purchase_order_lines line
                            WHERE line.company_id=o.company_id
                              AND line.purchase_order_id=o.purchase_order_id
                        ),
                        0
                    ) AS billed_quantity

                 FROM purchase_orders o

                 INNER JOIN purchase_suppliers s
                   ON s.company_id=o.company_id
                  AND s.supplier_id=o.supplier_id

                 LEFT JOIN inventory_warehouses w
                   ON w.company_id=o.company_id
                  AND w.warehouse_id=o.warehouse_id

                 LEFT JOIN inventory_warehouse_locations d
                   ON d.company_id=o.company_id
                  AND d.warehouse_id=o.warehouse_id
                  AND d.location_id=o.destination_location_id

                 WHERE o.company_id=:company_id
                   AND ($scope)",

                'purchase_order_id',

                [
                    'po_number',
                    'supplier_code',
                    'supplier_name',
                    'supplier_reference',
                    'warehouse_code',
                    'warehouse_name',
                    'destination_location_code',
                    'destination_location_name',
                    'status',
                    'currency',
                    'document_date',
                ],

                [
                    'date' => 'order_date',
                    'reference' => 'po_number',
                    'supplier' => 'supplier_name',
                    'expected' => 'expected_date',
                    'status' => 'status',
                    'total' => 'total_amount',
                ],

                [
                    'status' => 'status',
                    'currency' => 'currency',
                    'from' => ['document_date', '>='],
                    'to' => ['document_date', '<='],
                ],

                'date',
                'desc',
                $scopeParameters,
            ];
        }

        if ($entity === 'bills') {
            [$scope, $scopeParameters] =
                $this->orderScope($company, $actor, 'o');

            return [
                "SELECT
                    i.*,
                    DATE(i.invoice_date) AS document_date,
                    s.supplier_code,
                    s.business_name AS supplier_name,
                    o.po_number,
                    o.warehouse_id,
                    o.destination_location_id
                 FROM finance_invoices i

                 INNER JOIN purchase_suppliers s
                   ON s.company_id=i.company_id
                  AND s.supplier_id=i.vendor_id

                 INNER JOIN purchase_orders o
                   ON o.company_id=i.company_id
                  AND o.purchase_order_id=i.purchase_order_id

                 WHERE i.company_id=:company_id
                   AND i.document_type='vendor_bill'
                   AND ($scope)",

                'invoice_id',

                [
                    'invoice_number',
                    'supplier_invoice_number',
                    'supplier_code',
                    'supplier_name',
                    'po_number',
                    'status',
                    'payment_status',
                    'currency',
                    'document_date',
                ],

                [
                    'date' => 'invoice_date',
                    'reference' => 'invoice_number',
                    'supplier' => 'supplier_name',
                    'po' => 'po_number',
                    'status' => 'status',
                    'outstanding' => 'residual_amount',
                    'total' => 'total_amount',
                ],

                [
                    'status' => 'status',
                    'payment' => 'payment_status',
                    'currency' => 'currency',
                    'from' => ['document_date', '>='],
                    'to' => ['document_date', '<='],
                ],

                'date',
                'desc',
                $scopeParameters,
            ];
        }

        if ($entity === 'returns') {
            [$scope, $scopeParameters] =
                $this->orderScope($company, $actor, 'o');

            return [
                "SELECT
                    r.*,
                    DATE(r.return_date) AS document_date,
                    o.po_number,
                    o.destination_location_id,
                    s.supplier_code,
                    s.business_name AS supplier_name,
                    w.code AS warehouse_code,
                    w.name AS warehouse_name
                 FROM procurement_vendor_returns r

                 INNER JOIN purchase_orders o
                   ON o.company_id=r.company_id
                  AND o.purchase_order_id=r.purchase_order_id

                 INNER JOIN purchase_suppliers s
                   ON s.company_id=r.company_id
                  AND s.supplier_id=r.supplier_id

                 LEFT JOIN inventory_warehouses w
                   ON w.company_id=r.company_id
                  AND w.warehouse_id=r.warehouse_id

                 WHERE r.company_id=:company_id
                   AND ($scope)",

                'vendor_return_id',

                [
                    'return_number',
                    'po_number',
                    'supplier_code',
                    'supplier_name',
                    'warehouse_code',
                    'warehouse_name',
                    'reason',
                    'status',
                    'document_date',
                ],

                [
                    'date' => 'return_date',
                    'reference' => 'return_number',
                    'po' => 'po_number',
                    'supplier' => 'supplier_name',
                    'status' => 'status',
                ],

                [
                    'status' => 'status',
                    'from' => ['document_date', '>='],
                    'to' => ['document_date', '<='],
                ],

                'date',
                'desc',
                $scopeParameters,
            ];
        }

        throw new InvalidArgumentException(
            'Unsupported Procurement list.'
        );
    }

    /**
     * SQL equivalent of the existing ProcurementService order access rule.
     *
     * Company Owner / System Administrator retain implicit company-wide
     * operational access. Everyone else remains restricted to explicit
     * warehouse/location assignments.
     *
     * @return array{0:string,1:array<string,int>}
     */
    private function orderScope(
        int $company,
        int $actor,
        string $alias
    ): array {
        $wide = $this->hasImplicitAllAccess(
            $company,
            $actor
        );

        $activeWarehouse =
            "EXISTS(
                SELECT 1
                FROM inventory_warehouses scope_w
                WHERE scope_w.company_id=$alias.company_id
                  AND scope_w.warehouse_id=$alias.warehouse_id
                  AND scope_w.active=1
                  AND scope_w.deleted_at IS NULL
            )";

        $activeLocation =
            "EXISTS(
                SELECT 1
                FROM inventory_warehouse_locations scope_l
                WHERE scope_l.company_id=$alias.company_id
                  AND scope_l.warehouse_id=$alias.warehouse_id
                  AND scope_l.location_id=$alias.destination_location_id
                  AND scope_l.active=1
                  AND scope_l.deleted_at IS NULL
            )";

        if ($wide) {
            return [
                "((
                    $alias.destination_location_id IS NULL
                    AND $activeWarehouse
                  ) OR (
                    $alias.destination_location_id IS NOT NULL
                    AND $activeLocation
                  ))",
                [],
            ];
        }

        $warehouseAccess =
            "EXISTS(
                SELECT 1
                FROM inventory_user_warehouse_access scope_wa
                WHERE scope_wa.company_id=$alias.company_id
                  AND scope_wa.user_id=:proc_warehouse_actor
                  AND scope_wa.warehouse_id=$alias.warehouse_id
                  AND scope_wa.active=1
            )";

        $locationAccess =
            "EXISTS(
                SELECT 1
                FROM inventory_user_location_access scope_la
                WHERE scope_la.company_id=$alias.company_id
                  AND scope_la.user_id=:proc_location_actor
                  AND scope_la.warehouse_id=$alias.warehouse_id
                  AND scope_la.location_id=$alias.destination_location_id
                  AND scope_la.active=1
            )";

        return [
            "((
                $alias.destination_location_id IS NULL
                AND $activeWarehouse
                AND $warehouseAccess
              ) OR (
                $alias.destination_location_id IS NOT NULL
                AND $activeLocation
                AND $locationAccess
              ))",

            [
                'proc_warehouse_actor' => $actor,
                'proc_location_actor' => $actor,
            ],
        ];
    }

    private function hasImplicitAllAccess(
        int $company,
        int $actor
    ): bool {
        if ($actor < 1) {
            return false;
        }

        $statement = \db()->prepare(
            "SELECT COUNT(*)
             FROM company_user_roles ur
             INNER JOIN roles r
               ON r.role_id=ur.role_id
             WHERE ur.company_id=?
               AND ur.user_id=?
               AND r.code IN(
                   'company_owner',
                   'system_administrator'
               )"
        );

        $statement->execute([$company, $actor]);

        return (int) $statement->fetchColumn() > 0;
    }
}
