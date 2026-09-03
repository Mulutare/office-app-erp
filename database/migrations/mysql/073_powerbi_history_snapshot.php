<?php

declare(strict_types=1);

return [
    'version'=>'073',
    'description'=>'Create Power BI historical export staging tables and views',
    'preflight'=>static function(\PDO $connection):string{
        $tables=['bi_powerbi_history_import_batches','bi_powerbi_history_rows'];
        $views=['vw_powerbi_history_export_rows','vw_powerbi_history_date_detail'];

        $tableQuoted=implode(',',array_map(static fn(string $name):string=>$connection->quote($name),$tables));
        $viewQuoted=implode(',',array_map(static fn(string $name):string=>$connection->quote($name),$views));

        $tableCount=(int)$connection->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND table_name IN($tableQuoted)")->fetchColumn();
        $viewCount=(int)$connection->query("SELECT COUNT(*) FROM information_schema.views WHERE table_schema=DATABASE() AND table_name IN($viewQuoted)")->fetchColumn();

        if($tableCount===0 && $viewCount===0)return 'apply';
        if($tableCount===count($tables) && $viewCount===count($views))return 'baseline';
        throw new \RuntimeException('Migration 073 found a partial Power BI historical staging layer.');
    },
    'statements'=>[
        <<<'SQL'
CREATE TABLE bi_powerbi_history_import_batches (
    batch_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    batch_code VARCHAR(100) NOT NULL,
    source_file_name VARCHAR(255) NOT NULL,
    report_date DATE NOT NULL,
    cutover_date DATE NOT NULL,
    source_system VARCHAR(32) NOT NULL DEFAULT 'POWERBI_HISTORY',
    filter_text TEXT NULL,
    source_row_count INT UNSIGNED NOT NULL,
    imported_row_count INT UNSIGNED NOT NULL,
    detail_row_count INT UNSIGNED NOT NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (batch_id),
    UNIQUE KEY uq_bi_powerbi_history_batch (company_id,batch_code),
    KEY idx_bi_powerbi_history_batch_dates (company_id,report_date,cutover_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE bi_powerbi_history_rows (
    history_row_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    source_row_number INT UNSIGNED NOT NULL,
    row_type VARCHAR(32) NOT NULL,
    pbi_shop_id VARCHAR(64) NULL,
    shop_manager_label VARCHAR(255) NULL,
    shop_location VARCHAR(255) NULL,
    employee_name VARCHAR(255) NULL,
    pbi_product_id VARCHAR(64) NULL,
    product_name VARCHAR(255) NULL,
    report_date DATE NULL,
    report_date_raw VARCHAR(64) NULL,
    auto_beginning_stock DECIMAL(20,4) NULL,
    total_received DECIMAL(20,4) NULL,
    total_sold DECIMAL(20,4) NULL,
    closing_stock DECIMAL(20,4) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (history_row_id),
    UNIQUE KEY uq_bi_powerbi_history_source_row (batch_id,source_row_number),
    KEY idx_bi_powerbi_history_date (company_id,report_date,row_type),
    KEY idx_bi_powerbi_history_shop (company_id,pbi_shop_id,report_date),
    KEY idx_bi_powerbi_history_product (company_id,pbi_product_id,report_date),
    CONSTRAINT fk_bi_powerbi_history_batch
        FOREIGN KEY (batch_id) REFERENCES bi_powerbi_history_import_batches(batch_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_history_export_rows AS
SELECT r.company_id,
       b.batch_code,
       b.source_file_name,
       b.report_date AS batch_report_date,
       b.cutover_date,
       r.source_row_number,
       r.row_type,
       r.pbi_shop_id,
       r.shop_manager_label,
       r.shop_location,
       r.employee_name,
       r.pbi_product_id,
       r.product_name,
       r.report_date,
       r.report_date_raw,
       r.auto_beginning_stock,
       r.total_received,
       r.total_sold,
       r.closing_stock,
       'POWERBI_HISTORY' AS SourceSystem,
       'HIERARCHICAL_EXPORT_ROWS_OVERLAP_DO_NOT_SUM_ACROSS_LEVELS' AS aggregation_note
FROM bi_powerbi_history_rows r
INNER JOIN bi_powerbi_history_import_batches b ON b.batch_id=r.batch_id
WHERE r.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_history_date_detail AS
SELECT r.company_id,
       b.batch_code,
       b.cutover_date,
       r.source_row_number,
       r.pbi_shop_id,
       r.shop_manager_label,
       r.shop_location,
       r.employee_name,
       r.pbi_product_id,
       r.product_name,
       r.report_date,
       r.auto_beginning_stock,
       r.total_received,
       r.total_sold,
       r.closing_stock,
       'POWERBI_HISTORY' AS SourceSystem,
       'EMPLOYEE_PRODUCT_ROWS_CAN_OVERLAP_BETWEEN_ROLE_GROUPS_USE_WITH_GOVERNED_MEASURES' AS aggregation_note
FROM bi_powerbi_history_rows r
INNER JOIN bi_powerbi_history_import_batches b ON b.batch_id=r.batch_id
WHERE r.company_id=2
  AND r.row_type='DATE_DETAIL'
  AND r.report_date < b.cutover_date
SQL,
    ],
];
