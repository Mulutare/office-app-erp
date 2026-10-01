<?php

declare(strict_types=1);

// The live-stock warehouse branch is unchanged from 101. The Quick Sale branch
// keeps its exact grouping and sales-price fallback; multiplication/rounding is
// moved outside the grouped query so MySQL can validate ONLY_FULL_GROUP_BY.
$stockSql = <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_live_stock_detail AS
SELECT d.company_id,d.reporting_date AS report_date,d.warehouse_id,w.pbi_shop_id,
       CASE WHEN w.pbi_shop_id IS NOT NULL THEN w.shop_name ELSE NULL END AS shop_manager_label,
       CASE WHEN w.pbi_shop_id IS NOT NULL THEN w.shop_name ELSE NULL END AS shop_location,
       CASE WHEN w.pbi_shop_id IS NOT NULL THEN sm.manager_name ELSE er.employee_name END AS employee_name,
       CASE WHEN w.pbi_shop_id IS NOT NULL THEN 'Shop Manager' ELSE er.role END AS role,
       CASE WHEN w.pbi_shop_id IS NOT NULL THEN 'Shop Manager' ELSE er.role_group END AS role_group,
       h.cluster_name,h.region_name,
       prm.reporting_product_name AS product_name,prm.section_name,
       ROUND(d.beginning_stock_quantity*COALESCE(pp.reporting_unit_value,p.unit_price),4) AS auto_beginning_stock,
       ROUND(d.total_inbound_quantity*COALESCE(pp.reporting_unit_value,p.unit_price),4) AS total_received,
       ROUND(d.net_sold_quantity*COALESCE(pp.reporting_unit_value,p.unit_price),4) AS total_sold,
       ROUND(d.closing_stock_quantity*COALESCE(pp.reporting_unit_value,p.unit_price),4) AS closing_stock,
       COALESCE(pp.reporting_unit_value,p.unit_price) AS reporting_unit_value,
       COALESCE(pp.price_basis,'PRODUCT_MASTER_FALLBACK') AS value_basis,
       CASE WHEN w.pbi_shop_id IS NOT NULL THEN sm.mapping_status ELSE er.role_basis END AS role_mapping_status,
       'WAREHOUSE_PRODUCT_DAY' AS source_grain,
       'ERP_LIVE' AS SourceSystem
FROM (
    SELECT company_id,reporting_date,warehouse_id,product_id,
           SUM(beginning_stock_quantity) AS beginning_stock_quantity,
           SUM(total_inbound_quantity) AS total_inbound_quantity,
           SUM(net_sold_quantity) AS net_sold_quantity,
           SUM(closing_stock_quantity) AS closing_stock_quantity
    FROM vw_powerbi_inventory_daily
    WHERE company_id=2
    GROUP BY company_id,reporting_date,warehouse_id,product_id
) d
INNER JOIN vw_powerbi_warehouses w ON w.warehouse_id=d.warehouse_id
LEFT JOIN vw_powerbi_shop_hierarchy h ON h.warehouse_id=d.warehouse_id
INNER JOIN vw_powerbi_products p ON p.product_id=d.product_id
INNER JOIN bi_powerbi_product_reporting_map prm
  ON prm.company_id=d.company_id AND prm.product_id=d.product_id AND prm.active=TRUE
LEFT JOIN vw_powerbi_product_reporting_price_periods pp
  ON pp.company_id=d.company_id AND pp.product_id=d.product_id
 AND DATE_ADD(d.reporting_date,INTERVAL 86399 SECOND) BETWEEN pp.valid_from AND pp.valid_to
LEFT JOIN vw_powerbi_current_shop_manager_scope sm ON sm.warehouse_id=d.warehouse_id
LEFT JOIN hr_employees me
  ON me.company_id=w.company_id AND me.user_id=w.manager_user_id AND me.deleted_at IS NULL
