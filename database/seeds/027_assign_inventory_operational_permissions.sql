INSERT INTO permissions(name,code,module,description,active) VALUES
 ('Use Assigned Warehouses','inventory.warehouses.use','inventory','Select and operate explicitly assigned warehouses and stock locations',TRUE),
 ('Validate Sales Deliveries','inventory.deliveries.validate','inventory','Reserve and validate Sales deliveries from assigned warehouse locations',TRUE),
 ('Manage Warehouse User Access','inventory.warehouse_access.manage','inventory','Assign operational warehouse and location access to company users',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module),description=VALUES(description),active=TRUE;

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p
WHERE
 (r.code IN('system_administrator','company_owner') AND p.code IN('inventory.warehouses.use','inventory.deliveries.validate','inventory.warehouse_access.manage'))
 OR (r.code='warehouse_inventory_user' AND p.code IN('inventory.warehouses.view','inventory.warehouses.use','inventory.deliveries.validate','inventory.transfers.view'))
 OR (r.code IN('sales_user','sales_manager') AND p.code IN('inventory.warehouses.view','inventory.warehouses.use'));

INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT c.company_id,rp.role_id,rp.permission_id,c.provisioned_by
FROM companies c CROSS JOIN role_permissions rp
INNER JOIN roles r ON r.role_id=rp.role_id
INNER JOIN permissions p ON p.permission_id=rp.permission_id
WHERE c.deleted_at IS NULL
 AND (
  (r.code IN('system_administrator','company_owner') AND p.code IN('inventory.warehouses.use','inventory.deliveries.validate','inventory.warehouse_access.manage'))
  OR (r.code='warehouse_inventory_user' AND p.code IN('inventory.warehouses.view','inventory.warehouses.use','inventory.deliveries.validate','inventory.transfers.view'))
  OR (r.code IN('sales_user','sales_manager') AND p.code IN('inventory.warehouses.view','inventory.warehouses.use'))
 );
