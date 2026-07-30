<?php

declare(strict_types=1);

return [
    'version' => '180',
    'description' =>
        'Separate company owner administration from employee self service',
    'statements' => [
        <<<'SQL'
UPDATE roles
SET
    name = 'Company Owner',
    description =
        'Administers company users, security, configuration and licensed ERP operations',
    is_system = 1,
    active = 1
WHERE code = 'company_owner'
SQL,
        <<<'SQL'
DELETE FROM company_role_permissions
WHERE role_id = (
    SELECT role_id
    FROM roles
    WHERE code = 'company_owner'
)
AND permission_id IN (
    SELECT permission_id
    FROM permissions
    WHERE code IN (
        'hr.leave.self.view',
        'hr.leave.self.request',
        'attendance.self.view',
        'attendance.self.record'
    )
)
SQL,
        <<<'SQL'
DELETE FROM role_permissions
WHERE role_id = (
    SELECT role_id
    FROM roles
    WHERE code = 'company_owner'
)
AND permission_id IN (
    SELECT permission_id
    FROM permissions
    WHERE code IN (
        'hr.leave.self.view',
        'hr.leave.self.request',
        'attendance.self.view',
        'attendance.self.record'
    )
)
SQL,
    ],
];
