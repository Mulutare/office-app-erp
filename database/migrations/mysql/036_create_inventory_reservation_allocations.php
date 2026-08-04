<?php

declare(strict_types=1);

return [
    'version' => '036',
    'description' =>
        'Create inventory sales reservation allocations',
    'preflight' => static function (
        \PDO $connection
    ): string {
        $exists = (int) $connection->query(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name =
                   'inventory_sales_reservation_allocations'"
        )->fetchColumn();

        if ($exists === 0) {
            return 'apply';
        }

        if ($exists === 1) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 036 found an invalid reservation allocation schema.'
        );
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE inventory_sales_reservation_allocations (
    allocation_id BIGINT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    commitment_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    stock_balance_id BIGINT UNSIGNED NOT NULL,
    quantity_reserved DECIMAL(18,3) NOT NULL,
    quantity_released DECIMAL(18,3)
        NOT NULL DEFAULT 0,
    quantity_fulfilled DECIMAL(18,3)
        NOT NULL DEFAULT 0,
    status VARCHAR(30)
        NOT NULL DEFAULT 'reserved',
    reserved_at DATETIME NOT NULL,
    released_at DATETIME NULL,
    fulfilled_at DATETIME NULL,
    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT uq_inventory_sales_allocation
        UNIQUE (
            company_id,
            commitment_id,
            stock_balance_id
        ),

    CONSTRAINT uq_inventory_sales_allocation_identity
        UNIQUE (
            company_id,
            allocation_id
        ),

    CONSTRAINT ck_inventory_sales_allocation_quantities
        CHECK (
            quantity_reserved > 0
            AND quantity_released >= 0
            AND quantity_fulfilled >= 0
            AND (
                quantity_released
                + quantity_fulfilled
            ) <= quantity_reserved
        ),

    CONSTRAINT ck_inventory_sales_allocation_status
        CHECK (
            status IN (
                'reserved',
                'partially_released',
                'released',
                'partially_fulfilled',
                'fulfilled',
                'cancelled'
            )
        ),

    CONSTRAINT fk_inventory_sales_allocation_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_inventory_sales_allocation_commitment
        FOREIGN KEY (commitment_id)
        REFERENCES inventory_sales_commitments(
            commitment_id
        )
        ON DELETE CASCADE,

    CONSTRAINT fk_inventory_sales_allocation_order
        FOREIGN KEY (order_id)
        REFERENCES sales_orders(order_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_inventory_sales_allocation_product
        FOREIGN KEY (
            company_id,
            product_id
        )
        REFERENCES sales_products(
            company_id,
            product_id
        )
        ON DELETE RESTRICT,

    CONSTRAINT fk_inventory_sales_allocation_warehouse
        FOREIGN KEY (
            company_id,
            warehouse_id
        )
        REFERENCES inventory_warehouses(
            company_id,
            warehouse_id
        )
        ON DELETE RESTRICT,

    CONSTRAINT fk_inventory_sales_allocation_location
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

    CONSTRAINT fk_inventory_sales_allocation_balance
        FOREIGN KEY (
            company_id,
            stock_balance_id
        )
        REFERENCES inventory_stock_balances(
            company_id,
            stock_balance_id
        )
        ON DELETE RESTRICT,

    INDEX idx_inventory_sales_allocation_order (
        company_id,
        order_id,
        status
    ),

    INDEX idx_inventory_sales_allocation_commitment (
        company_id,
        commitment_id,
        status
    ),

    INDEX idx_inventory_sales_allocation_product (
        company_id,
        product_id,
        warehouse_id,
        status
    ),

    INDEX idx_inventory_sales_allocation_location (
        company_id,
        warehouse_id,
        location_id,
        status
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];