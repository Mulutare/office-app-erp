<?php

declare(strict_types=1);

return [
    'version' => '048',
    'description' =>
        'Allow tenant administrators to manage licensed module enablement',

    'statements' => [
        <<<'SQL'
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
    ON permissions.code = 'administration.modules.manage'
WHERE roles.code = 'company_owner'
  AND roles.active = TRUE
  AND permissions.active = TRUE
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions existing
      WHERE existing.role_id = roles.role_id
        AND existing.permission_id = permissions.permission_id
  )
SQL,
        <<<'SQL'
INSERT INTO company_role_permissions
    (
        company_id,
        role_id,
        permission_id,
        granted_by
    )
SELECT
    companies.company_id,
    roles.role_id,
    permissions.permission_id,
    companies.owner_user_id
FROM companies
INNER JOIN roles
    ON roles.code = 'company_owner'
INNER JOIN permissions
    ON permissions.code = 'administration.modules.manage'
WHERE companies.owner_user_id IS NOT NULL
  AND roles.active = TRUE
  AND permissions.active = TRUE
ON DUPLICATE KEY UPDATE
    granted_by = VALUES(granted_by)
SQL,
    ],
];