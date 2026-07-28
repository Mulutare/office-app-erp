INSERT INTO roles
    (
        name,
        code,
        description,
        is_system,
        active
    )
VALUES
    (
        'Employee Self Service',
        'employee_self_service',
        'Access personal ERP services and manager responsibilities',
        TRUE,
        TRUE
    )
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_system = TRUE,
    active = TRUE;

INSERT INTO permissions
    (
        name,
        code,
        module,
        description,
        active
    )
VALUES
    (
        'View Personal Leave',
        'hr.leave.self.view',
        'hr',
        'View leave requests linked to the signed-in employee account',
        TRUE
    ),
    (
        'Request Personal Leave',
        'hr.leave.self.request',
        'hr',
        'Submit leave for the signed-in employee account',
        TRUE
    ),
    (
        'Approve Team Leave',
        'hr.leave.team.approve',
        'hr',
        'Approve leave for directly managed company users',
        TRUE
    )
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    module = VALUES(module),
    description = VALUES(description),
    active = TRUE;

INSERT INTO role_permissions
    (
        role_id,
        permission_id
    )
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
ON DUPLICATE KEY UPDATE
    permission_id = VALUES(permission_id);

INSERT INTO company_role_permissions
    (
        company_id,
        role_id,
        permission_id,
        granted_by
    )
SELECT
    companies.company_id,
    grants.role_id,
    grants.permission_id,
    companies.provisioned_by
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
ON DUPLICATE KEY UPDATE
    granted_by = VALUES(granted_by);
