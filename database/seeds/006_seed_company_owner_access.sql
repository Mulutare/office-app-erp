INSERT INTO roles
    (
        name,
        code,
        description,
        is_system,
        active
    )
SELECT
    'Company Owner',
    'company_owner',
    'Administers company users, security, configuration and licensed ERP operations',
    TRUE,
    TRUE
WHERE NOT EXISTS (
    SELECT 1
    FROM roles
    WHERE code = 'company_owner'
);

UPDATE roles
SET
    name = 'Company Owner',
    description =
        'Administers company users, security, configuration and licensed ERP operations',
    is_system = TRUE,
    active = TRUE
WHERE code = 'company_owner';

INSERT INTO role_permissions
    (
        role_id,
        permission_id
    )
SELECT
    roles.role_id,
    permissions.permission_id
FROM roles
CROSS JOIN permissions
WHERE roles.code = 'company_owner'
  AND roles.active = TRUE
  AND permissions.active = TRUE
  AND permissions.code NOT IN (
      'administration.companies.manage',
      'administration.modules.manage'
  )
  AND permissions.code NOT LIKE '%.self.%'
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions existing
      WHERE existing.role_id = roles.role_id
        AND existing.permission_id =
            permissions.permission_id
  );

UPDATE users
SET is_platform_admin = TRUE
WHERE username = 'admin'
  AND deleted_at IS NULL;

INSERT INTO company_role_permissions
    (
        company_id,
        role_id,
        permission_id,
        granted_by
    )
SELECT
    companies.company_id,
    templates.role_id,
    templates.permission_id,
    companies.provisioned_by
FROM companies
CROSS JOIN role_permissions templates
WHERE companies.deleted_at IS NULL
  AND NOT EXISTS (
      SELECT 1
      FROM company_role_permissions existing
      WHERE existing.company_id =
                companies.company_id
        AND existing.role_id =
                templates.role_id
        AND existing.permission_id =
                templates.permission_id
  );
