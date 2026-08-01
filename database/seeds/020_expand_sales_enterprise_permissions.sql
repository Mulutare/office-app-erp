INSERT INTO permissions (name, code, module, description, active)
VALUES
    ('Submit Sales Orders', 'sales.orders.submit', 'sales', 'Submit draft sales orders for approval', TRUE),
    ('Confirm Sales Orders', 'sales.orders.confirm', 'sales', 'Confirm approved sales orders', TRUE),
    ('Cancel Sales Orders', 'sales.orders.cancel', 'sales', 'Cancel eligible sales orders with a reason', TRUE),
    ('Manage Customer Credit', 'sales.credit.manage', 'sales', 'Maintain customer credit policy and holds', TRUE),
    ('Release Credit Holds', 'sales.credit.release', 'sales', 'Release a credit hold with an auditable reason', TRUE),
    ('Export Sales Reports', 'sales.reports.export', 'sales', 'Export authorized Sales reports', TRUE),
    ('View Sales Margins', 'sales.margin.view', 'sales', 'View Sales cost and margin information', TRUE),
    ('Replay Sales Integrations', 'sales.integrations.replay', 'sales', 'Replay dead-letter Sales integration events', TRUE)
ON DUPLICATE KEY UPDATE
    name = VALUES(name), module = VALUES(module),
    description = VALUES(description), active = TRUE;

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.role_id, permissions.permission_id
FROM roles CROSS JOIN permissions
WHERE roles.code IN ('system_administrator', 'company_owner')
  AND permissions.code IN (
      'sales.orders.submit','sales.orders.confirm','sales.orders.cancel',
      'sales.credit.manage','sales.credit.release','sales.reports.export',
      'sales.margin.view','sales.integrations.replay'
  )
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
  AND roles.code IN ('system_administrator', 'company_owner')
  AND permissions.code LIKE 'sales.%'
  AND NOT EXISTS (
      SELECT 1 FROM company_role_permissions existing
      WHERE existing.company_id = companies.company_id
        AND existing.role_id = grants.role_id
        AND existing.permission_id = grants.permission_id
  );
