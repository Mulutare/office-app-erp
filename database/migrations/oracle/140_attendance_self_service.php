<?php

declare(strict_types=1);

return [
    'version' => '140',
    'description' =>
        'Add attendance self service source and access',
    'statements' => [
        'ALTER TABLE attendance_records'
            . ' DROP CONSTRAINT ck_attendance_source',
        <<<'SQL'
ALTER TABLE attendance_records
    ADD CONSTRAINT ck_attendance_source
        CHECK (
            source IN (
                'manual',
                'import',
                'device',
                'system',
                'self_service'
            )
        )
SQL,
        <<<'SQL'
MERGE INTO permissions target
USING (
    SELECT
        'View Personal Attendance' AS name,
        'attendance.self.view' AS code,
        'attendance' AS module,
        'View attendance linked to the signed-in employee account'
            AS description
    FROM dual
    UNION ALL
    SELECT
        'Record Personal Attendance',
        'attendance.self.record',
        'attendance',
        'Check in and out for the signed-in employee account'
    FROM dual
    UNION ALL
    SELECT
        'View Team Attendance',
        'attendance.team.view',
        'attendance',
        'View attendance for directly managed company users'
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
            'employee_self_service',
            'system_administrator',
            'company_owner',
            'executive_viewer',
            'hr_administrator',
            'it_administrator',
            'finance_officer',
            'finance_approver',
            'business_development_officer',
            'auditor'
        )
      AND permissions.code IN (
            'attendance.self.view',
            'attendance.self.record',
            'attendance.team.view'
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
    CROSS JOIN role_permissions grants
    INNER JOIN permissions
        ON permissions.permission_id =
            grants.permission_id
    WHERE companies.deleted_at IS NULL
      AND permissions.code IN (
            'attendance.self.view',
            'attendance.self.record',
            'attendance.team.view'
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
