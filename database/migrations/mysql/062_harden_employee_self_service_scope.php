<?php

declare(strict_types=1);

return [
    'version' => '062',
    'description' =>
        'Restrict attendance and leave administration to HR-authorized roles',
    'preflight' => static function (
        \PDO $connection
    ): string {
        $statement = $connection->query(
            "SELECT
                (
                    SELECT COUNT(*)
                    FROM role_permissions grants
                    INNER JOIN roles
                        ON roles.role_id = grants.role_id
                    INNER JOIN permissions
                        ON permissions.permission_id =
                            grants.permission_id
                    WHERE roles.code NOT IN (
                        'system_administrator',
                        'company_owner',
                        'hr_administrator'
                    )
                    AND permissions.code IN (
                        'attendance.records.view',
                        'attendance.records.manage',
                        'attendance.team.view',
                        'hr.leave.view',
                        'hr.leave.manage',
                        'hr.leave.approve',
                        'hr.leave.team.approve',
                        'hr.leave.policy.manage',
                        'hr.leave.balance.manage'
                    )
                )
                +
                (
                    SELECT COUNT(*)
                    FROM company_role_permissions grants
                    INNER JOIN roles
                        ON roles.role_id = grants.role_id
                    INNER JOIN permissions
                        ON permissions.permission_id =
                            grants.permission_id
                    WHERE roles.code NOT IN (
                        'system_administrator',
                        'company_owner',
                        'hr_administrator'
                    )
                    AND permissions.code IN (
                        'attendance.records.view',
                        'attendance.records.manage',
                        'attendance.team.view',
                        'hr.leave.view',
                        'hr.leave.manage',
                        'hr.leave.approve',
                        'hr.leave.team.approve',
                        'hr.leave.policy.manage',
                        'hr.leave.balance.manage'
                    )
                ) AS invalid_grants"
        );

        return (int) $statement->fetchColumn() > 0
            ? 'apply'
            : 'baseline';
    },
    'statements' => [
        <<<'SQL'
DELETE grants
FROM role_permissions grants
INNER JOIN roles
    ON roles.role_id = grants.role_id
INNER JOIN permissions
    ON permissions.permission_id =
        grants.permission_id
WHERE roles.code NOT IN (
    'system_administrator',
    'company_owner',
    'hr_administrator'
)
AND permissions.code IN (
    'attendance.records.view',
    'attendance.records.manage',
    'attendance.team.view',
    'hr.leave.view',
    'hr.leave.manage',
    'hr.leave.approve',
    'hr.leave.team.approve',
    'hr.leave.policy.manage',
    'hr.leave.balance.manage'
)
SQL,
        <<<'SQL'
DELETE grants
FROM company_role_permissions grants
INNER JOIN roles
    ON roles.role_id = grants.role_id
INNER JOIN permissions
    ON permissions.permission_id =
        grants.permission_id
WHERE roles.code NOT IN (
    'system_administrator',
    'company_owner',
    'hr_administrator'
)
AND permissions.code IN (
    'attendance.records.view',
    'attendance.records.manage',
    'attendance.team.view',
    'hr.leave.view',
    'hr.leave.manage',
    'hr.leave.approve',
    'hr.leave.team.approve',
    'hr.leave.policy.manage',
    'hr.leave.balance.manage'
)
SQL,
    ],
];