<?php

declare(strict_types=1);

return [
    'version' => '034',
    'description' =>
        'Create tenant-scoped inventory balances and movement ledger',
    'preflight' => static function (
        \PDO $connection
    ): string {
        $tables = (int) $connection->query(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN (
                    'inventory_stock_balances',
                    'inventory_stock_movements'
               )"
        )->fetchColumn();

        $productIdentity = (int) $connection->query(
            "SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'sales_products'
               AND index_name = 'uq_sales_product_identity'"
        )->fetchColumn();

        if ($tables === 0 && $productIdentity === 0) {
            return 'apply';
        }

        if ($tables === 2 && $productIdentity > 0) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 034 found a partial inventory stock schema.'
        );
    },
    'statements' => [
        <<<'SQL'
ALTER TABLE sales_products
    ADD CONSTRAINT uq_sales_product_identity
        UNIQUE (company_id, product_id)
SQL,
        <<<'SQL'
CREATE TABLE inventory_stock_balances (
    stock_balance_id BIGINT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity_on_hand DECIMAL(18,3) NOT NULL DEFAULT 0,
    quantity_reserved DECIMAL(18,3) NOT NULL DEFAULT 0,
    quantity_available DECIMAL(18,3)
        GENERATED ALWAYS AS (
            quantity_on_hand - quantity_reserved
        ) STORED,
    average_unit_cost DECIMAL(18,6) NOT NULL DEFAULT 0,
    inventory_value DECIMAL(20,6)
        GENERATED ALWAYS AS (
            quantity_on_hand * average_unit_cost
        ) STORED,
    version_number BIGINT UNSIGNED NOT NULL DEFAULT 1,
    last_movement_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_inventory_stock_balance
        UNIQUE (
            company_id,
            warehouse_id,
            location_id,
            product_id
        ),
    CONSTRAINT uq_inventory_stock_balance_identity
        UNIQUE (
            company_id,
            stock_balance_id
        ),
    CONSTRAINT ck_inventory_stock_reserved
        CHECK (quantity_reserved >= 0),
    CONSTRAINT ck_inventory_stock_cost
        CHECK (average_unit_cost >= 0),
    CONSTRAINT ck_inventory_stock_version
        CHECK (version_number > 0),
    CONSTRAINT fk_inventory_stock_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_stock_warehouse
        FOREIGN KEY (
            company_id,
            warehouse_id
        )
        REFERENCES inventory_warehouses(
            company_id,
            warehouse_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_stock_location
        FOREIGN KEY (
            company_id,
            warehouse_id,
            location_id
        )
        REFERENCES inventory_warehouse_locations(
            company_id,
            warehouse_id,
            location_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_stock_product
        FOREIGN KEY (
            company_id,
            product_id
        )
        REFERENCES sales_products(
            company_id,
            product_id
        )
        ON DELETE RESTRICT,
    INDEX idx_inventory_stock_product (
        company_id,
        product_id,
        warehouse_id
    ),
    INDEX idx_inventory_stock_available (
        company_id,
        warehouse_id,
        product_id,
        quantity_available
    ),
    INDEX idx_inventory_stock_location (
        company_id,
        warehouse_id,
        location_id,
        product_id
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_stock_movements (
    movement_id BIGINT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    movement_type VARCHAR(30) NOT NULL,
    quantity_delta DECIMAL(18,3) NOT NULL,
    unit_cost DECIMAL(18,6) NOT NULL DEFAULT 0,
    movement_value DECIMAL(20,6)
        GENERATED ALWAYS AS (
            quantity_delta * unit_cost
        ) STORED,
    currency CHAR(3) NOT NULL DEFAULT 'ETB',
    reference_type VARCHAR(40) NOT NULL,
    reference_id BIGINT UNSIGNED NULL,
    reference_number VARCHAR(80) NULL,
    related_movement_id BIGINT UNSIGNED NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    notes VARCHAR(500) NULL,
    occurred_at DATETIME NOT NULL,
    recorded_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_inventory_movement_identity
        UNIQUE (company_id, movement_id),
    CONSTRAINT uq_inventory_movement_idempotency
        UNIQUE (company_id, idempotency_key),
    CONSTRAINT ck_inventory_movement_type
        CHECK (
            movement_type IN (
                'opening',
                'receipt',
                'issue',
                'transfer_out',
                'transfer_in',
                'adjustment_in',
                'adjustment_out',
                'return_in',
                'return_out',
                'fulfilment'
            )
        ),
    CONSTRAINT ck_inventory_movement_quantity
        CHECK (quantity_delta <> 0),
    CONSTRAINT ck_inventory_movement_cost
        CHECK (unit_cost >= 0),
    CONSTRAINT ck_inventory_movement_currency
        CHECK (CHAR_LENGTH(currency) = 3),
    CONSTRAINT fk_inventory_movement_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_movement_warehouse
        FOREIGN KEY (
            company_id,
            warehouse_id
        )
        REFERENCES inventory_warehouses(
            company_id,
            warehouse_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_movement_location
        FOREIGN KEY (
            company_id,
            warehouse_id,
            location_id
        )
        REFERENCES inventory_warehouse_locations(
            company_id,
            warehouse_id,
            location_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_movement_product
        FOREIGN KEY (
            company_id,
            product_id
        )
        REFERENCES sales_products(
            company_id,
            product_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_movement_related
        FOREIGN KEY (
            company_id,
            related_movement_id
        )
        REFERENCES inventory_stock_movements(
            company_id,
            movement_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_movement_recorder
        FOREIGN KEY (recorded_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    INDEX idx_inventory_movement_stock_card (
        company_id,
        product_id,
        warehouse_id,
        location_id,
        occurred_at,
        movement_id
    ),
    INDEX idx_inventory_movement_reference (
        company_id,
        reference_type,
        reference_id
    ),
    INDEX idx_inventory_movement_date (
        company_id,
        occurred_at,
        movement_type
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];