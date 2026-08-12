UPDATE erp_modules
SET available=TRUE, active=TRUE,
    description='Fixed asset capitalization, depreciation, custody and disposal.'
WHERE code='assets';

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p
WHERE r.code IN('system_administrator','company_owner') AND p.code LIKE 'assets.%' AND p.active=TRUE;

INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT cm.company_id,rp.role_id,rp.permission_id,c.provisioned_by
FROM company_modules cm
INNER JOIN erp_modules m ON m.module_id=cm.module_id AND m.code='assets'
INNER JOIN companies c ON c.company_id=cm.company_id AND c.deleted_at IS NULL
INNER JOIN role_permissions rp
INNER JOIN roles r ON r.role_id=rp.role_id AND r.code IN('system_administrator','company_owner')
INNER JOIN permissions p ON p.permission_id=rp.permission_id AND p.code LIKE 'assets.%' AND p.active=TRUE
WHERE cm.enabled=TRUE AND cm.license_status IN('active','trial')
  AND (cm.expires_at IS NULL OR cm.expires_at>NOW());
