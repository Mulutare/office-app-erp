<?php

declare(strict_types=1);

return [
    'version' => '108',
    'description' => 'Confirm Safaricom incentive semantics and source legacy Power BI claim/refund metrics from ERP-native incentive workflows',
    'preflight' => static function(\PDO $connection): string {
        $required = [
            'sales_incentive_claims',
            'sales_incentive_settlements',
            'sales_quick_sale_reports',
            'sales_quick_sales',
            'bi_powerbi_shop_manager_assignments',
            'bi_powerbi_shop_daily_metrics',
            'bi_powerbi_live_source_contracts',
            'vw_powerbi_shop_hierarchy',
            'vw_powerbi_live_float_incentives',
            'vw_powerbi_live_shop_daily_supplemental',
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
                'Migration 108 requires migrations 101 through 107 and the Sales incentive workflow.'
            );
        }

        $semanticPending = (int)$connection->query(
            "SELECT COUNT(*) FROM bi_powerbi_live_source_contracts
             WHERE company_id=2
               AND capability_code IN(
                   'FLOAT_INCENTIVE_TO_SAFARICOM',
                   'FLOAT_INCENTIVE_REFUND_FROM_SAFARICOM'
               )
               AND mapping_status='SEMANTIC_CONFIRMATION_REQUIRED'
               AND cutover_blocking=TRUE"
        )->fetchColumn();

        $nativeConfirmed = (int)$connection->query(
            "SELECT COUNT(*) FROM bi_powerbi_live_source_contracts
             WHERE company_id=2
               AND capability_code IN(
                   'FLOAT_INCENTIVE_TO_SAFARICOM',
                   'FLOAT_INCENTIVE_REFUND_FROM_SAFARICOM'
               )
               AND mapping_status='NATIVE_READY'
               AND cutover_blocking=FALSE"
        )->fetchColumn();

        $sentinel = (int)$connection->query(
            "SELECT COUNT(*) FROM information_schema.views
             WHERE table_schema=DATABASE()
               AND table_name='vw_powerbi_108_safaricom_semantic_audit'"
        )->fetchColumn();

        if ($semanticPending === 2 && $nativeConfirmed === 0 && $sentinel === 0) {
            return 'apply';
        }

        if ($semanticPending === 0 && $nativeConfirmed === 2 && $sentinel === 1) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 108 found a partial or unexpected Safaricom semantic-confirmation state.'
        );
    },
    'statements' => [
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_safaricom_claim_attribution AS
SELECT c.company_id,c.incentive_claim_id,c.claim_basis,c.status,
       c.external_body,c.proposed_amount,c.approved_amount,c.currency,
       c.external_reference,c.originating_report_id,c.float_id,
       c.dsa_dsp_user_id,c.responsible_manager_id,
       c.submitted_at,c.approved_at,c.rejected_at,
       COALESCE(rpt.warehouse_id,mgr.warehouse_id,explicit_mgr.warehouse_id,dsa_scope.warehouse_id) AS warehouse_id,
       CASE
         WHEN rpt.warehouse_id IS NOT NULL THEN 'ORIGINATING_CONFIRMED_SALES_REPORT'
         WHEN mgr.warehouse_id IS NOT NULL THEN 'EFFECTIVE_CONFIRMED_MANAGER_ASSIGNMENT'
         WHEN explicit_mgr.warehouse_id IS NOT NULL THEN 'EXPLICIT_WAREHOUSE_MANAGER'
         WHEN dsa_scope.warehouse_id IS NOT NULL THEN 'UNIQUE_ACTIVE_DSA_WAREHOUSE_SCOPE'
         ELSE 'UNRESOLVED'
       END AS attribution_basis
FROM sales_incentive_claims c
LEFT JOIN (
    SELECT r.company_id,r.report_id,MIN(qs.warehouse_id) AS warehouse_id
    FROM sales_quick_sale_reports r
    INNER JOIN sales_quick_sales qs
      ON qs.company_id=r.company_id AND qs.quick_sale_id=r.quick_sale_id
    WHERE r.company_id=2
    GROUP BY r.company_id,r.report_id
    HAVING COUNT(DISTINCT qs.warehouse_id)=1
) rpt
  ON rpt.company_id=c.company_id
 AND rpt.report_id=c.originating_report_id
LEFT JOIN (
    SELECT c2.company_id,c2.incentive_claim_id,MIN(a.warehouse_id) AS warehouse_id
    FROM sales_incentive_claims c2
    INNER JOIN hr_employees e
      ON e.company_id=c2.company_id
     AND e.user_id=c2.responsible_manager_id
     AND e.deleted_at IS NULL
    INNER JOIN bi_powerbi_shop_manager_assignments a
      ON a.company_id=e.company_id
     AND a.employee_id=e.employee_id
     AND a.status='confirmed'
     AND a.effective_from<=DATE(c2.submitted_at)
     AND (a.effective_to IS NULL OR a.effective_to>=DATE(c2.submitted_at))
    WHERE c2.company_id=2
    GROUP BY c2.company_id,c2.incentive_claim_id
    HAVING COUNT(DISTINCT a.warehouse_id)=1
) mgr
  ON mgr.company_id=c.company_id
 AND mgr.incentive_claim_id=c.incentive_claim_id
LEFT JOIN (
    SELECT c3.company_id,c3.incentive_claim_id,MIN(w.warehouse_id) AS warehouse_id
    FROM sales_incentive_claims c3
    INNER JOIN inventory_warehouses w
      ON w.company_id=c3.company_id
     AND w.manager_user_id=c3.responsible_manager_id
     AND w.active=TRUE
     AND w.deleted_at IS NULL
    WHERE c3.company_id=2
    GROUP BY c3.company_id,c3.incentive_claim_id
    HAVING COUNT(DISTINCT w.warehouse_id)=1
) explicit_mgr
  ON explicit_mgr.company_id=c.company_id
 AND explicit_mgr.incentive_claim_id=c.incentive_claim_id
LEFT JOIN (
    SELECT c4.company_id,c4.incentive_claim_id,MIN(wa.warehouse_id) AS warehouse_id
    FROM sales_incentive_claims c4
    INNER JOIN inventory_user_warehouse_access wa
      ON wa.company_id=c4.company_id
     AND wa.user_id=c4.dsa_dsp_user_id
     AND wa.active=TRUE
    INNER JOIN inventory_warehouses w
      ON w.company_id=wa.company_id
     AND w.warehouse_id=wa.warehouse_id
     AND w.active=TRUE
     AND w.deleted_at IS NULL
    WHERE c4.company_id=2
    GROUP BY c4.company_id,c4.incentive_claim_id
    HAVING COUNT(DISTINCT wa.warehouse_id)=1
) dsa_scope
  ON dsa_scope.company_id=c.company_id
 AND dsa_scope.incentive_claim_id=c.incentive_claim_id
WHERE c.company_id=2
  AND c.external_body='Safaricom'
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_safaricom_claim_daily AS
SELECT a.company_id,a.warehouse_id,h.shop_name AS shop_manager,
       DATE(a.submitted_at) AS report_date,
       SUM(CASE
             WHEN a.status='submitted' THEN a.proposed_amount
             WHEN a.status IN('approved','partially_settled','settled')
               THEN COALESCE(a.approved_amount,a.proposed_amount)
             ELSE 0
           END) AS float_incentive_to_safaricom_birr,
       GROUP_CONCAT(
           DISTINCT NULLIF(a.external_reference,'')
           ORDER BY a.external_reference SEPARATOR ', '
       ) AS source_reference,
       COUNT(*) AS claim_count,
       'ERP_SAFARICOM_CLAIMS' AS SourceSystem
FROM vw_powerbi_safaricom_claim_attribution a
INNER JOIN vw_powerbi_shop_hierarchy h
  ON h.company_id=a.company_id AND h.warehouse_id=a.warehouse_id
WHERE a.company_id=2
  AND a.warehouse_id IS NOT NULL
  AND a.status IN('submitted','approved','partially_settled','settled')
GROUP BY a.company_id,a.warehouse_id,h.shop_name,DATE(a.submitted_at)
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_safaricom_refund_daily AS
SELECT a.company_id,a.warehouse_id,h.shop_name AS shop_manager,
       s.settlement_date AS report_date,
       SUM(s.amount) AS float_incentive_refund_from_safaricom_birr,
       GROUP_CONCAT(
           DISTINCT NULLIF(s.external_payment_reference,'')
           ORDER BY s.external_payment_reference SEPARATOR ', '
       ) AS source_reference,
       COUNT(*) AS settlement_count,
       'ERP_SAFARICOM_SETTLEMENTS' AS SourceSystem
FROM sales_incentive_settlements s
INNER JOIN vw_powerbi_safaricom_claim_attribution a
  ON a.company_id=s.company_id
 AND a.incentive_claim_id=s.incentive_claim_id
INNER JOIN vw_powerbi_shop_hierarchy h
  ON h.company_id=a.company_id AND h.warehouse_id=a.warehouse_id
WHERE s.company_id=2
  AND s.external_body='Safaricom'
  AND s.reversed_at IS NULL
  AND a.warehouse_id IS NOT NULL
GROUP BY a.company_id,a.warehouse_id,h.shop_name,s.settlement_date
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_live_float_incentives AS
SELECT d.company_id,d.shop_manager,d.report_date,
       d.float_incentive_to_safaricom_birr AS float_incentive_airtime_to_safaricom_birr,
       d.SourceSystem
FROM vw_powerbi_safaricom_claim_daily d
WHERE d.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_live_shop_daily_supplemental AS
SELECT k.company_id,k.warehouse_id,h.shop_name AS shop_manager,k.report_date,
       m.manager_reported_deposit_birr,
       m.reward_sim_cards_pieces,
       m.incentive_sim_cards_birr,
       m.float_airtime_incentive_birr,
       COALESCE(c.float_incentive_to_safaricom_birr,
                m.float_incentive_to_safaricom_birr) AS float_incentive_to_safaricom_birr,
       COALESCE(r.float_incentive_refund_from_safaricom_birr,
                m.float_incentive_refund_from_safaricom_birr) AS float_incentive_refund_from_safaricom_birr,
       m.total_float_returned_birr,
       m.safaricom_sim_value_borrowed_birr,
       m.safaricom_mifi_value_borrowed_pcs,
       m.qty_returned_birr_10_pieces,m.qty_returned_birr_15_pieces,
       m.qty_returned_birr_20_pieces,m.qty_returned_birr_25_pieces,
       m.qty_returned_birr_50_pieces,m.qty_returned_birr_100_pieces,
       COALESCE(m.source_reference,c.source_reference,r.source_reference) AS source_reference,
       CASE
         WHEN c.warehouse_id IS NOT NULL AND r.warehouse_id IS NOT NULL
           THEN 'ERP_APP_CAPTURE_PLUS_SAFARICOM_CLAIM_AND_SETTLEMENT'
         WHEN c.warehouse_id IS NOT NULL
           THEN 'ERP_APP_CAPTURE_PLUS_SAFARICOM_CLAIM'
         WHEN r.warehouse_id IS NOT NULL
           THEN 'ERP_APP_CAPTURE_PLUS_SAFARICOM_SETTLEMENT'
         ELSE m.provenance
       END AS provenance,
       'ERP_SUPPLEMENTAL' AS SourceSystem
FROM (
    SELECT company_id,warehouse_id,report_date
    FROM bi_powerbi_shop_daily_metrics
    WHERE company_id=2
    UNION
    SELECT company_id,warehouse_id,report_date
    FROM vw_powerbi_safaricom_claim_daily
    WHERE company_id=2
    UNION
    SELECT company_id,warehouse_id,report_date
    FROM vw_powerbi_safaricom_refund_daily
    WHERE company_id=2
) k
INNER JOIN vw_powerbi_shop_hierarchy h
  ON h.company_id=k.company_id AND h.warehouse_id=k.warehouse_id
LEFT JOIN bi_powerbi_shop_daily_metrics m
  ON m.company_id=k.company_id
 AND m.warehouse_id=k.warehouse_id
 AND m.report_date=k.report_date
LEFT JOIN vw_powerbi_safaricom_claim_daily c
  ON c.company_id=k.company_id
 AND c.warehouse_id=k.warehouse_id
 AND c.report_date=k.report_date
LEFT JOIN vw_powerbi_safaricom_refund_daily r
  ON r.company_id=k.company_id
 AND r.warehouse_id=k.warehouse_id
 AND r.report_date=k.report_date
WHERE k.company_id=2
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
       a.warehouse_id,a.attribution_basis,
       c.submitted_at,c.approved_at,c.rejected_at,c.rejection_reason,c.created_at,
       'CONFIRMED_LEGACY_FLOAT_INCENTIVE_TO_SAFARICOM' AS semantic_class,
       'ERP_NATIVE' AS SourceSystem
FROM sales_incentive_claims c
LEFT JOIN vw_powerbi_safaricom_claim_attribution a
  ON a.company_id=c.company_id AND a.incentive_claim_id=c.incentive_claim_id
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
       a.warehouse_id,a.attribution_basis,
       'CONFIRMED_LEGACY_FLOAT_INCENTIVE_REFUND_FROM_SAFARICOM' AS semantic_class,
       'ERP_NATIVE' AS SourceSystem
FROM sales_incentive_settlements s
INNER JOIN sales_incentive_claims c
  ON c.company_id=s.company_id AND c.incentive_claim_id=s.incentive_claim_id
LEFT JOIN vw_powerbi_safaricom_claim_attribution a
  ON a.company_id=c.company_id AND a.incentive_claim_id=c.incentive_claim_id
LEFT JOIN hr_employees de
  ON de.company_id=c.company_id AND de.user_id=c.dsa_dsp_user_id AND de.deleted_at IS NULL
LEFT JOIN hr_employees me
  ON me.company_id=c.company_id AND me.user_id=c.responsible_manager_id AND me.deleted_at IS NULL
WHERE s.company_id=2
SQL,
        <<<'SQL'
UPDATE bi_powerbi_live_source_contracts
SET preferred_source_object=
      CASE capability_code
        WHEN 'FLOAT_INCENTIVE_TO_SAFARICOM'
          THEN 'vw_powerbi_safaricom_claim_daily'
        WHEN 'FLOAT_INCENTIVE_REFUND_FROM_SAFARICOM'
          THEN 'vw_powerbi_safaricom_refund_daily'
      END,
    mapping_status='NATIVE_READY',
    cutover_blocking=FALSE,
    semantic_note=
      CASE capability_code
        WHEN 'FLOAT_INCENTIVE_TO_SAFARICOM'
          THEN 'Business confirmed on 2026-09-30 that this legacy Power BI metric is the Safaricom incentive claim amount: proposed amount while submitted, otherwise the approved amount for approved/partially-settled/settled claims. The reporting date is the claim submission date.'
        WHEN 'FLOAT_INCENTIVE_REFUND_FROM_SAFARICOM'
          THEN 'Business confirmed on 2026-09-30 that this legacy Power BI metric is value received back from Safaricom against an approved incentive claim. Non-reversed sales_incentive_settlements amounts are authoritative on settlement_date.'
      END
WHERE company_id=2
  AND capability_code IN(
      'FLOAT_INCENTIVE_TO_SAFARICOM',
      'FLOAT_INCENTIVE_REFUND_FROM_SAFARICOM'
  )
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_108_safaricom_semantic_audit AS
SELECT 2 AS company_id,
       (SELECT COUNT(*)
        FROM sales_incentive_claims c
        WHERE c.company_id=2
          AND c.external_body='Safaricom'
          AND c.status IN('submitted','approved','partially_settled','settled')) AS eligible_claims,
       (SELECT COUNT(*)
        FROM vw_powerbi_safaricom_claim_attribution a
        WHERE a.company_id=2
          AND a.status IN('submitted','approved','partially_settled','settled')
          AND a.warehouse_id IS NOT NULL) AS attributed_claims,
       (SELECT COUNT(*)
        FROM vw_powerbi_safaricom_claim_attribution a
        WHERE a.company_id=2
          AND a.status IN('submitted','approved','partially_settled','settled')
          AND a.warehouse_id IS NULL) AS unattributed_claims,
       (SELECT COUNT(*)
        FROM sales_incentive_settlements s
        WHERE s.company_id=2
          AND s.external_body='Safaricom'
          AND s.reversed_at IS NULL) AS eligible_refunds,
       (SELECT COUNT(*)
        FROM sales_incentive_settlements s
        INNER JOIN vw_powerbi_safaricom_claim_attribution a
          ON a.company_id=s.company_id
         AND a.incentive_claim_id=s.incentive_claim_id
        WHERE s.company_id=2
          AND s.external_body='Safaricom'
          AND s.reversed_at IS NULL
          AND a.warehouse_id IS NOT NULL) AS attributed_refunds,
       (SELECT COUNT(*)
        FROM sales_incentive_settlements s
        INNER JOIN vw_powerbi_safaricom_claim_attribution a
          ON a.company_id=s.company_id
         AND a.incentive_claim_id=s.incentive_claim_id
        WHERE s.company_id=2
          AND s.external_body='Safaricom'
          AND s.reversed_at IS NULL
          AND a.warehouse_id IS NULL) AS unattributed_refunds,
       (SELECT COUNT(*) FROM vw_powerbi_safaricom_claim_daily WHERE company_id=2) AS claim_daily_rows,
       (SELECT COUNT(*) FROM vw_powerbi_safaricom_refund_daily WHERE company_id=2) AS refund_daily_rows
SQL,
    ],
];
