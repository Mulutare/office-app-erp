<?php

declare(strict_types=1);

return [
    'version' => '100',
    'description' => 'Preserve exported Power BI legacy reporting facts for company 2 before live database cutover',
    'preflight' => static function(\PDO $connection): string {
        $tables = [
            'bi_powerbi_legacy_import_batches',
            'bi_powerbi_legacy_stock_rows',
            'bi_powerbi_legacy_daily_shop_assets',
            'bi_powerbi_legacy_bank_transactions',
            'bi_powerbi_legacy_shop_sim_incentives',
            'bi_powerbi_legacy_float_incentives',
            'bi_powerbi_legacy_float_returns',
            'bi_powerbi_reporting_control',
        ];
        $views = [
            'vw_powerbi_legacy_stock_history',
            'vw_powerbi_legacy_daily_shop_assets',
            'vw_powerbi_legacy_bank_transactions',
            'vw_powerbi_legacy_shop_sim_incentives',
            'vw_powerbi_legacy_float_incentives',
            'vw_powerbi_legacy_float_returns',
        ];

        $tableQuoted = implode(',', array_map(static fn(string $name): string => $connection->quote($name), $tables));
        $viewQuoted = implode(',', array_map(static fn(string $name): string => $connection->quote($name), $views));

        $tableCount = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_type='BASE TABLE'
               AND table_name IN($tableQuoted)"
        )->fetchColumn();
        $viewCount = (int) $connection->query(
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
            'Migration 100 found a partial Power BI legacy-history reporting layer.'
        );
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE bi_powerbi_legacy_import_batches (
    batch_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    dataset_code VARCHAR(64) NOT NULL,
    source_file_name VARCHAR(255) NOT NULL,
    source_sha256 CHAR(64) NOT NULL,
    source_row_count INT UNSIGNED NOT NULL,
    min_report_date DATE NULL,
    max_report_date DATE NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (batch_id),
    UNIQUE KEY uq_powerbi_legacy_batch_source (company_id,dataset_code,source_sha256),
    KEY idx_powerbi_legacy_batch_dates (company_id,dataset_code,min_report_date,max_report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE bi_powerbi_legacy_stock_rows (
    row_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    source_row_number INT UNSIGNED NOT NULL,
    shop_manager_label VARCHAR(255) NULL,
    shop_location VARCHAR(255) NULL,
    employee_name VARCHAR(255) NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    report_date DATE NOT NULL,
    auto_beginning_stock DECIMAL(20,4) NOT NULL,
    total_received DECIMAL(20,4) NOT NULL,
    total_sold DECIMAL(20,4) NOT NULL,
    closing_stock DECIMAL(20,4) NOT NULL,
    raw_payload JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (row_id),
    UNIQUE KEY uq_powerbi_legacy_stock_source (batch_id,source_row_number),
    KEY idx_powerbi_legacy_stock_date (company_id,report_date),
    KEY idx_powerbi_legacy_stock_shop_date (company_id,shop_manager_label,report_date),
    KEY idx_powerbi_legacy_stock_employee_date (company_id,employee_name,report_date),
    KEY idx_powerbi_legacy_stock_product_date (company_id,product_name,report_date),
    CONSTRAINT fk_powerbi_legacy_stock_batch
        FOREIGN KEY (batch_id) REFERENCES bi_powerbi_legacy_import_batches(batch_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE bi_powerbi_legacy_daily_shop_assets (
    row_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    source_row_number INT UNSIGNED NOT NULL,
    shop_manager VARCHAR(255) NULL,
    report_date DATE NOT NULL,
    stock_carry_forward_check VARCHAR(80) NULL,
    beginning_stock_selected_period DECIMAL(20,4) NULL,
    total_received_daily DECIMAL(20,4) NULL,
    available_for_sale_daily DECIMAL(20,4) NULL,
    total_sold_daily DECIMAL(20,4) NULL,
    incentive_sim_cards_birr DECIMAL(20,4) NULL,
    total_stock_daily DECIMAL(20,4) NULL,
    total_deposit_daily DECIMAL(20,4) NULL,
    cash_variance_daily DECIMAL(20,4) NULL,
    float_airtime_incentive_daily DECIMAL(20,4) NULL,
    stock_reconciliation_daily VARCHAR(80) NULL,
    total_sold_difference_daily DECIMAL(20,4) NULL,
    deposit_difference_daily VARCHAR(80) NULL,
    float_available_for_sale_daily DECIMAL(20,4) NULL,
    float_deposit_daily DECIMAL(20,4) NULL,
    float_closing_stock_daily DECIMAL(20,4) NULL,
    raw_payload JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (row_id),
    UNIQUE KEY uq_powerbi_legacy_daily_assets_source (batch_id,source_row_number),
    KEY idx_powerbi_legacy_daily_assets_date (company_id,report_date),
    KEY idx_powerbi_legacy_daily_assets_shop_date (company_id,shop_manager,report_date),
    CONSTRAINT fk_powerbi_legacy_daily_assets_batch
        FOREIGN KEY (batch_id) REFERENCES bi_powerbi_legacy_import_batches(batch_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE bi_powerbi_legacy_bank_transactions (
    row_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    source_row_number INT UNSIGNED NOT NULL,
    shop_manager VARCHAR(255) NULL,
    employee_name VARCHAR(255) NOT NULL,
    report_date DATE NOT NULL,
    bank_transaction_id VARCHAR(255) NULL,
    total_cash_deposit_birr DECIMAL(20,4) NULL,
    raw_payload JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (row_id),
    UNIQUE KEY uq_powerbi_legacy_bank_source (batch_id,source_row_number),
    KEY idx_powerbi_legacy_bank_date (company_id,report_date),
    KEY idx_powerbi_legacy_bank_shop_date (company_id,shop_manager,report_date),
    KEY idx_powerbi_legacy_bank_employee_date (company_id,employee_name,report_date),
    KEY idx_powerbi_legacy_bank_transaction (company_id,bank_transaction_id),
    CONSTRAINT fk_powerbi_legacy_bank_batch
        FOREIGN KEY (batch_id) REFERENCES bi_powerbi_legacy_import_batches(batch_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE bi_powerbi_legacy_shop_sim_incentives (
    row_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    source_row_number INT UNSIGNED NOT NULL,
    shop_manager VARCHAR(255) NULL,
    report_date DATE NOT NULL,
    reward_sim_cards_pieces DECIMAL(20,4) NULL,
    incentive_sim_cards_birr DECIMAL(20,4) NULL,
    raw_payload JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (row_id),
    UNIQUE KEY uq_powerbi_legacy_sim_source (batch_id,source_row_number),
    KEY idx_powerbi_legacy_sim_date (company_id,report_date),
    KEY idx_powerbi_legacy_sim_shop_date (company_id,shop_manager,report_date),
    CONSTRAINT fk_powerbi_legacy_sim_batch
        FOREIGN KEY (batch_id) REFERENCES bi_powerbi_legacy_import_batches(batch_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE bi_powerbi_legacy_float_incentives (
    row_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    source_row_number INT UNSIGNED NOT NULL,
    shop_manager VARCHAR(255) NULL,
    report_date DATE NOT NULL,
    float_incentive_airtime_to_safaricom_birr DECIMAL(20,4) NULL,
    raw_payload JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (row_id),
    UNIQUE KEY uq_powerbi_legacy_float_incentive_source (batch_id,source_row_number),
    KEY idx_powerbi_legacy_float_incentive_date (company_id,report_date),
    KEY idx_powerbi_legacy_float_incentive_shop_date (company_id,shop_manager,report_date),
    CONSTRAINT fk_powerbi_legacy_float_incentive_batch
        FOREIGN KEY (batch_id) REFERENCES bi_powerbi_legacy_import_batches(batch_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE bi_powerbi_legacy_float_returns (
    row_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    source_row_number INT UNSIGNED NOT NULL,
    shop_manager VARCHAR(255) NULL,
    report_date DATE NOT NULL,
    total_float_returned_birr DECIMAL(20,4) NULL,
    raw_payload JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (row_id),
    UNIQUE KEY uq_powerbi_legacy_float_return_source (batch_id,source_row_number),
    KEY idx_powerbi_legacy_float_return_date (company_id,report_date),
    KEY idx_powerbi_legacy_float_return_shop_date (company_id,shop_manager,report_date),
    CONSTRAINT fk_powerbi_legacy_float_return_batch
        FOREIGN KEY (batch_id) REFERENCES bi_powerbi_legacy_import_batches(batch_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE bi_powerbi_reporting_control (
    company_id BIGINT UNSIGNED NOT NULL,
    reporting_mode VARCHAR(20) NOT NULL DEFAULT 'HISTORY_ONLY',
    live_cutover_date DATE NULL,
    notes VARCHAR(1000) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id),
    CONSTRAINT ck_powerbi_reporting_mode
        CHECK (reporting_mode IN ('HISTORY_ONLY','HYBRID','LIVE_ONLY')),
    CONSTRAINT ck_powerbi_reporting_cutover
        CHECK (reporting_mode='HISTORY_ONLY' OR live_cutover_date IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO bi_powerbi_reporting_control(company_id,reporting_mode,live_cutover_date,notes)
VALUES(2,'HISTORY_ONLY',NULL,'Legacy Power BI exports are authoritative until ERP live cutover is explicitly approved.')
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_legacy_stock_history AS
SELECT r.company_id,r.source_row_number,r.shop_manager_label,r.shop_location,
       r.employee_name,r.product_name,r.report_date,r.auto_beginning_stock,
       r.total_received,r.total_sold,r.closing_stock,
       b.source_file_name,b.source_sha256,b.imported_at,
       'POWERBI_HISTORY' AS SourceSystem
FROM bi_powerbi_legacy_stock_rows r
INNER JOIN bi_powerbi_legacy_import_batches b ON b.batch_id=r.batch_id
WHERE r.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_legacy_daily_shop_assets AS
SELECT r.company_id,r.source_row_number,r.shop_manager,r.report_date,
       r.stock_carry_forward_check,r.beginning_stock_selected_period,
       r.total_received_daily,r.available_for_sale_daily,r.total_sold_daily,
       r.incentive_sim_cards_birr,r.total_stock_daily,r.total_deposit_daily,
       r.cash_variance_daily,r.float_airtime_incentive_daily,
       r.stock_reconciliation_daily,r.total_sold_difference_daily,
       r.deposit_difference_daily,r.float_available_for_sale_daily,
       r.float_deposit_daily,r.float_closing_stock_daily,
       b.source_file_name,b.source_sha256,b.imported_at,
       'POWERBI_HISTORY' AS SourceSystem
FROM bi_powerbi_legacy_daily_shop_assets r
INNER JOIN bi_powerbi_legacy_import_batches b ON b.batch_id=r.batch_id
WHERE r.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_legacy_bank_transactions AS
SELECT r.company_id,r.source_row_number,r.shop_manager,r.employee_name,
       r.report_date,r.bank_transaction_id,r.total_cash_deposit_birr,
       b.source_file_name,b.source_sha256,b.imported_at,
       'POWERBI_HISTORY' AS SourceSystem
FROM bi_powerbi_legacy_bank_transactions r
INNER JOIN bi_powerbi_legacy_import_batches b ON b.batch_id=r.batch_id
WHERE r.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_legacy_shop_sim_incentives AS
SELECT r.company_id,r.source_row_number,r.shop_manager,r.report_date,
       r.reward_sim_cards_pieces,r.incentive_sim_cards_birr,
       b.source_file_name,b.source_sha256,b.imported_at,
       'POWERBI_HISTORY' AS SourceSystem
FROM bi_powerbi_legacy_shop_sim_incentives r
INNER JOIN bi_powerbi_legacy_import_batches b ON b.batch_id=r.batch_id
WHERE r.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_legacy_float_incentives AS
SELECT r.company_id,r.source_row_number,r.shop_manager,r.report_date,
       r.float_incentive_airtime_to_safaricom_birr,
       b.source_file_name,b.source_sha256,b.imported_at,
       'POWERBI_HISTORY' AS SourceSystem
FROM bi_powerbi_legacy_float_incentives r
INNER JOIN bi_powerbi_legacy_import_batches b ON b.batch_id=r.batch_id
WHERE r.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_legacy_float_returns AS
SELECT r.company_id,r.source_row_number,r.shop_manager,r.report_date,
       r.total_float_returned_birr,
       b.source_file_name,b.source_sha256,b.imported_at,
       'POWERBI_HISTORY' AS SourceSystem
FROM bi_powerbi_legacy_float_returns r
INNER JOIN bi_powerbi_legacy_import_batches b ON b.batch_id=r.batch_id
WHERE r.company_id=2
SQL,
    ],
];
