INSERT INTO permissions
    (
        name,
        code,
        module,
        description
    )
VALUES
    (
        'Manage Customer Companies',
        'administration.companies.manage',
        'administration',
        'Provision customer companies and their ERP module subscriptions'
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
INNER JOIN permissions
    ON permissions.code =
        'administration.companies.manage'
WHERE roles.code = 'system_administrator'
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions
      WHERE role_permissions.role_id =
            roles.role_id
        AND role_permissions.permission_id =
            permissions.permission_id
  );
