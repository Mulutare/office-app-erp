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
WHERE roles.code = 'hr_administrator'
  AND roles.active = TRUE
  AND permissions.active = TRUE
  AND permissions.code IN (
      'organization.job_titles.view',
      'organization.job_titles.manage'
  )
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
INNER JOIN roles
    ON roles.code = 'hr_administrator'
   AND roles.active = TRUE
INNER JOIN role_permissions grants
    ON grants.role_id = roles.role_id
INNER JOIN permissions
    ON permissions.permission_id =
        grants.permission_id
   AND permissions.active = TRUE
WHERE companies.deleted_at IS NULL
  AND permissions.code IN (
      'organization.job_titles.view',
      'organization.job_titles.manage'
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
