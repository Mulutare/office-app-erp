<?php

declare(strict_types=1);

require __DIR__ . '/../app/helpers/bootstrap.php';

use App\Services\DataExchange\ExportDataProvider;
use App\Services\Lists\ProcurementListService;
use App\Services\Lists\ProcurementWorkspaceListService;

$db = db();
$checks = 0;

$check = static function (
    bool $condition,
    string $message
) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $message);
    }

    ++$checks;
    echo 'PASS ' . $message . PHP_EOL;
};

/*
 * Prefer a company that already has a Finance journal so
 * Supplier Bill fixtures can also be exercised.
 */
$company = (int) $db->query(
    "SELECT c.company_id
     FROM companies c
     WHERE c.deleted_at IS NULL
       AND EXISTS(
           SELECT 1
           FROM finance_journals j
           WHERE j.company_id=c.company_id
       )
     ORDER BY c.company_id
     LIMIT 1"
)->fetchColumn();

if ($company < 1) {
    $company = (int) $db->query(
        "SELECT company_id
         FROM companies
         WHERE deleted_at IS NULL
         ORDER BY company_id
         LIMIT 1"
    )->fetchColumn();
}

if ($company < 1) {
    throw new RuntimeException(
        'No test company is available.'
    );
}

/*
 * Use a real user who does NOT have implicit company-wide
 * warehouse access in this company. That lets the test prove
 * sibling warehouse/location isolation.
 */
$actorStatement = $db->prepare(
    "SELECT u.user_id
     FROM users u
     WHERE u.deleted_at IS NULL
       AND NOT EXISTS(
           SELECT 1
           FROM company_user_roles ur
           INNER JOIN roles r
             ON r.role_id=ur.role_id
           WHERE ur.company_id=?
             AND ur.user_id=u.user_id
             AND r.code IN(
                 'company_owner',
                 'system_administrator'
             )
       )
     ORDER BY u.user_id
     LIMIT 1"
);

$actorStatement->execute([$company]);
$actor = (int) $actorStatement->fetchColumn();

if ($actor < 1) {
    throw new RuntimeException(
        'A non-administrator test user is required.'
    );
}

$oldAuth = $_SESSION['auth'] ?? null;
$oldGet = $_GET;

$_SESSION['auth'] = [
    'user_id' => $actor,
    'permissions' => [],
    'company' => [
        'company_id' => $company,
    ],
];

$prefix =
    'SLP' .
    strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

$db->beginTransaction();

