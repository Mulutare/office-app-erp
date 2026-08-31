INSERT INTO permissions(name,code,module,description,active) VALUES
 ('Use Procurement Receiving Destinations','procurement.receiving_destinations.use','procurement','Select an assigned warehouse and receiving-enabled location for inbound Purchase Orders',TRUE),
 ('Manage Asset Transfers','assets.transfers.manage','assets','Transfer active assets between departments, custodians and asset locations',TRUE),
 ('Manage Asset Maintenance','assets.maintenance.manage','assets','Create and update asset maintenance operations',TRUE),
 ('Manage Asset Custody and Locations','assets.custody.manage','assets','Assign asset custodians, departments and non-inventory asset locations',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module),description=VALUES(description),active=TRUE;

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p
WHERE
 (r.code IN('system_administrator','company_owner') AND p.code IN('procurement.receiving_destinations.use','assets.transfers.manage','assets.maintenance.manage','assets.custody.manage'))
 OR (r.code='warehouse_inventory_user' AND p.code IN('inventory.view','inventory.warehouses.view','inventory.warehouses.use','inventory.receipts.view','inventory.receipts.create','inventory.receipts.approve','inventory.receipts.post','inventory.deliveries.validate','procurement.view','procurement.receipts.create','procurement.receiving_destinations.use'));

-- Existing ordinary company roles are intentionally not updated. Only the
-- protected owner/admin baseline receives the new catalog entries automatically.
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT c.company_id,r.role_id,p.permission_id,c.provisioned_by
FROM companies c CROSS JOIN roles r CROSS JOIN permissions p
WHERE c.deleted_at IS NULL
 AND r.code IN('system_administrator','company_owner')
 AND p.code IN('procurement.receiving_destinations.use','assets.transfers.manage','assets.maintenance.manage','assets.custody.manage');
