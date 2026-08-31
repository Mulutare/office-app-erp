INSERT INTO permissions(name,code,module,description,active) VALUES
 ('Create Stock Transfers','inventory.transfers.create','inventory','Create and submit internal stock transfers between authorized locations',TRUE),
 ('Approve Stock Transfers','inventory.transfers.approve','inventory','Approve submitted internal stock transfers',TRUE),
 ('Dispatch Stock Transfers','inventory.transfers.dispatch','inventory','Move approved stock from its exact source into controlled transit',TRUE),
 ('Receive Stock Transfers','inventory.transfers.receive','inventory','Receive dispatched stock from transit into its exact destination',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module),description=VALUES(description),active=TRUE;

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p
WHERE (r.code IN('system_administrator','company_owner') AND p.code IN('inventory.transfers.create','inventory.transfers.approve','inventory.transfers.dispatch','inventory.transfers.receive'))
 OR (r.code='warehouse_inventory_user' AND p.code IN('inventory.transfers.view','inventory.transfers.create','inventory.transfers.dispatch','inventory.transfers.receive'));

-- Do not escalate existing ordinary company roles. Protected ownership roles
-- retain their authoritative implicit baseline; administrators assign others.
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT c.company_id,r.role_id,p.permission_id,c.provisioned_by FROM companies c CROSS JOIN roles r CROSS JOIN permissions p
WHERE c.deleted_at IS NULL AND r.code IN('system_administrator','company_owner')
 AND p.code IN('inventory.transfers.create','inventory.transfers.approve','inventory.transfers.dispatch','inventory.transfers.receive');
