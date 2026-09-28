<?php

declare(strict_types=1);

return [
    'version' => '099',
    'description' => 'Preserve sales manager incentive settlement permission in role and company templates',
    'statements' => [
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id
FROM roles r
JOIN permissions p ON p.code='sales.incentive.settle' AND p.active=TRUE
WHERE r.code='sales_manager' AND r.active=TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT c.company_id,rp.role_id,rp.permission_id,c.provisioned_by
FROM companies c
JOIN role_permissions rp ON 1=1
JOIN roles r ON r.role_id=rp.role_id AND r.active=TRUE
JOIN permissions p ON p.permission_id=rp.permission_id AND p.active=TRUE
WHERE c.deleted_at IS NULL
  AND r.code='sales_manager'
  AND p.code='sales.incentive.settle'
SQL,
    ],
];
