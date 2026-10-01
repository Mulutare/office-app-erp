<?php

declare(strict_types=1);

namespace OfficeApp\Deployment;

use PDO;
use RuntimeException;
use Throwable;

const POWERBI_UPGRADE_ENVIRONMENT_SQL = <<<'SQL'
SELECT VERSION() AS version,@@version_comment AS version_comment,
       @@character_set_server AS character_set_server,@@collation_server AS collation_server,
       @@character_set_database AS character_set_database,@@collation_database AS collation_database,
       @@character_set_client AS character_set_client,@@character_set_connection AS character_set_connection,
       @@character_set_results AS character_set_results,@@collation_connection AS collation_connection,
       @@GLOBAL.sql_mode AS global_sql_mode,@@SESSION.sql_mode AS sql_mode,DATABASE() AS database_name
SQL;

/** Establish the proven production session without changing SQL modes or data. */
function initializePowerBiUpgradeSession(PDO $pdo): array
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('Power BI upgrade validation requires the MySQL PDO driver.');
    }
    $before = array_change_key_case($pdo->query(POWERBI_UPGRADE_ENVIRONMENT_SQL)->fetch(PDO::FETCH_ASSOC), CASE_LOWER);
    if (preg_match('/^8\.4\.11(?:-cll-lve)?$/D', (string)$before['version']) !== 1
        || $before['version_comment'] !== 'MySQL Community Server - GPL') {
        throw new RuntimeException('Power BI upgrade validation requires the proven MySQL Community Server 8.4.11 environment.');
    }
    $required = [
        'database_name' => 'passiontech_officeapp',
        'character_set_server' => 'utf8mb4',
        'collation_server' => 'utf8mb4_0900_ai_ci',
        'character_set_database' => 'utf8mb4',
        'collation_database' => 'utf8mb4_0900_ai_ci',
    ];
    foreach ($required as $name => $expected) {
        if (($before[$name] ?? null) !== $expected) {
            throw new RuntimeException('Power BI upgrade environment mismatch: ' . $name . '; expected ' . $expected . '.');
        }
    }
    foreach (['global_sql_mode', 'sql_mode'] as $name) {
        $modes = array_map('trim', explode(',', strtoupper((string)$before[$name])));
        if (!in_array('ONLY_FULL_GROUP_BY', $modes, true)) {
            throw new RuntimeException('Power BI upgrade validation requires ONLY_FULL_GROUP_BY in ' . $name . '.');
        }
    }

    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec("SET collation_connection='utf8mb4_unicode_ci'");
    $after = array_change_key_case($pdo->query(POWERBI_UPGRADE_ENVIRONMENT_SQL)->fetch(PDO::FETCH_ASSOC), CASE_LOWER);
    foreach (['global_sql_mode', 'sql_mode'] as $name) {
        if ($after[$name] !== $before[$name]) {
            throw new RuntimeException('Power BI upgrade initialization did not preserve ' . $name . '.');
        }
    }
    $required += [
        'character_set_client' => 'utf8mb4',
        'character_set_connection' => 'utf8mb4',
        'character_set_results' => 'utf8mb4',
        'collation_connection' => 'utf8mb4_unicode_ci',
    ];
    foreach ($required as $name => $expected) {
        if (($after[$name] ?? null) !== $expected) {
            throw new RuntimeException('Power BI upgrade session initialization failed: ' . $name . '; expected ' . $expected . '.');
        }
    }
    return $after;
}

/**
 * Query every existing view in an owned read-only transaction. Business rows
 * are discarded; only environment, object metadata, counts and queries return.
 * False validates the restored 099 baseline; true validates the 109 release.
 */
