INSERT INTO roles (name, code, description, is_system)
VALUES
    ('Sales Manager', 'sales_manager', 'Manage Sales operations, controls and reporting', TRUE),
    ('Sales Officer', 'sales_officer', 'Maintain customers and create customer sales orders', TRUE),
    ('Sales Approver', 'sales_approver', 'Approve, fulfil and control customer sales orders', TRUE),
    ('Sales Cashier', 'sales_cashier', 'Record and report customer receipts', TRUE),
    ('Sales Inventory Controller', 'sales_inventory_controller', 'Control serialized Sales products', TRUE),
    ('Sales Commission Officer', 'sales_commission_officer', 'Approve and settle Sales commissions', TRUE),
    ('Sales Credit Controller', 'sales_credit_controller', 'Maintain customer credit and release holds', TRUE)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_system = VALUES(is_system),
    active = TRUE;

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.role_id, permissions.permission_id
FROM roles
INNER JOIN permissions
    ON permissions.code LIKE 'sales.%'
WHERE roles.code = 'sales_manager'
  AND permissions.code <> 'sales.integrations.replay'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions existing
      WHERE existing.role_id = roles.role_id
        AND existing.permission_id = permissions.permission_id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.role_id, permissions.permission_id
FROM roles
INNER JOIN permissions ON permissions.code IN (
    'sales.view', 'sales.catalogue.manage', 'sales.orders.create',
    'sales.orders.submit', 'sales.orders.cancel', 'sales.reports.export'
)
WHERE roles.code = 'sales_officer'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions existing
      WHERE existing.role_id = roles.role_id
        AND existing.permission_id = permissions.permission_id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.role_id, permissions.permission_id
FROM roles
INNER JOIN permissions ON permissions.code IN (
    'sales.view', 'sales.orders.approve', 'sales.orders.confirm',
    'sales.orders.cancel', 'sales.targets.manage', 'sales.margin.view',
    'sales.reports.export'
)
WHERE roles.code = 'sales_approver'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions existing
      WHERE existing.role_id = roles.role_id
        AND existing.permission_id = permissions.permission_id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.role_id, permissions.permission_id
FROM roles
INNER JOIN permissions ON permissions.code IN (
    'sales.view', 'sales.payments.record', 'sales.reports.export'
)
WHERE roles.code = 'sales_cashier'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions existing
      WHERE existing.role_id = roles.role_id
        AND existing.permission_id = permissions.permission_id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.role_id, permissions.permission_id
FROM roles
INNER JOIN permissions ON permissions.code IN ('sales.view', 'sales.serials.manage')
WHERE roles.code = 'sales_inventory_controller'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions existing
      WHERE existing.role_id = roles.role_id
        AND existing.permission_id = permissions.permission_id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.role_id, permissions.permission_id
FROM roles
INNER JOIN permissions ON permissions.code IN ('sales.view', 'sales.commissions.manage')
WHERE roles.code = 'sales_commission_officer'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions existing
      WHERE existing.role_id = roles.role_id
        AND existing.permission_id = permissions.permission_id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.role_id, permissions.permission_id
FROM roles
INNER JOIN permissions ON permissions.code IN (
    'sales.view', 'sales.credit.manage', 'sales.credit.release',
    'sales.reports.export'
)
WHERE roles.code = 'sales_credit_controller'
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
  AND roles.code IN (
      'sales_manager', 'sales_officer', 'sales_approver', 'sales_cashier',
      'sales_inventory_controller', 'sales_commission_officer',
      'sales_credit_controller'
  )
  AND permissions.code LIKE 'sales.%'
  AND NOT EXISTS (
      SELECT 1 FROM company_role_permissions existing
      WHERE existing.company_id = companies.company_id
        AND existing.role_id = grants.role_id
        AND existing.permission_id = grants.permission_id
  );
