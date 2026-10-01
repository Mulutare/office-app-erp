<?php

declare(strict_types=1);

return [
    'version' => '105',
    'description' => 'Govern Power BI live-source semantics, expose ERP-native adapters, and block cutover until unsupported legacy metrics have truthful capture paths',
    'preflight' => static function(\PDO $connection): string {
        $required = [
            'companies',
            'hr_employees',
            'sales_products',
            'bi_powerbi_reporting_control',
            'bi_powerbi_employee_deposit_events',
            'bi_powerbi_shop_daily_metrics',
            'bi_powerbi_product_reporting_map',
            'bi_powerbi_shop_manager_assignments',
            'sales_dsa_float_issuances',
            'sales_incentive_claims',
            'sales_incentive_settlements',
            'sales_settlements',
            'sales_settlement_lines',
            'sales_quick_sales',
            'sales_quick_sale_reports',
            'sales_quick_sale_report_lines',
            'inventory_goods_receipts',
            'vw_powerbi_live_stock_detail',
            'vw_powerbi_live_daily_shop_assets',
            'vw_powerbi_live_grv_daily',
            'vw_powerbi_current_shop_manager_scope',
            'vw_powerbi_inventory_daily',
            'vw_powerbi_compat_stock_detail',
            'vw_powerbi_cutover_blockers',
            'vw_powerbi_reporting_readiness'
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
                'Migration 105 requires migrations 101-104 and the existing Sales, Inventory and Finance source schema.'
            );
        }

        $tableCount = (int)$connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_type='BASE TABLE'
               AND table_name='bi_powerbi_live_source_contracts'"
        )->fetchColumn();

        $views = [
            'vw_powerbi_native_dsa_float_issuances',
            'vw_powerbi_native_safaricom_incentive_claims',
            'vw_powerbi_native_safaricom_incentive_settlements',
            'vw_powerbi_native_sales_settlements',
            'vw_powerbi_105_live_source_contract_audit'
        ];
        $viewQuoted = implode(',', array_map(
            static fn(string $name): string => $connection->quote($name),
            $views
        ));
        $viewCount = (int)$connection->query(
            "SELECT COUNT(*) FROM information_schema.views
             WHERE table_schema=DATABASE() AND table_name IN($viewQuoted)"
        )->fetchColumn();

        if ($tableCount === 0 && $viewCount === 0) {
            return 'apply';
        }
        if ($tableCount === 1 && $viewCount === count($views)) {
            return 'baseline';
        }

        throw new \RuntimeException('Migration 105 found a partial Power BI live-source governance layer.');
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE bi_powerbi_live_source_contracts (
    company_id BIGINT UNSIGNED NOT NULL,
    capability_code VARCHAR(100) NOT NULL,
    powerbi_semantic VARCHAR(255) NOT NULL,
    preferred_source_object VARCHAR(190) NOT NULL,
    mapping_status VARCHAR(60) NOT NULL,
    cutover_blocking BOOLEAN NOT NULL DEFAULT TRUE,
    semantic_note VARCHAR(1500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id,capability_code),
    KEY idx_pbi_live_source_status (company_id,mapping_status,cutover_blocking),
    CONSTRAINT ck_pbi_live_source_status CHECK(
        mapping_status IN(
            'NATIVE_READY',
            'CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING',
            'SEMANTIC_CONFIRMATION_REQUIRED'
        )
    ),
    CONSTRAINT fk_pbi_live_source_company
        FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO bi_powerbi_live_source_contracts
    (company_id,capability_code,powerbi_semantic,preferred_source_object,mapping_status,cutover_blocking,semantic_note)
VALUES
(2,'STOCK_DETAIL','Beginning stock, received, sold and closing stock by shop/employee/product/day','vw_powerbi_live_stock_detail','NATIVE_READY',FALSE,'Native ERP inventory and confirmed quick-sale sources already feed the governed live stock compatibility view.'),
(2,'DAILY_SHOP_STOCK_METRICS','Daily beginning stock, received, sold, closing stock and reconciliation','vw_powerbi_live_daily_shop_assets','NATIVE_READY',FALSE,'The stock side of Daily Shop Level Assets is derived from the governed live stock detail. Supplemental deposit/incentive fields are governed separately below.'),
(2,'GRV_NUMBER','Posted GRV/receipt number by shop/day','vw_powerbi_live_grv_daily','NATIVE_READY',FALSE,'Posted inventory goods receipts are the authoritative ERP source for GRV numbers.'),
(2,'DSA_CONFIRMED_SALES','Confirmed DSA/DSP sold quantities and reporting value by employee/product/day','sales_quick_sale_reports + sales_quick_sale_report_lines','NATIVE_READY',FALSE,'Confirmed quick-sale report lines are the authoritative DSA/DSP sold-quantity source. Reporting value remains distinct from inventory cost.'),

(2,'EMPLOYEE_BANK_TRANSACTION_DETAIL','Employee/shop/date bank transaction ID and total cash deposit','bi_powerbi_employee_deposit_events','CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING',TRUE,'Existing sales settlements and bank tables do not carry the legacy employee-plus-shop attribution needed by the Power BI Bank Transaction ID dataset. The dedicated capture table exists, but an ERP workflow must write it automatically.'),
(2,'MANAGER_REPORTED_DEPOSIT','Manager reported deposit used for deposit-difference reconciliation','bi_powerbi_shop_daily_metrics.manager_reported_deposit_birr','CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING',TRUE,'This is a distinct business assertion and must be captured from the responsible ERP workflow; it must not be inferred from a settlement total.'),
(2,'REWARD_SIM_CARDS_PIECES','Reward SIM Cards (pieces)','bi_powerbi_shop_daily_metrics.reward_sim_cards_pieces','CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING',TRUE,'No existing operational table models this exact legacy metric.'),
(2,'INCENTIVE_SIM_CARDS_BIRR','Incentive SIM Cards (Birr)','bi_powerbi_shop_daily_metrics.incentive_sim_cards_birr','CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING',TRUE,'No existing operational table models this exact legacy metric.'),
(2,'FLOAT_AIRTIME_INCENTIVE_DAILY','Float Airtime Incentive (Daily)','bi_powerbi_shop_daily_metrics.float_airtime_incentive_birr','CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING',TRUE,'This legacy metric is distinct from the separately exported Float Incentive Airtime To Safaricom value and must not be conflated.'),
(2,'TOTAL_FLOAT_RETURNED','Total Float Returned (Birr)','bi_powerbi_shop_daily_metrics.total_float_returned_birr','CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING',TRUE,'A float issuance reversal/correction is not assumed to equal the legacy DMRM float-return metric.'),
(2,'SAFARICOM_SIM_VALUE_BORROWED','Safaricom SIM value borrowed (Birr)','bi_powerbi_shop_daily_metrics.safaricom_sim_value_borrowed_birr','CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING',TRUE,'No existing operational table models this exact legacy metric.'),
(2,'SAFARICOM_MIFI_VALUE_BORROWED','Safaricom MiFi value borrowed (pieces)','bi_powerbi_shop_daily_metrics.safaricom_mifi_value_borrowed_pcs','CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING',TRUE,'No existing operational table models this exact legacy metric.'),
(2,'RETURNED_BIRR_10_PIECES','Returned Birr 10 denomination quantity (pieces)','bi_powerbi_shop_daily_metrics.qty_returned_birr_10_pieces','CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING',TRUE,'Denomination-level return quantities require explicit operational capture.'),
(2,'RETURNED_BIRR_15_PIECES','Returned Birr 15 denomination quantity (pieces)','bi_powerbi_shop_daily_metrics.qty_returned_birr_15_pieces','CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING',TRUE,'Denomination-level return quantities require explicit operational capture.'),
(2,'RETURNED_BIRR_20_PIECES','Returned Birr 20 denomination quantity (pieces)','bi_powerbi_shop_daily_metrics.qty_returned_birr_20_pieces','CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING',TRUE,'Denomination-level return quantities require explicit operational capture.'),
(2,'RETURNED_BIRR_25_PIECES','Returned Birr 25 denomination quantity (pieces)','bi_powerbi_shop_daily_metrics.qty_returned_birr_25_pieces','CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING',TRUE,'Denomination-level return quantities require explicit operational capture.'),
(2,'RETURNED_BIRR_50_PIECES','Returned Birr 50 denomination quantity (pieces)','bi_powerbi_shop_daily_metrics.qty_returned_birr_50_pieces','CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING',TRUE,'Denomination-level return quantities require explicit operational capture.'),
(2,'RETURNED_BIRR_100_PIECES','Returned Birr 100 denomination quantity (pieces)','bi_powerbi_shop_daily_metrics.qty_returned_birr_100_pieces','CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING',TRUE,'Denomination-level return quantities require explicit operational capture.'),

(2,'FLOAT_INCENTIVE_TO_SAFARICOM','Float Incentive Airtime To Safaricom (Birr)','sales_incentive_claims / bi_powerbi_shop_daily_metrics.float_incentive_to_safaricom_birr','SEMANTIC_CONFIRMATION_REQUIRED',TRUE,'sales_incentive_claims are Safaricom incentive claims, but the audit does not prove that approved/proposed claim amount is identical to the legacy Float Incentive Airtime To Safaricom metric.'),
(2,'FLOAT_INCENTIVE_REFUND_FROM_SAFARICOM','Float Incentive Refund From Safaricom (Birr)','sales_incentive_settlements / bi_powerbi_shop_daily_metrics.float_incentive_refund_from_safaricom_birr','SEMANTIC_CONFIRMATION_REQUIRED',TRUE,'sales_incentive_settlements record Safaricom incentive settlements, but the audit does not prove that settlement amount is identical to the legacy refund metric.')
ON DUPLICATE KEY UPDATE
    powerbi_semantic=VALUES(powerbi_semantic),
    preferred_source_object=VALUES(preferred_source_object),
    mapping_status=VALUES(mapping_status),
    cutover_blocking=VALUES(cutover_blocking),
    semantic_note=VALUES(semantic_note)
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_native_dsa_float_issuances AS
SELECT f.company_id,f.float_id,f.issued_date,f.amount,f.currency,f.reference,
       f.status,f.correction_of_id,f.reason,f.product_id,p.sku,p.name AS product_name,
       f.dsa_dsp_user_id,
       CONCAT_WS(' ',de.first_name,NULLIF(de.middle_name,''),de.last_name) AS dsa_dsp_employee,
       f.manager_user_id,
       CONCAT_WS(' ',me.first_name,NULLIF(me.middle_name,''),me.last_name) AS manager_employee,
       f.created_at,
       'MANAGER_ISSUED_DSA_CASH_FLOAT_NOT_LEGACY_SAFARICOM_INCENTIVE' AS semantic_class,
       'ERP_NATIVE' AS SourceSystem
FROM sales_dsa_float_issuances f
LEFT JOIN sales_products p
  ON p.company_id=f.company_id AND p.product_id=f.product_id
LEFT JOIN hr_employees de
  ON de.company_id=f.company_id AND de.user_id=f.dsa_dsp_user_id AND de.deleted_at IS NULL
LEFT JOIN hr_employees me
  ON me.company_id=f.company_id AND me.user_id=f.manager_user_id AND me.deleted_at IS NULL
WHERE f.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_native_safaricom_incentive_claims AS
SELECT c.company_id,c.incentive_claim_id,c.claim_basis,c.status,c.external_body,
       c.proposed_amount,c.approved_amount,c.currency,c.external_reference,
       c.originating_report_id,c.float_id,c.confirmed_sales_snapshot,
       c.submission_key,c.dsa_dsp_user_id,
       CONCAT_WS(' ',de.first_name,NULLIF(de.middle_name,''),de.last_name) AS dsa_dsp_employee,
       c.responsible_manager_id,
       CONCAT_WS(' ',me.first_name,NULLIF(me.middle_name,''),me.last_name) AS manager_employee,
       c.submitted_at,c.approved_at,c.rejected_at,c.rejection_reason,c.created_at,
       'SAFARICOM_INCENTIVE_CLAIM_REQUIRES_LEGACY_SEMANTIC_CONFIRMATION' AS semantic_class,
       'ERP_NATIVE' AS SourceSystem
FROM sales_incentive_claims c
LEFT JOIN hr_employees de
  ON de.company_id=c.company_id AND de.user_id=c.dsa_dsp_user_id AND de.deleted_at IS NULL
LEFT JOIN hr_employees me
  ON me.company_id=c.company_id AND me.user_id=c.responsible_manager_id AND me.deleted_at IS NULL
WHERE c.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_native_safaricom_incentive_settlements AS
SELECT s.company_id,s.incentive_settlement_id,s.incentive_claim_id,s.settlement_date,
       s.amount,s.currency,s.external_body,s.external_payment_reference,
       s.evidence_reference,s.idempotency_key,s.created_at,s.reversed_at,s.reversal_reason,
       c.claim_basis,c.status AS claim_status,c.proposed_amount,c.approved_amount,
       c.dsa_dsp_user_id,
       CONCAT_WS(' ',de.first_name,NULLIF(de.middle_name,''),de.last_name) AS dsa_dsp_employee,
       c.responsible_manager_id,
       CONCAT_WS(' ',me.first_name,NULLIF(me.middle_name,''),me.last_name) AS manager_employee,
       'SAFARICOM_INCENTIVE_SETTLEMENT_REQUIRES_LEGACY_REFUND_SEMANTIC_CONFIRMATION' AS semantic_class,
       'ERP_NATIVE' AS SourceSystem
FROM sales_incentive_settlements s
INNER JOIN sales_incentive_claims c
  ON c.company_id=s.company_id AND c.incentive_claim_id=s.incentive_claim_id
LEFT JOIN hr_employees de
  ON de.company_id=c.company_id AND de.user_id=c.dsa_dsp_user_id AND de.deleted_at IS NULL
LEFT JOIN hr_employees me
  ON me.company_id=c.company_id AND me.user_id=c.responsible_manager_id AND me.deleted_at IS NULL
WHERE s.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_native_sales_settlements AS
SELECT s.company_id,s.settlement_id,s.settlement_number,s.bank_account_id,s.currency,
       s.expected_amount,s.confirmed_amount,s.variance_amount,s.remaining_amount,
       s.reconciliation_status,s.workflow_status,s.notes,s.return_reason,
       COUNT(sl.settlement_line_id) AS settlement_line_count,
       COUNT(DISTINCT sl.sales_order_id) AS sales_order_count,
       SUM(sl.amount) AS linked_sales_order_amount,
       s.created_by,s.submitted_by,s.supervisor_reviewed_by,s.finance_reconciled_by,s.approved_by,
       s.created_at,s.submitted_at,s.supervisor_reviewed_at,s.finance_reconciled_at,s.approved_at,s.closed_at,
       'NO_EMPLOYEE_SHOP_BANK_TRANSACTION_ATTRIBUTION_ON_SETTLEMENT' AS employee_deposit_mapping_status,
       'ERP_NATIVE' AS SourceSystem
FROM sales_settlements s
LEFT JOIN sales_settlement_lines sl
  ON sl.company_id=s.company_id AND sl.settlement_id=s.settlement_id
WHERE s.company_id=2
GROUP BY s.company_id,s.settlement_id,s.settlement_number,s.bank_account_id,s.currency,
         s.expected_amount,s.confirmed_amount,s.variance_amount,s.remaining_amount,
         s.reconciliation_status,s.workflow_status,s.notes,s.return_reason,
         s.created_by,s.submitted_by,s.supervisor_reviewed_by,s.finance_reconciled_by,s.approved_by,
         s.created_at,s.submitted_at,s.supervisor_reviewed_at,s.finance_reconciled_at,s.approved_at,s.closed_at
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_cutover_blockers AS
SELECT 2 AS company_id,'SHOP_MANAGER_CONFIRMATION' AS blocker_code,
       COUNT(*) AS issue_count,
       'Unique warehouse access candidates require explicit business confirmation before live cutover.' AS blocker_note
FROM vw_powerbi_current_shop_manager_scope
WHERE mapping_status IN(
    'PENDING_BUSINESS_CONFIRMATION',
    'UNRESOLVED_NO_MANAGER',
    'UNRESOLVED_MULTIPLE_CANDIDATES'
)
HAVING COUNT(*)>0

UNION ALL

SELECT 2,'REPORTING_PRODUCT_SCOPE',
       ABS(11-COUNT(*)),
       'Exactly the 11 verified legacy reporting products must be active in the Power BI reporting map.'
FROM bi_powerbi_product_reporting_map
WHERE company_id=2 AND active=TRUE
HAVING COUNT(*)<>11

UNION ALL

SELECT 2,'UNEXPECTED_ACTIVE_REPORTING_PRODUCT',
       COUNT(*),
       'Products outside the verified legacy Power BI product set are still active in the reporting map.'
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
HAVING COUNT(*)>0

UNION ALL

SELECT 2,'HISTORY_ROLE_MAPPING',
       COUNT(*),
       'Historical stock rows still have no evidence-backed effective role assignment.'
FROM vw_powerbi_compat_stock_detail
WHERE SourceSystem='POWERBI_HISTORY'
  AND role_mapping_status='UNRESOLVED_HISTORY_ROLE'
HAVING COUNT(*)>0

UNION ALL

SELECT 2,'LIVE_CAPTURE_INTEGRATION',
       COUNT(*),
       'Legacy Power BI metrics have database capture schema but are not yet wired to an automatic ERP workflow.'
FROM bi_powerbi_live_source_contracts
WHERE company_id=2
  AND cutover_blocking=TRUE
  AND mapping_status='CAPTURE_SCHEMA_READY_APP_INTEGRATION_PENDING'
HAVING COUNT(*)>0

UNION ALL

SELECT 2,'LIVE_SEMANTIC_CONFIRMATION',
       COUNT(*),
       'Potential ERP-native sources exist, but their business meaning has not been proven equivalent to the legacy Power BI metric.'
FROM bi_powerbi_live_source_contracts
WHERE company_id=2
  AND cutover_blocking=TRUE
  AND mapping_status='SEMANTIC_CONFIRMATION_REQUIRED'
HAVING COUNT(*)>0
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
          AND s.SourceSystem='POWERBI_HISTORY'
          AND s.role_mapping_status='UNRESOLVED_HISTORY_ROLE') AS unresolved_history_role_rows,
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
CREATE OR REPLACE VIEW vw_powerbi_105_live_source_contract_audit AS
SELECT c.company_id,c.mapping_status,c.cutover_blocking,
       COUNT(*) AS capability_count,
       GROUP_CONCAT(c.capability_code ORDER BY c.capability_code SEPARATOR ', ') AS capability_codes
FROM bi_powerbi_live_source_contracts c
WHERE c.company_id=2
GROUP BY c.company_id,c.mapping_status,c.cutover_blocking
SQL,
    ],
];