function auditPowerBiUpgradeViews(PDO $pdo, bool $requireRelease109 = false): array
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('Power BI upgrade view audit requires the MySQL PDO driver.');
    }
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Power BI upgrade view audit requires its own read-only transaction.');
    }
    $report = ['result' => 'FAIL', 'target' => $requireRelease109 ? '109' : '099', 'views' => []];
    $pdo->exec('START TRANSACTION READ ONLY');
    try {
        $environment = array_change_key_case($pdo->query(POWERBI_UPGRADE_ENVIRONMENT_SQL)->fetch(PDO::FETCH_ASSOC), CASE_LOWER);
        $report['environment'] = $environment;
        if (preg_match('/^8\.4\.11(?:-cll-lve)?$/D', (string)$environment['version']) !== 1
            || $environment['version_comment'] !== 'MySQL Community Server - GPL') {
            throw new RuntimeException('Power BI upgrade view audit requires the proven MySQL Community Server 8.4.11 environment.');
        }
        foreach ([
            'database_name' => 'passiontech_officeapp',
            'character_set_server' => 'utf8mb4',
            'collation_server' => 'utf8mb4_0900_ai_ci',
            'character_set_database' => 'utf8mb4',
            'collation_database' => 'utf8mb4_0900_ai_ci',
            'character_set_client' => 'utf8mb4',
            'character_set_connection' => 'utf8mb4',
            'character_set_results' => 'utf8mb4',
            'collation_connection' => 'utf8mb4_unicode_ci',
        ] as $name => $expected) {
            if (($environment[$name] ?? null) !== $expected) {
                throw new RuntimeException('Power BI upgrade view audit environment mismatch: ' . $name . '; expected ' . $expected . '.');
            }
        }
        foreach (['global_sql_mode', 'sql_mode'] as $name) {
            if (!in_array('ONLY_FULL_GROUP_BY', array_map('trim', explode(',', strtoupper((string)$environment[$name]))), true)) {
                throw new RuntimeException('Power BI upgrade view audit requires ONLY_FULL_GROUP_BY in ' . $name . '.');
            }
        }

        $versions = $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
        $expectedVersions = array_map(static fn(int $version): string => sprintf('%03d', $version), range(15, $requireRelease109 ? 109 : 99));
        if ($versions !== $expectedVersions) {
            throw new RuntimeException('Power BI upgrade view audit requires the exact ordered 015-' . $report['target'] . ' migration ledger.');
        }
        $report['migration_maximum'] = (string)end($versions);
        $report['migration_count'] = count($versions);
        $report['step_residue'] = (int)$pdo->query('SELECT COUNT(*) FROM schema_migration_steps')->fetchColumn();
        if ($report['step_residue'] !== 0) {
            throw new RuntimeException('Power BI upgrade view audit requires zero schema_migration_steps residue.');
        }

        $views = array_map(
            static fn(array $view): array => array_change_key_case($view, CASE_LOWER),
            $pdo->query('SELECT table_name,character_set_client,collation_connection,security_type FROM information_schema.views WHERE table_schema=DATABASE() ORDER BY table_name')->fetchAll(PDO::FETCH_ASSOC)
        );
        $names = array_column($views, 'table_name');
        $required = [
            'vw_powerbi_bi_employees', 'vw_powerbi_cash_deposits', 'vw_powerbi_employees',
            'vw_powerbi_fulfilled_sales', 'vw_powerbi_history_date_detail', 'vw_powerbi_history_export_rows',
            'vw_powerbi_inventory_balance_reconciliation', 'vw_powerbi_inventory_daily', 'vw_powerbi_inventory_movements',
            'vw_powerbi_locations', 'vw_powerbi_products', 'vw_powerbi_receipts',
            'vw_powerbi_sales_agents', 'vw_powerbi_sales_order_lines', 'vw_powerbi_sales_orders',
            'vw_powerbi_sales_payments', 'vw_powerbi_shop_hierarchy', 'vw_powerbi_warehouse_cluster_bridge',
            'vw_powerbi_warehouse_territory_bridge', 'vw_powerbi_warehouses',
            'vw_sales_user_authorized_orders', 'vw_sales_user_warehouse_location_scope', 'vw_user_warehouse_location_scope',
        ];
        if ($requireRelease109) {
            $required = array_merge($required, [
                'vw_powerbi_live_stock_detail', 'vw_powerbi_cutover_blockers',
                'vw_powerbi_reporting_readiness', 'vw_powerbi_109_explicit_shop_scope_audit',
            ]);
        }
        foreach ($required as $name) {
            if (!in_array($name, $names, true)) {
                throw new RuntimeException('Power BI upgrade view audit required view absent: ' . $name . '; query: SELECT * FROM `' . str_replace('`', '``', $name) . '`.');
            }
        }
        $report['required_view_count'] = count($required);
        foreach ($views as $view) {
            $name = $view['table_name'];
            $query = 'SELECT * FROM `' . str_replace('`', '``', $name) . '`';
            try {
                $statement = $pdo->query($query);
                $count = 0;
                while ($statement->fetch(PDO::FETCH_NUM) !== false) ++$count;
                $statement->closeCursor();
                $report['views'][$name] = $view + ['query' => $query, 'result' => 'PASS', 'resolved_rows' => $count];
            } catch (Throwable $error) {
                throw new RuntimeException('Power BI upgrade view audit failed: ' . $name . '; query: ' . $query . '; MySQL error: ' . $error->getMessage(), 0, $error);
            }
        }
        $report['view_count'] = count($views);

        if ($requireRelease109) {
            // Read governed state and counts directly. The actual GLOBAL and
            // SESSION SQL modes were independently verified above.
            $query = <<<'SQL'
SELECT c.company_id,
       (SELECT COUNT(*) FROM vw_powerbi_cutover_blockers b WHERE b.company_id=c.company_id) AS cutover_blocker_rows,
       c.reporting_mode,c.live_cutover_date,
       a.explicit_pbi_shop_count,a.unexpected_scoped_external_ids
FROM bi_powerbi_reporting_control c
INNER JOIN vw_powerbi_109_explicit_shop_scope_audit a ON a.company_id=c.company_id
WHERE c.company_id=2
SQL;
            try {
                $rows = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $error) {
                throw new RuntimeException('Power BI upgrade 109 readiness metadata query failed; query: ' . $query . '; MySQL error: ' . $error->getMessage(), 0, $error);
            }
            if (count($rows) !== 1) {
                throw new RuntimeException('Power BI upgrade 109 readiness metadata must return exactly one row.');
            }
            $audit = array_change_key_case($rows[0], CASE_LOWER);
            validatePowerBiUpgradeReadinessMetadata($audit);
            $report['readiness_audit'] = $audit;
        }
        $report['result'] = 'PASS';
        return $report;
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

/** Validate direct release metadata without requiring cutover blockers to be zero. */
function validatePowerBiUpgradeReadinessMetadata(array $audit): void
{
    if ((int)($audit['company_id'] ?? 0) !== 2
        || ($audit['reporting_mode'] ?? null) !== 'HISTORY_ONLY'
        || !array_key_exists('live_cutover_date', $audit) || $audit['live_cutover_date'] !== null
        || (int)($audit['explicit_pbi_shop_count'] ?? 0) !== 22
        || !array_key_exists('unexpected_scoped_external_ids', $audit)
        || (int)$audit['unexpected_scoped_external_ids'] !== 0
        || !array_key_exists('cutover_blocker_rows', $audit)
        || !is_numeric($audit['cutover_blocker_rows']) || (int)$audit['cutover_blocker_rows'] < 0) {
        throw new RuntimeException('Power BI upgrade 109 readiness scope/reporting/count invariant failed.');
    }
}
