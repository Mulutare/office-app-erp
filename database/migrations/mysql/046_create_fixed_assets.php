<?php

declare(strict_types=1);

return [
    'version' => '046',
    'description' => 'Create tenant-scoped fixed asset accounting and lifecycle core',
    'preflight' => static function (\PDO $connection): string {
        $tables = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name IN (
                'asset_categories','fixed_assets','asset_depreciation_schedule',
                'asset_transfers','asset_maintenance_records','asset_disposals','asset_history'
             )"
        )->fetchColumn();
        if ($tables === 0) return 'apply';
        if ($tables === 7) return 'baseline';
        throw new \RuntimeException('Migration 046 found a partial Fixed Assets schema.');
    },
    'statements' => [
        <<<'SQL'
INSERT INTO erp_modules(code,name,navigation_label,description,route_path,permission_namespace,icon_text,sort_order,available,active)
VALUES('assets','Fixed Assets','Assets','Fixed asset capitalization, depreciation, custody and disposal.','/assets','assets','FA',45,TRUE,TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),navigation_label=VALUES(navigation_label),description=VALUES(description),route_path=VALUES(route_path),permission_namespace=VALUES(permission_namespace),icon_text=VALUES(icon_text),sort_order=VALUES(sort_order),available=TRUE,active=TRUE
SQL,
        <<<'SQL'
INSERT INTO permissions(name,code,module,description,active) VALUES
('View Fixed Assets','assets.view','assets','View the company asset register and reports',TRUE),
('Manage Asset Drafts','assets.manage','assets','Create categories, assets, transfers and maintenance records',TRUE),
('Activate Fixed Assets','assets.activate','assets','Activate assets and generate depreciation schedules',TRUE),
('Post Asset Depreciation','assets.depreciation.post','assets','Post scheduled depreciation to Finance',TRUE),
('Capitalize Inventory as Assets','assets.inventory.capitalize','assets','Issue inventory into the fixed asset register',TRUE),
('Dispose Fixed Assets','assets.dispose','assets','Sell, scrap or write off active assets',TRUE),
('View Asset Reports','assets.reports.view','assets','View asset cost, depreciation and net book value reports',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module),description=VALUES(description),active=TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p
WHERE r.code IN('system_administrator','company_owner') AND p.code LIKE 'assets.%' AND p.active=TRUE
SQL,
        <<<'SQL'
CREATE TABLE asset_categories (
    asset_category_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    category_code VARCHAR(40) NOT NULL,
    category_name VARCHAR(140) NOT NULL,
    asset_account_id BIGINT UNSIGNED NOT NULL,
    accumulated_depreciation_account_id BIGINT UNSIGNED NOT NULL,
    depreciation_expense_account_id BIGINT UNSIGNED NOT NULL,
    disposal_gain_account_id BIGINT UNSIGNED NOT NULL,
    disposal_loss_account_id BIGINT UNSIGNED NOT NULL,
    depreciation_method VARCHAR(30) NOT NULL DEFAULT 'straight_line',
    useful_life_months SMALLINT UNSIGNED NOT NULL,
    depreciation_frequency VARCHAR(20) NOT NULL DEFAULT 'monthly',
    salvage_behavior VARCHAR(20) NOT NULL DEFAULT 'fixed',
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_asset_category_identity UNIQUE(company_id,asset_category_id),
    CONSTRAINT uq_asset_category_code UNIQUE(company_id,category_code),
    CONSTRAINT ck_asset_category_method CHECK(depreciation_method IN('straight_line')),
    CONSTRAINT ck_asset_category_life CHECK(useful_life_months BETWEEN 1 AND 1200),
    CONSTRAINT ck_asset_category_frequency CHECK(depreciation_frequency IN('monthly')),
    CONSTRAINT ck_asset_category_salvage CHECK(salvage_behavior IN('fixed','zero')),
    CONSTRAINT fk_asset_category_company FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_category_asset_account FOREIGN KEY(company_id,asset_account_id) REFERENCES finance_accounts(company_id,account_id) ON DELETE RESTRICT,
    CONSTRAINT fk_asset_category_accum_account FOREIGN KEY(company_id,accumulated_depreciation_account_id) REFERENCES finance_accounts(company_id,account_id) ON DELETE RESTRICT,
    CONSTRAINT fk_asset_category_expense_account FOREIGN KEY(company_id,depreciation_expense_account_id) REFERENCES finance_accounts(company_id,account_id) ON DELETE RESTRICT,
    CONSTRAINT fk_asset_category_gain_account FOREIGN KEY(company_id,disposal_gain_account_id) REFERENCES finance_accounts(company_id,account_id) ON DELETE RESTRICT,
    CONSTRAINT fk_asset_category_loss_account FOREIGN KEY(company_id,disposal_loss_account_id) REFERENCES finance_accounts(company_id,account_id) ON DELETE RESTRICT,
    CONSTRAINT fk_asset_category_creator FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_asset_category_updater FOREIGN KEY(updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE fixed_assets (
    asset_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    asset_category_id BIGINT UNSIGNED NOT NULL,
    asset_number VARCHAR(60) NOT NULL,
    asset_name VARCHAR(180) NOT NULL,
    department_id INT UNSIGNED NULL,
    custodian_employee_id BIGINT UNSIGNED NULL,
    location_name VARCHAR(160) NULL,
    vendor_name VARCHAR(180) NULL,
    acquisition_date DATE NOT NULL,
    in_service_date DATE NULL,
    acquisition_cost DECIMAL(18,2) NOT NULL,
    additional_capitalized_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
    salvage_value DECIMAL(18,2) NOT NULL DEFAULT 0,
    accumulated_depreciation DECIMAL(18,2) NOT NULL DEFAULT 0,
    book_value DECIMAL(18,2) NOT NULL,
    currency CHAR(3) NOT NULL,
    depreciation_method VARCHAR(30) NOT NULL,
    useful_life_months SMALLINT UNSIGNED NOT NULL,
    depreciation_frequency VARCHAR(20) NOT NULL,
    serial_number VARCHAR(120) NULL,
    product_id BIGINT UNSIGNED NULL,
    source_inventory_movement_id BIGINT UNSIGNED NULL,
    source_warehouse_id BIGINT UNSIGNED NULL,
    source_location_id BIGINT UNSIGNED NULL,
    source_quantity DECIMAL(18,3) NULL,
    acquisition_journal_batch_id BIGINT UNSIGNED NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    activated_by BIGINT UNSIGNED NULL,
    activated_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_fixed_asset_identity UNIQUE(company_id,asset_id),
    CONSTRAINT uq_fixed_asset_number UNIQUE(company_id,asset_number),
    CONSTRAINT uq_fixed_asset_serial UNIQUE(company_id,serial_number),
    CONSTRAINT ck_fixed_asset_status CHECK(status IN('draft','active','fully_depreciated','under_maintenance','disposed','sold','scrapped','cancelled')),
    CONSTRAINT ck_fixed_asset_cost CHECK(acquisition_cost>0 AND additional_capitalized_cost>=0 AND salvage_value>=0 AND accumulated_depreciation>=0 AND book_value>=0),
    CONSTRAINT ck_fixed_asset_salvage CHECK(salvage_value<=acquisition_cost+additional_capitalized_cost),
    CONSTRAINT ck_fixed_asset_currency CHECK(currency REGEXP '^[A-Z]{3}$'),
    CONSTRAINT ck_fixed_asset_source CHECK((product_id IS NULL AND source_inventory_movement_id IS NULL AND source_quantity IS NULL) OR (product_id IS NOT NULL AND source_inventory_movement_id IS NOT NULL AND source_quantity>0)),
    CONSTRAINT fk_fixed_asset_company FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_fixed_asset_category FOREIGN KEY(company_id,asset_category_id) REFERENCES asset_categories(company_id,asset_category_id) ON DELETE RESTRICT,
    CONSTRAINT fk_fixed_asset_department FOREIGN KEY(company_id,department_id) REFERENCES hr_departments(company_id,department_id) ON DELETE RESTRICT,
    CONSTRAINT fk_fixed_asset_custodian FOREIGN KEY(company_id,custodian_employee_id) REFERENCES hr_employees(company_id,employee_id) ON DELETE RESTRICT,
    CONSTRAINT fk_fixed_asset_product FOREIGN KEY(company_id,product_id) REFERENCES sales_products(company_id,product_id) ON DELETE RESTRICT,
    CONSTRAINT fk_fixed_asset_movement FOREIGN KEY(company_id,source_inventory_movement_id) REFERENCES inventory_stock_movements(company_id,movement_id) ON DELETE RESTRICT,
    CONSTRAINT fk_fixed_asset_acquisition_journal FOREIGN KEY(company_id,acquisition_journal_batch_id) REFERENCES finance_journal_batches(company_id,journal_batch_id) ON DELETE RESTRICT,
    CONSTRAINT fk_fixed_asset_source_location FOREIGN KEY(company_id,source_warehouse_id,source_location_id) REFERENCES inventory_warehouse_locations(company_id,warehouse_id,location_id) ON DELETE RESTRICT,
    CONSTRAINT fk_fixed_asset_activator FOREIGN KEY(activated_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_fixed_asset_creator FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_fixed_asset_updater FOREIGN KEY(updated_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_fixed_asset_register(company_id,status,asset_category_id,in_service_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE asset_depreciation_schedule (
    depreciation_line_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    asset_id BIGINT UNSIGNED NOT NULL,
    period_number SMALLINT UNSIGNED NOT NULL,
    depreciation_date DATE NOT NULL,
    depreciation_amount DECIMAL(18,2) NOT NULL,
    accumulated_amount DECIMAL(18,2) NOT NULL,
    book_value_after DECIMAL(18,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
    journal_batch_id BIGINT UNSIGNED NULL,
    posted_by BIGINT UNSIGNED NULL,
    posted_at DATETIME NULL,
    reversal_journal_batch_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_asset_depreciation_identity UNIQUE(company_id,depreciation_line_id),
    CONSTRAINT uq_asset_depreciation_period UNIQUE(company_id,asset_id,period_number),
    CONSTRAINT uq_asset_depreciation_date UNIQUE(company_id,asset_id,depreciation_date),
    CONSTRAINT ck_asset_depreciation_amount CHECK(depreciation_amount>0 AND accumulated_amount>0 AND book_value_after>=0),
    CONSTRAINT ck_asset_depreciation_status CHECK(status IN('scheduled','posted','reversed','cancelled')),
    CONSTRAINT fk_asset_depreciation_asset FOREIGN KEY(company_id,asset_id) REFERENCES fixed_assets(company_id,asset_id) ON DELETE RESTRICT,
    CONSTRAINT fk_asset_depreciation_journal FOREIGN KEY(company_id,journal_batch_id) REFERENCES finance_journal_batches(company_id,journal_batch_id) ON DELETE RESTRICT,
    CONSTRAINT fk_asset_depreciation_reversal FOREIGN KEY(company_id,reversal_journal_batch_id) REFERENCES finance_journal_batches(company_id,journal_batch_id) ON DELETE RESTRICT,
    CONSTRAINT fk_asset_depreciation_poster FOREIGN KEY(posted_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_asset_depreciation_due(company_id,status,depreciation_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE asset_transfers (
    asset_transfer_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    asset_id BIGINT UNSIGNED NOT NULL,
    from_department_id INT UNSIGNED NULL,
    to_department_id INT UNSIGNED NULL,
    from_custodian_employee_id BIGINT UNSIGNED NULL,
    to_custodian_employee_id BIGINT UNSIGNED NULL,
    from_location_name VARCHAR(160) NULL,
    to_location_name VARCHAR(160) NULL,
    transferred_at DATETIME NOT NULL,
    reason VARCHAR(500) NOT NULL,
    transferred_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_asset_transfer_identity UNIQUE(company_id,asset_transfer_id),
    CONSTRAINT fk_asset_transfer_asset FOREIGN KEY(company_id,asset_id) REFERENCES fixed_assets(company_id,asset_id) ON DELETE RESTRICT,
    CONSTRAINT fk_asset_transfer_from_department FOREIGN KEY(company_id,from_department_id) REFERENCES hr_departments(company_id,department_id) ON DELETE RESTRICT,
    CONSTRAINT fk_asset_transfer_to_department FOREIGN KEY(company_id,to_department_id) REFERENCES hr_departments(company_id,department_id) ON DELETE RESTRICT,
    CONSTRAINT fk_asset_transfer_from_custodian FOREIGN KEY(company_id,from_custodian_employee_id) REFERENCES hr_employees(company_id,employee_id) ON DELETE RESTRICT,
    CONSTRAINT fk_asset_transfer_to_custodian FOREIGN KEY(company_id,to_custodian_employee_id) REFERENCES hr_employees(company_id,employee_id) ON DELETE RESTRICT,
    CONSTRAINT fk_asset_transfer_actor FOREIGN KEY(transferred_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_asset_transfer_history(company_id,asset_id,transferred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE asset_maintenance_records (
    asset_maintenance_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    asset_id BIGINT UNSIGNED NOT NULL,
    maintenance_type VARCHAR(30) NOT NULL,
    description VARCHAR(500) NOT NULL,
    vendor_name VARCHAR(180) NULL,
    cost DECIMAL(18,2) NOT NULL DEFAULT 0,
    maintenance_date DATE NOT NULL,
    completed_date DATE NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'planned',
    capitalized BOOLEAN NOT NULL DEFAULT FALSE,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_asset_maintenance_identity UNIQUE(company_id,asset_maintenance_id),
    CONSTRAINT ck_asset_maintenance_type CHECK(maintenance_type IN('preventive','corrective','inspection','repair')),
    CONSTRAINT ck_asset_maintenance_cost CHECK(cost>=0),
    CONSTRAINT ck_asset_maintenance_status CHECK(status IN('planned','in_progress','completed','cancelled')),
    CONSTRAINT fk_asset_maintenance_asset FOREIGN KEY(company_id,asset_id) REFERENCES fixed_assets(company_id,asset_id) ON DELETE RESTRICT,
    CONSTRAINT fk_asset_maintenance_creator FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_asset_maintenance_history(company_id,asset_id,maintenance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE asset_disposals (
    asset_disposal_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    asset_id BIGINT UNSIGNED NOT NULL,
    disposal_type VARCHAR(20) NOT NULL,
    disposal_date DATE NOT NULL,
    proceeds_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    book_value_at_disposal DECIMAL(18,2) NOT NULL,
    gain_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    loss_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    journal_batch_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    disposed_by BIGINT UNSIGNED NULL,
    disposed_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_asset_disposal_identity UNIQUE(company_id,asset_disposal_id),
    CONSTRAINT uq_asset_disposal_once UNIQUE(company_id,asset_id),
    CONSTRAINT ck_asset_disposal_type CHECK(disposal_type IN('sale','scrap','write_off')),
    CONSTRAINT ck_asset_disposal_amounts CHECK(proceeds_amount>=0 AND book_value_at_disposal>=0 AND gain_amount>=0 AND loss_amount>=0 AND NOT(gain_amount>0 AND loss_amount>0)),
    CONSTRAINT fk_asset_disposal_asset FOREIGN KEY(company_id,asset_id) REFERENCES fixed_assets(company_id,asset_id) ON DELETE RESTRICT,
    CONSTRAINT fk_asset_disposal_journal FOREIGN KEY(company_id,journal_batch_id) REFERENCES finance_journal_batches(company_id,journal_batch_id) ON DELETE RESTRICT,
    CONSTRAINT fk_asset_disposal_actor FOREIGN KEY(disposed_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE asset_history (
    asset_history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    asset_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL,
    from_status VARCHAR(30) NULL,
    to_status VARCHAR(30) NULL,
    details_json LONGTEXT NULL,
    actor_id BIGINT UNSIGNED NULL,
    occurred_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_asset_history_identity UNIQUE(company_id,asset_history_id),
    CONSTRAINT fk_asset_history_asset FOREIGN KEY(company_id,asset_id) REFERENCES fixed_assets(company_id,asset_id) ON DELETE RESTRICT,
    CONSTRAINT fk_asset_history_actor FOREIGN KEY(actor_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_asset_history_timeline(company_id,asset_id,occurred_at,asset_history_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
