<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$pdo = db();
try {
    // Enforce read-only execution at the database, including all assertions.
    $pdo->exec('START TRANSACTION READ ONLY');
    $check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.views WHERE table_schema=DATABASE() AND table_name='vw_powerbi_109_explicit_shop_scope_audit'")->fetchColumn() === 1, 'Migration 109 audit view exists');
    $audit = $pdo->query('SELECT * FROM vw_powerbi_109_explicit_shop_scope_audit WHERE company_id=2')->fetch(PDO::FETCH_ASSOC);
    $check(is_array($audit) && (int)$audit['explicit_pbi_shop_count'] === 22, 'Exactly 22 company-2 explicit Power BI shops');
    $check((int)$pdo->query("SELECT COUNT(*) FROM vw_powerbi_warehouses WHERE pbi_shop_id IS NOT NULL AND pbi_shop_id NOT REGEXP '^PBI-SHOP-[0-9]{3}$'")->fetchColumn() === 0, 'Every scoped ID matches the explicit PBI shop contract');
    $ids = $pdo->query('SELECT pbi_shop_id FROM vw_powerbi_warehouses WHERE company_id=2 AND pbi_shop_id IS NOT NULL ORDER BY pbi_shop_id')->fetchAll(PDO::FETCH_COLUMN);
    $check($ids === array_map(static fn(int $id): string => sprintf('PBI-SHOP-%03d', $id), range(1, 22)), 'Scope is precisely PBI-SHOP-001 through PBI-SHOP-022');
    $scope = $pdo->query('SELECT warehouse_id,mapping_status FROM vw_powerbi_current_shop_manager_scope WHERE company_id=2')->fetchAll(PDO::FETCH_ASSOC);
    $check(count($scope) === 22 && count(array_unique(array_column($scope, 'warehouse_id'))) === 22, 'Current manager scope contains exactly 22 distinct shops');
    $statuses = array_count_values(array_column($scope, 'mapping_status'));
    $check(($statuses['CONFIRMED_REPORTING_ASSIGNMENT'] ?? 0) === 21 && ($statuses['EXPLICIT_WAREHOUSE_MANAGER'] ?? 0) === 1, '21 confirmed assignments and one explicit warehouse manager');
    $check((int)$pdo->query("SELECT COUNT(*) FROM data_external_ids x INNER JOIN vw_powerbi_warehouses w ON w.company_id=x.company_id AND w.warehouse_id=x.entity_id WHERE x.entity_type='warehouses' AND x.external_id NOT REGEXP '^PBI-SHOP-[0-9]{3}$' AND w.pbi_shop_id=x.external_id")->fetchColumn() === 0, 'Generic ERP external IDs never become Power BI shop IDs');
    $check((int)$pdo->query('SELECT COUNT(*) FROM inventory_warehouses w LEFT JOIN vw_powerbi_warehouses v ON v.company_id=w.company_id AND v.warehouse_id=w.warehouse_id WHERE w.company_id=2 AND w.deleted_at IS NULL AND v.warehouse_id IS NULL')->fetchColumn() === 0, 'All previously exposed warehouses remain exposed');
    foreach ([25 => 'warehouses_2_25', 26 => 'warehouses_2_26'] as $warehouseId => $externalId) {
        $q = $pdo->prepare("SELECT x.external_id,w.pbi_shop_id FROM data_external_ids x LEFT JOIN vw_powerbi_warehouses w ON w.company_id=x.company_id AND w.warehouse_id=x.entity_id WHERE x.company_id=2 AND x.entity_type='warehouses' AND x.entity_id=?");
        $q->execute([$warehouseId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $check($row['external_id'] === $externalId && $row['pbi_shop_id'] === null, 'Warehouse ' . $warehouseId . ' retains its generic ERP ID with null Power BI ID');
        } else {
            echo 'INFO generic external ID for warehouse ' . $warehouseId . ' is absent locally; conditional check skipped.' . PHP_EOL;
        }
    }
    // The migration must have no data-writing statement, even when the local
    // fixture lacks the production generic IDs. Runtime before/after snapshots
    // provide additional validation when applying it locally.
    $migration = require __DIR__ . '/../database/migrations/mysql/109_powerbi_explicit_shop_scope.php';
    $check(count($migration['statements']) === 2 && array_reduce($migration['statements'], static fn(bool $ok, string $sql): bool => $ok && preg_match('/^CREATE OR REPLACE VIEW /', $sql) === 1 && preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE INTO|TRUNCATE|DROP)\b/i', $sql) === 0, true), 'Migration only replaces two views; underlying external IDs cannot be deleted or rewritten');
    $control = $pdo->query('SELECT reporting_mode,live_cutover_date FROM bi_powerbi_reporting_control WHERE company_id=2')->fetch(PDO::FETCH_ASSOC);
    $check(is_array($control) && $control['reporting_mode'] === 'HISTORY_ONLY', 'Reporting remains HISTORY_ONLY');
    $check(is_array($control) && $control['live_cutover_date'] === null, 'Live cutover date remains NULL');
    $check(is_array($audit) && (int)$audit['unexpected_scoped_external_ids'] === 0 && (int)$audit['current_shop_scope_rows'] === 22, 'Read-only audit agrees with the hardened scope');
} catch (Throwable $exception) {
    echo 'FAIL unexpected: ' . $exception->getMessage() . PHP_EOL;
    $failed++;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
echo PHP_EOL . ($passed + $failed) . ' checks, ' . $failed . ' failures' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
