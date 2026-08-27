UPDATE erp_modules
SET available = TRUE,
    description =
        'Daily attendance, time status and workforce presence control.'
WHERE code = 'attendance';

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
        'View Attendance Records',
        'attendance.records.view',
        'attendance',
        'View company attendance records and daily workforce status',
        TRUE
    ),
    (
        'Manage Attendance Records',
        'attendance.records.manage',
        'attendance',
        'Create and update company attendance records',
        TRUE
    ),
    (
        'View Leave Requests',
        'hr.leave.view',
        'hr',
        'View company leave requests and leave policy information',
        TRUE
    ),
    (
        'Manage Leave Requests',
        'hr.leave.manage',
        'hr',
        'Create company leave requests for authorized employees',
        TRUE
    ),
    (
        'Approve Leave Requests',
        'hr.leave.approve',
        'hr',
        'Approve or reject company leave requests',
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
        roles.code IN (
            'system_administrator',
            'company_owner',
            'hr_administrator'
        )
        AND permissions.code IN (
            'attendance.records.view',
            'attendance.records.manage',
            'hr.leave.view',
            'hr.leave.manage',
            'hr.leave.approve'
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
INNER JOIN roles
    ON roles.role_id = grants.role_id
INNER JOIN permissions
    ON permissions.permission_id =
        grants.permission_id
WHERE companies.deleted_at IS NULL
  AND permissions.code IN (
        'attendance.records.view',
        'attendance.records.manage',
        'hr.leave.view',
        'hr.leave.manage',
        'hr.leave.approve'
    )
ON DUPLICATE KEY UPDATE
    granted_by = VALUES(granted_by);

INSERT INTO hr_leave_types
    (
        company_id,
        code,
        name,
        annual_entitlement,
        requires_approval,
        active,
        created_by,
        updated_by
    )
SELECT
    companies.company_id,
    defaults.code,
    defaults.name,
    defaults.annual_entitlement,
    TRUE,
    TRUE,
    companies.provisioned_by,
    companies.provisioned_by
FROM companies
CROSS JOIN (
    SELECT
        'ANNUAL' AS code,
        'Annual Leave' AS name,
        21.00 AS annual_entitlement
    UNION ALL SELECT
        'SICK',
        'Sick Leave',
        14.00
    UNION ALL SELECT
        'COMPASSIONATE',
        'Compassionate Leave',
        5.00
    UNION ALL SELECT
        'UNPAID',
        'Unpaid Leave',
        0.00
) defaults
WHERE companies.deleted_at IS NULL
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    annual_entitlement =
        VALUES(annual_entitlement),
    requires_approval = TRUE,
    active = TRUE,
    deleted_at = NULL;
