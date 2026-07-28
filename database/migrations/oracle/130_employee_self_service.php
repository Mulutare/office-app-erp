<?php

declare(strict_types=1);

return [
    'version' => '130',
    'description' =>
        'Add company reporting managers and employee self service access',
    'statements' => [
        'ALTER TABLE company_users'
            . ' ADD (manager_user_id NUMBER(19))',
        'ALTER TABLE company_users'
            . ' ADD CONSTRAINT fk_company_users_manager'
            . ' FOREIGN KEY (manager_user_id)'
            . ' REFERENCES users (user_id)'
            . ' ON DELETE SET NULL',
        'CREATE INDEX ix_company_users_manager'
            . ' ON company_users ('
            . 'company_id, manager_user_id, active'
            . ')',
        <<<'SQL'
MERGE INTO roles target
USING (
    SELECT
        'Employee Self Service' AS name,
        'employee_self_service' AS code,
        'Access personal ERP services and manager responsibilities'
            AS description
    FROM dual
) source
ON (target.code = source.code)
WHEN MATCHED THEN UPDATE SET
    target.name = source.name,
    target.description = source.description,
    target.is_system = 1,
    target.active = 1
WHEN NOT MATCHED THEN INSERT (
    name,
    code,
    description,
    is_system,
    active
) VALUES (
    source.name,
    source.code,
    source.description,
    1,
    1
)
SQL,
        <<<'SQL'
MERGE INTO permissions target
USING (
    SELECT
        'View Personal Leave' AS name,
        'hr.leave.self.view' AS code,
        'hr' AS module,
        'View leave requests linked to the signed-in employee account'
            AS description
    FROM dual
    UNION ALL
    SELECT
        'Request Personal Leave',
        'hr.leave.self.request',
        'hr',
        'Submit leave for the signed-in employee account'
    FROM dual
    UNION ALL
    SELECT
        'Approve Team Leave',
        'hr.leave.team.approve',
        'hr',
        'Approve leave for directly managed company users'
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
    WHERE (
            roles.code = 'employee_self_service'
            AND permissions.code IN (
                'dashboard.view',
                'hr.leave.self.view',
                'hr.leave.self.request',
                'hr.leave.team.approve'
            )
        )
       OR (
            roles.code IN (
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
                'hr.leave.self.view',
                'hr.leave.self.request',
                'hr.leave.team.approve'
            )
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
            'dashboard.view',
            'hr.leave.self.view',
            'hr.leave.self.request',
            'hr.leave.team.approve'
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