LEFT JOIN vw_powerbi_employee_role_scope er ON er.employee_id=me.employee_id
WHERE w.pbi_shop_id IS NOT NULL OR w.manager_user_id IS NOT NULL
UNION ALL
SELECT q.company_id,q.report_date,q.warehouse_id,q.pbi_shop_id,
       q.shop_name AS shop_manager_label,q.shop_name AS shop_location,
       q.employee_name,q.role,'DSA/DSP' AS role_group,q.cluster_name,q.region_name,
       q.product_name,q.section_name,
       CAST(0 AS DECIMAL(20,4)) AS auto_beginning_stock,
       ROUND(q.allocated_quantity*q.reporting_unit_value,4) AS total_received,
       ROUND(q.sold_quantity*q.reporting_unit_value,4) AS total_sold,
       ROUND(q.remaining_quantity*q.reporting_unit_value,4) AS closing_stock,
       q.reporting_unit_value,q.value_basis,q.role_basis AS role_mapping_status,
       'QUICK_SALE_EMPLOYEE_PRODUCT_DAY' AS source_grain,
       'ERP_LIVE' AS SourceSystem
FROM (
    SELECT qs.company_id,DATE(COALESCE(r.reviewed_at,r.created_at)) AS report_date,
           qs.warehouse_id,w.pbi_shop_id,w.shop_name,
           er.employee_name,er.role,er.role_basis,h.cluster_name,h.region_name,
           prm.reporting_product_name AS product_name,prm.section_name,
           SUM(rl.allocated_quantity) AS allocated_quantity,
           SUM(rl.sold_quantity) AS sold_quantity,
           SUM(rl.allocated_quantity-rl.sold_quantity-rl.returned_quantity) AS remaining_quantity,
           COALESCE(pp.reporting_unit_value,p.unit_price) AS reporting_unit_value,
           COALESCE(pp.price_basis,'PRODUCT_MASTER_FALLBACK') AS value_basis
    FROM sales_quick_sale_reports r
    INNER JOIN sales_quick_sales qs
      ON qs.company_id=r.company_id AND qs.quick_sale_id=r.quick_sale_id
    INNER JOIN sales_quick_sale_report_lines rl
      ON rl.company_id=r.company_id AND rl.report_id=r.report_id
    INNER JOIN vw_powerbi_warehouses w ON w.warehouse_id=qs.warehouse_id
    LEFT JOIN vw_powerbi_shop_hierarchy h ON h.warehouse_id=qs.warehouse_id
    INNER JOIN vw_powerbi_products p ON p.product_id=rl.product_id
    INNER JOIN bi_powerbi_product_reporting_map prm
      ON prm.company_id=rl.company_id AND prm.product_id=rl.product_id AND prm.active=TRUE
    LEFT JOIN vw_powerbi_product_reporting_price_periods pp
      ON pp.company_id=rl.company_id AND pp.product_id=rl.product_id
     AND DATE_ADD(DATE(COALESCE(r.reviewed_at,r.created_at)),INTERVAL 86399 SECOND)
         BETWEEN pp.valid_from AND pp.valid_to
    LEFT JOIN vw_powerbi_employee_role_scope er ON er.user_id=qs.user_id
    WHERE r.company_id=2 AND r.status='confirmed'
      AND er.role_group='DSA/DSP'
    GROUP BY qs.company_id,DATE(COALESCE(r.reviewed_at,r.created_at)),qs.warehouse_id,
             w.pbi_shop_id,w.shop_name,er.employee_name,er.role,er.role_basis,
             h.cluster_name,h.region_name,prm.reporting_product_name,prm.section_name,
             COALESCE(pp.reporting_unit_value,p.unit_price),COALESCE(pp.price_basis,'PRODUCT_MASTER_FALLBACK')
) q
SQL;
$blockersSql = <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_cutover_blockers AS
SELECT 2 AS company_id,'SHOP_MANAGER_CONFIRMATION' AS blocker_code,
       q.issue_count AS issue_count,
       'Unique warehouse access candidates require explicit business confirmation before live cutover.' AS blocker_note
