<?php

declare(strict_types=1);

return [
    'version' => '041',
    'description' =>
        'Add virtual locations, pickings, returns, adjustments and scrap execution',
    'preflight' => static function (\PDO $connection): string {
        $tables = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN (
                    'inventory_pickings',
                    'inventory_picking_lines',
                    'inventory_picking_completions',
                    'inventory_scrap_orders'
               )"
        )->fetchColumn();
        $usage = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'inventory_warehouse_locations'
               AND column_name = 'location_usage'"
        )->fetchColumn();

        if ($tables === 0 && $usage === 0) {
            return 'apply';
        }
        if ($tables === 4 && $usage === 1) {
            return 'baseline';
        }
        throw new \RuntimeException(
            'Migration 041 found a partial inventory execution schema.'
        );
    },
    'statements' => [
        <<<'SQL'
ALTER TABLE inventory_warehouse_locations
    DROP CONSTRAINT ck_inventory_location_type,
    ADD COLUMN location_usage VARCHAR(20) NOT NULL DEFAULT 'internal'
        AFTER location_type,
    ADD COLUMN is_virtual BOOLEAN
        GENERATED ALWAYS AS (location_usage <> 'internal') STORED
        AFTER location_usage,
    ADD CONSTRAINT ck_inventory_location_type CHECK (
        location_type IN (
            'zone', 'aisle', 'rack', 'shelf', 'bin', 'receiving',
            'dispatch', 'returns', 'quarantine', 'vendor', 'customer',
            'inventory', 'scrap', 'transit'
        )
    ),
    ADD CONSTRAINT ck_inventory_location_usage CHECK (
        location_usage IN ('internal', 'vendor', 'customer', 'inventory', 'scrap', 'transit')
    ),
    ADD CONSTRAINT uq_inventory_location_company_identity
        UNIQUE (company_id, location_id),
    ADD INDEX idx_inventory_location_usage (
        company_id, warehouse_id, location_usage, active
    )
SQL,
        <<<'SQL'
UPDATE inventory_warehouse_locations
SET location_usage = 'internal'
WHERE location_usage <> 'internal'
SQL,
        <<<'SQL'
INSERT INTO inventory_warehouse_locations (
    company_id, warehouse_id, parent_location_id, code, name,
    location_type, location_usage, pick_priority, receiving_allowed,
    picking_allowed, active, created_by, updated_by
)
SELECT
    warehouses.company_id,
    warehouses.warehouse_id,
    roots.location_id,
    CONCAT(warehouses.code, '/', virtuals.suffix),
    virtuals.location_name,
    virtuals.location_type,
    virtuals.location_usage,
    virtuals.priority,
    virtuals.receiving_allowed,
    virtuals.picking_allowed,
    TRUE,
    warehouses.created_by,
    warehouses.updated_by
FROM inventory_warehouses warehouses
INNER JOIN inventory_warehouse_locations roots
    ON roots.company_id = warehouses.company_id
   AND roots.warehouse_id = warehouses.warehouse_id
   AND roots.code = warehouses.code
   AND roots.parent_location_id IS NULL
CROSS JOIN (
    SELECT 'VENDOR' suffix, 'Vendors' location_name,
           'vendor' location_type, 'vendor' location_usage, 950 priority,
           TRUE receiving_allowed, TRUE picking_allowed
    UNION ALL SELECT 'CUSTOMER', 'Customers', 'customer', 'customer',
           951, TRUE, TRUE
    UNION ALL SELECT 'INVENTORY', 'Inventory Adjustment', 'inventory',
           'inventory', 952, TRUE, TRUE
    UNION ALL SELECT 'SCRAP', 'Scrap', 'scrap', 'scrap',
           953, TRUE, FALSE
    UNION ALL SELECT 'TRANSIT', 'Transit', 'transit', 'transit',
           954, TRUE, TRUE
) virtuals
WHERE warehouses.deleted_at IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM inventory_warehouse_locations existing
      WHERE existing.company_id = warehouses.company_id
        AND existing.warehouse_id = warehouses.warehouse_id
        AND existing.code = CONCAT(warehouses.code, '/', virtuals.suffix)
  )
SQL,
        <<<'SQL'
UPDATE inventory_operation_types operation_types
INNER JOIN inventory_warehouses warehouses
    ON warehouses.company_id = operation_types.company_id
   AND warehouses.warehouse_id = operation_types.warehouse_id
LEFT JOIN inventory_warehouse_locations vendor_locations
    ON vendor_locations.company_id = warehouses.company_id
   AND vendor_locations.warehouse_id = warehouses.warehouse_id
   AND vendor_locations.code = CONCAT(warehouses.code, '/VENDOR')
LEFT JOIN inventory_warehouse_locations customer_locations
    ON customer_locations.company_id = warehouses.company_id
   AND customer_locations.warehouse_id = warehouses.warehouse_id
   AND customer_locations.code = CONCAT(warehouses.code, '/CUSTOMER')
