<?php

declare(strict_types=1);

return [
    'version' => '069',
    'description' => 'Explicit Procurement receiving destinations and dynamic permission catalog metadata',
    'preflight' => static function (\PDO $connection): string {
        $poDestination = (int) $connection->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='purchase_orders' AND column_name='destination_location_id'")->fetchColumn();
        $receiptDestination = (int) $connection->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='inventory_goods_receipts' AND column_name='destination_location_id'")->fetchColumn();
        $permission = (int) $connection->query("SELECT COUNT(*) FROM permissions WHERE code='procurement.receiving_destinations.use'")->fetchColumn();
        if ($poDestination === 0 && $receiptDestination === 0 && $permission === 0) return 'apply';
        if ($poDestination === 1 && $receiptDestination === 1 && $permission === 1) return 'baseline';
        throw new \RuntimeException('Migration 069 found a partial Procurement receiving destination schema.');
    },
    'statements' => [
        <<<'SQL'
ALTER TABLE purchase_orders
 MODIFY COLUMN warehouse_id BIGINT UNSIGNED NULL,
 ADD COLUMN destination_location_id BIGINT UNSIGNED NULL AFTER warehouse_id,
 ADD INDEX idx_purchase_order_destination(company_id,warehouse_id,destination_location_id,status),
 ADD CONSTRAINT fk_purchase_order_destination
  FOREIGN KEY(company_id,warehouse_id,destination_location_id)
  REFERENCES inventory_warehouse_locations(company_id,warehouse_id,location_id)
  ON DELETE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE inventory_goods_receipts
 ADD COLUMN destination_location_id BIGINT UNSIGNED NULL AFTER warehouse_id,
 ADD INDEX idx_inventory_receipt_destination(company_id,warehouse_id,destination_location_id,status),
 ADD CONSTRAINT fk_inventory_receipt_destination
  FOREIGN KEY(company_id,warehouse_id,destination_location_id)
  REFERENCES inventory_warehouse_locations(company_id,warehouse_id,location_id)
  ON DELETE RESTRICT
SQL,
        <<<'SQL'
INSERT INTO permissions(name,code,module,description,active) VALUES
 ('Use Procurement Receiving Destinations','procurement.receiving_destinations.use','procurement','Select an assigned warehouse and receiving-enabled location for inbound Purchase Orders',TRUE),
 ('Manage Asset Transfers','assets.transfers.manage','assets','Transfer active assets between departments, custodians and asset locations',TRUE),
 ('Manage Asset Maintenance','assets.maintenance.manage','assets','Create and update asset maintenance operations',TRUE),
 ('Manage Asset Custody and Locations','assets.custody.manage','assets','Assign asset custodians, departments and non-inventory asset locations',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module),description=VALUES(description),active=TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p
WHERE
 (r.code IN('system_administrator','company_owner') AND p.code IN('procurement.receiving_destinations.use','assets.transfers.manage','assets.maintenance.manage','assets.custody.manage'))
 OR (r.code='warehouse_inventory_user' AND p.code IN('inventory.view','inventory.warehouses.view','inventory.warehouses.use','inventory.receipts.view','inventory.receipts.create','inventory.receipts.approve','inventory.receipts.post','inventory.deliveries.validate','procurement.view','procurement.receipts.create','procurement.receiving_destinations.use'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT c.company_id,r.role_id,p.permission_id,c.provisioned_by
FROM companies c CROSS JOIN roles r CROSS JOIN permissions p
WHERE c.deleted_at IS NULL
 AND r.code IN('system_administrator','company_owner')
 AND p.code IN('procurement.receiving_destinations.use','assets.transfers.manage','assets.maintenance.manage','assets.custody.manage')
SQL,
    ],
];
