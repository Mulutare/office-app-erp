<?php

declare(strict_types=1);

return [
    'version' => '057',
    'description' => 'Add perpetual weighted-average inventory valuation layers and accounting links',
    'statements' => [
        <<<'SQL'
CREATE TABLE inventory_valuation_layers (
    valuation_layer_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NULL,
    location_id BIGINT UNSIGNED NULL,
    stock_movement_id BIGINT UNSIGNED NOT NULL,
    movement_type VARCHAR(40) NOT NULL,
    source_document_type VARCHAR(60) NOT NULL,
    source_document_id BIGINT UNSIGNED NULL,
    source_document_reference VARCHAR(120) NULL,
    quantity DECIMAL(18,3) NOT NULL,
    unit_cost DECIMAL(18,6) NOT NULL,
    total_value DECIMAL(18,2) NOT NULL,
    currency CHAR(3) NOT NULL,
    costing_method VARCHAR(20) NOT NULL DEFAULT 'weighted_average',
    posting_date DATE NOT NULL,
    journal_batch_id BIGINT UNSIGNED NULL,
    reversal_of_layer_id BIGINT UNSIGNED NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_inventory_valuation_company_layer UNIQUE (company_id, valuation_layer_id),
    CONSTRAINT uq_inventory_valuation_movement UNIQUE (company_id, stock_movement_id),
    CONSTRAINT uq_inventory_valuation_key UNIQUE (company_id, idempotency_key),
    CONSTRAINT fk_inventory_valuation_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_valuation_product FOREIGN KEY (company_id, product_id) REFERENCES sales_products(company_id, product_id),
    CONSTRAINT fk_inventory_valuation_movement FOREIGN KEY (company_id, stock_movement_id) REFERENCES inventory_stock_movements(company_id, movement_id),
    CONSTRAINT fk_inventory_valuation_journal FOREIGN KEY (company_id, journal_batch_id) REFERENCES finance_journal_batches(company_id, journal_batch_id),
    CONSTRAINT fk_inventory_valuation_reversal FOREIGN KEY (company_id, reversal_of_layer_id) REFERENCES inventory_valuation_layers(company_id, valuation_layer_id),
    CONSTRAINT fk_inventory_valuation_actor FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT ck_inventory_valuation_quantity CHECK (quantity <> 0),
    CONSTRAINT ck_inventory_valuation_cost CHECK (unit_cost >= 0),
    CONSTRAINT ck_inventory_valuation_value CHECK (ABS(total_value - ROUND(quantity * unit_cost, 2)) <= 0.01),
    CONSTRAINT ck_inventory_valuation_currency CHECK (currency REGEXP '^[A-Z]{3}$'),
    CONSTRAINT ck_inventory_valuation_method CHECK (costing_method IN ('weighted_average','fifo','standard')),
    INDEX idx_inventory_valuation_reconcile (company_id, currency, posting_date),
    INDEX idx_inventory_valuation_product (company_id, product_id, posting_date),
    INDEX idx_inventory_valuation_source (company_id, source_document_type, source_document_id),
    INDEX idx_inventory_valuation_journal (company_id, journal_batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
