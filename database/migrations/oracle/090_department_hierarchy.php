<?php

declare(strict_types=1);

return [
    'version' => '090',
    'description' =>
        'Add tenant-safe department hierarchy',
    'statements' => [
        'ALTER TABLE hr_departments'
            . ' ADD (parent_department_id NUMBER(10))',
        'ALTER TABLE hr_departments'
            . ' MODIFY (description VARCHAR2(500 CHAR))',
        'ALTER TABLE hr_departments'
            . ' ADD CONSTRAINT uq_hr_dept_company_id'
            . ' UNIQUE (company_id, department_id)',
        'ALTER TABLE hr_departments'
            . ' ADD CONSTRAINT fk_hr_dept_parent'
            . ' FOREIGN KEY ('
            . 'company_id, parent_department_id'
            . ') REFERENCES hr_departments ('
            . 'company_id, department_id'
            . ')',
        'CREATE INDEX ix_hr_dept_parent'
            . ' ON hr_departments ('
            . 'company_id, parent_department_id,'
            . ' active, deleted_at'
            . ')',
        <<<'SQL'
MERGE INTO permissions target
USING (
    SELECT
        'View Company Departments' AS name,
        'organization.departments.view' AS code,
        'organization' AS module,
        'View company department structure and workforce counts'
            AS description
    FROM dual
    UNION ALL
    SELECT
        'Manage Company Departments',
        'organization.departments.manage',
        'organization',
        'Create and update company department structure'
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
        roles.code IN (
            'system_administrator',
            'company_owner',
            'hr_administrator'
        )
        AND permissions.code IN (
            'organization.departments.view',
            'organization.departments.manage'
        )
    ) OR (
        roles.code = 'executive_viewer'
        AND permissions.code =
            'organization.departments.view'
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
    INNER JOIN roles
        ON roles.role_id = grants.role_id
    INNER JOIN permissions
        ON permissions.permission_id =
            grants.permission_id
    WHERE companies.deleted_at IS NULL
      AND roles.code IN (
            'system_administrator',
            'company_owner',
            'hr_administrator',
            'executive_viewer'
      )
      AND permissions.code IN (
            'organization.departments.view',
            'organization.departments.manage'
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
