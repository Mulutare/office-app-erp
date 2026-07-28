INSERT INTO permissions
    (
        name,
        code,
        module,
        description,
        active
    )
VALUES
    (
        'View Company Branches',
        'organization.branches.view',
        'organization',
        'View company branch structure and contact information',
        TRUE
    ),
    (
        'Manage Company Branches',
        'organization.branches.manage',
        'organization',
        'Create and update company branch structure',
        TRUE
    )
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    module = VALUES(module),
    description = VALUES(description),
    active = TRUE;

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
WHERE roles.code IN (
        'system_administrator',
        'company_owner'
    )
  AND permissions.code IN (
        'organization.branches.view',
        'organization.branches.manage'
    )
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions existing
      WHERE existing.role_id = roles.role_id
        AND existing.permission_id =
            permissions.permission_id
  );

INSERT INTO role_permissions
    (
        role_id,
        permission_id
    )
SELECT
    roles.role_id,
    permissions.permission_id
FROM roles
INNER JOIN permissions
    ON permissions.code =
        'organization.branches.view'
WHERE roles.code = 'executive_viewer'
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions existing
      WHERE existing.role_id = roles.role_id
        AND existing.permission_id =
            permissions.permission_id
  );

INSERT INTO company_role_permissions
    (
        company_id,
        role_id,
        permission_id,
        granted_by
    )
SELECT
    companies.company_id,
    grants.role_id,
    grants.permission_id,
    companies.provisioned_by
FROM companies
CROSS JOIN role_permissions grants
INNER JOIN roles
    ON roles.role_id = grants.role_id
WHERE companies.deleted_at IS NULL
  AND roles.code IN (
        'system_administrator',
        'company_owner',
        'executive_viewer'
    )
  AND EXISTS (
      SELECT 1
      FROM permissions
      WHERE permissions.permission_id =
            grants.permission_id
        AND permissions.code IN (
            'organization.branches.view',
            'organization.branches.manage'
        )
  )
  AND NOT EXISTS (
      SELECT 1
      FROM company_role_permissions existing
      WHERE existing.company_id =
                companies.company_id
        AND existing.role_id = grants.role_id
        AND existing.permission_id =
            grants.permission_id
  );
