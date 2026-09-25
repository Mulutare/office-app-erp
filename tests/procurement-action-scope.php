<?php

declare(strict_types=1);

require __DIR__ . '/../app/helpers/bootstrap.php';

use App\Models\CompanyMembership;
use App\Services\ActionRequiredCountService;
use App\Services\InventoryOperationalAccessService;
use App\Services\InventoryService;
use App\Services\ProcurementService;

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
});
if (getenv('DB_DATABASE') !== 'office_app_test') throw new RuntimeException('Isolated database required');

$pdo = db();
$company = 2;
$actor = 122; // Existing scoped shop-manager fixture, never Muluneh.
$previousSession = $_SESSION;
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $message);
    ++$checks;
    fwrite(STDOUT, 'PASS ' . $message . "\n");
};
$reset = static function (): void {
    foreach (['requestCache', 'itemCache'] as $name) {
        (new ReflectionProperty(ActionRequiredCountService::class, $name))->setValue(null, []);
    }
};
$tasks = new ActionRequiredCountService();
$procurement = new ProcurementService();
$access = new InventoryOperationalAccessService();
$suffix = bin2hex(random_bytes(5));
$insert = static function (string $table, array $row) use ($pdo): int {
    $columns = implode(',', array_keys($row));
    $marks = implode(',', array_fill(0, count($row), '?'));
    $pdo->prepare("INSERT INTO $table ($columns) VALUES ($marks)")->execute(array_values($row));
    return (int) $pdo->lastInsertId();
};

