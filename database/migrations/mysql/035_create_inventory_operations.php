<?php

declare(strict_types=1);

return [
    'version' => '035',
    'description' =>
        'Create tenant-scoped inventory operation documents',
    'preflight' => static function (
        \PDO $connection
    ): string {
        $count = (int) $connection->query(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN (
                    'inventory_goods_receipts',
                    'inventory_goods_receipt_lines',
                    'inventory_stock_adjustments',
                    'inventory_stock_adjustment_lines',
                    'inventory_transfers',
                    'inventory_transfer_lines'
               )"
        )->fetchColumn();

        if ($count === 0) {
            return 'apply';
        }

        if ($count === 6) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 035 found a partial inventory operations schema.'
        );
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE inventory_goods_receipts (
    goods_receipt_id BIGINT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    receipt_number VARCHAR(50) NOT NULL,
    supplier_name VARCHAR(160) NULL,
    supplier_reference VARCHAR(100) NULL,
    receipt_date DATE NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'ETB',
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    notes VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    posted_by BIGINT UNSIGNED NULL,
    posted_at DATETIME NULL,
    cancelled_by BIGINT UNSIGNED NULL,
    cancelled_at DATETIME NULL,
    cancellation_reason VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_inventory_goods_receipt_number
        UNIQUE (company_id, receipt_number),
    CONSTRAINT uq_inventory_goods_receipt_identity
        UNIQUE (
            company_id,
            warehouse_id,
            goods_receipt_id
        ),
    CONSTRAINT ck_inventory_goods_receipt_status
        CHECK (
            status IN (
                'draft',
                'submitted',
                'approved',
                'posted',
                'cancelled'
            )
        ),
    CONSTRAINT ck_inventory_goods_receipt_currency
        CHECK (CHAR_LENGTH(currency) = 3),
    CONSTRAINT fk_inventory_goods_receipt_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_goods_receipt_warehouse
        FOREIGN KEY (company_id, warehouse_id)
        REFERENCES inventory_warehouses(
            company_id,
            warehouse_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_goods_receipt_creator
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_inventory_goods_receipt_approver
        FOREIGN KEY (approved_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_inventory_goods_receipt_poster
        FOREIGN KEY (posted_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_inventory_goods_receipt_canceller
        FOREIGN KEY (cancelled_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    INDEX idx_inventory_goods_receipt_status (
        company_id,
        status,
        receipt_date
    ),
    INDEX idx_inventory_goods_receipt_warehouse (
        company_id,
        warehouse_id,
        receipt_date
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_goods_receipt_lines (
    goods_receipt_line_id BIGINT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    goods_receipt_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(18,3) NOT NULL,
    unit_cost DECIMAL(18,6) NOT NULL,
    line_value DECIMAL(20,6)
        GENERATED ALWAYS AS (
            quantity * unit_cost
        ) STORED,
    notes VARCHAR(255) NULL,
    CONSTRAINT uq_inventory_goods_receipt_line_identity
        UNIQUE (company_id, goods_receipt_line_id),
    CONSTRAINT ck_inventory_goods_receipt_line_values
        CHECK (
            quantity > 0
            AND unit_cost >= 0
        ),
    CONSTRAINT fk_inventory_goods_receipt_line_receipt
        FOREIGN KEY (
            company_id,
            warehouse_id,
            goods_receipt_id
        )
        REFERENCES inventory_goods_receipts(
            company_id,
            warehouse_id,
            goods_receipt_id
        )
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_goods_receipt_line_location
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
    CONSTRAINT fk_inventory_goods_receipt_line_product
        FOREIGN KEY (
            company_id,
            product_id
        )
        REFERENCES sales_products(
            company_id,
            product_id
        )
        ON DELETE RESTRICT,
    INDEX idx_inventory_goods_receipt_line_receipt (
        company_id,
        goods_receipt_id
    ),
    INDEX idx_inventory_goods_receipt_line_product (
        company_id,
        product_id,
        warehouse_id
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_stock_adjustments (
    adjustment_id BIGINT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    adjustment_number VARCHAR(50) NOT NULL,
    adjustment_date DATE NOT NULL,
    reason_code VARCHAR(40) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    notes VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    posted_by BIGINT UNSIGNED NULL,
    posted_at DATETIME NULL,
    cancelled_by BIGINT UNSIGNED NULL,
    cancelled_at DATETIME NULL,
    cancellation_reason VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_inventory_adjustment_number
        UNIQUE (company_id, adjustment_number),
    CONSTRAINT uq_inventory_adjustment_identity
        UNIQUE (
            company_id,
            warehouse_id,
            adjustment_id
        ),
    CONSTRAINT ck_inventory_adjustment_status
        CHECK (
            status IN (
                'draft',
                'submitted',
                'approved',
                'posted',
                'cancelled'
            )
        ),
    CONSTRAINT fk_inventory_adjustment_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_adjustment_warehouse
        FOREIGN KEY (company_id, warehouse_id)
        REFERENCES inventory_warehouses(
            company_id,
            warehouse_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_adjustment_creator
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_inventory_adjustment_approver
        FOREIGN KEY (approved_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_inventory_adjustment_poster
        FOREIGN KEY (posted_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_inventory_adjustment_canceller
        FOREIGN KEY (cancelled_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    INDEX idx_inventory_adjustment_status (
        company_id,
        status,
        adjustment_date
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_stock_adjustment_lines (
    adjustment_line_id BIGINT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    adjustment_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity_delta DECIMAL(18,3) NOT NULL,
    unit_cost DECIMAL(18,6) NOT NULL DEFAULT 0,
    notes VARCHAR(255) NULL,
    CONSTRAINT uq_inventory_adjustment_line_identity
        UNIQUE (company_id, adjustment_line_id),
    CONSTRAINT ck_inventory_adjustment_line_quantity
        CHECK (quantity_delta <> 0),
    CONSTRAINT ck_inventory_adjustment_line_cost
        CHECK (unit_cost >= 0),
    CONSTRAINT fk_inventory_adjustment_line_header
        FOREIGN KEY (
            company_id,
            warehouse_id,
            adjustment_id
        )
        REFERENCES inventory_stock_adjustments(
            company_id,
            warehouse_id,
            adjustment_id
        )
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_adjustment_line_location
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
    CONSTRAINT fk_inventory_adjustment_line_product
        FOREIGN KEY (
            company_id,
            product_id
        )
        REFERENCES sales_products(
            company_id,
            product_id
        )
        ON DELETE RESTRICT,
    INDEX idx_inventory_adjustment_line_header (
        company_id,
        adjustment_id
    ),
    INDEX idx_inventory_adjustment_line_product (
        company_id,
        product_id,
        warehouse_id
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_transfers (
    transfer_id BIGINT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    source_warehouse_id BIGINT UNSIGNED NOT NULL,
    destination_warehouse_id BIGINT UNSIGNED NOT NULL,
    transfer_number VARCHAR(50) NOT NULL,
    transfer_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    notes VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    posted_by BIGINT UNSIGNED NULL,
    posted_at DATETIME NULL,
    cancelled_by BIGINT UNSIGNED NULL,
    cancelled_at DATETIME NULL,
    cancellation_reason VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_inventory_transfer_number
        UNIQUE (company_id, transfer_number),
    CONSTRAINT uq_inventory_transfer_identity
        UNIQUE (
            company_id,
            source_warehouse_id,
            destination_warehouse_id,
            transfer_id
        ),
    CONSTRAINT ck_inventory_transfer_status
        CHECK (
            status IN (
                'draft',
                'submitted',
                'approved',
                'posted',
                'cancelled'
            )
        ),
    CONSTRAINT ck_inventory_transfer_warehouses
        CHECK (
            source_warehouse_id <>
            destination_warehouse_id
        ),
    CONSTRAINT fk_inventory_transfer_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_transfer_source
        FOREIGN KEY (
            company_id,
            source_warehouse_id
        )
        REFERENCES inventory_warehouses(
            company_id,
            warehouse_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_transfer_destination
        FOREIGN KEY (
            company_id,
            destination_warehouse_id
        )
        REFERENCES inventory_warehouses(
            company_id,
            warehouse_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_transfer_creator
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_inventory_transfer_approver
        FOREIGN KEY (approved_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_inventory_transfer_poster
        FOREIGN KEY (posted_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_inventory_transfer_canceller
        FOREIGN KEY (cancelled_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    INDEX idx_inventory_transfer_status (
        company_id,
        status,
        transfer_date
    ),
    INDEX idx_inventory_transfer_source (
        company_id,
        source_warehouse_id,
        transfer_date
    ),
    INDEX idx_inventory_transfer_destination (
        company_id,
        destination_warehouse_id,
        transfer_date
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_transfer_lines (
    transfer_line_id BIGINT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    transfer_id BIGINT UNSIGNED NOT NULL,
    source_warehouse_id BIGINT UNSIGNED NOT NULL,
    source_location_id BIGINT UNSIGNED NOT NULL,
    destination_warehouse_id BIGINT UNSIGNED NOT NULL,
    destination_location_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(18,3) NOT NULL,
    unit_cost DECIMAL(18,6) NOT NULL DEFAULT 0,
    notes VARCHAR(255) NULL,
    CONSTRAINT uq_inventory_transfer_line_identity
        UNIQUE (company_id, transfer_line_id),
    CONSTRAINT ck_inventory_transfer_line_quantity
        CHECK (quantity > 0),
    CONSTRAINT ck_inventory_transfer_line_cost
        CHECK (unit_cost >= 0),
    CONSTRAINT fk_inventory_transfer_line_header
        FOREIGN KEY (
            company_id,
            source_warehouse_id,
            destination_warehouse_id,
            transfer_id
        )
        REFERENCES inventory_transfers(
            company_id,
            source_warehouse_id,
            destination_warehouse_id,
            transfer_id
        )
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_transfer_line_source
        FOREIGN KEY (
            company_id,
            source_warehouse_id,
            source_location_id
        )
        REFERENCES inventory_warehouse_locations(
            company_id,
            warehouse_id,
            location_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_transfer_line_destination
        FOREIGN KEY (
            company_id,
            destination_warehouse_id,
            destination_location_id
        )
        REFERENCES inventory_warehouse_locations(
            company_id,
            warehouse_id,
            location_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_transfer_line_product
        FOREIGN KEY (
            company_id,
            product_id
        )
        REFERENCES sales_products(
            company_id,
            product_id
        )
        ON DELETE RESTRICT,
    INDEX idx_inventory_transfer_line_header (
        company_id,
        transfer_id
    ),
    INDEX idx_inventory_transfer_line_product (
        company_id,
        product_id
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];