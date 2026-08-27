<?php

declare(strict_types=1);

return [
    'version' => '064',
    'description' => 'Remove implicit HR and organization administration from non-HR business roles',
    'preflight' => static function (\PDO $connection): string {
        $count = $connection->query(
            "SELECT COUNT(*) FROM role_permissions rp
             INNER JOIN roles r ON r.role_id=rp.role_id
             INNER JOIN permissions p ON p.permission_id=rp.permission_id
             WHERE r.code NOT IN ('system_administrator','company_owner','hr_administrator')
               AND p.code IN (
                 'hr.records.view','hr.records.manage',
                 'organization.branches.view','organization.branches.manage',
                 'organization.job_titles.view','organization.job_titles.manage',
                 'organization.departments.view','organization.departments.manage',
                 'organization.positions.view','organization.positions.manage',
                 'attendance.records.view','attendance.records.manage','attendance.team.view',
                 'hr.leave.view','hr.leave.manage','hr.leave.approve','hr.leave.team.approve',
                 'hr.leave.policy.manage','hr.leave.balance.manage'
               )"
        );
        return (int) $count->fetchColumn() > 0 ? 'apply' : 'baseline';
    },
    'statements' => [
        <<<'SQL'
DELETE rp FROM role_permissions rp
INNER JOIN roles r ON r.role_id=rp.role_id
INNER JOIN permissions p ON p.permission_id=rp.permission_id
WHERE r.code NOT IN ('system_administrator','company_owner','hr_administrator')
AND p.code IN (
 'hr.records.view','hr.records.manage',
 'organization.branches.view','organization.branches.manage',
 'organization.job_titles.view','organization.job_titles.manage',
 'organization.departments.view','organization.departments.manage',
 'organization.positions.view','organization.positions.manage',
 'attendance.records.view','attendance.records.manage','attendance.team.view',
 'hr.leave.view','hr.leave.manage','hr.leave.approve','hr.leave.team.approve',
 'hr.leave.policy.manage','hr.leave.balance.manage'
)
SQL,
        <<<'SQL'
DELETE crp FROM company_role_permissions crp
INNER JOIN roles r ON r.role_id=crp.role_id
INNER JOIN permissions p ON p.permission_id=crp.permission_id
WHERE r.code NOT IN ('system_administrator','company_owner','hr_administrator')
AND p.code IN (
 'hr.records.view','hr.records.manage',
 'organization.branches.view','organization.branches.manage',
 'organization.job_titles.view','organization.job_titles.manage',
 'organization.departments.view','organization.departments.manage',
 'organization.positions.view','organization.positions.manage',
 'attendance.records.view','attendance.records.manage','attendance.team.view',
 'hr.leave.view','hr.leave.manage','hr.leave.approve','hr.leave.team.approve',
 'hr.leave.policy.manage','hr.leave.balance.manage'
)
SQL,
    ],
];
