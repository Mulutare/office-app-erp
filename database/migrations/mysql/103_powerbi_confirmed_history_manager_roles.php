<?php

declare(strict_types=1);

return [
    'version' => '103',
    'description' => 'Record business-confirmed August 2026 Power BI shop-manager roles and normalize compatibility role collation',
    'preflight' => static function(\PDO $connection): string {
        $required = [
            'bi_powerbi_reporting_control',
            'bi_powerbi_shop_manager_assignments',
            'bi_powerbi_history_employee_aliases',
            'bi_powerbi_product_reporting_map',
            'bi_powerbi_legacy_stock_rows',
            'bi_employee_assignments',
            'hr_employees',
            'hr_employee_position_assignments',
            'vw_powerbi_history_stock_current',
            'vw_powerbi_history_employee_identity_candidates',
            'vw_powerbi_live_stock_detail',
            'vw_powerbi_shop_hierarchy',
            'vw_powerbi_current_shop_manager_scope',
            'vw_powerbi_inventory_daily'
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
                'Migration 103 requires the complete migration 102 Power BI hardening layer.'
            );
        }

        $table = (int)$connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_type='BASE TABLE'
               AND table_name='bi_powerbi_history_role_overrides'"
        )->fetchColumn();
        $sentinel = (int)$connection->query(
            "SELECT COUNT(*) FROM information_schema.views
             WHERE table_schema=DATABASE()
               AND table_name='vw_powerbi_103_history_manager_confirmation_audit'"
        )->fetchColumn();

        if ($table === 1 && $sentinel === 1) {
            return 'baseline';
        }

        /* Statements are intentionally idempotent so an unmodified partial
         * application can be resumed safely by the migration runner. */
        return 'apply';
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS bi_powerbi_history_role_overrides (
    override_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    legacy_employee_name VARCHAR(255) NOT NULL,
    canonical_employee_name VARCHAR(255) NULL,
    role VARCHAR(80) NOT NULL,
    role_group VARCHAR(80) NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'confirmed',
    evidence_source VARCHAR(120) NOT NULL,
    notes VARCHAR(1000) NULL,
    confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (override_id),
    UNIQUE KEY uq_pbi_history_role_override (
        company_id,legacy_employee_name,effective_from,effective_to,role_group
    ),
    KEY idx_pbi_history_role_override_dates (
        company_id,effective_from,effective_to,status
    ),
    CONSTRAINT ck_pbi_history_role_override_status
        CHECK (status IN('confirmed','rejected','ended')),
    CONSTRAINT ck_pbi_history_role_override_dates
        CHECK (effective_to>=effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO bi_powerbi_history_role_overrides
    (company_id,legacy_employee_name,canonical_employee_name,role,role_group,
     effective_from,effective_to,status,evidence_source,notes)
VALUES
(2,'Hagos Araya Zemichael','Hagos Araya Zemichael','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Mogos Beyene','Mogos Beyene','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Kibrom Gmariam','Kibrom Gmariam','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Bereket Meles','Bereket Meles','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'emp_bereket_meles','Bereket Meles','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','CONFIRMED_MANAGER_NORMALIZED_ALIAS','The normalized legacy alias resolves uniquely to Bereket Meles, who is in the confirmed August 2026 Shop Manager roster.'),
(2,'Mengistu Hailu','Mengistu Hailu','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Goitom Kidu','Goitom Kidu','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Tadesse G her','Tadesse G her','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Helen Aleget','Helen Aleget','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Ksanet Haylemaryam','Ksanet Haylemaryam','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Andom Nuguse','Andom Nuguse','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Filmon Yirga Araya','Filmon Yirga Araya','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Yoseph Kassaye','Yoseph Kassaye','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Mekonnen Negasi','Mekonnen Negasi','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Feven T brhan','Feven T brhan','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Tibletse Teame','Tibletse Teame','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Helen Desta','Helen Desta','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Halefom Shushay','Halefom Shushay','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Tsige Yoseph','Tsige Yoseph','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Elsa Nigusse','Elsa Nigusse','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Henok Fitsum','Henok Fitsum','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Eyerusalem Gebremichael','Eyerusalem Gebremichael','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.'),
(2,'Abel G medhin','Abel G medhin','Shop Manager','Shop Manager','2026-08-01','2026-08-26','confirmed','POWERBI_MANAGER_EXPORT_PLUS_BUSINESS_CONFIRMATION','Present in the Power BI Shop Manager export for September 2026; business confirmed on 2026-09-30 that the same manager roster applied during 2026-08-01 through 2026-08-26.')
ON DUPLICATE KEY UPDATE
    canonical_employee_name=VALUES(canonical_employee_name),
    role=VALUES(role),
    status=VALUES(status),
    evidence_source=VALUES(evidence_source),
    notes=VALUES(notes),
    confirmed_at=CURRENT_TIMESTAMP
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_history_employee_role_effective AS
SELECT q.company_id,q.batch_id,q.source_row_number,q.role,q.role_group,
       q.role_mapping_status
FROM (
    SELECT c.*,
           MIN(c.source_priority) OVER(
               PARTITION BY c.company_id,c.batch_id,c.source_row_number
           ) AS best_priority,
           COUNT(*) OVER(
               PARTITION BY c.company_id,c.batch_id,c.source_row_number,c.source_priority
           ) AS same_priority_count
    FROM (
        SELECT DISTINCT h.company_id,h.batch_id,h.source_row_number,
               o.role,o.role_group,
               1 AS source_priority,
               CONVERT(CONCAT('CONFIRMED_HISTORY_ROLE_OVERRIDE:',o.evidence_source) USING utf8mb4)
                   COLLATE utf8mb4_unicode_ci AS role_mapping_status
        FROM vw_powerbi_history_stock_current h
        INNER JOIN bi_powerbi_history_role_overrides o
          ON o.company_id=h.company_id
         AND CONVERT(LOWER(TRIM(o.legacy_employee_name)) USING utf8mb4) COLLATE utf8mb4_unicode_ci
             = CONVERT(LOWER(TRIM(h.employee_name)) USING utf8mb4) COLLATE utf8mb4_unicode_ci
         AND o.status='confirmed'
         AND o.effective_from<=h.report_date
         AND o.effective_to>=h.report_date
        WHERE h.company_id=2

        UNION ALL

        SELECT DISTINCT i.company_id,i.batch_id,i.source_row_number,
               ba.role_code AS role,
               CASE WHEN ba.role_code IN('DSA','DSP') THEN 'DSA/DSP' ELSE ba.role_code END AS role_group,
               10+i.identity_priority AS source_priority,
               CONVERT(CONCAT('EFFECTIVE_BI_ASSIGNMENT:',i.identity_basis) USING utf8mb4)
                   COLLATE utf8mb4_unicode_ci AS role_mapping_status
        FROM vw_powerbi_history_employee_identity_candidates i
        INNER JOIN bi_employee_assignments ba
          ON ba.employee_id=i.bi_employee_id
         AND ba.active=TRUE
         AND (ba.effective_from IS NULL OR ba.effective_from<=i.report_date)
         AND (ba.effective_to IS NULL OR ba.effective_to>=i.report_date)
         AND (i.warehouse_id IS NULL OR ba.warehouse_id=i.warehouse_id)
        WHERE ba.role_code IN('DSA','DSP')

        UNION ALL

        SELECT DISTINCT h.company_id,h.batch_id,h.source_row_number,
               CASE
                 WHEN pa.position_code_snapshot='TIGRAY-REGIONAL-MANAGER' THEN 'Regional Manager'
                 WHEN pa.position_code_snapshot='TIGRAY-DISTRICT-MANAGER' THEN 'District Manager'
                 WHEN pa.position_code_snapshot='TIGRAY-SHOP-MANAGER' THEN 'Shop Manager'
               END AS role,
               CASE
                 WHEN pa.position_code_snapshot='TIGRAY-REGIONAL-MANAGER' THEN 'Regional Manager'
                 WHEN pa.position_code_snapshot='TIGRAY-DISTRICT-MANAGER' THEN 'District Manager'
                 WHEN pa.position_code_snapshot='TIGRAY-SHOP-MANAGER' THEN 'Shop Manager'
               END AS role_group,
               200 AS source_priority,
               CONVERT('EFFECTIVE_HR_POSITION_ASSIGNMENT' USING utf8mb4)
                   COLLATE utf8mb4_unicode_ci AS role_mapping_status
        FROM vw_powerbi_history_stock_current h
        INNER JOIN hr_employees e
          ON e.company_id=h.company_id
         AND e.deleted_at IS NULL
         AND CONVERT(LOWER(TRIM(CONCAT_WS(' ',e.first_name,NULLIF(e.middle_name,''),e.last_name))) USING utf8mb4)
             COLLATE utf8mb4_unicode_ci
             = CONVERT(LOWER(TRIM(h.employee_name)) USING utf8mb4) COLLATE utf8mb4_unicode_ci
        INNER JOIN hr_employee_position_assignments pa
          ON pa.company_id=e.company_id
         AND pa.employee_id=e.employee_id
         AND pa.position_code_snapshot IN(
             'TIGRAY-REGIONAL-MANAGER',
             'TIGRAY-DISTRICT-MANAGER',
             'TIGRAY-SHOP-MANAGER'
         )
         AND pa.effective_from<=h.report_date
         AND (pa.effective_to IS NULL OR pa.effective_to>=h.report_date)
        WHERE h.company_id=2
    ) c
) q
WHERE q.source_priority=q.best_priority
  AND q.same_priority_count=1
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_compat_stock_detail AS
SELECT h.company_id,h.report_date,sh.warehouse_id,sh.pbi_shop_id,
       h.shop_manager_label,h.shop_location,h.employee_name,
       rr.role,rr.role_group,sh.cluster_name,sh.region_name,
       h.product_name,pm.section_name,
       h.auto_beginning_stock,h.total_received,h.total_sold,h.closing_stock,
       NULL AS reporting_unit_value,'LEGACY_EXPORTED_VALUE' AS value_basis,
       CONVERT(COALESCE(rr.role_mapping_status,'UNRESOLVED_HISTORY_ROLE') USING utf8mb4)
           COLLATE utf8mb4_unicode_ci AS role_mapping_status,
       'LEGACY_EXPORT_ROW' AS source_grain,'POWERBI_HISTORY' AS SourceSystem
FROM vw_powerbi_history_stock_current h
INNER JOIN bi_powerbi_reporting_control c ON c.company_id=h.company_id
LEFT JOIN (
    SELECT LOWER(TRIM(shop_name)) AS shop_name_key,
           MIN(warehouse_id) AS warehouse_id,MIN(pbi_shop_id) AS pbi_shop_id,
           MIN(cluster_name) AS cluster_name,MIN(region_name) AS region_name
    FROM vw_powerbi_shop_hierarchy
    WHERE company_id=2
    GROUP BY LOWER(TRIM(shop_name))
    HAVING COUNT(*)=1
) sh
  ON CONVERT(sh.shop_name_key USING utf8mb4) COLLATE utf8mb4_unicode_ci
     = CONVERT(LOWER(TRIM(h.shop_manager_label)) USING utf8mb4) COLLATE utf8mb4_unicode_ci
LEFT JOIN vw_powerbi_history_employee_role_effective rr
  ON rr.company_id=h.company_id
 AND rr.batch_id=h.batch_id
 AND rr.source_row_number=h.source_row_number
LEFT JOIN (
    SELECT LOWER(TRIM(reporting_product_name)) AS product_name_key,
           MIN(section_name) AS section_name
    FROM bi_powerbi_product_reporting_map
    WHERE company_id=2 AND active=TRUE
    GROUP BY LOWER(TRIM(reporting_product_name))
    HAVING COUNT(*)=1
) pm
  ON CONVERT(pm.product_name_key USING utf8mb4) COLLATE utf8mb4_unicode_ci
     = CONVERT(LOWER(TRIM(h.product_name)) USING utf8mb4) COLLATE utf8mb4_unicode_ci
WHERE c.reporting_mode IN('HISTORY_ONLY','HYBRID')
  AND (c.reporting_mode='HISTORY_ONLY' OR h.report_date<c.live_cutover_date)

UNION ALL

SELECT l.company_id,l.report_date,l.warehouse_id,l.pbi_shop_id,
       l.shop_manager_label,l.shop_location,l.employee_name,l.role,l.role_group,
       l.cluster_name,l.region_name,l.product_name,l.section_name,
       l.auto_beginning_stock,l.total_received,l.total_sold,l.closing_stock,
       l.reporting_unit_value,l.value_basis,
       CONVERT(l.role_mapping_status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS role_mapping_status,
       l.source_grain,l.SourceSystem
FROM vw_powerbi_live_stock_detail l
INNER JOIN bi_powerbi_reporting_control c ON c.company_id=l.company_id
WHERE c.reporting_mode IN('HYBRID','LIVE_ONLY')
  AND (c.reporting_mode='LIVE_ONLY' OR l.report_date>=c.live_cutover_date)
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
WHERE CONVERT(SourceSystem USING utf8mb4) COLLATE utf8mb4_unicode_ci
          = _utf8mb4'POWERBI_HISTORY' COLLATE utf8mb4_unicode_ci
  AND CONVERT(role_mapping_status USING utf8mb4) COLLATE utf8mb4_unicode_ci
          = _utf8mb4'UNRESOLVED_HISTORY_ROLE' COLLATE utf8mb4_unicode_ci
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
CREATE OR REPLACE VIEW vw_powerbi_103_history_manager_confirmation_audit AS
SELECT o.company_id,o.legacy_employee_name,o.canonical_employee_name,
       o.role,o.role_group,o.effective_from,o.effective_to,o.status,
       o.evidence_source,
       COUNT(h.source_row_number) AS matching_history_rows,
       MIN(h.report_date) AS matching_min_date,
       MAX(h.report_date) AS matching_max_date
FROM bi_powerbi_history_role_overrides o
LEFT JOIN vw_powerbi_history_stock_current h
  ON h.company_id=o.company_id
 AND CONVERT(LOWER(TRIM(h.employee_name)) USING utf8mb4) COLLATE utf8mb4_unicode_ci
     = CONVERT(LOWER(TRIM(o.legacy_employee_name)) USING utf8mb4) COLLATE utf8mb4_unicode_ci
 AND h.report_date BETWEEN o.effective_from AND o.effective_to
WHERE o.company_id=2
  AND o.status='confirmed'
GROUP BY o.company_id,o.legacy_employee_name,o.canonical_employee_name,
         o.role,o.role_group,o.effective_from,o.effective_to,o.status,o.evidence_source
SQL,
    ],
];