LEFT JOIN inventory_warehouse_locations inventory_locations
    ON inventory_locations.company_id = warehouses.company_id
   AND inventory_locations.warehouse_id = warehouses.warehouse_id
   AND inventory_locations.code = CONCAT(warehouses.code, '/INVENTORY')
SET operation_types.default_source_location_id = CASE
        WHEN operation_types.operation_kind = 'receipt'
            THEN vendor_locations.location_id
        WHEN operation_types.operation_kind = 'adjustment'
            THEN inventory_locations.location_id
        ELSE operation_types.default_source_location_id
    END,
    operation_types.default_destination_location_id = CASE
        WHEN operation_types.operation_kind = 'delivery'
            THEN customer_locations.location_id
        WHEN operation_types.operation_kind = 'adjustment'
            THEN inventory_locations.location_id
        ELSE operation_types.default_destination_location_id
    END
WHERE operation_types.is_default = TRUE
SQL,
        <<<'SQL'
CREATE TABLE inventory_pickings (
    picking_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    operation_type_id BIGINT UNSIGNED NOT NULL,
    sales_order_id BIGINT UNSIGNED NULL,
    original_picking_id BIGINT UNSIGNED NULL,
    backorder_of_id BIGINT UNSIGNED NULL,
    picking_type VARCHAR(30) NOT NULL,
    picking_number VARCHAR(60) NOT NULL,
    source_location_id BIGINT UNSIGNED NOT NULL,
    destination_location_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    notes VARCHAR(500) NULL,
    reserved_at DATETIME NULL,
    completed_at DATETIME NULL,
    completed_by BIGINT UNSIGNED NULL,
    cancelled_at DATETIME NULL,
    cancelled_by BIGINT UNSIGNED NULL,
    cancellation_reason VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_inventory_picking_number UNIQUE (company_id, picking_number),
    CONSTRAINT uq_inventory_picking_identity UNIQUE (company_id, picking_id),
    CONSTRAINT ck_inventory_picking_type CHECK (
        picking_type IN ('delivery', 'customer_return', 'vendor_return')
    ),
    CONSTRAINT ck_inventory_picking_status CHECK (
        status IN ('draft', 'ready', 'partially_done', 'done', 'cancelled')
    ),
    CONSTRAINT ck_inventory_picking_locations CHECK (
        source_location_id <> destination_location_id
    ),
    CONSTRAINT fk_inventory_picking_company FOREIGN KEY (company_id)
        REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_picking_warehouse
        FOREIGN KEY (company_id, warehouse_id)
        REFERENCES inventory_warehouses(company_id, warehouse_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_picking_operation
        FOREIGN KEY (company_id, warehouse_id, operation_type_id)
        REFERENCES inventory_operation_types(company_id, warehouse_id, operation_type_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_picking_source
        FOREIGN KEY (company_id, warehouse_id, source_location_id)
        REFERENCES inventory_warehouse_locations(company_id, warehouse_id, location_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_picking_destination
        FOREIGN KEY (company_id, warehouse_id, destination_location_id)
        REFERENCES inventory_warehouse_locations(company_id, warehouse_id, location_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_picking_sales_order
        FOREIGN KEY (sales_order_id) REFERENCES sales_orders(order_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_picking_original
        FOREIGN KEY (company_id, original_picking_id)
        REFERENCES inventory_pickings(company_id, picking_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_picking_backorder
        FOREIGN KEY (company_id, backorder_of_id)
        REFERENCES inventory_pickings(company_id, picking_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_picking_completer FOREIGN KEY (completed_by)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_inventory_picking_canceller FOREIGN KEY (cancelled_by)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_inventory_picking_creator FOREIGN KEY (created_by)
        REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_inventory_picking_status (company_id, status, picking_type),
    INDEX idx_inventory_picking_order (company_id, sales_order_id, picking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_picking_lines (
    picking_line_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    picking_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    source_location_id BIGINT UNSIGNED NOT NULL,
    destination_location_id BIGINT UNSIGNED NOT NULL,
    reservation_allocation_id BIGINT UNSIGNED NULL,
    original_picking_line_id BIGINT UNSIGNED NULL,
    requested_quantity DECIMAL(18,3) NOT NULL,
    reserved_quantity DECIMAL(18,3) NOT NULL DEFAULT 0,
    completed_quantity DECIMAL(18,3) NOT NULL DEFAULT 0,
    returned_quantity DECIMAL(18,3) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'ready',
    notes VARCHAR(255) NULL,
    CONSTRAINT uq_inventory_picking_line_identity UNIQUE (company_id, picking_line_id),
    CONSTRAINT ck_inventory_picking_line_quantities CHECK (
        requested_quantity > 0 AND reserved_quantity >= 0
        AND completed_quantity >= 0 AND completed_quantity <= requested_quantity
        AND returned_quantity >= 0 AND returned_quantity <= completed_quantity
    ),
    CONSTRAINT ck_inventory_picking_line_locations CHECK (
        source_location_id <> destination_location_id
    ),
    CONSTRAINT ck_inventory_picking_line_status CHECK (
        status IN ('ready', 'partially_done', 'done', 'cancelled')
    ),
    CONSTRAINT fk_inventory_picking_line_header
        FOREIGN KEY (company_id, picking_id)
        REFERENCES inventory_pickings(company_id, picking_id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_picking_line_product
        FOREIGN KEY (company_id, product_id)
        REFERENCES sales_products(company_id, product_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_picking_line_source
        FOREIGN KEY (company_id, source_location_id)
        REFERENCES inventory_warehouse_locations(company_id, location_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_picking_line_destination
        FOREIGN KEY (company_id, destination_location_id)
        REFERENCES inventory_warehouse_locations(company_id, location_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_picking_line_allocation
        FOREIGN KEY (reservation_allocation_id)
        REFERENCES inventory_sales_reservation_allocations(allocation_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_picking_line_original
        FOREIGN KEY (company_id, original_picking_line_id)
        REFERENCES inventory_picking_lines(company_id, picking_line_id)
        ON DELETE RESTRICT,
    INDEX idx_inventory_picking_line_header (company_id, picking_id),
    INDEX idx_inventory_picking_line_original (company_id, original_picking_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_picking_completions (
    picking_completion_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    picking_id BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    completed_quantity DECIMAL(18,3) NOT NULL,
    backorder_picking_id BIGINT UNSIGNED NULL,
    completed_by BIGINT UNSIGNED NOT NULL,
    completed_at DATETIME NOT NULL,
    CONSTRAINT uq_inventory_picking_completion UNIQUE (company_id, idempotency_key),
    CONSTRAINT ck_inventory_picking_completion_quantity CHECK (completed_quantity > 0),
    CONSTRAINT fk_inventory_picking_completion_header
        FOREIGN KEY (company_id, picking_id)
        REFERENCES inventory_pickings(company_id, picking_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_picking_completion_backorder
        FOREIGN KEY (company_id, backorder_picking_id)
        REFERENCES inventory_pickings(company_id, picking_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_picking_completion_actor FOREIGN KEY (completed_by)
        REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_scrap_orders (
    scrap_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    source_location_id BIGINT UNSIGNED NOT NULL,
    scrap_location_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    scrap_number VARCHAR(60) NOT NULL,
    quantity DECIMAL(18,3) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    posted_at DATETIME NULL,
    posted_by BIGINT UNSIGNED NULL,
    cancelled_at DATETIME NULL,
    cancelled_by BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_inventory_scrap_number UNIQUE (company_id, scrap_number),
    CONSTRAINT ck_inventory_scrap_quantity CHECK (quantity > 0),
    CONSTRAINT ck_inventory_scrap_status CHECK (status IN ('draft', 'done', 'cancelled')),
    CONSTRAINT ck_inventory_scrap_locations CHECK (source_location_id <> scrap_location_id),
    CONSTRAINT fk_inventory_scrap_warehouse
        FOREIGN KEY (company_id, warehouse_id)
        REFERENCES inventory_warehouses(company_id, warehouse_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_scrap_source
        FOREIGN KEY (company_id, warehouse_id, source_location_id)
        REFERENCES inventory_warehouse_locations(company_id, warehouse_id, location_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_scrap_destination
        FOREIGN KEY (company_id, warehouse_id, scrap_location_id)
        REFERENCES inventory_warehouse_locations(company_id, warehouse_id, location_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_scrap_product
        FOREIGN KEY (company_id, product_id)
        REFERENCES sales_products(company_id, product_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_scrap_poster FOREIGN KEY (posted_by)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_inventory_scrap_canceller FOREIGN KEY (cancelled_by)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_inventory_scrap_creator FOREIGN KEY (created_by)
        REFERENCES users(user_id) ON DELETE RESTRICT,
    INDEX idx_inventory_scrap_status (company_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
ALTER TABLE inventory_stock_adjustment_lines
    ADD COLUMN expected_quantity DECIMAL(18,3) NULL AFTER product_id,
    ADD COLUMN counted_quantity DECIMAL(18,3) NULL AFTER expected_quantity
SQL,
        <<<'SQL'
ALTER TABLE sales_orders
    DROP CONSTRAINT ck_sales_order_status,
    ADD CONSTRAINT ck_sales_order_status CHECK (
        status IN (
            'draft', 'submitted', 'confirmed', 'approved',
            'partially_fulfilled', 'partially_paid', 'paid',
            'fulfilled', 'cancelled', 'returned'
        )
    )
SQL,
    ],
];