FROM (
    SELECT COUNT(*) AS issue_count
    FROM vw_powerbi_current_shop_manager_scope
    WHERE mapping_status IN(
        'PENDING_BUSINESS_CONFIRMATION',
        'UNRESOLVED_NO_MANAGER',
        'UNRESOLVED_MULTIPLE_CANDIDATES'
    )
) q
WHERE q.issue_count>0
UNION ALL
SELECT 2 AS company_id,'REPORTING_PRODUCT_SCOPE' AS blocker_code,
       ABS(11-q.issue_count) AS issue_count,
       'Exactly the 11 verified legacy reporting products must be active in the Power BI reporting map.' AS blocker_note
FROM (
    SELECT COUNT(*) AS issue_count
    FROM bi_powerbi_product_reporting_map
    WHERE company_id=2 AND active=TRUE
) q
WHERE q.issue_count<>11
UNION ALL
SELECT 2 AS company_id,'UNEXPECTED_ACTIVE_REPORTING_PRODUCT' AS blocker_code,
       q.issue_count AS issue_count,
       'Products outside the verified legacy Power BI product set are still active in the reporting map.' AS blocker_note
FROM (
    SELECT COUNT(*) AS issue_count
    FROM bi_powerbi_product_reporting_map m
    INNER JOIN sales_products p
      ON p.company_id=m.company_id AND p.product_id=m.product_id
    WHERE m.company_id=2
      AND m.active=TRUE
      AND UPPER(p.sku) NOT IN(
          'FLOAT','PHYSICAL-SIM','ESIM','MIFI-DEVICE',
          'SCRATCH-005','SCRATCH-010','SCRATCH-015',
          'SCRATCH-020','SCRATCH-025','SCRATCH-050','SCRATCH-100'
      )
) q
WHERE q.issue_count>0
UNION ALL
SELECT 2 AS company_id,'HISTORY_ROLE_MAPPING' AS blocker_code,
       q.issue_count AS issue_count,
       'Historical stock rows still have no evidence-backed effective role assignment.' AS blocker_note
FROM (
    SELECT COUNT(*) AS issue_count
    FROM vw_powerbi_compat_stock_detail
    WHERE CONVERT(SourceSystem USING utf8mb4) COLLATE utf8mb4_unicode_ci
              = _utf8mb4'POWERBI_HISTORY' COLLATE utf8mb4_unicode_ci
      AND CONVERT(role_mapping_status USING utf8mb4) COLLATE utf8mb4_unicode_ci
              = _utf8mb4'UNRESOLVED_HISTORY_ROLE' COLLATE utf8mb4_unicode_ci
) q
WHERE q.issue_count>0
UNION ALL
SELECT 2 AS company_id,'LIVE_CAPTURE_INTEGRATION' AS blocker_code,
       q.issue_count AS issue_count,
       'Legacy Power BI metrics have database capture schema but are not yet wired to an automatic ERP workflow.' AS blocker_note
FROM (
    SELECT COUNT(*) AS issue_count
    FROM bi_powerbi_live_source_contracts
    WHERE company_id=2
      AND cutover_blocking=TRUE
      AND mapping_status='CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING'
) q
WHERE q.issue_count>0
UNION ALL
SELECT 2 AS company_id,'LIVE_SEMANTIC_CONFIRMATION' AS blocker_code,
       q.issue_count AS issue_count,
       'Potential ERP-native sources exist, but their business meaning has not been proven equivalent to the legacy Power BI metric.' AS blocker_note
