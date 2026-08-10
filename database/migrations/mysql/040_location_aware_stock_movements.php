<?php

declare(strict_types=1);

return [
    'version' => '040',
    'description' =>
        'Make the stock movement ledger location-aware and authoritative',
    'preflight' => static function (\PDO $connection): string {
        $columns = (int) $connection->query(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'inventory_stock_movements'
               AND column_name IN (
                    'source_warehouse_id',
                    'source_location_id',
                    'destination_warehouse_id',
                    'destination_location_id',
                    'requested_quantity',
                    'completed_quantity',
                    'operation_type_id',
                    'status',
                    'completed_at',
                    'completed_by'
               )"
        )->fetchColumn();

        if ($columns === 0) {
            return 'apply';
        }

        if ($columns === 10) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 040 found a partial location-aware movement schema.'
        );
    },
    'statements' => [
        <<<'SQL'
ALTER TABLE inventory_stock_movements
    DROP CONSTRAINT ck_inventory_movement_quantity,
    ADD COLUMN source_warehouse_id BIGINT UNSIGNED NULL
        AFTER product_id,
    ADD COLUMN source_location_id BIGINT UNSIGNED NULL
        AFTER source_warehouse_id,
    ADD COLUMN destination_warehouse_id BIGINT UNSIGNED NULL
        AFTER source_location_id,
    ADD COLUMN destination_location_id BIGINT UNSIGNED NULL
        AFTER destination_warehouse_id,
    ADD COLUMN requested_quantity DECIMAL(18,3) NULL
        AFTER movement_type,
    ADD COLUMN completed_quantity DECIMAL(18,3) NULL
        AFTER requested_quantity,
    ADD COLUMN operation_type_id BIGINT UNSIGNED NULL
        AFTER completed_quantity,
    ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'completed'
        AFTER operation_type_id,
    ADD COLUMN completed_at DATETIME NULL
        AFTER occurred_at,
    ADD COLUMN completed_by BIGINT UNSIGNED NULL
        AFTER recorded_by,
    ADD CONSTRAINT ck_inventory_movement_quantity
        CHECK (
            quantity_delta <> 0
            OR (
                source_location_id IS NOT NULL
                AND destination_location_id IS NOT NULL
            )
        ),
    ADD CONSTRAINT ck_inventory_movement_requested
        CHECK (requested_quantity IS NULL OR requested_quantity > 0),
    ADD CONSTRAINT ck_inventory_movement_completed
        CHECK (
            completed_quantity IS NULL
            OR (
                completed_quantity > 0
                AND completed_quantity <= requested_quantity
            )
        ),
    ADD CONSTRAINT ck_inventory_movement_status
        CHECK (status IN ('draft', 'ready', 'completed', 'cancelled')),
    ADD CONSTRAINT ck_inventory_movement_distinct_locations
        CHECK (
            source_location_id IS NULL
            OR destination_location_id IS NULL
            OR source_location_id <> destination_location_id
            OR source_warehouse_id <> destination_warehouse_id
        ),
    ADD CONSTRAINT fk_inventory_movement_source
        FOREIGN KEY (company_id, source_warehouse_id, source_location_id)
        REFERENCES inventory_warehouse_locations(
            company_id, warehouse_id, location_id
        ) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_inventory_movement_destination
        FOREIGN KEY (
            company_id,
            destination_warehouse_id,
            destination_location_id
        ) REFERENCES inventory_warehouse_locations(
            company_id, warehouse_id, location_id
        ) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_inventory_movement_operation_type
        FOREIGN KEY (company_id, warehouse_id, operation_type_id)
        REFERENCES inventory_operation_types(
            company_id, warehouse_id, operation_type_id
        ) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_inventory_movement_completer
        FOREIGN KEY (completed_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    ADD INDEX idx_inventory_movement_source (
        company_id, source_warehouse_id, source_location_id, product_id
    ),
    ADD INDEX idx_inventory_movement_destination (
        company_id,
        destination_warehouse_id,
        destination_location_id,
        product_id
    ),
    ADD INDEX idx_inventory_movement_status (
        company_id, status, occurred_at
    )
SQL,
        <<<'SQL'
UPDATE inventory_stock_movements
SET requested_quantity = ABS(quantity_delta),
    completed_quantity = ABS(quantity_delta),
    destination_warehouse_id = CASE
        WHEN quantity_delta > 0 THEN warehouse_id ELSE NULL END,
    destination_location_id = CASE
        WHEN quantity_delta > 0 THEN location_id ELSE NULL END,
    source_warehouse_id = CASE
        WHEN quantity_delta < 0 THEN warehouse_id ELSE NULL END,
    source_location_id = CASE
        WHEN quantity_delta < 0 THEN location_id ELSE NULL END,
    completed_at = occurred_at,
    completed_by = recorded_by
WHERE requested_quantity IS NULL
SQL,
        <<<'SQL'
ALTER TABLE inventory_stock_movements
    ADD CONSTRAINT ck_inventory_movement_endpoints
        CHECK (
            source_location_id IS NOT NULL
            OR destination_location_id IS NOT NULL
        )
SQL,
        <<<'SQL'
ALTER TABLE inventory_stock_movements
    MODIFY requested_quantity DECIMAL(18,3) NOT NULL,
    MODIFY completed_quantity DECIMAL(18,3) NULL
SQL,
    ],
];
