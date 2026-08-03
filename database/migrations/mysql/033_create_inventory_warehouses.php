<?php

declare(strict_types=1);

return [
    'version' => '033',
    'description' =>
        'Create tenant-scoped inventory warehouses and locations',
    'preflight' => static function (
        \PDO $connection
    ): string {
        $statement = $connection->query(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN (
                    'inventory_warehouses',
                    'inventory_warehouse_locations'
               )"
        );

        $count = (int) $statement->fetchColumn();

        if ($count === 0) {
            return 'apply';
        }

        if ($count === 2) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 033 found a partial inventory warehouse schema.'
        );
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE inventory_warehouses (
    warehouse_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    manager_user_id BIGINT UNSIGNED NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(160) NOT NULL,
    warehouse_type VARCHAR(30) NOT NULL DEFAULT 'standard',
    address VARCHAR(255) NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    allow_negative_stock BOOLEAN NOT NULL DEFAULT FALSE,
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    default_scope TINYINT
        GENERATED ALWAYS AS (
            CASE
                WHEN is_default = TRUE THEN 1
                ELSE NULL
            END
        ) STORED,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    CONSTRAINT uq_inventory_warehouse_code
        UNIQUE (company_id, code),
    CONSTRAINT uq_inventory_warehouse_identity
        UNIQUE (company_id, warehouse_id),
    CONSTRAINT uq_inventory_default_warehouse
        UNIQUE (company_id, default_scope),
    CONSTRAINT ck_inventory_warehouse_type
        CHECK (
            warehouse_type IN (
                'standard',
                'retail',
                'distribution',
                'transit',
                'returns',
                'virtual'
            )
        ),
    CONSTRAINT fk_inventory_warehouse_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_warehouse_branch
        FOREIGN KEY (branch_id)
        REFERENCES organization_branches(branch_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_inventory_warehouse_manager
        FOREIGN KEY (manager_user_id)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_inventory_warehouse_creator
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_inventory_warehouse_updater
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    INDEX idx_inventory_warehouse_active (
        company_id,
        active,
        deleted_at,
        name
    ),
    INDEX idx_inventory_warehouse_branch (
        company_id,
        branch_id,
        active
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_warehouse_locations (
    location_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    parent_location_id BIGINT UNSIGNED NULL,
    code VARCHAR(60) NOT NULL,
    name VARCHAR(160) NOT NULL,
    location_type VARCHAR(30) NOT NULL DEFAULT 'bin',
    barcode VARCHAR(120) NULL,
    aisle VARCHAR(40) NULL,
    rack VARCHAR(40) NULL,
    shelf VARCHAR(40) NULL,
    bin VARCHAR(40) NULL,
    pick_priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    receiving_allowed BOOLEAN NOT NULL DEFAULT TRUE,
    picking_allowed BOOLEAN NOT NULL DEFAULT TRUE,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    CONSTRAINT uq_inventory_location_code
        UNIQUE (company_id, warehouse_id, code),
    CONSTRAINT uq_inventory_location_identity
        UNIQUE (
            company_id,
            warehouse_id,
            location_id
        ),
    CONSTRAINT uq_inventory_location_barcode
        UNIQUE (company_id, barcode),
    CONSTRAINT ck_inventory_location_type
        CHECK (
            location_type IN (
                'zone',
                'aisle',
                'rack',
                'shelf',
                'bin',
                'receiving',
                'dispatch',
                'returns',
                'quarantine'
            )
        ),
    CONSTRAINT fk_inventory_location_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_location_warehouse
        FOREIGN KEY (company_id, warehouse_id)
        REFERENCES inventory_warehouses(
            company_id,
            warehouse_id
        )
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_location_parent
        FOREIGN KEY (
            company_id,
            warehouse_id,
            parent_location_id
        )
        REFERENCES inventory_warehouse_locations(
            company_id,
            warehouse_id,
            location_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_location_creator
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_inventory_location_updater
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    INDEX idx_inventory_location_active (
        company_id,
        warehouse_id,
        active,
        pick_priority
    ),
    INDEX idx_inventory_location_hierarchy (
        company_id,
        warehouse_id,
        parent_location_id
    ),
    INDEX idx_inventory_location_coordinates (
        company_id,
        warehouse_id,
        aisle,
        rack,
        shelf,
        bin
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];