try {
    /*
     * --------------------------------------------------------
     * Warehouses and exact location authority
     * --------------------------------------------------------
     */
    $warehouse = $db->prepare(
        "INSERT INTO inventory_warehouses(
            company_id,
            code,
            name,
            warehouse_type,
            active,
            created_by,
            updated_by
         ) VALUES(?,?,?,'standard',1,?,?)"
    );

    $warehouse->execute([
        $company,
        $prefix . '-WH-A',
        $prefix . ' Allowed Warehouse',
        $actor,
        $actor,
    ]);

    $allowedWarehouse = (int) $db->lastInsertId();

    $warehouse->execute([
        $company,
        $prefix . '-WH-B',
        $prefix . ' Sibling Warehouse',
        $actor,
        $actor,
    ]);

    $siblingWarehouse = (int) $db->lastInsertId();

    $location = $db->prepare(
        "INSERT INTO inventory_warehouse_locations(
            company_id,
            warehouse_id,
            code,
            name,
            location_type,
            location_usage,
            pick_priority,
            receiving_allowed,
            picking_allowed,
            active,
            created_by,
            updated_by
         ) VALUES(
            ?,?,?,?,
            'bin','internal',
            100,1,1,1,?,?
         )"
    );

    $location->execute([
        $company,
        $allowedWarehouse,
        $prefix . '-WH-A/STOCK',
        $prefix . ' Allowed Stock',
        $actor,
        $actor,
    ]);

    $allowedLocation = (int) $db->lastInsertId();

    $location->execute([
        $company,
        $siblingWarehouse,
        $prefix . '-WH-B/STOCK',
        $prefix . ' Sibling Stock',
        $actor,
        $actor,
    ]);

    $siblingLocation = (int) $db->lastInsertId();

    $db->prepare(
        "INSERT INTO inventory_user_warehouse_access(
            company_id,user_id,warehouse_id,active,granted_by
         ) VALUES(?,?,?,?,?)"
    )->execute([
        $company,
        $actor,
        $allowedWarehouse,
        1,
        $actor,
    ]);

    $db->prepare(
        "INSERT INTO inventory_user_location_access(
            company_id,user_id,warehouse_id,location_id,
            active,granted_by
         ) VALUES(?,?,?,?,?,?)"
    )->execute([
        $company,
        $actor,
        $allowedWarehouse,
        $allowedLocation,
        1,
        $actor,
    ]);

    /*
     * --------------------------------------------------------
     * 106 Suppliers
     * --------------------------------------------------------
     */
    $supplier = $db->prepare(
        "INSERT INTO purchase_suppliers(
            company_id,
            supplier_code,
            business_name,
            payment_terms_days,
            currency,
            active,
            created_by,
            updated_by
         ) VALUES(?,?,?,?,?,1,?,?)"
    );

    $supplierIds = [];

    for ($i = 1; $i <= 106; ++$i) {
        $code =
            $prefix .
            '-SUP-' .
            str_pad((string)$i, 3, '0', STR_PAD_LEFT);

        $supplier->execute([
            $company,
            $code,
            $prefix . ' Supplier ' .
                str_pad((string)$i, 3, '0', STR_PAD_LEFT),
            30,
            'ETB',
            $actor,
            $actor,
        ]);

        $supplierIds[] = (int) $db->lastInsertId();
    }

    $primarySupplier = $supplierIds[0];

    /*
     * --------------------------------------------------------
     * 106 Requisitions
     * 105 submitted + 1 approved.
     * --------------------------------------------------------
     */
    $requisition = $db->prepare(
        "INSERT INTO purchase_requisitions(
            company_id,
            requisition_number,
            requester_user_id,
            department_id,
            requested_date,
            required_by_date,
            justification,
            status
         ) VALUES(?,?,?,NULL,?,?,?,?)"
    );

    $requisitionIds = [];

    for ($i = 1; $i <= 106; ++$i) {
        $day = (($i - 1) % 20) + 1;

        $requisition->execute([
            $company,
            $prefix .
                '-REQ-' .
                str_pad((string)$i, 3, '0', STR_PAD_LEFT),
            $actor,
            sprintf('2026-09-%02d', $day),
            '2026-10-15',
            $prefix . ' requisition ' . $i,
            $i === 106 ? 'approved' : 'submitted',
        ]);

        $requisitionIds[] =
            (int) $db->lastInsertId();
    }

    /*
     * --------------------------------------------------------
     * 106 authorized POs + 1 unauthorized sibling PO.
     * --------------------------------------------------------
     */
    $po = $db->prepare(
        "INSERT INTO purchase_orders(
            company_id,
            po_number,
            supplier_id,
            requisition_id,
            warehouse_id,
            destination_location_id,
            order_date,
            expected_date,
            currency,
            payment_terms_days,
            supplier_reference,
            subtotal,
            tax_amount,
            discount_amount,
            total_amount,
            status,
            created_by
         ) VALUES(
            ?,?,?,?,?,?,
            ?,?,
            'ETB',30,?,
            100,0,0,100,?,?
         )"
    );

    $poIds = [];

    for ($i = 1; $i <= 106; ++$i) {
        $status = match ($i) {
            1 => 'partially_received',
            2 => 'received',
            3 => 'partially_billed',
            default => 'draft',
        };

        $po->execute([
            $company,
            $prefix .
                '-PO-' .
                str_pad((string)$i, 3, '0', STR_PAD_LEFT),
            $primarySupplier,
            null,
            $allowedWarehouse,
            $allowedLocation,
            '2026-09-20',
            '2026-10-20',
            $prefix . '-REF-' . $i,
            $status,
            $actor,
        ]);

        $poIds[] = (int) $db->lastInsertId();
    }

    $po->execute([
        $company,
        $prefix . '-SIBLING-PO',
        $primarySupplier,
        null,
        $siblingWarehouse,
        $siblingLocation,
        '2026-09-20',
        '2026-10-20',
        $prefix . '-SIBLING',
        'draft',
        $actor,
    ]);

    $siblingPo = (int) $db->lastInsertId();

    /*
     * --------------------------------------------------------
     * Vendor returns: authorized + sibling.
     * --------------------------------------------------------
     */
    $vendorReturn = $db->prepare(
        "INSERT INTO procurement_vendor_returns(
            company_id,
            purchase_order_id,
            supplier_id,
            warehouse_id,
            return_number,
            return_date,
            reason,
            status,
            idempotency_key,
            posted_by,
            posted_at
         ) VALUES(
            ?,?,?,?,?,
            '2026-09-22',
            ?,
            'posted',
            ?,?,
            NOW()
         )"
    );

    for ($i = 1; $i <= 106; ++$i) {
        $vendorReturn->execute([
            $company,
            $poIds[$i - 1],
            $primarySupplier,
            $allowedWarehouse,
            $prefix .
                '-RET-' .
                str_pad((string)$i, 3, '0', STR_PAD_LEFT),
            $prefix . ' return ' . $i,
            $prefix . '-return-key-' . $i,
            $actor,
        ]);
    }

    $vendorReturn->execute([
        $company,
        $siblingPo,
        $primarySupplier,
        $siblingWarehouse,
        $prefix . '-SIBLING-RET',
        $prefix . ' sibling return',
        $prefix . '-sibling-return-key',
        $actor,
    ]);

    /*
     * --------------------------------------------------------
     * Supplier Bills when a company Finance journal exists.
     * --------------------------------------------------------
     */
    $journalStatement = $db->prepare(
        "SELECT journal_id
         FROM finance_journals
         WHERE company_id=?
         ORDER BY journal_id
         LIMIT 1"
    );

    $journalStatement->execute([$company]);
    $journal = (int) $journalStatement->fetchColumn();
    if($journal<1){
        $db->prepare("INSERT INTO finance_journals(company_id,journal_code,journal_name,journal_type) VALUES(?,?,?,'purchase')")->execute([$company,$prefix.'-JOURNAL','Disposable Procurement journal']);
        $journal=(int)$db->lastInsertId();
    }

    $billFixtures = false;

    if ($journal > 0) {
        $bill = $db->prepare(
            "INSERT INTO finance_invoices(
                company_id,
                journal_id,
                vendor_id,
                purchase_order_id,
                document_type,
                invoice_number,
                supplier_invoice_number,
                invoice_date,
                due_date,
                currency,
                payment_terms_days,
                invoice_policy,
                status,
                payment_status,
                untaxed_amount,
                tax_amount,
                total_amount,
                residual_amount,
                created_by
             ) VALUES(
                ?,?,?,?,
                'vendor_bill',
                ?,?,
                '2026-09-23',
                '2026-10-23',
                'ETB',
                30,
                'delivered',
                'draft',
                'unpaid',
                100,0,100,100,?
             )"
        );

        for ($i = 1; $i <= 106; ++$i) {
            $bill->execute([
                $company,
                $journal,
                $primarySupplier,
                $poIds[$i - 1],
                $prefix .
                    '-BILL-' .
                    str_pad((string)$i, 3, '0', STR_PAD_LEFT),
                $prefix . '-SUPINV-' . $i,
                $actor,
            ]);
        }

        $bill->execute([
            $company,
            $journal,
            $primarySupplier,
            $siblingPo,
            $prefix . '-SIBLING-BILL',
            $prefix . '-SIBLING-SUPINV',
            $actor,
        ]);

        $billFixtures = true;
    }

    $lists = new ProcurementListService();

    /*
     * --------------------------------------------------------
     * Suppliers
     * --------------------------------------------------------
     */
    $result = $lists->listing(
        'suppliers',
        ['q' => $prefix]
    )->page();

    $check(
        $result['pagination']['total'] === 106
        && count($result['rows']) === 25,
        'Suppliers count and page the complete company dataset'
    );

    foreach ([25, 50, 100] as $size) {
        $page = $lists->listing(
            'suppliers',
            [
                'q' => $prefix,
                'per_page' => $size,
            ]
        )->page();

        $check(
            count($page['rows']) === min($size, 106),
            'Suppliers support per-page size ' . $size
        );
    }

    $result = $lists->listing(
        'suppliers',
        ['q' => $prefix . '-SUP-105']
    )->page();

    $check(
        $result['pagination']['total'] === 1,
        'Supplier search finds a record beyond page one'
    );

    $check(
        count(
            $lists->listing(
                'suppliers',
                ['q' => $prefix]
            )->export()
        ) === 106,
        'Supplier export traverses every matching page'
    );

    $check(
        $lists->listing(
            'suppliers',
            ['q' => $prefix . '-NO-SUCH-SUPPLIER']
        )->page()['pagination']['total'] === 0,
        'Empty supplier search stays empty'
    );

    /*
     * --------------------------------------------------------
     * Requisitions
     * --------------------------------------------------------
     */
    $result = $lists->listing(
        'requisitions',
        ['q' => $prefix]
    )->page();

    $check(
        $result['pagination']['total'] === 106,
        'Requisitions count before pagination'
    );

    $check(
        $lists->listing(
            'requisitions',
            ['q' => $prefix . '-REQ-105']
        )->page()['pagination']['total'] === 1,
        'Requisition search runs before pagination'
    );

    $check(
        $lists->listing(
            'requisitions',
            ['status' => 'submitted']
        )->page()['pagination']['total'] === 105,
        'Requisition workflow status filters before pagination'
    );

    $approved =
        $lists->approvedRequisitionOptions();

    $approvedIds = array_map(
        static fn(array $row): int =>
            (int)$row['requisition_id'],
        $approved
    );

    $check(
        in_array($requisitionIds[105], $approvedIds, true),
        'Approved requisition options are not limited to the visible page'
    );

    /*
     * --------------------------------------------------------
     * Purchase Orders / warehouse-location isolation
     * --------------------------------------------------------
     */
    $result = $lists->listing(
        'purchase-orders',
        ['q' => $prefix]
    )->page();

    $check(
        $result['pagination']['total'] === 106,
        'Purchase Orders apply authority before count and page'
    );

    $check(
        $lists->listing(
            'purchase-orders',
            ['q' => $prefix . '-PO-105']
        )->page()['pagination']['total'] === 1,
        'Purchase Order search finds authorized data beyond page one'
    );

    $check(
        $lists->listing(
            'purchase-orders',
            ['q' => $prefix . '-SIBLING-PO']
        )->page()['pagination']['total'] === 0,
        'Purchase Order search cannot reveal a sibling warehouse'
    );

    $poExport = $lists->listing(
        'purchase-orders',
        ['q' => $prefix]
    )->export();

    $check(
        count($poExport) === 106
        && !in_array(
            $siblingPo,
            array_map(
                static fn(array $row): int =>
                    (int)$row['purchase_order_id'],
                $poExport
            ),
            true
        ),
        'Purchase Order export uses the same warehouse-location scope'
    );

    /*
     * --------------------------------------------------------
     * Vendor Returns inherit PO authority
     * --------------------------------------------------------
     */
    $result = $lists->listing(
        'returns',
        ['q' => $prefix]
    )->page();

    $check(
        $result['pagination']['total'] === 106,
        'Vendor Returns apply PO authority before pagination'
    );

    $check(
        $lists->listing(
            'returns',
            ['q' => $prefix . '-SIBLING-RET']
        )->page()['pagination']['total'] === 0,
        'Vendor Return search cannot reveal sibling warehouse data'
    );

    /*
     * --------------------------------------------------------
     * Supplier Bills inherit PO authority
     * --------------------------------------------------------
     */
    if ($billFixtures) {
        $result = $lists->listing(
            'bills',
            ['q' => $prefix]
        )->page();

        $check(
            $result['pagination']['total'] === 106,
            'Supplier Bills apply PO authority before pagination'
        );

        $check(
            $lists->listing(
                'bills',
                ['q' => $prefix . '-SIBLING-BILL']
            )->page()['pagination']['total'] === 0,
            'Supplier Bill search cannot reveal sibling warehouse data'
        );

        $check(
            count(
                $lists->listing(
                    'bills',
                    ['q' => $prefix]
                )->export()
            ) === 106,
            'Supplier Bill export traverses all authorized matching rows'
        );
    } else {
        /*
         * Still execute the list SQL so an invalid query cannot hide
         * behind an empty fixture.
         */
        $lists->listing('bills', [])->page();
        echo "SKIP bill row-count fixture: company has no Finance journal"
            . PHP_EOL;
    }

    /*
     * --------------------------------------------------------
     * Complete overview summary
     * --------------------------------------------------------
     */
    $summary = $lists->summary();

    $check(
        $summary['awaitingApproval'] === 105,
        'Overview requisition metric uses the complete dataset'
    );

    $check(
        $summary['openOrders'] === 106,
        'Overview Purchase Order metric excludes unauthorized sibling PO'
    );

    $check(
        $summary['partiallyReceived'] === 1,
        'Overview partially-received metric uses complete results'
    );

    $check(
        $summary['awaitingBill'] === 2,
        'Overview awaiting-bill metric uses complete results'
    );

    if ($billFixtures) {
        $check(
            $summary['outstandingBills'] === 106,
            'Overview outstanding-bill metric uses authorized complete results'
        );
    }

    /*
     * --------------------------------------------------------
     * Full supplier option catalogue
     * --------------------------------------------------------
     */
    $check(
        count($lists->supplierOptions()) === 106,
        'Purchase Order supplier options are not restricted to page one'
    );

    /*
     * --------------------------------------------------------
     * DataExchange uses the same smart-list query.
     * --------------------------------------------------------
     */
    $provider = new ExportDataProvider();

    $check(
        count(
            $provider->rows(
                'suppliers',
                ['q' => $prefix . '-SUP-105']
            )
        ) === 1,
        'Configured supplier export reuses Procurement filters'
    );

    $check(
        count(
            $provider->rows(
                'purchase-orders',
                ['q' => $prefix . '-PO-105']
            )
        ) === 1,
        'Configured Purchase Order export reuses Procurement filters'
    );

    $check(
        count(
            $provider->rows(
                'purchase-orders',
                ['q' => $prefix . '-SIBLING-PO']
            )
        ) === 0,
        'Configured Purchase Order export cannot leak sibling scope'
    );

    /*
     * --------------------------------------------------------
     * Workspace/UI
     * --------------------------------------------------------
     */
    $workspaceInput = [
        'section' => 'suppliers',
        'suppliers' => [
            'q' => $prefix,
            'per_page' => 25,
        ],
    ];

    $workspace =
        (new ProcurementWorkspaceListService())
            ->workspace($workspaceInput);

    $check(
        $workspace['lists']['suppliers']['pagination']['total']
            === 106,
        'Procurement workspace uses the paginated supplier foundation'
    );

    $_GET = $workspaceInput;

    ob_start();

    view(
        'procurement.index',
        $workspace + [
            'permissions' => [
                'procurement.suppliers.manage',
            ],
            'notice' => null,
            'error' => null,
        ]
    );

    $html = (string)ob_get_clean();

    $check(
        str_contains($html, 'of 106 records')
        && str_contains($html, 'Export filtered')
        && str_contains($html, 'Per page'),
        'Procurement Suppliers renders shared search, export and pagination controls'
    );

    $query =
        $workspace['lists']['suppliers']['query'];

    $pageUrl =
        $query->url(
            '/procurement',
            ['page' => 2]
        );

    $check(
        str_contains($pageUrl, 'section=suppliers')
        && str_contains(
            $pageUrl,
            'suppliers%5Bpage%5D=2'
        ),
        'Procurement pagination preserves its section and namespaced filters'
    );

    require_once __DIR__.'/support/licensed-import-fixture.php';
    licensedImportFixture($db,$company,$prefix.'IMPORT');
    $result=(new App\Services\DataExchange\ImportService())->test('suppliers',[
        [$prefix.'-SUP-105','Duplicate supplier','ETB'],
    ],[0=>'supplier_code',1=>'business_name',2=>'currency'])['result'];
    $check($result->duplicateRows===1&&$result->failed>0,'Supplier preview rejects a duplicate beyond the first 100 suppliers');

    echo $checks .
        ' Procurement smart-list checks passed' .
        PHP_EOL;

} catch (Throwable $error) {
    fwrite(
        STDERR,
        $error->getMessage() .
        PHP_EOL .
        $error->getTraceAsString() .
        PHP_EOL
    );

    exitCode:
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    $_SESSION['auth'] = $oldAuth ?? [];
    $_GET = $oldGet;

    exit(1);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    if ($oldAuth === null) {
        unset($_SESSION['auth']);
    } else {
        $_SESSION['auth'] = $oldAuth;
    }

    $_GET = $oldGet;
}
