<?php

declare(strict_types=1);

return [
    'version' => '101',
    'description' => 'Add governed Power BI history-live compatibility and supplemental reporting capture',
    'preflight' => static function(\PDO $connection): string {
        $required = [
            'bi_powerbi_reporting_control',
            'bi_powerbi_legacy_import_batches',
            'bi_powerbi_legacy_stock_rows',
            'bi_powerbi_legacy_daily_shop_assets',
            'bi_powerbi_legacy_bank_transactions',
            'bi_powerbi_legacy_shop_sim_incentives',
            'bi_powerbi_legacy_float_incentives',
            'bi_powerbi_legacy_float_returns',
            'vw_powerbi_inventory_daily',
            'vw_powerbi_warehouses',
            'vw_powerbi_shop_hierarchy',
            'vw_powerbi_products',
            'sales_products',
            'vw_powerbi_receipts',
            'hr_employees',
            'hr_employee_position_assignments',
            'inventory_user_warehouse_access',
            'inventory_warehouses',
            'sales_agents',
            'sales_quick_sales',
            'sales_quick_sale_reports',
            'sales_quick_sale_report_lines',
            'sales_product_price_changes',
        ];
        $requiredQuoted = implode(',', array_map(
            static fn(string $name): string => $connection->quote($name),
            $required
        ));
        $requiredCount = (int)$connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name IN($requiredQuoted)"
        )->fetchColumn();
        if ($requiredCount !== count($required)) {
            throw new \RuntimeException(
                'Migration 101 requires migrations 072, 076, 088-091 and 100 to be present.'
            );
        }

        $tables = [
            'bi_powerbi_product_reporting_map',
            'bi_powerbi_employee_deposit_events',
            'bi_powerbi_shop_daily_metrics',
        ];
        $views = [
            'vw_powerbi_product_reporting_price_periods',
            'vw_powerbi_current_shop_manager_scope',
            'vw_powerbi_employee_role_scope',
            'vw_powerbi_history_stock_current',
            'vw_powerbi_history_daily_shop_assets_current',
            'vw_powerbi_history_bank_transactions_current',
            'vw_powerbi_history_shop_sim_incentives_current',
            'vw_powerbi_history_float_incentives_current',
            'vw_powerbi_history_float_returns_current',
            'vw_powerbi_live_stock_detail',
            'vw_powerbi_live_bank_transaction_detail',
            'vw_powerbi_live_shop_sim_incentives',
            'vw_powerbi_live_float_incentives',
            'vw_powerbi_live_float_returns',
            'vw_powerbi_live_shop_daily_supplemental',
            'vw_powerbi_history_shop_daily_supplemental_current',
            'vw_powerbi_live_daily_shop_assets',
            'vw_powerbi_live_grv_daily',
            'vw_powerbi_compat_stock_detail',
            'vw_powerbi_compat_bank_transaction_detail',
            'vw_powerbi_compat_shop_sim_incentives',
            'vw_powerbi_compat_float_incentives',
            'vw_powerbi_compat_float_returns',
            'vw_powerbi_compat_shop_daily_supplemental',
            'vw_powerbi_compat_daily_shop_assets',
            'vw_powerbi_compat_stock_by_section',
            'vw_powerbi_reporting_readiness',
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

        if ($tableCount === 0 && $viewCount === 0) {
            return 'apply';
        }
        if ($tableCount === count($tables) && $viewCount === count($views)) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 101 found a partial Power BI compatibility layer.'
        );
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE bi_powerbi_product_reporting_map (
    company_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    reporting_product_name VARCHAR(255) NOT NULL,
    section_name VARCHAR(100) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    notes VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id,product_id),
    KEY idx_pbi_product_section (company_id,section_name,active),
    CONSTRAINT fk_pbi_product_map_product
        FOREIGN KEY (company_id,product_id)
        REFERENCES sales_products(company_id,product_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO bi_powerbi_product_reporting_map
    (company_id,product_id,reporting_product_name,section_name,active,notes)
SELECT p.company_id,p.product_id,p.name,
       CASE
         WHEN UPPER(p.sku)='FLOAT' THEN 'Float'
         WHEN UPPER(p.sku) LIKE 'SCRATCH-%' THEN 'Scratch Cards'
         WHEN UPPER(p.sku) IN('PHYSICAL-SIM','ESIM') THEN 'SIM Cards'
         WHEN UPPER(p.sku)='MIFI-DEVICE' THEN 'Mifi Devices'
         ELSE COALESCE(NULLIF(p.category,''),NULLIF(p.product_type,''),'Other')
       END,
       p.active,
       'Migration 101 seeded reporting classification from the product master.'
FROM sales_products p
WHERE p.company_id=2 AND p.deleted_at IS NULL
SQL,
        <<<'SQL'
CREATE TABLE bi_powerbi_employee_deposit_events (
    deposit_event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    report_date DATE NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    bank_transaction_id VARCHAR(255) NULL,
    amount_birr DECIMAL(20,4) NOT NULL,
    source_reference VARCHAR(255) NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    provenance VARCHAR(40) NOT NULL DEFAULT 'ERP_CAPTURE',
    notes VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (deposit_event_id),
    UNIQUE KEY uq_pbi_deposit_idempotency (company_id,idempotency_key),
    KEY idx_pbi_deposit_date (company_id,report_date,warehouse_id),
    KEY idx_pbi_deposit_employee (company_id,employee_id,report_date),
    KEY idx_pbi_deposit_bankref (company_id,bank_transaction_id),
    CONSTRAINT ck_pbi_deposit_amount CHECK(amount_birr>=0),
    CONSTRAINT fk_pbi_deposit_warehouse
        FOREIGN KEY (company_id,warehouse_id)
        REFERENCES inventory_warehouses(company_id,warehouse_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_pbi_deposit_employee
        FOREIGN KEY (company_id,employee_id)
        REFERENCES hr_employees(company_id,employee_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE bi_powerbi_shop_daily_metrics (
    shop_daily_metric_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    report_date DATE NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    manager_reported_deposit_birr DECIMAL(20,4) NULL,
    reward_sim_cards_pieces DECIMAL(20,4) NULL,
    incentive_sim_cards_birr DECIMAL(20,4) NULL,
    float_airtime_incentive_birr DECIMAL(20,4) NULL,
    float_incentive_to_safaricom_birr DECIMAL(20,4) NULL,
    float_incentive_refund_from_safaricom_birr DECIMAL(20,4) NULL,
    total_float_returned_birr DECIMAL(20,4) NULL,
    safaricom_sim_value_borrowed_birr DECIMAL(20,4) NULL,
    safaricom_mifi_value_borrowed_pcs DECIMAL(20,4) NULL,
    qty_returned_birr_10_pieces DECIMAL(20,4) NULL,
    qty_returned_birr_15_pieces DECIMAL(20,4) NULL,
    qty_returned_birr_20_pieces DECIMAL(20,4) NULL,
    qty_returned_birr_25_pieces DECIMAL(20,4) NULL,
    qty_returned_birr_50_pieces DECIMAL(20,4) NULL,
    qty_returned_birr_100_pieces DECIMAL(20,4) NULL,
    source_reference VARCHAR(255) NULL,
    provenance VARCHAR(40) NOT NULL DEFAULT 'ERP_CAPTURE',
    notes VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (shop_daily_metric_id),
    UNIQUE KEY uq_pbi_shop_daily_metric (company_id,warehouse_id,report_date),
    KEY idx_pbi_shop_daily_date (company_id,report_date),
    CONSTRAINT ck_pbi_shop_daily_nonnegative CHECK(
        (manager_reported_deposit_birr IS NULL OR manager_reported_deposit_birr>=0)
        AND (reward_sim_cards_pieces IS NULL OR reward_sim_cards_pieces>=0)
        AND (incentive_sim_cards_birr IS NULL OR incentive_sim_cards_birr>=0)
        AND (float_airtime_incentive_birr IS NULL OR float_airtime_incentive_birr>=0)
        AND (float_incentive_to_safaricom_birr IS NULL OR float_incentive_to_safaricom_birr>=0)
        AND (float_incentive_refund_from_safaricom_birr IS NULL OR float_incentive_refund_from_safaricom_birr>=0)
        AND (total_float_returned_birr IS NULL OR total_float_returned_birr>=0)
        AND (safaricom_sim_value_borrowed_birr IS NULL OR safaricom_sim_value_borrowed_birr>=0)
        AND (safaricom_mifi_value_borrowed_pcs IS NULL OR safaricom_mifi_value_borrowed_pcs>=0)
        AND (qty_returned_birr_10_pieces IS NULL OR qty_returned_birr_10_pieces>=0)
        AND (qty_returned_birr_15_pieces IS NULL OR qty_returned_birr_15_pieces>=0)
        AND (qty_returned_birr_20_pieces IS NULL OR qty_returned_birr_20_pieces>=0)
        AND (qty_returned_birr_25_pieces IS NULL OR qty_returned_birr_25_pieces>=0)
        AND (qty_returned_birr_50_pieces IS NULL OR qty_returned_birr_50_pieces>=0)
        AND (qty_returned_birr_100_pieces IS NULL OR qty_returned_birr_100_pieces>=0)
    ),
    CONSTRAINT fk_pbi_shop_daily_warehouse
        FOREIGN KEY (company_id,warehouse_id)
        REFERENCES inventory_warehouses(company_id,warehouse_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_product_reporting_price_periods AS
SELECT x.company_id,x.product_id,x.effective_from AS valid_from,
       COALESCE(
           DATE_SUB(
               LEAD(x.effective_from) OVER(
                   PARTITION BY x.company_id,x.product_id
                   ORDER BY x.effective_from,x.price_change_id
               ),
               INTERVAL 1 SECOND
           ),
           CAST('9999-12-31 23:59:59' AS DATETIME)
       ) AS valid_to,
       x.proposed_price AS reporting_unit_value,
       'APPROVED_PRICE_CHANGE' AS price_basis
FROM sales_product_price_changes x
WHERE x.company_id=2 AND x.status='approved'
UNION ALL
SELECT f.company_id,f.product_id,
       CAST('1900-01-01 00:00:00' AS DATETIME) AS valid_from,
       DATE_SUB(f.effective_from,INTERVAL 1 SECOND) AS valid_to,
       f.old_price AS reporting_unit_value,
       'PRE_FIRST_APPROVED_PRICE' AS price_basis
FROM (
    SELECT pc.*,
           ROW_NUMBER() OVER(
               PARTITION BY pc.company_id,pc.product_id
               ORDER BY pc.effective_from,pc.price_change_id
           ) AS rn
    FROM sales_product_price_changes pc
    WHERE pc.company_id=2 AND pc.status='approved'
) f
WHERE f.rn=1
  AND f.effective_from>CAST('1900-01-01 00:00:00' AS DATETIME)
UNION ALL
SELECT p.company_id,p.product_id,
       CAST('1900-01-01 00:00:00' AS DATETIME),
       CAST('9999-12-31 23:59:59' AS DATETIME),
       p.unit_price,
       'PRODUCT_MASTER_NO_APPROVED_PRICE_HISTORY'
FROM sales_products p
WHERE p.company_id=2
  AND p.deleted_at IS NULL
  AND NOT EXISTS(
      SELECT 1
      FROM sales_product_price_changes pc
      WHERE pc.company_id=p.company_id
        AND pc.product_id=p.product_id
        AND pc.status='approved'
  )
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_current_shop_manager_scope AS
SELECT w.company_id,w.warehouse_id,w.pbi_shop_id,w.shop_name,
       CASE
         WHEN ee.employee_id IS NOT NULL AND ee.job_title='Shop Manager' THEN ee.employee_id
         WHEN COALESCE(c.candidate_count,0)=1 THEN ce.employee_id
         ELSE NULL
       END AS manager_employee_id,
       CASE
         WHEN ee.employee_id IS NOT NULL AND ee.job_title='Shop Manager'
           THEN CONCAT_WS(' ',ee.first_name,NULLIF(ee.middle_name,''),ee.last_name)
         WHEN COALESCE(c.candidate_count,0)=1
           THEN CONCAT_WS(' ',ce.first_name,NULLIF(ce.middle_name,''),ce.last_name)
         ELSE NULL
       END AS manager_name,
       CASE
         WHEN ee.employee_id IS NOT NULL AND ee.job_title='Shop Manager' THEN 'EXPLICIT_WAREHOUSE_MANAGER'
         WHEN COALESCE(c.candidate_count,0)=1 THEN 'UNIQUE_ACTIVE_ACCESS_CANDIDATE'
         WHEN COALESCE(c.candidate_count,0)=0 THEN 'UNRESOLVED_NO_MANAGER'
         ELSE 'UNRESOLVED_MULTIPLE_CANDIDATES'
       END AS mapping_status
FROM vw_powerbi_warehouses w
LEFT JOIN hr_employees ee
  ON ee.company_id=w.company_id
 AND ee.user_id=w.manager_user_id
 AND ee.deleted_at IS NULL
 AND ee.employment_status='active'
LEFT JOIN (
    SELECT wa.company_id,wa.warehouse_id,
           COUNT(DISTINCT e.employee_id) AS candidate_count,
           MIN(e.employee_id) AS employee_id
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
LEFT JOIN hr_employees ce
  ON ce.company_id=w.company_id AND ce.employee_id=c.employee_id
WHERE w.company_id=2 AND w.pbi_shop_id IS NOT NULL
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_employee_role_scope AS
SELECT e.company_id,e.employee_id,e.user_id,
       CONCAT_WS(' ',e.first_name,NULLIF(e.middle_name,''),e.last_name) AS employee_name,
       e.job_title,
       CASE
         WHEN pa.position_code_snapshot='TIGRAY-REGIONAL-MANAGER' THEN 'Regional Manager'
         WHEN pa.position_code_snapshot='TIGRAY-DISTRICT-MANAGER' THEN 'District Manager'
         WHEN e.job_title='Shop Manager' THEN 'Shop Manager'
         WHEN a.agent_count=1 THEN a.agent_type
         ELSE e.job_title
       END AS role,
       CASE
         WHEN pa.position_code_snapshot='TIGRAY-REGIONAL-MANAGER' THEN 'Regional Manager'
         WHEN pa.position_code_snapshot='TIGRAY-DISTRICT-MANAGER' THEN 'District Manager'
         WHEN e.job_title='Shop Manager' THEN 'Shop Manager'
         WHEN a.agent_count=1 AND a.agent_type IN('DSA','DSP') THEN 'DSA/DSP'
         ELSE e.job_title
       END AS role_group,
       CASE
         WHEN pa.position_code_snapshot IN('TIGRAY-REGIONAL-MANAGER','TIGRAY-DISTRICT-MANAGER') THEN 'CURRENT_POSITION_ASSIGNMENT'
         WHEN e.job_title='Shop Manager' THEN 'HR_JOB_TITLE'
         WHEN a.agent_count=1 THEN 'SALES_AGENT_CLASSIFICATION'
         ELSE 'HR_JOB_TITLE'
       END AS role_basis
FROM hr_employees e
LEFT JOIN (
    SELECT company_id,employee_id,
           MAX(position_code_snapshot) AS position_code_snapshot,
           MAX(position_name_snapshot) AS position_name_snapshot
    FROM hr_employee_position_assignments
    WHERE assignment_status='current' AND current_marker=1
    GROUP BY company_id,employee_id
) pa
  ON pa.company_id=e.company_id AND pa.employee_id=e.employee_id
LEFT JOIN (
    SELECT company_id,employee_id,COUNT(*) AS agent_count,
           MIN(agent_type) AS agent_type
    FROM sales_agents
    WHERE company_id=2 AND employee_id IS NOT NULL
      AND active=TRUE AND deleted_at IS NULL
    GROUP BY company_id,employee_id
) a
  ON a.company_id=e.company_id AND a.employee_id=e.employee_id
WHERE e.company_id=2 AND e.deleted_at IS NULL
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_history_stock_current AS
SELECT q.*
FROM (
    SELECT r.company_id,r.batch_id,r.source_row_number,
           r.shop_manager_label,r.shop_location,r.employee_name,r.product_name,
           r.report_date,r.auto_beginning_stock,r.total_received,r.total_sold,r.closing_stock,
           b.source_file_name,b.source_sha256,b.imported_at,
           ROW_NUMBER() OVER(
             PARTITION BY r.company_id,r.report_date,r.shop_manager_label,r.shop_location,r.employee_name,r.product_name
             ORDER BY b.imported_at DESC,b.batch_id DESC,r.source_row_number DESC
           ) AS rn
    FROM bi_powerbi_legacy_stock_rows r
    INNER JOIN bi_powerbi_legacy_import_batches b ON b.batch_id=r.batch_id
    WHERE r.company_id=2
) q
WHERE q.rn=1
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_history_daily_shop_assets_current AS
SELECT q.*
FROM (
    SELECT r.company_id,r.batch_id,r.source_row_number,r.shop_manager,r.report_date,
           r.stock_carry_forward_check,r.beginning_stock_selected_period,
           r.total_received_daily,r.available_for_sale_daily,r.total_sold_daily,
           r.incentive_sim_cards_birr,r.total_stock_daily,r.total_deposit_daily,
           r.cash_variance_daily,r.float_airtime_incentive_daily,
           r.stock_reconciliation_daily,r.total_sold_difference_daily,
           r.deposit_difference_daily,r.float_available_for_sale_daily,
           r.float_deposit_daily,r.float_closing_stock_daily,
           b.source_file_name,b.source_sha256,b.imported_at,
           ROW_NUMBER() OVER(
             PARTITION BY r.company_id,r.report_date,r.shop_manager
             ORDER BY b.imported_at DESC,b.batch_id DESC,r.source_row_number DESC
           ) AS rn
    FROM bi_powerbi_legacy_daily_shop_assets r
    INNER JOIN bi_powerbi_legacy_import_batches b ON b.batch_id=r.batch_id
    WHERE r.company_id=2
) q
WHERE q.rn=1
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_history_bank_transactions_current AS
SELECT q.*
FROM (
    SELECT r.company_id,r.batch_id,r.source_row_number,r.shop_manager,r.employee_name,
           r.report_date,r.bank_transaction_id,r.total_cash_deposit_birr,
           b.source_file_name,b.source_sha256,b.imported_at,
           ROW_NUMBER() OVER(
             PARTITION BY r.company_id,r.report_date,r.shop_manager,r.employee_name
             ORDER BY b.imported_at DESC,b.batch_id DESC,r.source_row_number DESC
           ) AS rn
    FROM bi_powerbi_legacy_bank_transactions r
    INNER JOIN bi_powerbi_legacy_import_batches b ON b.batch_id=r.batch_id
    WHERE r.company_id=2
) q
WHERE q.rn=1
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_history_shop_sim_incentives_current AS
SELECT q.*
FROM (
    SELECT r.company_id,r.batch_id,r.source_row_number,r.shop_manager,r.report_date,
           r.reward_sim_cards_pieces,r.incentive_sim_cards_birr,
           b.source_file_name,b.source_sha256,b.imported_at,
           ROW_NUMBER() OVER(
             PARTITION BY r.company_id,r.report_date,r.shop_manager
             ORDER BY b.imported_at DESC,b.batch_id DESC,r.source_row_number DESC
           ) AS rn
    FROM bi_powerbi_legacy_shop_sim_incentives r
    INNER JOIN bi_powerbi_legacy_import_batches b ON b.batch_id=r.batch_id
    WHERE r.company_id=2
) q
WHERE q.rn=1
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_history_float_incentives_current AS
SELECT q.*
FROM (
    SELECT r.company_id,r.batch_id,r.source_row_number,r.shop_manager,r.report_date,
           r.float_incentive_airtime_to_safaricom_birr,
           b.source_file_name,b.source_sha256,b.imported_at,
           ROW_NUMBER() OVER(
             PARTITION BY r.company_id,r.report_date,r.shop_manager
             ORDER BY b.imported_at DESC,b.batch_id DESC,r.source_row_number DESC
           ) AS rn
    FROM bi_powerbi_legacy_float_incentives r
    INNER JOIN bi_powerbi_legacy_import_batches b ON b.batch_id=r.batch_id
    WHERE r.company_id=2
) q
WHERE q.rn=1
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_history_float_returns_current AS
SELECT q.*
FROM (
    SELECT r.company_id,r.batch_id,r.source_row_number,r.shop_manager,r.report_date,
           r.total_float_returned_birr,
           b.source_file_name,b.source_sha256,b.imported_at,
           ROW_NUMBER() OVER(
             PARTITION BY r.company_id,r.report_date,r.shop_manager
             ORDER BY b.imported_at DESC,b.batch_id DESC,r.source_row_number DESC
           ) AS rn
    FROM bi_powerbi_legacy_float_returns r
    INNER JOIN bi_powerbi_legacy_import_batches b ON b.batch_id=r.batch_id
    WHERE r.company_id=2
) q
WHERE q.rn=1
SQL,
        <<<'SQL'
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
SELECT qs.company_id,DATE(COALESCE(r.reviewed_at,r.created_at)) AS report_date,
       qs.warehouse_id,w.pbi_shop_id,w.shop_name AS shop_manager_label,w.shop_name AS shop_location,
       er.employee_name,er.role,'DSA/DSP' AS role_group,h.cluster_name,h.region_name,
       prm.reporting_product_name AS product_name,prm.section_name,
       CAST(0 AS DECIMAL(20,4)) AS auto_beginning_stock,
       ROUND(SUM(rl.allocated_quantity)*COALESCE(pp.reporting_unit_value,p.unit_price),4) AS total_received,
       ROUND(SUM(rl.sold_quantity)*COALESCE(pp.reporting_unit_value,p.unit_price),4) AS total_sold,
       ROUND(SUM(rl.allocated_quantity-rl.sold_quantity-rl.returned_quantity)*COALESCE(pp.reporting_unit_value,p.unit_price),4) AS closing_stock,
       COALESCE(pp.reporting_unit_value,p.unit_price) AS reporting_unit_value,
       COALESCE(pp.price_basis,'PRODUCT_MASTER_FALLBACK') AS value_basis,
       er.role_basis AS role_mapping_status,
       'QUICK_SALE_EMPLOYEE_PRODUCT_DAY' AS source_grain,
       'ERP_LIVE' AS SourceSystem
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
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_live_bank_transaction_detail AS
SELECT e.company_id,h.shop_name AS shop_manager,er.employee_name,e.report_date,
       MIN(NULLIF(e.bank_transaction_id,'')) AS first_bank_transaction_id,
       SUM(e.amount_birr) AS total_cash_deposit_birr,
       'ERP_DEPOSIT_CAPTURE' AS SourceSystem
FROM bi_powerbi_employee_deposit_events e
INNER JOIN vw_powerbi_shop_hierarchy h ON h.warehouse_id=e.warehouse_id
INNER JOIN vw_powerbi_employee_role_scope er ON er.employee_id=e.employee_id
WHERE e.company_id=2
GROUP BY e.company_id,h.shop_name,er.employee_name,e.report_date
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_live_shop_sim_incentives AS
SELECT m.company_id,h.shop_name AS shop_manager,m.report_date,
       m.reward_sim_cards_pieces,m.incentive_sim_cards_birr,
       'ERP_SUPPLEMENTAL' AS SourceSystem
FROM bi_powerbi_shop_daily_metrics m
INNER JOIN vw_powerbi_shop_hierarchy h ON h.warehouse_id=m.warehouse_id
WHERE m.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_live_float_incentives AS
SELECT m.company_id,h.shop_name AS shop_manager,m.report_date,
       m.float_incentive_to_safaricom_birr AS float_incentive_airtime_to_safaricom_birr,
       'ERP_SUPPLEMENTAL' AS SourceSystem
FROM bi_powerbi_shop_daily_metrics m
INNER JOIN vw_powerbi_shop_hierarchy h ON h.warehouse_id=m.warehouse_id
WHERE m.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_live_float_returns AS
SELECT m.company_id,h.shop_name AS shop_manager,m.report_date,
       m.total_float_returned_birr,
       'ERP_SUPPLEMENTAL' AS SourceSystem
FROM bi_powerbi_shop_daily_metrics m
INNER JOIN vw_powerbi_shop_hierarchy h ON h.warehouse_id=m.warehouse_id
WHERE m.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_live_shop_daily_supplemental AS
SELECT m.company_id,m.warehouse_id,h.shop_name AS shop_manager,m.report_date,
       m.manager_reported_deposit_birr,
       m.reward_sim_cards_pieces,m.incentive_sim_cards_birr,
       m.float_airtime_incentive_birr,
       m.float_incentive_to_safaricom_birr,
       m.float_incentive_refund_from_safaricom_birr,
       m.total_float_returned_birr,
       m.safaricom_sim_value_borrowed_birr,
       m.safaricom_mifi_value_borrowed_pcs,
       m.qty_returned_birr_10_pieces,m.qty_returned_birr_15_pieces,
       m.qty_returned_birr_20_pieces,m.qty_returned_birr_25_pieces,
       m.qty_returned_birr_50_pieces,m.qty_returned_birr_100_pieces,
       m.source_reference,m.provenance,
       'ERP_SUPPLEMENTAL' AS SourceSystem
FROM bi_powerbi_shop_daily_metrics m
INNER JOIN vw_powerbi_shop_hierarchy h ON h.warehouse_id=m.warehouse_id
WHERE m.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_history_shop_daily_supplemental_current AS
SELECT u.company_id,u.shop_manager,u.report_date,
       MAX(u.manager_reported_deposit_birr) AS manager_reported_deposit_birr,
       MAX(u.reward_sim_cards_pieces) AS reward_sim_cards_pieces,
       MAX(u.incentive_sim_cards_birr) AS incentive_sim_cards_birr,
       MAX(u.float_airtime_incentive_birr) AS float_airtime_incentive_birr,
       MAX(u.float_incentive_to_safaricom_birr) AS float_incentive_to_safaricom_birr,
       MAX(u.float_incentive_refund_from_safaricom_birr) AS float_incentive_refund_from_safaricom_birr,
       MAX(u.total_float_returned_birr) AS total_float_returned_birr,
       MAX(u.safaricom_sim_value_borrowed_birr) AS safaricom_sim_value_borrowed_birr,
       MAX(u.safaricom_mifi_value_borrowed_pcs) AS safaricom_mifi_value_borrowed_pcs,
       MAX(u.qty_returned_birr_10_pieces) AS qty_returned_birr_10_pieces,
       MAX(u.qty_returned_birr_15_pieces) AS qty_returned_birr_15_pieces,
       MAX(u.qty_returned_birr_20_pieces) AS qty_returned_birr_20_pieces,
       MAX(u.qty_returned_birr_25_pieces) AS qty_returned_birr_25_pieces,
       MAX(u.qty_returned_birr_50_pieces) AS qty_returned_birr_50_pieces,
       MAX(u.qty_returned_birr_100_pieces) AS qty_returned_birr_100_pieces,
       'POWERBI_HISTORY' AS SourceSystem
FROM (
    SELECT company_id,shop_manager,report_date,
           NULL AS manager_reported_deposit_birr,
           reward_sim_cards_pieces,incentive_sim_cards_birr,
           NULL AS float_airtime_incentive_birr,
           NULL AS float_incentive_to_safaricom_birr,
           NULL AS float_incentive_refund_from_safaricom_birr,
           NULL AS total_float_returned_birr,
           NULL AS safaricom_sim_value_borrowed_birr,
           NULL AS safaricom_mifi_value_borrowed_pcs,
           NULL AS qty_returned_birr_10_pieces,NULL AS qty_returned_birr_15_pieces,
           NULL AS qty_returned_birr_20_pieces,NULL AS qty_returned_birr_25_pieces,
           NULL AS qty_returned_birr_50_pieces,NULL AS qty_returned_birr_100_pieces
    FROM vw_powerbi_history_shop_sim_incentives_current
    UNION ALL
    SELECT company_id,shop_manager,report_date,
           NULL,NULL,NULL,NULL,
           float_incentive_airtime_to_safaricom_birr,
           NULL,NULL,NULL,NULL,
           NULL,NULL,NULL,NULL,NULL,NULL
    FROM vw_powerbi_history_float_incentives_current
    UNION ALL
    SELECT company_id,shop_manager,report_date,
           NULL,NULL,NULL,NULL,NULL,NULL,
           total_float_returned_birr,NULL,NULL,
           NULL,NULL,NULL,NULL,NULL,NULL
    FROM vw_powerbi_history_float_returns_current
    UNION ALL
    SELECT company_id,shop_manager,report_date,
           NULL,NULL,incentive_sim_cards_birr,float_airtime_incentive_daily,
           NULL,NULL,NULL,NULL,NULL,
           NULL,NULL,NULL,NULL,NULL,NULL
    FROM vw_powerbi_history_daily_shop_assets_current
) u
GROUP BY u.company_id,u.shop_manager,u.report_date
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_live_daily_shop_assets AS
SELECT b.company_id,b.shop_manager,b.report_date,
       CASE
         WHEN b.prior_closing_stock IS NULL THEN 'NO_PRIOR_LIVE_DAY'
         WHEN ABS(b.beginning_stock_selected_period-b.prior_closing_stock)<=0.01 THEN '✔ Balanced'
         ELSE '⚠ Review'
       END AS stock_carry_forward_check,
       b.beginning_stock_selected_period,
       b.total_received_daily,
       b.beginning_stock_selected_period+b.total_received_daily AS available_for_sale_daily,
       b.total_sold_daily,
       COALESCE(s.incentive_sim_cards_birr,0) AS incentive_sim_cards_birr,
       b.total_stock_daily,
       COALESCE(dep.total_deposit_daily,0) AS total_deposit_daily,
       b.total_sold_daily-COALESCE(dep.total_deposit_daily,0) AS cash_variance_daily,
       COALESCE(s.float_airtime_incentive_birr,0) AS float_airtime_incentive_daily,
       CASE
         WHEN ABS((b.beginning_stock_selected_period+b.total_received_daily-b.total_sold_daily)-b.total_stock_daily)<=0.01
           THEN '✔ Balanced'
         ELSE '⚠ Review'
       END AS stock_reconciliation_daily,
       b.total_sold_daily-COALESCE(ds.dsa_total_sold_daily,0) AS total_sold_difference_daily,
       CASE
         WHEN s.manager_reported_deposit_birr IS NULL THEN 'Not Captured'
         WHEN ABS(s.manager_reported_deposit_birr-COALESCE(dep.total_deposit_daily,0))<=0.01 THEN '✔ Balanced'
         ELSE CONCAT('⚠ Difference ',ROUND(s.manager_reported_deposit_birr-COALESCE(dep.total_deposit_daily,0),2))
       END AS deposit_difference_daily,
       b.float_beginning_stock+b.float_received AS float_available_for_sale_daily,
       COALESCE(ds.dsa_float_sold_daily,0) AS float_deposit_daily,
       b.float_closing_stock AS float_closing_stock_daily,
       'ERP_LIVE' AS SourceSystem
FROM (
    SELECT z.*,
           LAG(z.total_stock_daily) OVER(
             PARTITION BY z.company_id,z.warehouse_id ORDER BY z.report_date
           ) AS prior_closing_stock
    FROM (
        SELECT s.company_id,s.warehouse_id,s.shop_manager_label AS shop_manager,s.report_date,
               SUM(s.auto_beginning_stock) AS beginning_stock_selected_period,
               SUM(s.total_received) AS total_received_daily,
               SUM(s.total_sold) AS total_sold_daily,
               SUM(s.closing_stock) AS total_stock_daily,
               SUM(CASE WHEN UPPER(s.product_name)='FLOAT' THEN s.auto_beginning_stock ELSE 0 END) AS float_beginning_stock,
               SUM(CASE WHEN UPPER(s.product_name)='FLOAT' THEN s.total_received ELSE 0 END) AS float_received,
               SUM(CASE WHEN UPPER(s.product_name)='FLOAT' THEN s.closing_stock ELSE 0 END) AS float_closing_stock
        FROM vw_powerbi_live_stock_detail s
        WHERE s.role_group='Shop Manager' AND s.shop_manager_label IS NOT NULL
        GROUP BY s.company_id,s.warehouse_id,s.shop_manager_label,s.report_date
    ) z
) b
LEFT JOIN (
    SELECT company_id,warehouse_id,report_date,
           SUM(total_sold) AS dsa_total_sold_daily,
           SUM(CASE WHEN UPPER(product_name)='FLOAT' THEN total_sold ELSE 0 END) AS dsa_float_sold_daily
    FROM vw_powerbi_live_stock_detail
    WHERE role_group='DSA/DSP'
    GROUP BY company_id,warehouse_id,report_date
) ds
  ON ds.company_id=b.company_id AND ds.warehouse_id=b.warehouse_id AND ds.report_date=b.report_date
LEFT JOIN (
    SELECT company_id,warehouse_id,report_date,SUM(amount_birr) AS total_deposit_daily
    FROM bi_powerbi_employee_deposit_events
    WHERE company_id=2
    GROUP BY company_id,warehouse_id,report_date
) dep
  ON dep.company_id=b.company_id AND dep.warehouse_id=b.warehouse_id AND dep.report_date=b.report_date
LEFT JOIN bi_powerbi_shop_daily_metrics s
  ON s.company_id=b.company_id AND s.warehouse_id=b.warehouse_id AND s.report_date=b.report_date
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_live_grv_daily AS
SELECT r.company_id,r.warehouse_id,h.shop_name AS shop_manager,r.receipt_date AS report_date,
       GROUP_CONCAT(DISTINCT r.receipt_number ORDER BY r.receipt_number SEPARATOR ', ') AS grv_number,
       'ERP_LIVE_RECEIPTS' AS SourceSystem
FROM vw_powerbi_receipts r
INNER JOIN vw_powerbi_shop_hierarchy h ON h.warehouse_id=r.warehouse_id
WHERE r.company_id=2 AND r.is_posted=1
GROUP BY r.company_id,r.warehouse_id,h.shop_name,r.receipt_date
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_compat_stock_detail AS
SELECT h.company_id,h.report_date,sh.warehouse_id,sh.pbi_shop_id,
       h.shop_manager_label,h.shop_location,h.employee_name,
       er.role,er.role_group,sh.cluster_name,sh.region_name,
       h.product_name,pm.section_name,
       h.auto_beginning_stock,h.total_received,h.total_sold,h.closing_stock,
       NULL AS reporting_unit_value,'LEGACY_EXPORTED_VALUE' AS value_basis,
       CASE WHEN er.employee_id IS NULL THEN 'UNRESOLVED_HISTORY_ROLE' ELSE 'CURRENT_ERP_INFERRED_FOR_HISTORY' END AS role_mapping_status,
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
LEFT JOIN (
    SELECT LOWER(TRIM(employee_name)) AS employee_name_key,
           MIN(employee_id) AS employee_id,MIN(role) AS role,MIN(role_group) AS role_group
    FROM vw_powerbi_employee_role_scope
    WHERE company_id=2
    GROUP BY LOWER(TRIM(employee_name))
    HAVING COUNT(*)=1
) er
  ON er.employee_name_key=LOWER(TRIM(h.employee_name))
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
CREATE OR REPLACE VIEW vw_powerbi_compat_bank_transaction_detail AS
SELECT h.company_id,h.shop_manager,h.employee_name,h.report_date,
       h.bank_transaction_id AS first_bank_transaction_id,h.total_cash_deposit_birr,
       'POWERBI_HISTORY' AS SourceSystem
FROM vw_powerbi_history_bank_transactions_current h
INNER JOIN bi_powerbi_reporting_control c ON c.company_id=h.company_id
WHERE c.reporting_mode IN('HISTORY_ONLY','HYBRID')
  AND (c.reporting_mode='HISTORY_ONLY' OR h.report_date<c.live_cutover_date)
UNION ALL
SELECT l.company_id,l.shop_manager,l.employee_name,l.report_date,
       l.first_bank_transaction_id,l.total_cash_deposit_birr,l.SourceSystem
FROM vw_powerbi_live_bank_transaction_detail l
INNER JOIN bi_powerbi_reporting_control c ON c.company_id=l.company_id
WHERE c.reporting_mode IN('HYBRID','LIVE_ONLY')
  AND (c.reporting_mode='LIVE_ONLY' OR l.report_date>=c.live_cutover_date)
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_compat_shop_sim_incentives AS
SELECT h.company_id,h.shop_manager,h.report_date,h.reward_sim_cards_pieces,
       h.incentive_sim_cards_birr,'POWERBI_HISTORY' AS SourceSystem
FROM vw_powerbi_history_shop_sim_incentives_current h
INNER JOIN bi_powerbi_reporting_control c ON c.company_id=h.company_id
WHERE c.reporting_mode IN('HISTORY_ONLY','HYBRID')
  AND (c.reporting_mode='HISTORY_ONLY' OR h.report_date<c.live_cutover_date)
UNION ALL
SELECT l.company_id,l.shop_manager,l.report_date,l.reward_sim_cards_pieces,
       l.incentive_sim_cards_birr,l.SourceSystem
FROM vw_powerbi_live_shop_sim_incentives l
INNER JOIN bi_powerbi_reporting_control c ON c.company_id=l.company_id
WHERE c.reporting_mode IN('HYBRID','LIVE_ONLY')
  AND (c.reporting_mode='LIVE_ONLY' OR l.report_date>=c.live_cutover_date)
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_compat_float_incentives AS
SELECT h.company_id,h.shop_manager,h.report_date,h.float_incentive_airtime_to_safaricom_birr,
       'POWERBI_HISTORY' AS SourceSystem
FROM vw_powerbi_history_float_incentives_current h
INNER JOIN bi_powerbi_reporting_control c ON c.company_id=h.company_id
WHERE c.reporting_mode IN('HISTORY_ONLY','HYBRID')
  AND (c.reporting_mode='HISTORY_ONLY' OR h.report_date<c.live_cutover_date)
UNION ALL
SELECT l.company_id,l.shop_manager,l.report_date,l.float_incentive_airtime_to_safaricom_birr,
       l.SourceSystem
FROM vw_powerbi_live_float_incentives l
INNER JOIN bi_powerbi_reporting_control c ON c.company_id=l.company_id
WHERE c.reporting_mode IN('HYBRID','LIVE_ONLY')
  AND (c.reporting_mode='LIVE_ONLY' OR l.report_date>=c.live_cutover_date)
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_compat_float_returns AS
SELECT h.company_id,h.shop_manager,h.report_date,h.total_float_returned_birr,
       'POWERBI_HISTORY' AS SourceSystem
FROM vw_powerbi_history_float_returns_current h
INNER JOIN bi_powerbi_reporting_control c ON c.company_id=h.company_id
WHERE c.reporting_mode IN('HISTORY_ONLY','HYBRID')
  AND (c.reporting_mode='HISTORY_ONLY' OR h.report_date<c.live_cutover_date)
UNION ALL
SELECT l.company_id,l.shop_manager,l.report_date,l.total_float_returned_birr,l.SourceSystem
FROM vw_powerbi_live_float_returns l
INNER JOIN bi_powerbi_reporting_control c ON c.company_id=l.company_id
WHERE c.reporting_mode IN('HYBRID','LIVE_ONLY')
  AND (c.reporting_mode='LIVE_ONLY' OR l.report_date>=c.live_cutover_date)
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_compat_shop_daily_supplemental AS
SELECT h.company_id,sh.warehouse_id,h.shop_manager,h.report_date,
       h.manager_reported_deposit_birr,h.reward_sim_cards_pieces,h.incentive_sim_cards_birr,
       h.float_airtime_incentive_birr,h.float_incentive_to_safaricom_birr,
       h.float_incentive_refund_from_safaricom_birr,h.total_float_returned_birr,
       h.safaricom_sim_value_borrowed_birr,h.safaricom_mifi_value_borrowed_pcs,
       h.qty_returned_birr_10_pieces,h.qty_returned_birr_15_pieces,
       h.qty_returned_birr_20_pieces,h.qty_returned_birr_25_pieces,
       h.qty_returned_birr_50_pieces,h.qty_returned_birr_100_pieces,
       h.SourceSystem
FROM vw_powerbi_history_shop_daily_supplemental_current h
INNER JOIN bi_powerbi_reporting_control c ON c.company_id=h.company_id
LEFT JOIN vw_powerbi_shop_hierarchy sh
  ON LOWER(TRIM(sh.shop_name))=LOWER(TRIM(h.shop_manager))
WHERE c.reporting_mode IN('HISTORY_ONLY','HYBRID')
  AND (c.reporting_mode='HISTORY_ONLY' OR h.report_date<c.live_cutover_date)
UNION ALL
SELECT l.company_id,l.warehouse_id,l.shop_manager,l.report_date,
       l.manager_reported_deposit_birr,l.reward_sim_cards_pieces,l.incentive_sim_cards_birr,
       l.float_airtime_incentive_birr,l.float_incentive_to_safaricom_birr,
       l.float_incentive_refund_from_safaricom_birr,l.total_float_returned_birr,
       l.safaricom_sim_value_borrowed_birr,l.safaricom_mifi_value_borrowed_pcs,
       l.qty_returned_birr_10_pieces,l.qty_returned_birr_15_pieces,
       l.qty_returned_birr_20_pieces,l.qty_returned_birr_25_pieces,
       l.qty_returned_birr_50_pieces,l.qty_returned_birr_100_pieces,
       l.SourceSystem
FROM vw_powerbi_live_shop_daily_supplemental l
INNER JOIN bi_powerbi_reporting_control c ON c.company_id=l.company_id
WHERE c.reporting_mode IN('HYBRID','LIVE_ONLY')
  AND (c.reporting_mode='LIVE_ONLY' OR l.report_date>=c.live_cutover_date)
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_compat_daily_shop_assets AS
SELECT h.company_id,h.shop_manager,h.report_date,h.stock_carry_forward_check,
       h.beginning_stock_selected_period,h.total_received_daily,h.available_for_sale_daily,
       h.total_sold_daily,h.incentive_sim_cards_birr,h.total_stock_daily,
       h.total_deposit_daily,h.cash_variance_daily,h.float_airtime_incentive_daily,
       h.stock_reconciliation_daily,h.total_sold_difference_daily,h.deposit_difference_daily,
       h.float_available_for_sale_daily,h.float_deposit_daily,h.float_closing_stock_daily,
       'POWERBI_HISTORY' AS SourceSystem
FROM vw_powerbi_history_daily_shop_assets_current h
INNER JOIN bi_powerbi_reporting_control c ON c.company_id=h.company_id
WHERE c.reporting_mode IN('HISTORY_ONLY','HYBRID')
  AND (c.reporting_mode='HISTORY_ONLY' OR h.report_date<c.live_cutover_date)
UNION ALL
SELECT l.company_id,l.shop_manager,l.report_date,l.stock_carry_forward_check,
       l.beginning_stock_selected_period,l.total_received_daily,l.available_for_sale_daily,
       l.total_sold_daily,l.incentive_sim_cards_birr,l.total_stock_daily,
       l.total_deposit_daily,l.cash_variance_daily,l.float_airtime_incentive_daily,
       l.stock_reconciliation_daily,l.total_sold_difference_daily,l.deposit_difference_daily,
       l.float_available_for_sale_daily,l.float_deposit_daily,l.float_closing_stock_daily,
       l.SourceSystem
FROM vw_powerbi_live_daily_shop_assets l
INNER JOIN bi_powerbi_reporting_control c ON c.company_id=l.company_id
WHERE c.reporting_mode IN('HYBRID','LIVE_ONLY')
  AND (c.reporting_mode='LIVE_ONLY' OR l.report_date>=c.live_cutover_date)
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_compat_stock_by_section AS
SELECT company_id,report_date,role,role_group,section_name AS section,
       SUM(closing_stock) AS stock_value,
       SourceSystem
FROM vw_powerbi_compat_stock_detail
GROUP BY company_id,report_date,role,role_group,section_name,SourceSystem
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_reporting_readiness AS
SELECT c.company_id,c.reporting_mode,c.live_cutover_date,
       (SELECT COUNT(*) FROM vw_powerbi_current_shop_manager_scope s
        WHERE s.mapping_status LIKE 'UNRESOLVED%') AS unresolved_shop_manager_count,
       (SELECT COUNT(*) FROM bi_powerbi_product_reporting_map p
        WHERE p.company_id=c.company_id AND p.active=TRUE) AS reporting_product_map_count,
       (SELECT COUNT(*) FROM vw_powerbi_inventory_daily d
        WHERE d.company_id=c.company_id) AS live_inventory_daily_rows,
       (SELECT COUNT(*) FROM sales_quick_sale_reports r
        WHERE r.company_id=c.company_id AND r.status='confirmed') AS confirmed_quick_sale_reports,
       (SELECT COUNT(*) FROM bi_powerbi_employee_deposit_events e
        WHERE e.company_id=c.company_id) AS captured_employee_deposit_events,
       (SELECT COUNT(*) FROM bi_powerbi_shop_daily_metrics m
        WHERE m.company_id=c.company_id) AS captured_shop_daily_metric_rows,
       CASE
         WHEN c.reporting_mode='HISTORY_ONLY' THEN 'SAFE_HISTORY_ONLY'
         WHEN c.live_cutover_date IS NULL THEN 'BLOCKED_NO_CUTOVER_DATE'
         WHEN (SELECT COUNT(*) FROM vw_powerbi_current_shop_manager_scope s
               WHERE s.mapping_status LIKE 'UNRESOLVED%')>0 THEN 'REVIEW_MANAGER_MAPPING'
         ELSE 'LIVE_LAYER_AVAILABLE_REVIEW_SUPPLEMENTAL_CAPTURE'
       END AS readiness_status
FROM bi_powerbi_reporting_control c
WHERE c.company_id=2
SQL,
    ],
];
