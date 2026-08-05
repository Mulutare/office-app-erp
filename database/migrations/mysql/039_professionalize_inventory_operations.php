<?php

declare(strict_types=1);

return [
    'version' => '039',
    'description' =>
        'Professionalize Inventory operation types and internal transfers',

    'preflight' => static function (
        \PDO $connection
    ): string {
        $operationTypeTables = (int) $connection
            ->query(
                "SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name =
                       'inventory_operation_types'"
            )
            ->fetchColumn();

        $operationTypeColumns = (int) $connection
            ->query(
                "SELECT COUNT(*)
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND column_name = 'operation_type_id'
                   AND table_name IN (
                       'inventory_goods_receipts',
                       'inventory_transfers',
                       'inventory_stock_adjustments'
                   )"
            )
            ->fetchColumn();

        $operationTypeForeignKeys = (int) $connection
            ->query(
                "SELECT COUNT(*)
                 FROM information_schema.table_constraints
                 WHERE table_schema = DATABASE()
                   AND constraint_type = 'FOREIGN KEY'
                   AND constraint_name IN (
                       'fk_inventory_goods_receipt_operation_type',
                       'fk_inventory_transfer_operation_type',
                       'fk_inventory_adjustment_operation_type'
                   )"
            )
            ->fetchColumn();

        $legacyTransferConstraint = (int) $connection
            ->query(
                "SELECT COUNT(*)
                 FROM information_schema.table_constraints
                 WHERE table_schema = DATABASE()
                   AND table_name = 'inventory_transfers'
                   AND constraint_type = 'CHECK'
                   AND constraint_name =
                       'ck_inventory_transfer_warehouses'"
            )
            ->fetchColumn();

        $routeConstraint = (int) $connection
            ->query(
                "SELECT COUNT(*)
                 FROM information_schema.table_constraints
                 WHERE table_schema = DATABASE()
                   AND table_name =
                       'inventory_transfer_lines'
                   AND constraint_type = 'CHECK'
                   AND constraint_name =
                       'ck_inventory_transfer_line_route'"
            )
            ->fetchColumn();

        if (
            $operationTypeTables === 0
            && $operationTypeColumns === 0
            && $operationTypeForeignKeys === 0
            && $legacyTransferConstraint === 1
            && $routeConstraint === 0
        ) {
            return 'apply';
        }

        if (
            $operationTypeTables === 1
            && $operationTypeColumns === 3
            && $operationTypeForeignKeys === 3
            && $legacyTransferConstraint === 0
            && $routeConstraint === 1
        ) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 039 found a partial professional Inventory schema.'
        );
    },

    'statements' => [
        <<<'SQL'
CREATE TABLE inventory_operation_types (
    operation_type_id BIGINT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(160) NOT NULL,
    operation_kind VARCHAR(30) NOT NULL,

    default_source_location_id
        BIGINT UNSIGNED NULL,
    default_destination_location_id
        BIGINT UNSIGNED NULL,

    requires_approval BOOLEAN
        NOT NULL DEFAULT TRUE,
    auto_reserve BOOLEAN
        NOT NULL DEFAULT FALSE,
    allow_partial BOOLEAN
        NOT NULL DEFAULT TRUE,
    create_backorder BOOLEAN
        NOT NULL DEFAULT TRUE,

    is_default BOOLEAN
        NOT NULL DEFAULT FALSE,

    default_kind VARCHAR(30)
        GENERATED ALWAYS AS (
            CASE
                WHEN is_default = TRUE
                    THEN operation_kind
                ELSE NULL
            END
        ) STORED,

    active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT uq_inventory_operation_type_code
        UNIQUE (
            company_id,
            warehouse_id,
            code
        ),

    CONSTRAINT uq_inventory_operation_type_identity
        UNIQUE (
            company_id,
            warehouse_id,
            operation_type_id
        ),

    CONSTRAINT uq_inventory_operation_type_default
        UNIQUE (
            company_id,
            warehouse_id,
            default_kind
        ),

    CONSTRAINT ck_inventory_operation_type_kind
        CHECK (
            operation_kind IN (
                'receipt',
                'internal_transfer',
                'delivery',
                'adjustment'
            )
        ),

    CONSTRAINT fk_inventory_operation_type_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_inventory_operation_type_warehouse
        FOREIGN KEY (
            company_id,
            warehouse_id
        )
        REFERENCES inventory_warehouses(
            company_id,
            warehouse_id
        )
        ON DELETE RESTRICT,

    CONSTRAINT fk_inventory_operation_type_source
        FOREIGN KEY (
            company_id,
            warehouse_id,
            default_source_location_id
        )
        REFERENCES inventory_warehouse_locations(
            company_id,
            warehouse_id,
            location_id
        )
        ON DELETE RESTRICT,

    CONSTRAINT fk_inventory_operation_type_destination
        FOREIGN KEY (
            company_id,
            warehouse_id,
            default_destination_location_id
        )
        REFERENCES inventory_warehouse_locations(
            company_id,
            warehouse_id,
            location_id
        )
        ON DELETE RESTRICT,

    INDEX idx_inventory_operation_type_kind (
        company_id,
        warehouse_id,
        operation_kind,
        active
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,

        <<<'SQL'
INSERT INTO inventory_operation_types (
    company_id,
    warehouse_id,
    code,
    name,
    operation_kind,
    requires_approval,
    auto_reserve,
    allow_partial,
    create_backorder,
    is_default,
    active
)
SELECT
    warehouses.company_id,
    warehouses.warehouse_id,
    defaults_catalog.code,
    defaults_catalog.name,
    defaults_catalog.operation_kind,
    defaults_catalog.requires_approval,
    defaults_catalog.auto_reserve,
    defaults_catalog.allow_partial,
    defaults_catalog.create_backorder,
    TRUE,
    warehouses.active
FROM inventory_warehouses warehouses
CROSS JOIN (
    SELECT
        'RCPT' AS code,
        'Receipts' AS name,
        'receipt' AS operation_kind,
        TRUE AS requires_approval,
        FALSE AS auto_reserve,
        TRUE AS allow_partial,
        TRUE AS create_backorder

    UNION ALL

    SELECT
        'INT',
        'Internal Transfers',
        'internal_transfer',
        TRUE,
        FALSE,
        TRUE,
        TRUE

    UNION ALL

    SELECT
        'DLV',
        'Delivery Orders',
        'delivery',
        TRUE,
        TRUE,
        TRUE,
        TRUE

    UNION ALL

    SELECT
        'ADJ',
        'Inventory Adjustments',
        'adjustment',
        TRUE,
        FALSE,
        FALSE,
        FALSE
) defaults_catalog
WHERE warehouses.deleted_at IS NULL
SQL,

        <<<'SQL'
ALTER TABLE inventory_goods_receipts
    ADD COLUMN operation_type_id
        BIGINT UNSIGNED NULL
        AFTER warehouse_id
SQL,

        <<<'SQL'
ALTER TABLE inventory_transfers
    ADD COLUMN operation_type_id
        BIGINT UNSIGNED NULL
        AFTER destination_warehouse_id
SQL,

        <<<'SQL'
ALTER TABLE inventory_stock_adjustments
    ADD COLUMN operation_type_id
        BIGINT UNSIGNED NULL
        AFTER warehouse_id
SQL,

        <<<'SQL'
UPDATE inventory_goods_receipts receipts
INNER JOIN inventory_operation_types operation_types
    ON operation_types.company_id =
        receipts.company_id
   AND operation_types.warehouse_id =
        receipts.warehouse_id
   AND operation_types.operation_kind =
        'receipt'
   AND operation_types.is_default = TRUE
SET receipts.operation_type_id =
    operation_types.operation_type_id
WHERE receipts.operation_type_id IS NULL
SQL,

        <<<'SQL'
UPDATE inventory_transfers transfers
INNER JOIN inventory_operation_types operation_types
    ON operation_types.company_id =
        transfers.company_id
   AND operation_types.warehouse_id =
        transfers.source_warehouse_id
   AND operation_types.operation_kind =
        'internal_transfer'
   AND operation_types.is_default = TRUE
SET transfers.operation_type_id =
    operation_types.operation_type_id
WHERE transfers.operation_type_id IS NULL
SQL,

        <<<'SQL'
UPDATE inventory_stock_adjustments adjustments
INNER JOIN inventory_operation_types operation_types
    ON operation_types.company_id =
        adjustments.company_id
   AND operation_types.warehouse_id =
        adjustments.warehouse_id
   AND operation_types.operation_kind =
        'adjustment'
   AND operation_types.is_default = TRUE
SET adjustments.operation_type_id =
    operation_types.operation_type_id
WHERE adjustments.operation_type_id IS NULL
SQL,

        <<<'SQL'
ALTER TABLE inventory_goods_receipts
    MODIFY COLUMN operation_type_id
        BIGINT UNSIGNED NOT NULL
        AFTER warehouse_id,

    ADD CONSTRAINT
        fk_inventory_goods_receipt_operation_type
        FOREIGN KEY (
            company_id,
            warehouse_id,
            operation_type_id
        )
        REFERENCES inventory_operation_types(
            company_id,
            warehouse_id,
            operation_type_id
        )
        ON DELETE RESTRICT,

    ADD INDEX
        idx_inventory_goods_receipt_operation_type (
            company_id,
            warehouse_id,
            operation_type_id
        )
SQL,

        <<<'SQL'
ALTER TABLE inventory_transfers
    MODIFY COLUMN operation_type_id
        BIGINT UNSIGNED NOT NULL
        AFTER destination_warehouse_id,

    ADD CONSTRAINT
        fk_inventory_transfer_operation_type
        FOREIGN KEY (
            company_id,
            source_warehouse_id,
            operation_type_id
        )
        REFERENCES inventory_operation_types(
            company_id,
            warehouse_id,
            operation_type_id
        )
        ON DELETE RESTRICT,

    ADD INDEX
        idx_inventory_transfer_operation_type (
            company_id,
            source_warehouse_id,
            operation_type_id
        )
SQL,

        <<<'SQL'
ALTER TABLE inventory_stock_adjustments
    MODIFY COLUMN operation_type_id
        BIGINT UNSIGNED NOT NULL
        AFTER warehouse_id,

    ADD CONSTRAINT
        fk_inventory_adjustment_operation_type
        FOREIGN KEY (
            company_id,
            warehouse_id,
            operation_type_id
        )
        REFERENCES inventory_operation_types(
            company_id,
            warehouse_id,
            operation_type_id
        )
        ON DELETE RESTRICT,

    ADD INDEX
        idx_inventory_adjustment_operation_type (
            company_id,
            warehouse_id,
            operation_type_id
        )
SQL,

        <<<'SQL'
ALTER TABLE inventory_transfers
    DROP CONSTRAINT ck_inventory_transfer_warehouses
SQL,

        <<<'SQL'
ALTER TABLE inventory_transfer_lines
    ADD CONSTRAINT ck_inventory_transfer_line_route
        CHECK (
            source_warehouse_id
                <> destination_warehouse_id
            OR source_location_id
                <> destination_location_id
        )
SQL,
    ],
];