$pdo->beginTransaction();
try {
    // Test-only grants roll back with every fixture. No company module or membership changes.
    $pdo->prepare("INSERT INTO company_user_permission_overrides (company_id,user_id,permission_id,allowed,updated_by)
        SELECT ?,?,permission_id,1,? FROM permissions WHERE code LIKE 'procurement.%' OR code LIKE 'inventory.receipts.%' OR code='inventory.module.enabled'
        ON DUPLICATE KEY UPDATE allowed=1")->execute([$company, $actor, $actor]);
    $permissions = (new CompanyMembership())->permissionCodes($actor, $company);
    $_SESSION['auth'] = ['user_id' => $actor, 'company' => (new App\Models\CompanyModule())->companyById($company), 'permissions' => $permissions, 'is_platform_admin' => false];
    $locations = $access->locationsForUser($company, $actor);
    $inside = $locations[0] ?? null;
    if ($inside === null) throw new RuntimeException('Scoped manager fixture requires a receiving location');
    $warehouse = (int) $inside['warehouse_id'];
    $location = (int) $inside['location_id'];
    $deniedLocation = $pdo->query("SELECT location_id FROM inventory_warehouse_locations WHERE company_id=$company AND warehouse_id=$warehouse AND active=1 AND deleted_at IS NULL AND location_id NOT IN (SELECT location_id FROM inventory_user_location_access WHERE company_id=$company AND user_id=$actor AND active=1) LIMIT 1")->fetchColumn();
    $outside = $pdo->query("SELECT warehouse_id,location_id FROM inventory_warehouse_locations WHERE company_id=$company AND warehouse_id<>$warehouse AND active=1 AND deleted_at IS NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $check($deniedLocation !== false && is_array($outside), 'Fixtures include an unauthorized location and warehouse');

    $orderTemplate = $pdo->query("SELECT * FROM purchase_orders WHERE company_id=$company LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$orderTemplate) throw new RuntimeException('Purchase order fixture required');
    $lineTemplate = $pdo->query('SELECT * FROM purchase_order_lines WHERE purchase_order_id=' . (int) $orderTemplate['purchase_order_id'] . ' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$lineTemplate) throw new RuntimeException('Purchase order line fixture required');
    unset($orderTemplate['purchase_order_id'], $lineTemplate['purchase_order_line_id']);
    $makeOrder = static function (int $warehouseId, ?int $locationId, string $label) use ($insert, $orderTemplate, $lineTemplate, $actor, $suffix): int {
        $id = $insert('purchase_orders', array_replace($orderTemplate, [
            'po_number' => 'SCOPE-' . $label . '-' . $suffix, 'status' => 'confirmed',
            'warehouse_id' => $warehouseId, 'destination_location_id' => $locationId,
            'created_by' => $actor, 'requisition_id' => null,
        ]));
        $insert('purchase_order_lines', array_replace($lineTemplate, [
            'purchase_order_id' => $id, 'ordered_quantity' => 10, 'received_quantity' => 0,
            'returned_quantity' => 0, 'billed_quantity' => 0, 'requisition_line_id' => null,
        ]));
        return $id;
    };
    $allowed = $makeOrder($warehouse, $location, 'allowed');
    $denied = $makeOrder($warehouse, (int) $deniedLocation, 'location');
    $otherWarehouse = $makeOrder((int) $outside['warehouse_id'], (int) $outside['location_id'], 'warehouse');
    $legacy = $makeOrder($warehouse, null, 'legacy');
    $fixtureIds = [$allowed, $denied, $otherWarehouse, $legacy];
    $idSql = implode(',', $fixtureIds);
    $check($procurement->orderAccessible($allowed, $actor) && !$procurement->orderAccessible($denied, $actor)
        && !$procurement->orderAccessible($otherWarehouse, $actor), 'Authoritative PO rule distinguishes warehouse and location scope');
    $batch = $procurement->accessibleOrderIds($company, $actor, array_merge($fixtureIds, [$allowed, 99999999]));
    sort($batch);
    $expected = [$allowed, $legacy];
    sort($expected);
    $check($batch === $expected, 'Batch access matches direct checks, including legacy destination and missing IDs');
    $check($procurement->accessibleOrderIds(1, $actor, $fixtureIds) === [], 'Batch rejects a company other than the active tenant');

    foreach ([
        ['draft', 'orders', 'submit_purchase_order'],
        ['submitted', 'orders', 'approve_purchase_order'],
        ['approved', 'orders', 'confirm_purchase_order'],
        ['billed', 'orders', 'close_purchase_order'],
        ['confirmed', 'receipts', 'create_receipt'],
        ['partially_received', 'receipts', 'create_receipt'],
        ['received', 'bills', 'create_supplier_bill'],
    ] as [$status, $section, $action]) {
        $pdo->prepare("UPDATE purchase_orders SET status=?,created_by=? WHERE purchase_order_id IN ($idSql)")
            ->execute([$status, $status === 'draft' ? $actor : 121]);
        $pdo->exec("UPDATE purchase_order_lines SET received_quantity=" . ($status === 'received' ? '10' : '0') . " WHERE purchase_order_id IN ($idSql)");
        $reset();
        $items = $tasks->itemsFor($company, $actor, $permissions, 'procurement', $section);
        $matches = array_values(array_filter($items, static fn (array $item): bool => in_array($item['id'], $fixtureIds, true) && $item['action_key'] === $action));
        $visibleIds = array_column($matches, 'id');
        sort($visibleIds);
        $check($visibleIds === $expected, "$action ($status) includes only accessible purchase orders");
        foreach ($matches as $item) {
            $check($item['url'] === appBasePath() . '/procurement/' . $item['id'] && $procurement->orderAccessible($item['id'], $actor), "$action destination uses the accessible PO route");
        }
        $check($tasks->counts($company, $actor, $permissions)['procurement'][$section] === count($items), "$section badge equals its filtered task list for $status");
    }

    $pdo->exec("UPDATE purchase_orders SET status='confirmed' WHERE purchase_order_id IN ($idSql)");
    $pdo->exec("UPDATE purchase_order_lines SET received_quantity=ordered_quantity WHERE purchase_order_id=$allowed");
    $reset();
    $items = $tasks->itemsFor($company, $actor, $permissions, 'procurement', 'receipts');
    $check(!in_array($allowed, array_column($items, 'id'), true), 'Confirmed PO without outstanding quantity does not create a receipt task');
    $withoutView = array_values(array_diff($permissions, ['procurement.view']));
    $check(array_filter($tasks->itemsFor($company, $actor, $withoutView, 'procurement', 'receipts'), static fn (array $row): bool => $row['entity'] === 'purchase_order') === [], 'Action permission without PO route permission is insufficient');

    $receiptTemplate = $pdo->query("SELECT * FROM inventory_goods_receipts WHERE company_id=$company LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$receiptTemplate) throw new RuntimeException('Receipt fixture required');
    unset($receiptTemplate['goods_receipt_id']);
    $receiptIds = [];
    foreach ([[$warehouse, $location], [$warehouse, (int) $deniedLocation], [(int) $outside['warehouse_id'], (int) $outside['location_id']]] as $index => [$wid, $lid]) {
        $receiptIds[] = $insert('inventory_goods_receipts', array_replace($receiptTemplate, [
            'warehouse_id' => $wid, 'destination_location_id' => $lid, 'purchase_order_id' => null,
            'operation_type_id' => (int) $pdo->query("SELECT operation_type_id FROM inventory_operation_types WHERE company_id=$company AND warehouse_id=$wid LIMIT 1")->fetchColumn(),
            'receipt_number' => 'SCOPE-R-' . $index . '-' . $suffix, 'status' => 'submitted', 'created_by' => 121,
        ]));
    }
    $receiptSql = implode(',', $receiptIds);
    foreach (['submitted' => 'approve_receipt', 'approved' => 'post_receipt'] as $status => $action) {
        $pdo->prepare("UPDATE inventory_goods_receipts SET status=? WHERE goods_receipt_id IN ($receiptSql)")->execute([$status]);
        $reset();
        foreach (['procurement', 'inventory'] as $module) {
            $items = $tasks->itemsFor($company, $actor, $permissions, $module, 'receipts');
            $matches = array_values(array_filter($items, static fn (array $row): bool => $row['action_key'] === $action && in_array($row['id'], $receiptIds, true)));
            $check(array_column($matches, 'id') === [$receiptIds[0]], "$module $action excludes unauthorized warehouses and locations");
            $check($matches[0]['url'] === appBasePath() . '/inventory/receipts/' . $receiptIds[0]
                && (new InventoryService())->receipt($receiptIds[0]) !== null, "$module $action opens the authorized inventory receipt");
            $check($tasks->counts($company, $actor, $permissions)[$module]['receipts'] === count($items), "$module receipt badge and list agree for $action");
        }
    }
    $noReceiptView = array_values(array_diff($permissions, ['inventory.receipts.view']));
    $check(array_filter($tasks->itemsFor($company, $actor, $noReceiptView, 'procurement', 'receipts'), static fn (array $row): bool => $row['entity'] === 'goods_receipt') === [], 'Receipt actions require receipt detail permission');

    $billTemplate = $pdo->query("SELECT * FROM finance_invoices WHERE company_id=$company AND document_type='vendor_bill' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$billTemplate) throw new RuntimeException('Supplier bill fixture required');
    unset($billTemplate['invoice_id']);
    $billIds = [];
    foreach ([$allowed, $denied, $otherWarehouse] as $index => $orderId) {
        $billIds[] = $insert('finance_invoices', array_replace($billTemplate, [
            'purchase_order_id' => $orderId, 'invoice_number' => 'SCOPE-B-' . $index . '-' . $suffix,
            'supplier_invoice_number' => 'SCOPE-S-' . $index . '-' . $suffix,
            'status' => 'draft', 'residual_amount' => 10,
        ]));
    }
    $billSql = implode(',', $billIds);
    foreach (['draft' => ['bills', 'post_supplier_bill'], 'posted' => ['payments', 'post_supplier_payment']] as $status => [$section, $action]) {
        $pdo->prepare("UPDATE finance_invoices SET status=? WHERE invoice_id IN ($billSql)")->execute([$status]);
        $reset();
        $items = $tasks->itemsFor($company, $actor, $permissions, 'procurement', $section);
        $matches = array_values(array_filter($items, static fn (array $row): bool => $row['action_key'] === $action && in_array($row['id'], $billIds, true)));
        $check(array_column($matches, 'id') === [$billIds[0]], "$action follows its underlying purchase order scope");
        $check($tasks->counts($company, $actor, $permissions)['procurement'][$section] === count($items), "$section badge equals scoped supplier bill tasks");
    }

    $controller = new App\Controllers\ProcurementController();
    http_response_code(200);
    ob_start();
    $controller->showOrder((string) $denied);
    $deniedHtml = (string) ob_get_clean();
    $check(http_response_code() === 404 && str_contains($deniedHtml, 'Purchase order not found') && !str_contains($deniedHtml, 'User not found'), 'Inaccessible PO returns order-specific 404');
    ob_start();
    $controller->showOrder('99999999');
    $missingHtml = (string) ob_get_clean();
    $check($deniedHtml === $missingHtml, 'Missing and unauthorized orders return indistinguishable 404 pages');
    http_response_code(200);
    ob_start();
    $controller->showOrder((string) $allowed);
    $allowedHtml = (string) ob_get_clean();
    $check(http_response_code() === 200 && str_contains($allowedHtml, 'SCOPE-allowed-' . $suffix), 'Accessible task opens the correct purchase order successfully');

    $manyOrders = [];
    for ($index = 0; $index < 501; ++$index) {
        $manyOrders[] = $insert('purchase_orders', array_replace($orderTemplate, [
            'po_number' => 'SCOPE-BATCH-' . $index . '-' . $suffix,
            'warehouse_id' => $warehouse, 'destination_location_id' => $location,
            'requisition_id' => null,
        ]));
    }
    $queries = static fn (): int => (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetchColumn(1);
    $before = $queries();
    $procurement->accessibleOrderIds($company, $actor, [$manyOrders[0]]);
    $singleQueries = $queries() - $before;
    $before = $queries();
    $manyAllowed = $procurement->accessibleOrderIds($company, $actor, $manyOrders);
    $batchQueries = $queries() - $before;
    $check(count($manyAllowed) === 501 && $batchQueries === $singleQueries + 1, '501 orders at one destination need only one extra batch query, not per-order authorization queries');

    $otherCompany = (int) $pdo->query("SELECT company_id FROM companies WHERE company_id<>$company LIMIT 1")->fetchColumn();
    if ($otherCompany < 1) throw new RuntimeException('Second company fixture required');
    $_SESSION['auth']['company'] = ['company_id' => $otherCompany];
    $check(!$procurement->orderAccessible($allowed, $actor) && $procurement->accessibleOrderIds($otherCompany, $actor, [$allowed]) === [], 'Cross-company order IDs remain inaccessible');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION = $previousSession;
    $reset();
}
echo "$checks procurement task scope checks passed\n";
