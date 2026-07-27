INSERT INTO permissions
    (
        name,
        code,
        module,
        description
    )
VALUES
    (
        'Manage Company Modules',
        'administration.modules.manage',
        'administration',
        'Enable or disable licensed ERP modules for the company'
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
        'administration.modules.manage'
WHERE roles.code = 'system_administrator'
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions
      WHERE role_permissions.role_id =
            roles.role_id
        AND role_permissions.permission_id =
            permissions.permission_id
  );
