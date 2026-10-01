<?php

declare(strict_types=1);

return [
    'version' => '102',
    'description' => 'Harden Power BI product scope, effective-dated history roles and manager confirmation gates',
    'preflight' => static function(\PDO $connection): string {
        $required = [
            'bi_powerbi_product_reporting_map',
            'bi_powerbi_reporting_control',
            'bi_powerbi_legacy_stock_rows',
            'bi_powerbi_legacy_import_batches',
            'bi_employees',
            'bi_employee_assignments',
            'hr_employees',
            'hr_employee_position_assignments',
            'inventory_user_warehouse_access',
            'inventory_warehouses',
            'vw_powerbi_warehouses',
            'vw_powerbi_shop_hierarchy',
            'vw_powerbi_history_stock_current',
            'vw_powerbi_live_stock_detail',
            'vw_powerbi_compat_stock_detail',
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
                'Migration 102 requires migrations 100 and 101 plus BI/HR assignment sources.'
            );
        }

        $tables = [
            'bi_powerbi_shop_manager_assignments',
            'bi_powerbi_history_employee_aliases'
        ];
        $views = [
            'vw_powerbi_shop_manager_candidates',
            'vw_powerbi_current_shop_manager_scope',
            'vw_powerbi_history_employee_identity_candidates',
            'vw_powerbi_history_employee_role_effective',
            'vw_powerbi_compat_stock_detail',
            'vw_powerbi_cutover_blockers',
            'vw_powerbi_reporting_readiness'
        ];

        $tableQuoted = implode(',', array_map(
            static fn(string $name): string => $connection->quote($name),
            $tables
        ));
        $viewQuoted = implode(',', array_map(
            static fn(string $name): string => $connection->quote($name),
            $views
        ));

        $tableCount = (int)$connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_type='BASE TABLE'
               AND table_name IN($tableQuoted)"
        )->fetchColumn();
        $viewCount = (int)$connection->query(
            "SELECT COUNT(*) FROM information_schema.views
             WHERE table_schema=DATABASE() AND table_name IN($viewQuoted)"
        )->fetchColumn();

        if ($tableCount === 0 && $viewCount === 3) {
            /*
             * Three view names already exist from 101:
             * vw_powerbi_current_shop_manager_scope,
             * vw_powerbi_compat_stock_detail, and
             * vw_powerbi_reporting_readiness.
             * Everything else in this migration is new.
             */
            return 'apply';
        }

        if ($tableCount === count($tables) && $viewCount === count($views)) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 102 found a partial Power BI hardening layer.'
        );
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE bi_powerbi_shop_manager_assignments (
    assignment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    effective_from DATE NULL,
    effective_to DATE NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    mapping_source VARCHAR(80) NOT NULL,
    notes VARCHAR(1000) NULL,
    confirmed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    current_confirmed_warehouse_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (
            CASE
              WHEN status='confirmed' AND effective_to IS NULL
              THEN warehouse_id
              ELSE NULL
            END
        ) STORED,
    PRIMARY KEY (assignment_id),
    UNIQUE KEY uq_pbi_manager_assignment_identity
        (company_id,warehouse_id,employee_id,status),
    UNIQUE KEY uq_pbi_manager_current_confirmed
        (company_id,current_confirmed_warehouse_id),
    KEY idx_pbi_manager_employee
        (company_id,employee_id,status,effective_from,effective_to),
    CONSTRAINT ck_pbi_manager_assignment_status
        CHECK (status IN('pending','confirmed','rejected','ended')),
    CONSTRAINT ck_pbi_manager_assignment_dates
        CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to>=effective_from),
    CONSTRAINT ck_pbi_manager_confirmation_date
        CHECK (status<>'confirmed' OR effective_from IS NOT NULL),
    CONSTRAINT fk_pbi_manager_assignment_warehouse
        FOREIGN KEY (company_id,warehouse_id)
        REFERENCES inventory_warehouses(company_id,warehouse_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_pbi_manager_assignment_employee
        FOREIGN KEY (company_id,employee_id)
        REFERENCES hr_employees(company_id,employee_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO bi_powerbi_shop_manager_assignments
    (company_id,warehouse_id,employee_id,effective_from,effective_to,status,mapping_source,notes)
SELECT w.company_id,w.warehouse_id,MIN(e.employee_id),
       NULL,NULL,'pending','UNIQUE_ACTIVE_ACCESS_CANDIDATE',
       'Seeded as a review candidate only. Unique active warehouse access is not an authoritative manager appointment.'
FROM vw_powerbi_warehouses w
INNER JOIN inventory_user_warehouse_access wa
  ON wa.company_id=w.company_id
 AND wa.warehouse_id=w.warehouse_id
 AND wa.active=TRUE
INNER JOIN hr_employees e
  ON e.company_id=wa.company_id
 AND e.user_id=wa.user_id
 AND e.deleted_at IS NULL
 AND e.employment_status='active'
 AND e.job_title='Shop Manager'
LEFT JOIN hr_employees explicit_manager
  ON explicit_manager.company_id=w.company_id
 AND explicit_manager.user_id=w.manager_user_id
 AND explicit_manager.deleted_at IS NULL
 AND explicit_manager.employment_status='active'
 AND explicit_manager.job_title='Shop Manager'
WHERE w.company_id=2
  AND w.pbi_shop_id IS NOT NULL
  AND explicit_manager.employee_id IS NULL
GROUP BY w.company_id,w.warehouse_id
HAVING COUNT(DISTINCT e.employee_id)=1
SQL,
        <<<'SQL'
CREATE TABLE bi_powerbi_history_employee_aliases (
    company_id BIGINT UNSIGNED NOT NULL,
    legacy_employee_name VARCHAR(255) NOT NULL,
    bi_employee_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    mapping_basis VARCHAR(80) NOT NULL,
    notes VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id,legacy_employee_name),
    KEY idx_pbi_history_alias_employee (company_id,bi_employee_id,status),
    CONSTRAINT ck_pbi_history_alias_status
        CHECK (status IN('pending','confirmed','rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO bi_powerbi_history_employee_aliases
    (company_id,legacy_employee_name,bi_employee_id,status,mapping_basis,notes)
SELECT 2,h.employee_name,MIN(b.employee_id),'confirmed',
       'EMP_PREFIX_NORMALIZED_EXACT',
       'Confirmed automatically because stripping emp_ and replacing underscores with spaces yields exactly one BI employee name.'
FROM (
    SELECT DISTINCT employee_name
    FROM bi_powerbi_legacy_stock_rows
    WHERE company_id=2
      AND LOWER(employee_name) LIKE 'emp\_%'
) h
INNER JOIN bi_employees b
  ON b.company_id=2
 AND b.active=TRUE
 AND LOWER(TRIM(b.employee_name))=
     LOWER(TRIM(REPLACE(REPLACE(h.employee_name,'emp_',''),'_',' ')))
GROUP BY h.employee_name
HAVING COUNT(DISTINCT b.employee_id)=1
SQL,
        <<<'SQL'
UPDATE bi_powerbi_product_reporting_map m
INNER JOIN sales_products p
  ON p.company_id=m.company_id AND p.product_id=m.product_id
SET m.active=CASE
      WHEN UPPER(p.sku) IN(
          'FLOAT','PHYSICAL-SIM','ESIM','MIFI-DEVICE',
          'SCRATCH-005','SCRATCH-010','SCRATCH-015',
          'SCRATCH-020','SCRATCH-025','SCRATCH-050','SCRATCH-100'
      ) THEN TRUE
      ELSE FALSE
    END,
    m.notes=CASE
      WHEN UPPER(p.sku) IN(
          'FLOAT','PHYSICAL-SIM','ESIM','MIFI-DEVICE',
          'SCRATCH-005','SCRATCH-010','SCRATCH-015',
          'SCRATCH-020','SCRATCH-025','SCRATCH-050','SCRATCH-100'
      )
      THEN 'Migration 102: included in the verified legacy Power BI product set.'
      ELSE 'Migration 102: excluded from Power BI compatibility until explicitly approved; not present in the verified legacy product set.'
    END
WHERE m.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_shop_manager_candidates AS
SELECT a.company_id,a.assignment_id,a.warehouse_id,w.pbi_shop_id,w.shop_name,
       a.employee_id,
       CONCAT_WS(' ',e.first_name,NULLIF(e.middle_name,''),e.last_name) AS employee_name,
       a.effective_from,a.effective_to,a.status,a.mapping_source,a.notes,
       a.confirmed_at,a.created_at,a.updated_at
FROM bi_powerbi_shop_manager_assignments a
INNER JOIN vw_powerbi_warehouses w
  ON w.company_id=a.company_id AND w.warehouse_id=a.warehouse_id
INNER JOIN hr_employees e
  ON e.company_id=a.company_id AND e.employee_id=a.employee_id
WHERE a.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_current_shop_manager_scope AS
SELECT w.company_id,w.warehouse_id,w.pbi_shop_id,w.shop_name,
       CASE
         WHEN ee.employee_id IS NOT NULL AND ee.job_title='Shop Manager'
           THEN ee.employee_id
         WHEN ca.employee_id IS NOT NULL
           THEN ca.employee_id
         ELSE NULL
       END AS manager_employee_id,
       CASE
         WHEN ee.employee_id IS NOT NULL AND ee.job_title='Shop Manager'
           THEN CONCAT_WS(' ',ee.first_name,NULLIF(ee.middle_name,''),ee.last_name)
         WHEN ca.employee_id IS NOT NULL
           THEN CONCAT_WS(' ',ce.first_name,NULLIF(ce.middle_name,''),ce.last_name)
         ELSE NULL
       END AS manager_name,
       CASE
         WHEN ee.employee_id IS NOT NULL AND ee.job_title='Shop Manager'
           THEN 'EXPLICIT_WAREHOUSE_MANAGER'
         WHEN ca.employee_id IS NOT NULL
           THEN 'CONFIRMED_REPORTING_ASSIGNMENT'
         WHEN COALESCE(c.candidate_count,0)=1
           THEN 'PENDING_BUSINESS_CONFIRMATION'
         WHEN COALESCE(c.candidate_count,0)=0
           THEN 'UNRESOLVED_NO_MANAGER'
         ELSE 'UNRESOLVED_MULTIPLE_CANDIDATES'
       END AS mapping_status
FROM vw_powerbi_warehouses w
LEFT JOIN hr_employees ee
  ON ee.company_id=w.company_id
 AND ee.user_id=w.manager_user_id
 AND ee.deleted_at IS NULL
 AND ee.employment_status='active'
LEFT JOIN (
    SELECT a.company_id,a.warehouse_id,MIN(a.employee_id) AS employee_id
    FROM bi_powerbi_shop_manager_assignments a
    WHERE a.company_id=2
      AND a.status='confirmed'
      AND a.effective_from<=CURRENT_DATE
      AND (a.effective_to IS NULL OR a.effective_to>=CURRENT_DATE)
    GROUP BY a.company_id,a.warehouse_id
    HAVING COUNT(*)=1
) ca
  ON ca.company_id=w.company_id AND ca.warehouse_id=w.warehouse_id
LEFT JOIN hr_employees ce
  ON ce.company_id=ca.company_id
 AND ce.employee_id=ca.employee_id
 AND ce.deleted_at IS NULL
 AND ce.employment_status='active'
LEFT JOIN (
    SELECT wa.company_id,wa.warehouse_id,
           COUNT(DISTINCT e.employee_id) AS candidate_count
    FROM inventory_user_warehouse_access wa
    INNER JOIN hr_employees e
      ON e.company_id=wa.company_id
     AND e.user_id=wa.user_id
     AND e.deleted_at IS NULL
     AND e.employment_status='active'
     AND e.job_title='Shop Manager'
    WHERE wa.company_id=2 AND wa.active=TRUE
    GROUP BY wa.company_id,wa.warehouse_id
) c
  ON c.company_id=w.company_id AND c.warehouse_id=w.warehouse_id
WHERE w.company_id=2 AND w.pbi_shop_id IS NOT NULL
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_history_employee_identity_candidates AS
SELECT h.company_id,h.batch_id,h.source_row_number,h.report_date,
       h.employee_name AS legacy_employee_name,h.shop_manager_label,
       sh.warehouse_id,b.employee_id AS bi_employee_id,b.employee_name AS bi_employee_name,
       CASE
         WHEN LOWER(TRIM(b.employee_name))=LOWER(TRIM(h.employee_name))
           THEN 10
         WHEN a.bi_employee_id=b.employee_id AND a.status='confirmed'
           THEN 20
         WHEN LOWER(h.employee_name) LIKE 'emp\_%'
          AND LOWER(TRIM(b.employee_name))=
              LOWER(TRIM(REPLACE(REPLACE(h.employee_name,'emp_',''),'_',' ')))
           THEN 30
         ELSE 99
       END AS identity_priority,
       CASE
         WHEN LOWER(TRIM(b.employee_name))=LOWER(TRIM(h.employee_name))
           THEN 'LEGACY_NAME_EXACT_BI_NAME'
         WHEN a.bi_employee_id=b.employee_id AND a.status='confirmed'
           THEN CONCAT('CONFIRMED_ALIAS:',a.mapping_basis)
         WHEN LOWER(h.employee_name) LIKE 'emp\_%'
          AND LOWER(TRIM(b.employee_name))=
              LOWER(TRIM(REPLACE(REPLACE(h.employee_name,'emp_',''),'_',' ')))
           THEN 'EMP_PREFIX_NORMALIZED_EXACT'
         ELSE 'UNMATCHED'
       END AS identity_basis
FROM vw_powerbi_history_stock_current h
LEFT JOIN (
    SELECT LOWER(TRIM(shop_name)) AS shop_name_key,
           MIN(warehouse_id) AS warehouse_id
    FROM vw_powerbi_shop_hierarchy
    WHERE company_id=2
    GROUP BY LOWER(TRIM(shop_name))
    HAVING COUNT(*)=1
) sh
  ON sh.shop_name_key=LOWER(TRIM(h.shop_manager_label))
LEFT JOIN bi_powerbi_history_employee_aliases a
  ON a.company_id=h.company_id
 AND LOWER(TRIM(a.legacy_employee_name))=LOWER(TRIM(h.employee_name))
 AND a.status='confirmed'
INNER JOIN bi_employees b
  ON b.company_id=h.company_id
 AND b.active=TRUE
 AND (
      LOWER(TRIM(b.employee_name))=LOWER(TRIM(h.employee_name))
      OR b.employee_id=a.bi_employee_id
      OR (
          LOWER(h.employee_name) LIKE 'emp\_%'
          AND LOWER(TRIM(b.employee_name))=
              LOWER(TRIM(REPLACE(REPLACE(h.employee_name,'emp_',''),'_',' ')))
      )
 )
WHERE h.company_id=2
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
        SELECT DISTINCT i.company_id,i.batch_id,i.source_row_number,
               ba.role_code AS role,
               CASE WHEN ba.role_code IN('DSA','DSP') THEN 'DSA/DSP' ELSE ba.role_code END AS role_group,
               10+i.identity_priority AS source_priority,
               CONCAT('EFFECTIVE_BI_ASSIGNMENT:',i.identity_basis) AS role_mapping_status
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
               'EFFECTIVE_HR_POSITION_ASSIGNMENT' AS role_mapping_status
        FROM vw_powerbi_history_stock_current h
        INNER JOIN hr_employees e
          ON e.company_id=h.company_id
         AND e.deleted_at IS NULL
         AND LOWER(TRIM(CONCAT_WS(' ',e.first_name,NULLIF(e.middle_name,''),e.last_name)))=
             LOWER(TRIM(h.employee_name))
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
       COALESCE(rr.role_mapping_status,'UNRESOLVED_HISTORY_ROLE') AS role_mapping_status,
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
  ON sh.shop_name_key=LOWER(TRIM(h.shop_manager_label))
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
  ON pm.product_name_key=LOWER(TRIM(h.product_name))
WHERE c.reporting_mode IN('HISTORY_ONLY','HYBRID')
  AND (c.reporting_mode='HISTORY_ONLY' OR h.report_date<c.live_cutover_date)

UNION ALL

SELECT l.company_id,l.report_date,l.warehouse_id,l.pbi_shop_id,
       l.shop_manager_label,l.shop_location,l.employee_name,l.role,l.role_group,
       l.cluster_name,l.region_name,l.product_name,l.section_name,
       l.auto_beginning_stock,l.total_received,l.total_sold,l.closing_stock,
       l.reporting_unit_value,l.value_basis,l.role_mapping_status,l.source_grain,l.SourceSystem
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
WHERE SourceSystem='POWERBI_HISTORY'
  AND role_mapping_status='UNRESOLVED_HISTORY_ROLE'
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
    ],
];
