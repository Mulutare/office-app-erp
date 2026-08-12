<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';

use App\Repositories\RepositoryFactory;
use App\Services\AssetService;
use App\Services\AuthService;
use App\Services\InventoryService;
use App\Services\WarehouseManagementService;

$failures = 0;
$checks = 0;
$check = static function (bool $condition, string $description) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $description . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
};

$password = (string) getenv('TEST_ADMIN_PASSWORD');
$login = (new AuthService())->attempt('test_tenant_a_admin', $password);
$companyId = (int) ($_SESSION['auth']['company']['company_id'] ?? 0);
$actorId = (int) ($_SESSION['auth']['user_id'] ?? 0);
$check(($login['successful'] ?? false) === true && $companyId > 0 && $actorId > 0, 'Asset integration actor authenticates in Tenant A');

$accounts = [
    ['1590', 'Test Fixed Assets', 'asset', 'debit'],
    ['1591', 'Test Accumulated Depreciation', 'asset', 'credit'],
    ['6590', 'Test Depreciation Expense', 'expense', 'debit'],
    ['4590', 'Test Disposal Gain', 'revenue', 'credit'],
    ['6591', 'Test Disposal Loss', 'expense', 'debit'],
];
$insertAccount = db()->prepare(
    'INSERT IGNORE INTO finance_accounts(company_id,account_code,account_name,account_type,normal_balance,currency,created_by,updated_by)
     VALUES(:company_id,:code,:name,:type,:normal,:currency,:actor,:actor2)'
);
foreach ($accounts as [$code, $name, $type, $normal]) {
    $insertAccount->execute([
        'company_id' => $companyId,
        'code' => $code,
        'name' => $name,
        'type' => $type,
        'normal' => $normal,
        'currency' => 'ETB',
        'actor' => $actorId,
        'actor2' => $actorId,
    ]);
}
$accountQuery = db()->prepare(
    "SELECT account_code,account_id FROM finance_accounts
     WHERE company_id=:company_id AND account_code IN('1590','1591','6590','4590','6591')"
);
$accountQuery->execute(['company_id' => $companyId]);
$accountIds = [];
foreach ($accountQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $accountIds[$row['account_code']] = (int) $row['account_id'];
}

$service = new AssetService();
$category = $service->createCategory([
    'category_code' => 'TEST-SL-36',
    'category_name' => 'Test Straight Line 36 Months',
    'useful_life_months' => 36,
    'salvage_behavior' => 'fixed',
    'asset_account_id' => $accountIds['1590'] ?? 0,
    'accumulated_depreciation_account_id' => $accountIds['1591'] ?? 0,
    'depreciation_expense_account_id' => $accountIds['6590'] ?? 0,
    'disposal_gain_account_id' => $accountIds['4590'] ?? 0,
    'disposal_loss_account_id' => $accountIds['6591'] ?? 0,
], $actorId);
$check(($category['successful'] ?? false) === true, 'Tenant-scoped asset category persists controlled Finance accounts');
$categoryId = (int) ($category['id'] ?? 0);

$createAsset = static function (AssetService $service, int $categoryId, int $actorId, string $number): array {
    return $service->createAsset([
        'asset_category_id' => $categoryId,
        'asset_number' => $number,
        'asset_name' => 'Lifecycle Server ' . $number,
        'acquisition_date' => '2026-01-15',
        'acquisition_cost' => 60000,
        'salvage_value' => 6000,
        'currency' => 'ETB',
        'location_name' => 'Server Room A',
    ], $actorId);
};

$created = $createAsset($service, $categoryId, $actorId, 'FA-TEST-GAIN');
$assetId = (int) ($created['id'] ?? 0);
$activated = $service->activate($assetId, '2026-01-15', $actorId);
$asset = $service->asset($assetId);
$check(
    ($created['successful'] ?? false) === true
    && ($activated['successful'] ?? false) === true
    && count($asset['schedule'] ?? []) === 36
    && (float) $asset['schedule'][0]['depreciation_amount'] === 1500.00,
    'Draft asset activates with a persisted 36-period straight-line schedule'
);

$firstLineId = (int) $asset['schedule'][0]['depreciation_line_id'];
$posted = $service->postDepreciation($firstLineId, $actorId);
$replayed = $service->postDepreciation($firstLineId, $actorId);
$afterPost = $service->asset($assetId);
$journalCount = db()->prepare(
    "SELECT COUNT(*) FROM finance_journal_batches WHERE company_id=:company_id AND source_type='asset_depreciation' AND source_id=:source_id"
);
$journalCount->execute(['company_id' => $companyId, 'source_id' => (string) $firstLineId]);
$check(
    ($posted['successful'] ?? false) === true
    && ($replayed['replayed'] ?? false) === true
    && (int) $journalCount->fetchColumn() === 1
    && (float) $afterPost['accumulated_depreciation'] === 1500.00
    && (float) $afterPost['book_value'] === 58500.00,
    'Depreciation posts once, is idempotent, and updates accumulated depreciation and NBV'
);

