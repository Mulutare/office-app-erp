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
        'Manage Leave Balances',
        'hr.leave.balance.manage',
        'hr',
        'Manage employee annual allocations, carry-over and balance adjustments',
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
INNER JOIN permissions
    ON permissions.code =
        'hr.leave.balance.manage'
WHERE roles.code IN (
    'system_administrator',
    'company_owner',
    'hr_administrator'
)
ON DUPLICATE KEY UPDATE
    permission_id = VALUES(permission_id);

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
INNER JOIN permissions
    ON permissions.permission_id =
        grants.permission_id
WHERE companies.deleted_at IS NULL
  AND permissions.code =
        'hr.leave.balance.manage'
  AND roles.code IN (
        'system_administrator',
        'company_owner',
        'hr_administrator'
  )
ON DUPLICATE KEY UPDATE
    granted_by = VALUES(granted_by);
