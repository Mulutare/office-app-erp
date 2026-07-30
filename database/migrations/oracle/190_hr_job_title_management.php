<?php

declare(strict_types=1);

return [
    'version' => '190',
    'description' =>
        'Allow HR administrators to maintain job-title prerequisites',
    'statements' => [
        <<<'SQL'
MERGE INTO role_permissions target
USING (
    SELECT
        roles.role_id,
        permissions.permission_id
    FROM roles
    CROSS JOIN permissions
    WHERE roles.code = 'hr_administrator'
      AND roles.active = 1
      AND permissions.active = 1
      AND permissions.code IN (
          'organization.job_titles.view',
          'organization.job_titles.manage'
      )
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
    INNER JOIN roles
        ON roles.code = 'hr_administrator'
       AND roles.active = 1
    INNER JOIN role_permissions grants
        ON grants.role_id = roles.role_id
    INNER JOIN permissions
        ON permissions.permission_id =
            grants.permission_id
       AND permissions.active = 1
    WHERE companies.deleted_at IS NULL
      AND permissions.code IN (
          'organization.job_titles.view',
          'organization.job_titles.manage'
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
