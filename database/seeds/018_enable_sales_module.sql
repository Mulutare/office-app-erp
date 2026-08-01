UPDATE erp_modules
SET available = TRUE,
    description = 'Customers, telecom products, orders, targets, commissions and receivables.'
WHERE code = 'sales';

UPDATE company_modules
INNER JOIN erp_modules
    ON erp_modules.module_id = company_modules.module_id
SET company_modules.enabled = TRUE,
    company_modules.license_status = 'active',
    company_modules.licensed_at = COALESCE(
        company_modules.licensed_at,
        NOW()
    )
WHERE erp_modules.code = 'sales'
  AND company_modules.license_status = 'not_licensed';

INSERT INTO permissions (name, code, module, description, active)
VALUES
    ('View Sales', 'sales.view', 'sales', 'View sales dashboards, orders and receivables', TRUE),
    ('Manage Sales Catalogue', 'sales.catalogue.manage', 'sales', 'Maintain customers, products, territories and agents', TRUE),
    ('Create Sales Orders', 'sales.orders.create', 'sales', 'Create and confirm customer sales orders', TRUE),
    ('Record Sales Payments', 'sales.payments.record', 'sales', 'Record customer receipts against sales orders', TRUE),
    ('Manage Sales Targets', 'sales.targets.manage', 'sales', 'Set territory and DSA/DSP sales targets', TRUE)
ON DUPLICATE KEY UPDATE
    name = VALUES(name), module = VALUES(module),
    description = VALUES(description), active = TRUE;

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.role_id, permissions.permission_id
FROM roles CROSS JOIN permissions
WHERE roles.code IN ('system_administrator', 'company_owner')
  AND permissions.code LIKE 'sales.%'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions existing
      WHERE existing.role_id = roles.role_id
        AND existing.permission_id = permissions.permission_id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.role_id, permissions.permission_id
FROM roles INNER JOIN permissions ON permissions.code = 'sales.view'
WHERE roles.code = 'executive_viewer'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions existing
      WHERE existing.role_id = roles.role_id
        AND existing.permission_id = permissions.permission_id
  );

INSERT INTO company_role_permissions (company_id, role_id, permission_id, granted_by)
SELECT companies.company_id, grants.role_id, grants.permission_id, companies.provisioned_by
FROM companies
CROSS JOIN role_permissions grants
INNER JOIN roles ON roles.role_id = grants.role_id
INNER JOIN permissions ON permissions.permission_id = grants.permission_id
WHERE companies.deleted_at IS NULL
  AND roles.code IN ('system_administrator', 'company_owner', 'executive_viewer')
  AND permissions.code LIKE 'sales.%'
  AND NOT EXISTS (
      SELECT 1 FROM company_role_permissions existing
      WHERE existing.company_id = companies.company_id
        AND existing.role_id = grants.role_id
        AND existing.permission_id = grants.permission_id
  );