FROM (
    SELECT COUNT(*) AS issue_count
    FROM bi_powerbi_live_source_contracts
    WHERE company_id=2
      AND cutover_blocking=TRUE
      AND mapping_status='SEMANTIC_CONFIRMATION_REQUIRED'
) q
WHERE q.issue_count>0
SQL;
$readinessSql = <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_reporting_readiness AS
SELECT c.company_id,c.reporting_mode,c.live_cutover_date,
       (SELECT COUNT(*)
        FROM vw_powerbi_current_shop_manager_scope s
        WHERE s.mapping_status IN(
            'PENDING_BUSINESS_CONFIRMATION',
            'UNRESOLVED_NO_MANAGER',
            'UNRESOLVED_MULTIPLE_CANDIDATES'
        )) AS unresolved_shop_manager_count,
       (SELECT COUNT(*)
        FROM bi_powerbi_shop_manager_assignments a
        WHERE a.company_id=c.company_id AND a.status='pending') AS pending_shop_manager_confirmations,
       (SELECT COUNT(*)
        FROM bi_powerbi_product_reporting_map p
        WHERE p.company_id=c.company_id AND p.active=TRUE) AS reporting_product_map_count,
       (SELECT COUNT(*)
        FROM vw_powerbi_compat_stock_detail s
        WHERE s.company_id=c.company_id
          AND CONVERT(s.SourceSystem USING utf8mb4) COLLATE utf8mb4_unicode_ci
              = _utf8mb4'POWERBI_HISTORY' COLLATE utf8mb4_unicode_ci
          AND CONVERT(s.role_mapping_status USING utf8mb4) COLLATE utf8mb4_unicode_ci
              = _utf8mb4'UNRESOLVED_HISTORY_ROLE' COLLATE utf8mb4_unicode_ci) AS unresolved_history_role_rows,
       (SELECT COUNT(*)
        FROM vw_powerbi_inventory_daily d
        WHERE d.company_id=c.company_id) AS live_inventory_daily_rows,
       (SELECT COUNT(*)
        FROM sales_quick_sale_reports r
        WHERE r.company_id=c.company_id AND r.status='confirmed') AS confirmed_quick_sale_reports,
       (SELECT COUNT(*)
        FROM bi_powerbi_employee_deposit_events e
        WHERE e.company_id=c.company_id) AS captured_employee_deposit_events,
       (SELECT COUNT(*)
        FROM bi_powerbi_shop_daily_metrics m
        WHERE m.company_id=c.company_id) AS captured_shop_daily_metric_rows,
       (SELECT COUNT(*) FROM bi_powerbi_live_source_contracts s
        WHERE s.company_id=c.company_id AND s.mapping_status='NATIVE_READY') AS native_ready_live_source_contracts,
       (SELECT COUNT(*) FROM bi_powerbi_live_source_contracts s
        WHERE s.company_id=c.company_id AND s.mapping_status='APP_CAPTURE_READY') AS app_capture_ready_live_source_contracts,
       (SELECT COUNT(*) FROM bi_powerbi_live_source_contracts s
        WHERE s.company_id=c.company_id
          AND s.mapping_status='CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING') AS capture_integration_pending_contracts,
       (SELECT COUNT(*) FROM bi_powerbi_live_source_contracts s
        WHERE s.company_id=c.company_id
          AND s.mapping_status='SEMANTIC_CONFIRMATION_REQUIRED') AS semantic_confirmation_pending_contracts,
       (SELECT COUNT(*) FROM vw_powerbi_cutover_blockers b
        WHERE b.company_id=c.company_id) AS cutover_blocker_count,
       CASE
         WHEN c.reporting_mode='HISTORY_ONLY' THEN 'SAFE_HISTORY_ONLY'
         WHEN c.live_cutover_date IS NULL THEN 'BLOCKED_NO_CUTOVER_DATE'
         WHEN EXISTS(
             SELECT 1 FROM vw_powerbi_cutover_blockers b
             WHERE b.company_id=c.company_id
         ) THEN 'BLOCKED_REVIEW_CUTOVER_BLOCKERS'
         ELSE 'LIVE_LAYER_AVAILABLE_REVIEW_SUPPLEMENTAL_CAPTURE'
       END AS readiness_status
