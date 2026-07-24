/*
 * Baseline permission assignments for standard OfficeApp roles.
 *
 * This seed is additive and idempotent:
 * - it can be run repeatedly without duplicate assignments;
 * - it does not remove organization-specific grants;
 * - it uses stable role and permission codes, not numeric IDs.
 */

INSERT INTO role_permissions
    (
        role_id,
        permission_id
    )
SELECT
    roles.role_id,
    permissions.permission_id
FROM (
    SELECT
        'executive_viewer' AS role_code,
        'dashboard.view' AS permission_code
    UNION ALL SELECT
        'executive_viewer',
        'hr.records.view'
    UNION ALL SELECT
        'executive_viewer',
        'it.records.view'
    UNION ALL SELECT
        'executive_viewer',
        'finance.records.view'
    UNION ALL SELECT
        'executive_viewer',
        'business.records.view'

    UNION ALL SELECT
        'hr_administrator',
        'dashboard.view'
    UNION ALL SELECT
        'hr_administrator',
        'hr.records.view'
    UNION ALL SELECT
        'hr_administrator',
        'hr.records.manage'

    UNION ALL SELECT
        'it_administrator',
        'dashboard.view'
    UNION ALL SELECT
        'it_administrator',
        'it.records.view'
    UNION ALL SELECT
        'it_administrator',
        'it.records.manage'

    UNION ALL SELECT
        'finance_officer',
        'dashboard.view'
    UNION ALL SELECT
        'finance_officer',
        'finance.records.view'
    UNION ALL SELECT
        'finance_officer',
        'finance.records.manage'

    UNION ALL SELECT
        'finance_approver',
        'dashboard.view'
    UNION ALL SELECT
        'finance_approver',
        'finance.records.view'
    UNION ALL SELECT
        'finance_approver',
        'finance.requests.approve'

    UNION ALL SELECT
        'business_development_officer',
        'dashboard.view'
    UNION ALL SELECT
        'business_development_officer',
        'business.records.view'
    UNION ALL SELECT
        'business_development_officer',
        'business.records.manage'

    UNION ALL SELECT
        'auditor',
        'dashboard.view'
    UNION ALL SELECT
        'auditor',
        'audit.logs.view'
    UNION ALL SELECT
        'auditor',
        'hr.records.view'
    UNION ALL SELECT
        'auditor',
        'it.records.view'
    UNION ALL SELECT
        'auditor',
        'finance.records.view'
    UNION ALL SELECT
        'auditor',
        'business.records.view'
) AS baseline
INNER JOIN roles
    ON roles.code = baseline.role_code
   AND roles.active = TRUE
INNER JOIN permissions
    ON permissions.code = baseline.permission_code
   AND permissions.active = TRUE
LEFT JOIN role_permissions existing
    ON existing.role_id = roles.role_id
   AND existing.permission_id = permissions.permission_id
WHERE existing.role_id IS NULL;
