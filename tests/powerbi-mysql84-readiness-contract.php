<?php

declare(strict_types=1);

// This test is copied into tests/ in the isolated rehearsal source.
if (
    getenv('OFFICEAPP_REHEARSAL_ISOLATED') !== '1'
    || getenv('DB_DRIVER') !== 'mysql'
    || getenv('DB_HOST') !== 'db'
    || getenv('DB_DATABASE') !== 'passiontech_officeapp'
    || getenv('DB_USERNAME') !== 'root'
    || (string) getenv('DB_PASSWORD') !== ''
    || preg_match('/^officeapp-rehearsal-[a-f0-9]{32}-php$/', (string) getenv('OFFICEAPP_REHEARSAL_CONTAINER')) !== 1
) {
    fwrite(STDERR, 'FAIL isolated readiness test rejected before opening a connection' . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/../app/helpers/bootstrap.php';
require_once __DIR__ . '/../tools/rehearsal-baseline-audit.php';

$passed = 0;
$failed = 0;
$pdo = null;
$fixtureStarted = false;
$protectedBefore = null;
$check = static function (bool $ok, string $label) use (&$passed): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    ++$passed;
    echo 'PASS ', $label, PHP_EOL;
};
$quoteIdentifier = static fn (string $name): string => chr(96) . str_replace(chr(96), chr(96) . chr(96), $name) . chr(96);
$protectedHashes = [
    '062_harden_employee_self_service_scope.php' => '7fa22730977ba00541593738cfee669285cc2e1d6a132768c9bc8aea7227c8e0',
    '100_powerbi_legacy_history_facts.php' => '62074f1e0ae17e68f3fe98d828fae98d38b8bef937fa2af0a3bb7dfed7ff9201',
    '102_powerbi_reporting_hardening.php' => '3df7bf555d80c9e98a9e4ba7b7c5961bd73bcc7cae9e9eccf6547533f593978f',
    '103_powerbi_confirmed_history_manager_roles.php' => '5d649ca217902baf3dd3f7fdcc546dbd1a0057e63b514e2ffe6b2654ff2847be',
    '104_powerbi_confirmed_remaining_history_roles.php' => '7f6e5b6186d16ff0dc5d5ae0661a48d16835bd80472a0221da4a13092cc98ff6',
    '107_powerbi_confirm_current_shop_managers.php' => 'd81316e20f6e0ef05eeae36378e5605841728ebf0ea0d4d263a6fececace9386',
    '108_powerbi_confirm_safaricom_incentive_semantics.php' => 'd271287506c953a59c2acdd81b15732d02f2fe4d0258eb2be42b95d921a9a46a',
];
$legacyTables = [
    'bi_powerbi_legacy_import_batches', 'bi_powerbi_legacy_stock_rows',
    'bi_powerbi_legacy_daily_shop_assets', 'bi_powerbi_legacy_bank_transactions',
    'bi_powerbi_legacy_shop_sim_incentives', 'bi_powerbi_legacy_float_incentives',
    'bi_powerbi_legacy_float_returns',
];
$protectedTables = array_merge($legacyTables, [
    'schema_migrations', 'schema_migration_steps', 'bi_powerbi_reporting_control',
    'bi_powerbi_history_rows', 'bi_powerbi_history_import_batches',
    'bi_powerbi_history_role_overrides', 'bi_powerbi_history_employee_aliases',
    'bi_powerbi_shop_manager_assignments', 'bi_powerbi_live_source_contracts',
    'data_external_ids', 'inventory_warehouses', 'sales_incentive_claims',
    'sales_incentive_settlements', 'sales_incentive_events',
    'sales_quick_sales', 'sales_quick_sale_reports', 'sales_quick_sale_report_lines',
]);
$snapshot = static function (PDO $connection) use ($protectedTables, $quoteIdentifier): array {
    $out = [];
    foreach ($protectedTables as $table) {
        $rows = array_map(
            static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR),
            $connection->query('SELECT * FROM ' . $quoteIdentifier($table))->fetchAll(PDO::FETCH_ASSOC)
        );
        sort($rows, SORT_STRING);
        $out[$table] = ['count' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
    }
    return $out;
};

try {
    $directory = __DIR__ . '/../database/migrations/mysql';
    foreach ($protectedHashes as $file => $expectedHash) {
        $check(hash_file('sha256', $directory . '/' . $file) === $expectedHash, 'Protected checkout bytes unchanged: ' . $file);
    }
    $check(glob($directory . '/110_*.php') === [], 'Target-109 catalog contains no migration 110');
    $definitions = [];
    $viewSql = [];
    foreach (glob($directory . '/10[0-9]_*.php') as $migrationPath) {
        $definition = require $migrationPath;
        $definitions[$definition['version']] = $definition;
        foreach ($definition['statements'] as $statement) {
            if (preg_match('/^\s*CREATE\s+OR\s+REPLACE\s+VIEW\s+(\w+)\s+AS\b/i', $statement, $match) === 1) {
                $viewSql[$match[1]] = $statement;
            }
        }
    }
    foreach (['101', '105', '106', '109'] as $version) {
        $check(isset($definitions[$version]) && $definitions[$version]['version'] === $version && is_callable($definitions[$version]['preflight'] ?? null), 'Required migration version and fail-closed preflight retained: ' . $version);
    }
    $check(count($definitions['109']['statements']) === 2, 'Migration 109 retains exactly its original two view statements');
    foreach ([
        0 => '7204be94d999126d844ffe4eb919afb44be850336444093c172c43dfdc9b0ae4',
        1 => 'e51830c13c8de59c658801c5f5d4c098b2347c24a2c3bc0dcde19e17e67db6d6',
    ] as $index => $unchangedStatementHash) {
        $check(hash('sha256', str_replace(["\r\n", "\r"], "\n", $definitions['109']['statements'][$index])) === $unchangedStatementHash, 'Migration 109 SQL body remains byte-equivalent after newline normalization: statement ' . ($index + 1));
    }
    $check(!isset($viewSql['vw_powerbi_110_mysql84_readiness_audit']), 'Target-109 SQL creates no migration-110 sentinel');
    foreach (['vw_powerbi_live_stock_detail', 'vw_powerbi_cutover_blockers', 'vw_powerbi_reporting_readiness', 'vw_powerbi_109_explicit_shop_scope_audit'] as $name) {
        $check(isset($viewSql[$name]), 'Final target-109 layer contains required view: ' . $name);
    }
    $stockSql = $viewSql['vw_powerbi_live_stock_detail'];
    $check(str_contains($stockSql, "'WAREHOUSE_PRODUCT_DAY'") && str_contains($stockSql, "'QUICK_SALE_EMPLOYEE_PRODUCT_DAY'"), 'Live stock preserves both warehouse and quick-sale source grains');
    $check(str_contains($stockSql, 'sales_quick_sale_report_lines') && str_contains($stockSql, "r.status='confirmed'"), 'Live stock continues to use confirmed native quick-sale lines');
    $check(str_contains($stockSql, 'reporting_unit_value') && str_contains($stockSql, 'PRODUCT_MASTER_FALLBACK'), 'Live stock retains governed value basis and product fallback');
    $check(preg_match('/ROUND\s*\(\s*q\.allocated_quantity\s*\*\s*q\.reporting_unit_value/i', $stockSql) === 1
        && preg_match('/SUM\s*\(\s*rl\.allocated_quantity\s*\)\s+AS\s+allocated_quantity/i', $stockSql) === 1
        && preg_match('/SUM\s*\(\s*rl\.sold_quantity\s*\)\s+AS\s+sold_quantity/i', $stockSql) === 1,
        'Quick-sale monetary multiplication occurs outside the explicitly grouped quantity source');
    $expectedBlockerCodes = [
        'SHOP_MANAGER_CONFIRMATION', 'REPORTING_PRODUCT_SCOPE',
        'UNEXPECTED_ACTIVE_REPORTING_PRODUCT', 'HISTORY_ROLE_MAPPING',
        'LIVE_CAPTURE_INTEGRATION', 'LIVE_SEMANTIC_CONFIRMATION',
    ];
    $blockerSql = $viewSql['vw_powerbi_cutover_blockers'];
    foreach ($expectedBlockerCodes as $code) {
        $check(str_contains($blockerSql, "'" . $code . "'"), 'Final blocker meaning retained: ' . $code);
    }
    $check(preg_match('/\bHAVING\b/i', $blockerSql) === 0, 'Blocker branches use derived/scalar counts without aggregate HAVING');
    foreach (['105' => ['vw_powerbi_cutover_blockers', 'vw_powerbi_reporting_readiness'], '106' => ['vw_powerbi_reporting_readiness']] as $version => $names) {
        $migrationViewSql = [];
        foreach ($definitions[$version]['statements'] as $statement) {
            if (preg_match('/^\s*CREATE\s+OR\s+REPLACE\s+VIEW\s+(\w+)\s+AS\b/i', $statement, $match) === 1) $migrationViewSql[$match[1]] = $statement;
        }
        foreach ($names as $name) {
            foreach (['SourceSystem' => 'POWERBI_HISTORY', 'role_mapping_status' => 'UNRESOLVED_HISTORY_ROLE'] as $column => $literal) {
            $pattern = '/CONVERT\s*\(\s*(?:\w+\.)?' . $column . '\s+USING\s+utf8mb4\s*\)\s+COLLATE\s+utf8mb4_unicode_ci\s*=\s*_utf8mb4\'' . $literal . '\'\s+COLLATE\s+utf8mb4_unicode_ci/i';
                $check(preg_match($pattern, $migrationViewSql[$name] ?? '') === 1, 'Migration ' . $version . ' ' . $name . ' explicitly normalizes ' . $column);
            }
        }
    }
    $profile = json_decode(
        ltrim((string) file_get_contents(__DIR__ . '/../tools/rehearsal-expected-environment.json'), "\xef\xbb\xbf"),
        true, 512, JSON_THROW_ON_ERROR
    );
    $check(is_array($profile) && $profile !== [], 'Proven rehearsal session profile is present');
    $pdo = db();
    $check($pdo->query('SELECT DATABASE()')->fetchColumn() === 'passiontech_officeapp', 'Connected schema matches the isolated rehearsal');
    $check($pdo->query('SELECT @@collation_connection')->fetchColumn() === 'utf8mb4_unicode_ci', 'Cold application PDO establishes Unicode without a rehearsal override');
    initializeRehearsalSession($pdo, $profile);
    $check(!$pdo->inTransaction(), 'Focused test owns its transaction');
    $actualSqlMode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    $check(in_array('ONLY_FULL_GROUP_BY', explode(',', $actualSqlMode), true), 'Actual session keeps ONLY_FULL_GROUP_BY enabled');
    $check($pdo->query('SELECT VERSION()')->fetchColumn() === '8.4.11' && $pdo->query('SELECT @@version_comment')->fetchColumn() === 'MySQL Community Server - GPL', 'Actual isolated engine is the production-equivalent MySQL Community 8.4.11');
    echo 'INFO isolated fixture container=', getenv('OFFICEAPP_REHEARSAL_CONTAINER'), '; schema=passiontech_officeapp', PHP_EOL;
    $protectedBefore = $snapshot($pdo);
    foreach (array_merge($legacyTables, ['bi_powerbi_history_rows', 'bi_powerbi_history_import_batches']) as $table) {
        $check($protectedBefore[$table]['count'] === 0, 'Verified production-backup history remains empty: ' . $table);
    }

    $pdo->exec('START TRANSACTION READ ONLY');
    try {
        $check($pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() === '109', 'Isolated upgrade ledger ends at 109');
        $check((int) $pdo->query('SELECT COUNT(*) FROM schema_migration_steps')->fetchColumn() === 0, 'Migration step ledger has zero residue');
        $check(($definitions['109']['preflight'])($pdo) === 'baseline', 'Exact completed 109 layer passes its read-only baseline preflight');
        $check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.views WHERE table_schema=DATABASE() AND table_name='vw_powerbi_110_mysql84_readiness_audit'")->fetchColumn() === 0, 'Completed target-109 database contains no migration-110 sentinel');
        $runner = new \App\Database\MigrationRunner($pdo, 'mysql');
        $checksumMatcher = new ReflectionMethod($runner, 'checksumsMatchVersion');
        $checksumQuery = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');
        foreach ([
            '101_powerbi_live_compatibility_layer.php' => '3141f79b113165309c150934b1d48ca4414f77bf5b94c33aa6324da2d7613356',
            '105_powerbi_live_source_governance.php' => '191fea7f6ad59e63149e2796d00606e2df696d0c90cb497735b626ef4da1ad04',
            '106_powerbi_app_capture_readiness.php' => '13b3e7223bd1418da8e1f395560d3abf75c82e36fbc571fb24dc7ecbe9af3e37',
            '109_powerbi_explicit_shop_scope.php' => 'bc2c3ca169d6b38926bcc61916b4da07a8053fd85e39ea57b2b7f3ea7c432192',
        ] as $file => $formerChecksum) {
            $version = substr($file, 0, 3);
            $currentChecksum = hash('sha256', str_replace(["\r\n", "\r"], "\n", (string)file_get_contents($directory . '/' . $file)));
            $checksumQuery->execute([$version]);
            $check($currentChecksum !== $formerChecksum && $checksumQuery->fetchColumn() === $currentChecksum, 'Rehearsal ledger records the intentionally corrected migration checksum: ' . $version);
            $check($checksumMatcher->invoke($runner, $version, $formerChecksum, $currentChecksum) === false, 'No former-checksum compatibility exception exists for corrected migration: ' . $version);
        }
        foreach (['vw_powerbi_live_stock_detail', 'vw_powerbi_cutover_blockers', 'vw_powerbi_reporting_readiness', 'vw_powerbi_109_explicit_shop_scope_audit'] as $name) {
            $pdo->query('SELECT COUNT(*) FROM ' . $quoteIdentifier($name))->fetchColumn();
            $check(true, 'Repaired view resolves under ONLY_FULL_GROUP_BY: ' . $name);
        }
        $readiness = $pdo->query('SELECT * FROM vw_powerbi_reporting_readiness WHERE company_id=2')->fetch(PDO::FETCH_ASSOC);
        $readinessFields = [
            'company_id', 'reporting_mode', 'live_cutover_date',
            'unresolved_shop_manager_count', 'pending_shop_manager_confirmations',
            'reporting_product_map_count', 'unresolved_history_role_rows',
            'live_inventory_daily_rows', 'confirmed_quick_sale_reports',
            'captured_employee_deposit_events', 'captured_shop_daily_metric_rows',
            'native_ready_live_source_contracts', 'app_capture_ready_live_source_contracts',
            'capture_integration_pending_contracts', 'semantic_confirmation_pending_contracts',
            'cutover_blocker_count', 'readiness_status',
        ];
        $check(is_array($readiness) && array_diff($readinessFields, array_keys($readiness)) === [], 'Readiness retains every final migration-106 field');
        $check($readiness['reporting_mode'] === 'HISTORY_ONLY' && $readiness['live_cutover_date'] === null, 'HISTORY_ONLY and null live cutover remain unchanged');
        $check((int) $readiness['unresolved_history_role_rows'] === 0, 'Actual production-backup unresolved history count is zero');
        $shopIds = $pdo->query('SELECT pbi_shop_id FROM vw_powerbi_warehouses WHERE company_id=2 AND pbi_shop_id IS NOT NULL ORDER BY pbi_shop_id')->fetchAll(PDO::FETCH_COLUMN);
        $check($shopIds === array_map(static fn (int $id): string => sprintf('PBI-SHOP-%03d', $id), range(1, 22)), 'Exactly 22 explicit PBI shops remain');
        $check((int) $pdo->query("SELECT COUNT(*) FROM data_external_ids x JOIN vw_powerbi_warehouses w ON w.company_id=x.company_id AND w.warehouse_id=x.entity_id WHERE x.company_id=2 AND x.entity_type='warehouses' AND x.external_id NOT REGEXP '^PBI-SHOP-[0-9]{3}$' AND w.pbi_shop_id=x.external_id")->fetchColumn() === 0, 'Generic warehouse external IDs remain excluded from PBI shop IDs');
        $managers = $pdo->query('SELECT mapping_status,COUNT(*) AS manager_count FROM vw_powerbi_current_shop_manager_scope WHERE company_id=2 GROUP BY mapping_status')->fetchAll(PDO::FETCH_KEY_PAIR);
        $check(count($managers) === 2 && (int) ($managers['CONFIRMED_REPORTING_ASSIGNMENT'] ?? 0) === 21 && (int) ($managers['EXPLICIT_WAREHOUSE_MANAGER'] ?? 0) === 1, '21 governed managers plus one explicit manager remain');
        $adi = $pdo->query("SELECT warehouse_id,manager_employee_id,mapping_status FROM vw_powerbi_current_shop_manager_scope WHERE company_id=2 AND pbi_shop_id='PBI-SHOP-022'")->fetch(PDO::FETCH_ASSOC);
        $check(is_array($adi) && (int) $adi['warehouse_id'] === 23 && (int) $adi['manager_employee_id'] === 105 && $adi['mapping_status'] === 'EXPLICIT_WAREHOUSE_MANAGER', 'Explicit Adi Hageray manager identity remains intact');
        $check((int)$pdo->query("SELECT COUNT(*) FROM bi_powerbi_live_source_contracts WHERE company_id=2 AND capability_code IN('FLOAT_INCENTIVE_TO_SAFARICOM','FLOAT_INCENTIVE_REFUND_FROM_SAFARICOM') AND mapping_status='NATIVE_READY' AND cutover_blocking=FALSE")->fetchColumn() === 2, 'Both Safaricom semantic contracts remain native ready without cutover blockers');
        $check((int) $pdo->query("SELECT COUNT(*) FROM bi_powerbi_history_role_overrides WHERE company_id=2 AND status='confirmed' AND (LOWER(TRIM(legacy_employee_name))='mussie yohannes tsegay' OR LOWER(TRIM(canonical_employee_name))='mussie yohannes tsegay')")->fetchColumn() === 0, 'No confirmed historical Mussie role is fabricated');
        $check((int) $pdo->query('SELECT COUNT(*) FROM vw_powerbi_fulfilled_sales WHERE fulfilled_sales_amount IS NOT NULL OR returned_sales_amount IS NOT NULL OR net_sales_amount IS NOT NULL')->fetchColumn() === 0, 'Inventory cost is not substituted for fulfilled sales revenue');
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    require_once __DIR__ . '/../deployment/powerbi-upgrade-validation.php';
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $nativeRejected = false;
    try { \OfficeApp\Deployment\auditPowerBiUpgradeViews($pdo, true); }
    catch (RuntimeException $error) { $nativeRejected = str_contains($error->getMessage(), 'requires emulated prepares'); }
    $check($nativeRejected && !$pdo->inTransaction(), 'Deployment audit rejects native prepares before starting a transaction');
    $deploymentSession = \OfficeApp\Deployment\initializePowerBiUpgradeSession($pdo);
    $check($deploymentSession['emulate_prepares'] === true && (bool)$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), 'Deployment initializer enables and verifies text protocol on the actual MySQL PDO');
    $check($deploymentSession['collation_connection'] === 'utf8mb4_unicode_ci' && in_array('ONLY_FULL_GROUP_BY', explode(',', $deploymentSession['sql_mode']), true), 'Actual deployment session helper preserves Unicode and ONLY_FULL_GROUP_BY');
    $deploymentHealth = \OfficeApp\Deployment\auditPowerBiUpgradeViews($pdo, true);
    $check($deploymentHealth['result'] === 'PASS' && $deploymentHealth['target'] === '109' && $deploymentHealth['migration_maximum'] === '109', 'Actual release-health helper queries the completed target-109 Power BI views');
    $check($deploymentHealth['readiness_audit']['reporting_mode'] === 'HISTORY_ONLY'
        && $deploymentHealth['readiness_audit']['live_cutover_date'] === null
        && (int)$deploymentHealth['readiness_audit']['explicit_pbi_shop_count'] === 22
        && (int)$deploymentHealth['readiness_audit']['unexpected_scoped_external_ids'] === 0,
        'Shared release-health metadata proves HISTORY_ONLY, null cutover and exact explicit shop scope');
    $check(!$pdo->inTransaction(), 'Successful release-health audit closes its read-only transaction');
    $rejectHealth = static function (string $expectedMessage) use ($pdo): bool {
        try { \OfficeApp\Deployment\auditPowerBiUpgradeViews($pdo, true); return false; }
        catch (RuntimeException $error) { return str_contains($error->getMessage(), $expectedMessage); }
    };
    // These object/ledger fixtures exist only in the disposable clone. Restore
    // exact saved definitions/rows before any later test, even after a failure.
    $ledger109 = $pdo->query("SELECT * FROM schema_migrations WHERE version='109'")->fetch(PDO::FETCH_ASSOC);
    $check(is_array($ledger109), 'Missing-ledger negative fixture starts from a complete 109 row');
    try {
        $pdo->exec("DELETE FROM schema_migrations WHERE version='109'");
        $check($rejectHealth('exact ordered 015-109 migration ledger'), 'Release-health helper rejects a missing 109 ledger row');
        $check(!$pdo->inTransaction(), 'Failed ledger audit closes its read-only transaction');
    } finally {
        $restore = $pdo->prepare('INSERT INTO schema_migrations (' . implode(',', array_map($quoteIdentifier, array_keys($ledger109))) . ') VALUES (' . implode(',', array_fill(0, count($ledger109), '?')) . ')');
        $restore->execute(array_values($ledger109));
    }
    $requiredView = 'vw_powerbi_109_explicit_shop_scope_audit';
    $savedView = array_change_key_case($pdo->query('SHOW CREATE VIEW ' . $quoteIdentifier($requiredView))->fetch(PDO::FETCH_ASSOC), CASE_LOWER);
    try {
        $pdo->exec('DROP VIEW ' . $quoteIdentifier($requiredView));
        $check($rejectHealth('required view absent: ' . $requiredView), 'Release-health helper rejects a missing required 109 scope view');
        $check(!$pdo->inTransaction(), 'Failed view-health audit closes its read-only transaction');
    } finally { $pdo->exec($savedView['create view']); }
    $scopeAuditBefore = $pdo->query('SELECT * FROM ' . $quoteIdentifier($requiredView))->fetchAll(PDO::FETCH_ASSOC);
    // The altered literal intentionally matches the same current 001-022 rows.
    // Exact-definition rejection must therefore come from preflight comparison,
    // rather than a changed runtime count. The actual view is always restored.
    $changedLiteralSql = str_replace('^PBI-SHOP-[0-9]{3}$', '^PBI-SHOP-0[0-9]{2}$', $viewSql[$requiredView]);
    $check($changedLiteralSql !== $viewSql[$requiredView], 'Regex-literal negative fixture changes the stored 109 audit definition');
    try {
        $pdo->exec($changedLiteralSql);
        $check($pdo->query('SELECT * FROM ' . $quoteIdentifier($requiredView))->fetchAll(PDO::FETCH_ASSOC) === $scopeAuditBefore, 'Altered regex fixture preserves current 22-shop metadata counts');
        $rejectedChangedLiteral = false;
        try { ($definitions['109']['preflight'])($pdo); }
        catch (RuntimeException $error) { $rejectedChangedLiteral = str_contains($error->getMessage(), 'partial or unexpected explicit-shop-scope state'); }
        $check($rejectedChangedLiteral, '109 preflight rejects a changed regex literal despite unchanged runtime counts');
    } finally {
        $pdo->exec('DROP VIEW ' . $quoteIdentifier($requiredView));
        $pdo->exec($savedView['create view']);
    }
    $check(($definitions['109']['preflight'])($pdo) === 'baseline', 'Restored exact 109 audit passes baseline after the regex-literal fixture');
    $withoutRequiredMode = implode(',', array_values(array_filter(explode(',', $actualSqlMode), static fn(string $mode): bool => $mode !== 'ONLY_FULL_GROUP_BY')));
    try {
        $pdo->exec('SET SESSION sql_mode=' . $pdo->quote($withoutRequiredMode));
        $check($rejectHealth('requires ONLY_FULL_GROUP_BY in sql_mode'), 'Release-health helper rejects a session missing ONLY_FULL_GROUP_BY');
        $check(!$pdo->inTransaction(), 'Failed SQL-mode audit closes its read-only transaction');
    } finally { $pdo->exec('SET SESSION sql_mode=' . $pdo->quote($actualSqlMode)); }
    try {
        $pdo->exec("SET collation_connection='utf8mb4_general_ci'");
        $check($rejectHealth('environment mismatch: collation_connection'), 'Release-health helper rejects an unverified connection collation');
        $check(!$pdo->inTransaction(), 'Failed environment audit closes its read-only transaction');
    } finally { initializeRehearsalSession($pdo, $profile); }
    $rejectMetadata = static function (array $metadata): bool {
        try { \OfficeApp\Deployment\validatePowerBiUpgradeReadinessMetadata($metadata); return false; }
        catch (RuntimeException) { return true; }
    };
    foreach ([
        'reporting_mode' => 'LIVE_ENABLED', 'live_cutover_date' => '2026-08-01',
        'explicit_pbi_shop_count' => 21, 'unexpected_scoped_external_ids' => 1,
        'company_id' => 3, 'cutover_blocker_rows' => -1,
    ] as $field => $invalidValue) {
        $check($rejectMetadata(array_replace($deploymentHealth['readiness_audit'], [$field => $invalidValue])), 'Shared readiness validator rejects invalid metadata: ' . $field);
    }
    $missingCutoverMetadata = $deploymentHealth['readiness_audit'];
    unset($missingCutoverMetadata['live_cutover_date']);
    $check($rejectMetadata($missingCutoverMetadata), 'Shared readiness validator rejects absent cutover metadata');
    $check($snapshot($pdo) === $protectedBefore, 'Negative health fixtures preserve all 23 protected data and ledger snapshots');

    // Authorized synthetic rows validate semantics only and are always rolled back.
    // They do not belong to the restored production backup or migration payload.
    $pdo->beginTransaction();
    $fixtureStarted = true;
    $fixtureId = bin2hex(random_bytes(16));
    $batch = $pdo->prepare("INSERT INTO bi_powerbi_legacy_import_batches(company_id,dataset_code,source_file_name,source_sha256,source_row_count,min_report_date,max_report_date) VALUES(2,'READINESS_109_SYNTHETIC',?,?,99,'2026-08-01','2026-08-01')");
    $batch->execute(['synthetic-readiness-109-' . $fixtureId . '.csv', hash('sha256', $fixtureId)]);
    $batchId = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare("INSERT INTO bi_powerbi_legacy_stock_rows(batch_id,company_id,source_row_number,shop_manager_label,shop_location,employee_name,product_name,report_date,auto_beginning_stock,total_received,total_sold,closing_stock,raw_payload) VALUES(?,2,?,'SYNTHETIC_READINESS_SHOP','SYNTHETIC_READINESS_SHOP','Mussie Yohannes Tsegay',?,'2026-08-01',0,0,0,0,?)");
    for ($row = 1; $row <= 99; ++$row) {
        $insert->execute([
            $batchId, $row, 'SYNTHETIC_READINESS_PRODUCT_' . sprintf('%03d', $row),
            json_encode(['synthetic_readiness_fixture' => $fixtureId, 'row' => $row], JSON_THROW_ON_ERROR),
        ]);
    }
    $check($pdo->inTransaction(), 'Synthetic history insert remains inside the owned rollback transaction');
    $history = $pdo->query("SELECT COUNT(*) AS row_count,COUNT(DISTINCT employee_name) AS employee_count,SUM(employee_name='Mussie Yohannes Tsegay') AS mussie_count,SUM(role IS NOT NULL OR role_group IS NOT NULL OR role_mapping_status<>'UNRESOLVED_HISTORY_ROLE') AS fabricated_count FROM vw_powerbi_compat_stock_detail WHERE company_id=2 AND SourceSystem='POWERBI_HISTORY'")->fetch(PDO::FETCH_ASSOC);
    $check((int) $history['row_count'] === 99 && (int) $history['employee_count'] === 1 && (int) $history['mussie_count'] === 99 && (int) $history['fabricated_count'] === 0, 'Exactly 99 synthetic Mussie rows remain unresolved with null roles');
    $historyBlockers = $pdo->query("SELECT issue_count FROM vw_powerbi_cutover_blockers WHERE company_id=2 AND blocker_code='HISTORY_ROLE_MAPPING'")->fetchAll(PDO::FETCH_COLUMN);
    $check(count($historyBlockers) === 1 && (int) $historyBlockers[0] === 99, 'History blocker reports exactly 99 synthetic unresolved rows under ONLY_FULL_GROUP_BY');
    $check(($definitions['109']['preflight'])($pdo) === 'baseline', '109 scope baseline accepts governed unresolved history with nonzero blocker rows');
    $fixtureReadiness = $pdo->query('SELECT reporting_mode,live_cutover_date,unresolved_history_role_rows,readiness_status FROM vw_powerbi_reporting_readiness WHERE company_id=2')->fetch(PDO::FETCH_ASSOC);
    $check((int) $fixtureReadiness['unresolved_history_role_rows'] === 99 && $fixtureReadiness['reporting_mode'] === 'HISTORY_ONLY' && $fixtureReadiness['live_cutover_date'] === null && $fixtureReadiness['readiness_status'] === 'SAFE_HISTORY_ONLY', 'Readiness exposes synthetic 99 while preserving HISTORY_ONLY and null cutover');
    $fixtureAudit = array_replace($deploymentHealth['readiness_audit'], ['cutover_blocker_rows' => (int)$pdo->query('SELECT COUNT(*) FROM vw_powerbi_cutover_blockers WHERE company_id=2')->fetchColumn()]);
    \OfficeApp\Deployment\validatePowerBiUpgradeReadinessMetadata($fixtureAudit);
    $check($fixtureAudit['cutover_blocker_rows'] > 0 && $pdo->inTransaction(), 'Shared readiness validator accepts a real nonzero history blocker inside the rollback fixture');
    $check(in_array('ONLY_FULL_GROUP_BY', explode(',', (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn()), true), 'Synthetic fixture never disables ONLY_FULL_GROUP_BY');
    $pdo->rollBack();
    $fixtureStarted = false;
    $check($snapshot($pdo) === $protectedBefore, 'Rollback preserves ledger, role mappings, native rows, generic IDs, warehouses and empty history');
    $check((int) $pdo->query("SELECT COUNT(*) FROM vw_powerbi_cutover_blockers WHERE company_id=2 AND blocker_code='HISTORY_ROLE_MAPPING'")->fetchColumn() === 0, 'Synthetic history blocker disappears after rollback');
    $check((int) $pdo->query('SELECT unresolved_history_role_rows FROM vw_powerbi_reporting_readiness WHERE company_id=2')->fetchColumn() === 0, 'Production-backup history count returns to zero after synthetic fixture');
    $check(\OfficeApp\Deployment\auditPowerBiUpgradeViews($pdo, true)['result'] === 'PASS' && !$pdo->inTransaction(), 'Actual target-109 release-health audit passes again after every negative and rollback fixture');
    echo 'INFO actual backup history=0; synthetic rollback history=99; synthetic history retained=0', PHP_EOL;
} catch (Throwable $error) {
    ++$failed;
    echo 'FAIL ', $error->getMessage(), PHP_EOL;
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
        $fixtureStarted = false;
    }
    if ($pdo instanceof PDO && $protectedBefore !== null) {
        try {
            if ($snapshot($pdo) !== $protectedBefore) {
                throw new RuntimeException('Protected state differs after fixture cleanup');
            }
            if ($fixtureStarted) {
                throw new RuntimeException('Synthetic fixture transaction was not cleaned up');
            }
        } catch (Throwable $cleanupError) {
            ++$failed;
            echo 'FAIL cleanup: ', $cleanupError->getMessage(), PHP_EOL;
        }
    }
}

echo PHP_EOL, $passed + $failed, ' readiness checks, ', $failed, ' failures', PHP_EOL;
exit($failed === 0 ? 0 : 1);