FROM bi_powerbi_reporting_control c
WHERE c.company_id=2
SQL;
// This constant is a creation-time proof, not a live session-mode reading.
// The preflight requires ONLY_FULL_GROUP_BY independently before APPLY or
// BASELINE; runtime verification must independently check @@SESSION.sql_mode.
// MySQL forbids system variables in view definitions. No global privileges,
// performance_schema access, stored routine, or extra business table is needed.
$auditSql = <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_110_mysql84_readiness_audit AS
SELECT c.company_id AS company_id,
       1 AS sql_mode_contains_only_full_group_by,
       (SELECT COUNT(*) FROM vw_powerbi_cutover_blockers b WHERE b.company_id=c.company_id) AS cutover_blocker_rows,
       c.reporting_mode AS reporting_mode,c.live_cutover_date AS live_cutover_date,
       a.explicit_pbi_shop_count AS explicit_pbi_shop_count,
       a.unexpected_scoped_external_ids AS unexpected_scoped_external_ids
FROM bi_powerbi_reporting_control c
INNER JOIN vw_powerbi_109_explicit_shop_scope_audit a ON a.company_id=c.company_id
WHERE c.company_id=2
SQL;

// Approved MySQL 8.4.11 SHOW CREATE definitions captured from the clean 109
// diagnostic clone. Normalization preserves structural parentheses, literals,
// charset introducers, and explicit COLLATE operations.
$legacyHashes = [
    'vw_powerbi_live_stock_detail' => '29a9a9e36b3bba93a1d8523dedfb4a93f5649696a0fed528270c5861ddc2f78a',
    'vw_powerbi_cutover_blockers' => 'b8c621874869b91797dee61430e75821c8265a866a85b08fa0e13e38035836e7',
    'vw_powerbi_reporting_readiness' => 'ca35c7a44551b42ac0f3cd3710a20baf24b51083c573396b51243f60fabe60cd',
];
$expectedHashes = [
    'vw_powerbi_live_stock_detail' => '668f374f6969163e6d95edc59b1b85c722eef9b8b65458c535cfae6683facaa8',
    'vw_powerbi_cutover_blockers' => '4cf72d3216d90c9193c24d00a21d1bba2597326bb37d75195c17b7b8d30b8469',
    'vw_powerbi_reporting_readiness' => 'abc46560ddccaef635c490cd847cc00680ef2c7435e4c3e407abf2f444e84ec6',
    'vw_powerbi_110_mysql84_readiness_audit' => 'c4f8fdb0ba764827e6607f875b35e30c56a0006f393edabfadf6b1355e50a49d',
];

