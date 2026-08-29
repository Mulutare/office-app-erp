<?php

declare(strict_types=1);

return [
    'version' => '068',
    'description' => 'Require explicit authorized Sales fulfilment warehouse and source location',
    'preflight' => static function (\PDO $connection): string {
        $columns = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema=DATABASE() AND table_name='sales_orders'
               AND column_name IN('warehouse_id','source_location_id')"
        )->fetchColumn();
        $tables = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_name IN('inventory_user_warehouse_access','inventory_user_location_access')"
        )->fetchColumn();
        $permissions = (int) $connection->query(
            "SELECT COUNT(*) FROM permissions
             WHERE code IN('inventory.warehouses.use','inventory.deliveries.validate','inventory.warehouse_access.manage')"
        )->fetchColumn();
        if ($columns === 0 && $tables === 0 && $permissions === 0) {
            return 'apply';
        }
        if ($columns === 2 && $tables === 2 && $permissions === 3) {
            return 'baseline';
        }
        throw new \RuntimeException('Migration 068 found a partial Sales fulfilment authorization schema.');
    },
    'statements' => [
        <<<'SQL'
ALTER TABLE sales_orders
 ADD COLUMN warehouse_id BIGINT UNSIGNED NULL AFTER branch_id,
 ADD COLUMN source_location_id BIGINT UNSIGNED NULL AFTER warehouse_id,
 ADD INDEX idx_sales_order_fulfilment(company_id,warehouse_id,source_location_id,status),
 ADD CONSTRAINT fk_sales_order_fulfilment_warehouse
  FOREIGN KEY(company_id,warehouse_id)
  REFERENCES inventory_warehouses(company_id,warehouse_id)
  ON DELETE RESTRICT,
 ADD CONSTRAINT fk_sales_order_fulfilment_source
  FOREIGN KEY(company_id,warehouse_id,source_location_id)
  REFERENCES inventory_warehouse_locations(company_id,warehouse_id,location_id)
  ON DELETE RESTRICT
SQL,
        <<<'SQL'
CREATE TABLE inventory_user_warehouse_access (
 access_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 warehouse_id BIGINT UNSIGNED NOT NULL,
 active BOOLEAN NOT NULL DEFAULT TRUE,
 granted_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT uq_inventory_user_warehouse_access UNIQUE(company_id,user_id,warehouse_id),
 CONSTRAINT uq_inventory_user_warehouse_access_identity UNIQUE(company_id,access_id),
 CONSTRAINT fk_inventory_user_warehouse_membership FOREIGN KEY(company_id,user_id)
  REFERENCES company_users(company_id,user_id) ON DELETE CASCADE,
 CONSTRAINT fk_inventory_user_warehouse_resource FOREIGN KEY(company_id,warehouse_id)
  REFERENCES inventory_warehouses(company_id,warehouse_id) ON DELETE CASCADE,
 CONSTRAINT fk_inventory_user_warehouse_granter FOREIGN KEY(granted_by)
  REFERENCES users(user_id) ON DELETE SET NULL,
 INDEX idx_inventory_user_warehouse_lookup(company_id,user_id,active,warehouse_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_user_location_access (
 access_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 warehouse_id BIGINT UNSIGNED NOT NULL,
 location_id BIGINT UNSIGNED NOT NULL,
 active BOOLEAN NOT NULL DEFAULT TRUE,
 granted_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT uq_inventory_user_location_access UNIQUE(company_id,user_id,location_id),
 CONSTRAINT fk_inventory_user_location_warehouse_access FOREIGN KEY(company_id,user_id,warehouse_id)
  REFERENCES inventory_user_warehouse_access(company_id,user_id,warehouse_id) ON DELETE CASCADE,
 CONSTRAINT fk_inventory_user_location_resource FOREIGN KEY(company_id,warehouse_id,location_id)
  REFERENCES inventory_warehouse_locations(company_id,warehouse_id,location_id) ON DELETE CASCADE,
 CONSTRAINT fk_inventory_user_location_granter FOREIGN KEY(granted_by)
  REFERENCES users(user_id) ON DELETE SET NULL,
 INDEX idx_inventory_user_location_lookup(company_id,user_id,warehouse_id,active,location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO permissions(name,code,module,description,active) VALUES
 ('Use Assigned Warehouses','inventory.warehouses.use','inventory','Select and operate explicitly assigned warehouses and stock locations',TRUE),
 ('Validate Sales Deliveries','inventory.deliveries.validate','inventory','Reserve and validate Sales deliveries from assigned warehouse locations',TRUE),
 ('Manage Warehouse User Access','inventory.warehouse_access.manage','inventory','Assign operational warehouse and location access to company users',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module),description=VALUES(description),active=TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id
FROM roles r CROSS JOIN permissions p
WHERE
 (r.code IN('system_administrator','company_owner') AND p.code IN('inventory.warehouses.use','inventory.deliveries.validate','inventory.warehouse_access.manage'))
 OR (r.code='warehouse_inventory_user' AND p.code IN('inventory.warehouses.view','inventory.warehouses.use','inventory.deliveries.validate','inventory.transfers.view'))
 OR (r.code IN('sales_user','sales_manager') AND p.code IN('inventory.warehouses.view','inventory.warehouses.use'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT c.company_id,rp.role_id,rp.permission_id,c.provisioned_by
FROM companies c
CROSS JOIN role_permissions rp
INNER JOIN roles r ON r.role_id=rp.role_id
INNER JOIN permissions p ON p.permission_id=rp.permission_id
WHERE c.deleted_at IS NULL
 AND (
  (r.code IN('system_administrator','company_owner') AND p.code IN('inventory.warehouses.use','inventory.deliveries.validate','inventory.warehouse_access.manage'))
  OR (r.code='warehouse_inventory_user' AND p.code IN('inventory.warehouses.view','inventory.warehouses.use','inventory.deliveries.validate','inventory.transfers.view'))
  OR (r.code IN('sales_user','sales_manager') AND p.code IN('inventory.warehouses.view','inventory.warehouses.use'))
 )
SQL,
    ],
];
