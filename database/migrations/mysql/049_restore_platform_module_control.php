<?php

declare(strict_types=1);

return [
    'version' => '049',
    'description' =>
        'Restore platform-only module assignment and activation control',

    'statements' => [
        <<<'SQL'
DELETE company_permissions
FROM company_role_permissions company_permissions
INNER JOIN roles
    ON roles.role_id = company_permissions.role_id
INNER JOIN permissions
    ON permissions.permission_id =
       company_permissions.permission_id
WHERE roles.code = 'company_owner'
  AND permissions.code =
      'administration.modules.manage'
SQL,
        <<<'SQL'
DELETE templates
FROM role_permissions templates
INNER JOIN roles
    ON roles.role_id = templates.role_id
INNER JOIN permissions
    ON permissions.permission_id =
       templates.permission_id
WHERE roles.code = 'company_owner'
  AND permissions.code =
      'administration.modules.manage'
SQL,
    ],
];