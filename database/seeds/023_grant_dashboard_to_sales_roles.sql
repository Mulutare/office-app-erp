INSERT INTO role_permissions (
    role_id,
    permission_id
)
SELECT
    roles.role_id,
    permissions.permission_id
FROM roles
INNER JOIN permissions
    ON permissions.code = 'dashboard.view'
WHERE roles.code IN (
    'sales_manager',
    'sales_officer',
    'sales_approver',
    'sales_cashier',
    'sales_inventory_controller',
    'sales_commission_officer',
    'sales_credit_controller'
)
  AND roles.active = TRUE
  AND permissions.active = TRUE
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions existing
      WHERE existing.role_id = roles.role_id
        AND existing.permission_id = permissions.permission_id
  );

INSERT INTO company_role_permissions (
    company_id,
    role_id,
    permission_id,
    granted_by
)
SELECT
    companies.company_id,
    roles.role_id,
    permissions.permission_id,
    companies.provisioned_by
FROM companies
CROSS JOIN roles
CROSS JOIN permissions
WHERE companies.deleted_at IS NULL
  AND roles.code IN (
      'sales_manager',
      'sales_officer',
      'sales_approver',
      'sales_cashier',
      'sales_inventory_controller',
      'sales_commission_officer',
      'sales_credit_controller'
  )
  AND roles.active = TRUE
  AND permissions.code = 'dashboard.view'
  AND permissions.active = TRUE
  AND NOT EXISTS (
      SELECT 1
      FROM company_role_permissions existing
      WHERE existing.company_id = companies.company_id
        AND existing.role_id = roles.role_id
        AND existing.permission_id = permissions.permission_id
  );