$maintenance = $service->addMaintenance($assetId, [
    'maintenance_type' => 'repair',
    'description' => 'Replace cooling fan',
    'cost' => 3000,
    'maintenance_date' => '2026-03-01',
], $actorId);
$transfer = $service->transfer($assetId, [
    'location_name' => 'Server Room B',
    'reason' => 'Capacity reallocation',
], $actorId);
$afterOperations = $service->asset($assetId);
$check(
    ($maintenance['successful'] ?? false) === true
    && ($transfer['successful'] ?? false) === true
    && (float) $afterOperations['acquisition_cost'] === 60000.00
    && $afterOperations['location_name'] === 'Server Room B'
    && count($afterOperations['maintenance']) === 1
    && count($afterOperations['transfers']) === 1,
    'Routine maintenance does not capitalize cost and transfer preserves auditable custody history'
);

$gainDisposal = $service->dispose($assetId, [
    'disposal_type' => 'sale',
    'disposal_date' => '2026-03-15',
    'proceeds_amount' => 60000,
    'reason' => 'Lifecycle sale test',
], $actorId);
$duplicateDisposal = $service->dispose($assetId, [
    'disposal_type' => 'sale',
    'disposal_date' => '2026-03-16',
    'proceeds_amount' => 60000,
    'reason' => 'Must be rejected',
], $actorId);
$disposedAsset = $service->asset($assetId);
$scheduledAfterDisposal = array_filter($disposedAsset['schedule'], static fn(array $row): bool => $row['status'] === 'scheduled');
$check(
    ($gainDisposal['successful'] ?? false) === true
    && (float) $gainDisposal['gain'] === 1500.00
    && ($duplicateDisposal['successful'] ?? true) === false
    && $disposedAsset['status'] === 'sold'
    && $scheduledAfterDisposal === [],
    'Sale above NBV records gain, cancels future depreciation, and prevents second disposal'
);

$lossCreated = $createAsset($service, $categoryId, $actorId, 'FA-TEST-LOSS');
$lossAssetId = (int) ($lossCreated['id'] ?? 0);
$service->activate($lossAssetId, '2026-01-15', $actorId);
$lossAsset = $service->asset($lossAssetId);
$service->postDepreciation((int) $lossAsset['schedule'][0]['depreciation_line_id'], $actorId);
$lossDisposal = $service->dispose($lossAssetId, [
    'disposal_type' => 'sale',
    'disposal_date' => '2026-03-15',
    'proceeds_amount' => 50000,
    'reason' => 'Lifecycle loss test',
], $actorId);
$check(
    ($lossDisposal['successful'] ?? false) === true
    && (float) $lossDisposal['loss'] === 8500.00
    && (float) $lossDisposal['gain'] === 0.00,
    'Sale below NBV records the exact disposal loss'
);

$warehouse = (new WarehouseManagementService())->create([
    'code' => 'ASSET-WH',
    'name' => 'Asset Integration Warehouse',
    'warehouse_type' => 'standard',
    'branch_id' => null,
    'manager_user_id' => null,
    'address' => 'Asset Test Site',
    'phone' => null,
    'email' => null,
    'allow_negative_stock' => false,
    'is_default' => true,
    'active' => true,
], $actorId);
$warehouseId = (int) ($warehouse['warehouseId'] ?? 0);
$locationQuery = db()->prepare(
    "SELECT code,location_id FROM inventory_warehouse_locations
     WHERE company_id=:company_id AND warehouse_id=:warehouse_id
       AND code IN('ASSET-WH/INPUT','ASSET-WH/STOCK')"
);
$locationQuery->execute(['company_id' => $companyId, 'warehouse_id' => $warehouseId]);
$locations = [];
foreach ($locationQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $locations[$row['code']] = (int) $row['location_id'];
}
$operationQuery = db()->prepare(
    "SELECT operation_type_id FROM inventory_operation_types
     WHERE company_id=:company_id AND warehouse_id=:warehouse_id
       AND operation_kind='receipt' AND is_default=TRUE"
);
$operationQuery->execute(['company_id' => $companyId, 'warehouse_id' => $warehouseId]);
$receiptOperationId = (int) $operationQuery->fetchColumn();

