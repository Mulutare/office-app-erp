<?php

declare(strict_types=1);

return [
    'version' => '150',
    'description' =>
        'Add tenant leave policy management access',
    'statements' => [
        <<<'SQL'
MERGE INTO permissions target
USING (
    SELECT
        'Manage Leave Policies' AS name,
        'hr.leave.policy.manage' AS code,
        'hr' AS module,
        'Create and update company leave types, entitlements and approval controls'
            AS description
    FROM dual
) source
ON (target.code = source.code)
WHEN MATCHED THEN UPDATE SET
    target.name = source.name,
    target.module = source.module,
    target.description = source.description,
    target.active = 1
WHEN NOT MATCHED THEN INSERT (
    name,
    code,
    module,
    description,
    active
) VALUES (
    source.name,
    source.code,
    source.module,
    source.description,
    1
)
SQL,
        <<<'SQL'
MERGE INTO role_permissions target
USING (
    SELECT
        roles.role_id,
        permissions.permission_id
    FROM roles
    CROSS JOIN permissions
    WHERE roles.code IN (
            'system_administrator',
            'company_owner',
            'hr_administrator'
        )
      AND permissions.code =
            'hr.leave.policy.manage'
) source
ON (
    target.role_id = source.role_id
    AND target.permission_id =
        source.permission_id
)
WHEN NOT MATCHED THEN INSERT (
    role_id,
    permission_id
) VALUES (
    source.role_id,
    source.permission_id
)
SQL,
        <<<'SQL'
MERGE INTO company_role_permissions target
USING (
    SELECT
        companies.company_id,
        grants.role_id,
        grants.permission_id,
        companies.provisioned_by AS granted_by
    FROM companies
    CROSS JOIN role_permissions grants
    INNER JOIN roles
        ON roles.role_id = grants.role_id
    INNER JOIN permissions
        ON permissions.permission_id =
            grants.permission_id
    WHERE companies.deleted_at IS NULL
      AND permissions.code =
            'hr.leave.policy.manage'
      AND roles.code IN (
            'system_administrator',
            'company_owner',
            'hr_administrator'
        )
) source
ON (
    target.company_id = source.company_id
    AND target.role_id = source.role_id
    AND target.permission_id =
        source.permission_id
)
WHEN NOT MATCHED THEN INSERT (
    company_id,
    role_id,
    permission_id,
    granted_by
) VALUES (
    source.company_id,
    source.role_id,
    source.permission_id,
    source.granted_by
)
SQL,
    ],
];