return [
    'version' => '110',
    'description' => 'Repair MySQL 8.4 grouped stock projections and preserve governed Power BI readiness and history collation',
    'preflight' => static function (\PDO $connection) use ($legacyHashes, $expectedHashes): string {
        $mode = (string)$connection->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        if (!in_array('ONLY_FULL_GROUP_BY', explode(',', strtoupper($mode)), true)) {
            throw new \RuntimeException('Migration 110 requires ONLY_FULL_GROUP_BY in the current session.');
        }
        foreach ($expectedHashes as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \RuntimeException('Migration 110 expected definitions have not been sealed.');
            }
        }
        $required = ['schema_migrations', 'bi_powerbi_reporting_control',
            'bi_powerbi_live_source_contracts', 'vw_powerbi_cutover_blockers',
            'vw_powerbi_reporting_readiness', 'vw_powerbi_109_explicit_shop_scope_audit'];
        foreach (glob(__DIR__ . '/10[0-9]_*.php') as $file) {
            $definition = require $file;
            foreach ($definition['statements'] as $statement) {
                if (preg_match('/^CREATE\s+(?:OR\s+REPLACE\s+)?(?:TABLE|VIEW)\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)/i', $statement, $match) === 1) {
                    $required[] = $match[1];
                }
            }
        }
        $required = array_values(array_unique($required));
        $quoted = implode(',', array_map($connection->quote(...), $required));
        $present = (int)$connection->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN($quoted)")->fetchColumn();
        if ($present !== count($required)) {
            throw new \RuntimeException('Migration 110 requires the complete migration-109 Power BI layer.');
        }
        $migration109 = glob(__DIR__ . '/109_*.php');
        if (!is_array($migration109) || count($migration109) !== 1) {
            throw new \RuntimeException('Migration 110 requires exactly one reviewed migration-109 source.');
        }
        $source109 = file_get_contents($migration109[0]);
        if (!is_string($source109)) {
            throw new \RuntimeException('Migration 110 cannot validate migration 109 source.');
        }
        $checksum109 = hash('sha256', str_replace(["\r\n", "\r"], "\n", $source109));
        $applied109 = $connection->query("SELECT checksum FROM schema_migrations WHERE version='109'")->fetchColumn();
        if (!is_string($applied109) || !hash_equals($checksum109, $applied109)) {
            throw new \RuntimeException('Migration 110 requires the reviewed migration 109 ledger checksum.');
        }
        $database = (string)$connection->query('SELECT DATABASE()')->fetchColumn();
        $normalize = static function (string $sql) use ($database): string {
            $parts = preg_split("/('(?:''|\\\\.|[^'\\\\])*')/s", $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
            if (!is_array($parts)) {
                throw new \RuntimeException('Migration 110 could not normalize a view definition.');
            }
            foreach ($parts as $index => &$part) {
                if ($index % 2 === 0) {
                    $part = str_replace('`', '', $part);
                    $part = (string)preg_replace('/(?<![A-Za-z0-9_$])' . preg_quote($database, '/') . '\./i', '', $part);
                    $part = strtolower((string)preg_replace('/\s+/', '', $part));
                }
            }
            unset($part);
            return implode('', $parts);
        };
        $readHash = static function (string $name) use ($connection, $normalize): ?string {
            $statement = $connection->prepare('SELECT view_definition FROM information_schema.views WHERE table_schema=DATABASE() AND table_name=?');
            $statement->execute([$name]);
            $definition = $statement->fetchColumn();
            return $definition === false ? null : hash('sha256', $normalize((string)$definition));
        };
        $current = [];
        foreach ($expectedHashes as $name => $hash) $current[$name] = $readHash($name);
        $sentinel = 'vw_powerbi_110_mysql84_readiness_audit';
        $legacy = $current[$sentinel] === null;
        foreach ($legacyHashes as $name => $hash) $legacy = $legacy && $current[$name] !== null && hash_equals($hash, $current[$name]);
        if ($legacy) return 'apply';

        $expected = true;
        foreach ($expectedHashes as $name => $hash) $expected = $expected && $current[$name] !== null && hash_equals($hash, $current[$name]);
        if ($expected) {
            $rows = $connection->query('SELECT * FROM vw_powerbi_110_mysql84_readiness_audit')->fetchAll(\PDO::FETCH_ASSOC);
            if (count($rows) === 1) {
                $audit = $rows[0];
                if ((int)$audit['company_id'] === 2
                    && (int)$audit['sql_mode_contains_only_full_group_by'] === 1
                    && $audit['reporting_mode'] === 'HISTORY_ONLY'
                    && $audit['live_cutover_date'] === null
                    && (int)$audit['explicit_pbi_shop_count'] === 22
                    && (int)$audit['unexpected_scoped_external_ids'] === 0) {
                    // A nonzero blocker count is allowed; unresolved history
                    // remains governed and must not be fabricated or suppressed.
                    $connection->query('SELECT * FROM vw_powerbi_reporting_readiness LIMIT 1')->fetchAll();
                    return 'baseline';
                }
            }
        }
        throw new \RuntimeException('Migration 110 found partial or unexpected MySQL readiness definitions or runtime state.');
    },
    'statements' => [$stockSql, $blockersSql, $readinessSql, $auditSql],
];