$productInsert = db()->prepare(
    "INSERT INTO sales_products(company_id,sku,name,product_type,unit_of_measure,unit_price,serial_tracking,active,created_by)
     VALUES(:company_id,'NETWORK-SERVER-ASSET','Network Server','telecom_product','unit',120000,FALSE,TRUE,:actor)"
);
$productInsert->execute(['company_id' => $companyId, 'actor' => $actorId]);
$productId = (int) db()->lastInsertId();
$receiptInsert = db()->prepare(
    "INSERT INTO inventory_goods_receipts(company_id,warehouse_id,operation_type_id,receipt_number,supplier_name,receipt_date,currency,status,created_by,approved_by,approved_at)
     VALUES(:company_id,:warehouse_id,:operation_type_id,'ASSET-GR-0001','Server Vendor','2026-01-10','ETB','approved',:actor,:actor2,'2026-01-10 08:00:00')"
);
$receiptInsert->execute([
    'company_id' => $companyId,
    'warehouse_id' => $warehouseId,
    'operation_type_id' => $receiptOperationId,
    'actor' => $actorId,
    'actor2' => $actorId,
]);
$receiptId = (int) db()->lastInsertId();
$receiptLine = db()->prepare(
    'INSERT INTO inventory_goods_receipt_lines(company_id,goods_receipt_id,warehouse_id,location_id,product_id,quantity,unit_cost,notes)
     VALUES(:company_id,:receipt_id,:warehouse_id,:location_id,:product_id,5,120000,:notes)'
);
$receiptLine->execute([
    'company_id' => $companyId,
    'receipt_id' => $receiptId,
    'warehouse_id' => $warehouseId,
    'location_id' => $locations['ASSET-WH/INPUT'] ?? 0,
    'product_id' => $productId,
    'notes' => 'Five servers received for capitalization test',
]);
$receiptPosted = (new InventoryService())->postGoodsReceipt($receiptId, $actorId);
$stockBefore = db()->prepare(
    'SELECT quantity_on_hand FROM inventory_stock_balances
     WHERE company_id=:company_id AND warehouse_id=:warehouse_id AND location_id=:location_id AND product_id=:product_id'
);
$stockBefore->execute([
    'company_id' => $companyId,
    'warehouse_id' => $warehouseId,
    'location_id' => $locations['ASSET-WH/STOCK'] ?? 0,
    'product_id' => $productId,
]);
$receivedStock = (float) $stockBefore->fetchColumn();

$capitalizationInput = [
    'asset_category_id' => $categoryId,
    'asset_number' => 'FA-INVENTORY-001',
    'asset_name' => 'Capitalized Network Server',
    'acquisition_date' => '2026-01-10',
    'salvage_value' => 0,
    'currency' => 'ETB',
    'warehouse_id' => $warehouseId,
    'location_id' => $locations['ASSET-WH/STOCK'] ?? 0,
    'product_id' => $productId,
    'quantity' => 1,
];
$capitalized = $service->capitalizeFromInventory($capitalizationInput, $actorId);
$capitalizationReplay = $service->capitalizeFromInventory($capitalizationInput, $actorId);
$capitalizedAsset = $service->asset((int) ($capitalized['id'] ?? 0));
$stockAfter = db()->prepare(
    'SELECT quantity_on_hand FROM inventory_stock_balances
     WHERE company_id=:company_id AND warehouse_id=:warehouse_id AND location_id=:location_id AND product_id=:product_id'
);
$stockAfter->execute([
    'company_id' => $companyId,
    'warehouse_id' => $warehouseId,
    'location_id' => $locations['ASSET-WH/STOCK'] ?? 0,
    'product_id' => $productId,
]);
$remainingStock = (float) $stockAfter->fetchColumn();
$movementQuery = db()->prepare(
    "SELECT COUNT(*) FROM inventory_stock_movements
     WHERE company_id=:company_id AND reference_type='asset_capitalization' AND reference_id=:asset_id
       AND completed_quantity=1 AND unit_cost=120000"
);
$movementQuery->execute([
    'company_id' => $companyId,
    'asset_id' => (int) ($capitalized['id'] ?? 0),
]);
$check(
    ($receiptPosted['successful'] ?? false) === true
    && $receivedStock === 5.0
    && ($capitalized['successful'] ?? false) === true
    && ($capitalizationReplay['successful'] ?? true) === false
    && $remainingStock === 4.0
    && (float) ($capitalizedAsset['acquisition_cost'] ?? 0) === 120000.00
    && (int) $movementQuery->fetchColumn() === 1,
    'Inventory capitalization traces Vendor to Input to Stock to Internal Asset Use exactly once at authoritative cost'
);

$balanceQuery = db()->prepare(
    'SELECT COUNT(*) FROM finance_journal_batches b
     WHERE b.company_id=:company_id AND b.source_type LIKE :source_type
       AND b.status=\'posted\' AND b.total_debit<>b.total_credit'
);
$balanceQuery->execute(['company_id' => $companyId, 'source_type' => 'asset_%']);
$check((int) $balanceQuery->fetchColumn() === 0, 'Every posted asset journal is balanced');

$foreignCompany = db()->prepare('SELECT company_id FROM companies WHERE company_id<>:company_id ORDER BY company_id LIMIT 1');
$foreignCompany->execute(['company_id' => $companyId]);
$foreignCompanyId = (int) $foreignCompany->fetchColumn();
$check(
    $foreignCompanyId > 0
    && RepositoryFactory::assets()->asset($foreignCompanyId, $assetId) === null,
    'Asset repository rejects cross-tenant reads'
);

echo PHP_EOL . sprintf('%d checks, %d failures', $checks, $failures) . PHP_EOL;
exit($failures === 0 ? 0 : 1);
