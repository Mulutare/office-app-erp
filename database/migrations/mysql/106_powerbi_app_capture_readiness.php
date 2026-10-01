<?php

declare(strict_types=1);

return [
    'version' => '106',
    'description' => 'Mark verified Power BI application capture workflows ready while preserving unresolved semantic and business cutover gates',
    'preflight' => static function(\PDO $connection): string {
        $required = [
            'bi_powerbi_live_source_contracts',
            'bi_powerbi_reporting_control',
            'vw_powerbi_cutover_blockers',
            'vw_powerbi_reporting_readiness',
            'vw_powerbi_105_live_source_contract_audit'
        ];
        $quoted = implode(',', array_map(
            static fn(string $name): string => $connection->quote($name),
            $required
        ));
        $count = (int)$connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name IN($quoted)"
        )->fetchColumn();

        if ($count !== count($required)) {
            throw new \RuntimeException(
                'Migration 106 requires migration 105 and the Power BI reporting-readiness layer.'
            );
        }

        $pending = (int)$connection->query(
            "SELECT COUNT(*) FROM bi_powerbi_live_source_contracts
             WHERE company_id=2
               AND mapping_status='CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING'"
        )->fetchColumn();

        $ready = (int)$connection->query(
            "SELECT COUNT(*) FROM bi_powerbi_live_source_contracts
             WHERE company_id=2
               AND mapping_status='APP_CAPTURE_READY'"
        )->fetchColumn();

        $sentinel = (int)$connection->query(
            "SELECT COUNT(*) FROM information_schema.views
             WHERE table_schema=DATABASE()
               AND table_name='vw_powerbi_106_app_capture_readiness'"
        )->fetchColumn();

        if ($pending === 14 && $ready === 0 && $sentinel === 0) {
            return 'apply';
        }

        if ($pending === 0 && $ready === 14 && $sentinel === 1) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 106 found a partial or unexpected Power BI application-capture readiness state.'
        );
    },
    'statements' => [
        <<<'SQL'
ALTER TABLE bi_powerbi_live_source_contracts
 DROP CONSTRAINT ck_pbi_live_source_status,
 ADD CONSTRAINT ck_pbi_live_source_status CHECK(
     mapping_status IN(
         'NATIVE_READY',
         'CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING',
         'APP_CAPTURE_READY',
         'SEMANTIC_CONFIRMATION_REQUIRED'
     )
 )
SQL,
        <<<'SQL'
UPDATE bi_powerbi_live_source_contracts
SET mapping_status='APP_CAPTURE_READY',
    cutover_blocking=FALSE,
    semantic_note=CONCAT(
        semantic_note,
        ' ERP application integration was verified locally on 2026-09-30 with contract tests and rollback-safe write-through smoke tests.'
    )
WHERE company_id=2
  AND mapping_status='CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING'
SQL,
        <<<'SQL'
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
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_106_app_capture_readiness AS
SELECT c.company_id,
       SUM(c.mapping_status='NATIVE_READY') AS native_ready_contracts,
       SUM(c.mapping_status='APP_CAPTURE_READY') AS app_capture_ready_contracts,
       SUM(c.mapping_status='CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING') AS app_integration_pending_contracts,
       SUM(c.mapping_status='SEMANTIC_CONFIRMATION_REQUIRED') AS semantic_confirmation_required_contracts,
       SUM(c.cutover_blocking=TRUE) AS source_contract_cutover_blockers,
       CASE
         WHEN SUM(c.mapping_status='APP_CAPTURE_READY')=14
          AND SUM(c.mapping_status='CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING')=0
         THEN 'APP_CAPTURE_LAYER_READY'
         ELSE 'REVIEW_APP_CAPTURE_LAYER'
       END AS app_capture_status
FROM bi_powerbi_live_source_contracts c
WHERE c.company_id=2
GROUP BY c.company_id
SQL,
    ],
];
