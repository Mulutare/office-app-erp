INSERT INTO permissions (name, code, module, description, active)
VALUES
    ('Manage API Clients', 'administration.api_clients.manage', 'administration', 'Create, rotate and revoke third-party API clients', TRUE),
    ('Manage API Webhooks', 'administration.api_webhooks.manage', 'administration', 'Manage third-party webhook subscriptions and secrets', TRUE),
    ('Replay API Webhooks', 'administration.api_webhooks.replay', 'administration', 'Replay failed third-party webhook deliveries', TRUE)
ON DUPLICATE KEY UPDATE
    name = VALUES(name), module = VALUES(module), description = VALUES(description), active = TRUE;

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.role_id, permissions.permission_id
FROM roles CROSS JOIN permissions
WHERE roles.code IN ('system_administrator', 'company_owner')
  AND permissions.code LIKE 'administration.api_%'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions existing
      WHERE existing.role_id = roles.role_id AND existing.permission_id = permissions.permission_id
  );

INSERT INTO company_role_permissions (company_id, role_id, permission_id, granted_by)
SELECT companies.company_id, grants.role_id, grants.permission_id, companies.provisioned_by
FROM companies CROSS JOIN role_permissions grants
INNER JOIN roles ON roles.role_id = grants.role_id
INNER JOIN permissions ON permissions.permission_id = grants.permission_id
WHERE companies.deleted_at IS NULL
  AND roles.code IN ('system_administrator', 'company_owner')
  AND permissions.code LIKE 'administration.api_%'
  AND NOT EXISTS (
      SELECT 1 FROM company_role_permissions existing
      WHERE existing.company_id = companies.company_id
        AND existing.role_id = grants.role_id AND existing.permission_id = grants.permission_id
  );
