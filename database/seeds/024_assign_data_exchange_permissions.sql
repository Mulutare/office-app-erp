INSERT INTO permissions(name,code,module,description,active) VALUES
('Import Sales Data','sales.import','sales','Import authorized Sales master data and draft documents',TRUE),
('Export Sales Data','sales.export','sales','Export authorized Sales data',TRUE),
('Import Inventory Data','inventory.import','inventory','Import authorized Inventory master data and draft operations',TRUE),
('Export Inventory Data','inventory.export','inventory','Export authorized Inventory data',TRUE),
('Import Finance Data','finance.import','finance','Import authorized Finance master data and draft documents',TRUE),
('Export Finance Data','finance.export','finance','Export authorized Finance data and reports',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module),description=VALUES(description),active=TRUE;

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p
WHERE r.code='company_owner' AND p.code IN (
    'sales.import','sales.export','inventory.import','inventory.export','finance.import','finance.export'
);

-- Explicit owner baseline repair for the licensed Inventory landing page.
-- New defaults must be reviewed and listed; permissions are never inferred
-- from an entire namespace.
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p
WHERE r.code='company_owner' AND p.code='inventory.view' AND p.active=TRUE;

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p
WHERE r.code='system_administrator' AND p.code IN (
    'sales.import','sales.export','inventory.import','inventory.export','finance.import','finance.export'
);

-- Synchronize the reviewed templates into existing actively licensed
-- companies. New companies receive the same templates during provisioning.
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT cm.company_id,rp.role_id,rp.permission_id,c.provisioned_by
FROM company_modules cm
INNER JOIN erp_modules m ON m.module_id=cm.module_id
INNER JOIN companies c ON c.company_id=cm.company_id AND c.deleted_at IS NULL
INNER JOIN permissions p ON p.module=m.code AND p.active=TRUE
INNER JOIN role_permissions rp ON rp.permission_id=p.permission_id
INNER JOIN roles r ON r.role_id=rp.role_id
WHERE cm.enabled=TRUE
  AND cm.license_status IN ('active','trial')
  AND (cm.expires_at IS NULL OR cm.expires_at>NOW())
  AND r.code IN ('company_owner','system_administrator')
  AND p.code IN ('inventory.view','sales.import','sales.export','inventory.import','inventory.export','finance.import','finance.export');
