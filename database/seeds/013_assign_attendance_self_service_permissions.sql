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
        'View Personal Attendance',
        'attendance.self.view',
        'attendance',
        'View attendance linked to the signed-in employee account',
        TRUE
    ),
    (
        'Record Personal Attendance',
        'attendance.self.record',
        'attendance',
        'Check in and out for the signed-in employee account',
        TRUE
    ),
    (
        'View Team Attendance',
        'attendance.team.view',
        'attendance',
        'View attendance for directly managed company users',
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
            'attendance.self.view',
            'attendance.self.record',
            'attendance.team.view'
        )
    )
   OR (
        roles.code IN (
            'system_administrator',
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
    )
   OR (
        roles.code = 'company_owner'
        AND permissions.code =
            'attendance.team.view'
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
        'attendance.self.view',
        'attendance.self.record',
        'attendance.team.view'
    )
ON DUPLICATE KEY UPDATE
    granted_by = VALUES(granted_by);
