SET NAMES utf8mb4;

INSERT INTO users
    (
        username,
        email,
        password_hash,
        display_name,
        is_platform_admin,
        active,
        must_change_password,
        failed_login_count
    )
VALUES
    (
        'test_platform_admin',
        'platform-admin@example.test',
        '$2y$10$llAbMeWCFDcP2O5LoAxzlePCs.zee4oKKxGuezj7oKAqUq0CEvO96',
        'Test Platform Administrator',
        TRUE,
        TRUE,
        FALSE,
        0
    )
ON DUPLICATE KEY UPDATE
    password_hash = VALUES(password_hash),
    display_name = VALUES(display_name),
    is_platform_admin = TRUE,
    active = TRUE,
    must_change_password = FALSE,
    failed_login_count = 0,
    locked_until = NULL,
    deleted_at = NULL;

SET @test_user_id := (
    SELECT user_id
    FROM users
    WHERE username = 'test_platform_admin'
    LIMIT 1
);

SET @default_company_id := (
    SELECT company_id
    FROM companies
    WHERE code = 'default'
    LIMIT 1
);

SET @system_role_id := (
    SELECT role_id
    FROM roles
    WHERE code = 'system_administrator'
    LIMIT 1
);

INSERT INTO user_roles
    (
        user_id,
        role_id,
        assigned_by
    )
VALUES
    (
        @test_user_id,
        @system_role_id,
        @test_user_id
    )
ON DUPLICATE KEY UPDATE
    assigned_by = VALUES(assigned_by);

INSERT INTO company_users
    (
        company_id,
        user_id,
        active,
        is_default,
        assigned_by
    )
VALUES
    (
        @default_company_id,
        @test_user_id,
        TRUE,
        TRUE,
        @test_user_id
    )
ON DUPLICATE KEY UPDATE
    active = TRUE,
    is_default = TRUE,
    assigned_by = VALUES(assigned_by);

INSERT INTO company_user_roles
    (
        company_id,
        user_id,
        role_id,
        assigned_by
    )
VALUES
    (
        @default_company_id,
        @test_user_id,
        @system_role_id,
        @test_user_id
    )
ON DUPLICATE KEY UPDATE
    assigned_by = VALUES(assigned_by);

INSERT INTO companies
    (
        code,
        name,
        legal_name,
        contact_email,
        country_code,
        default_currency,
        timezone,
        subscription_status,
        approval_status,
        approved_at,
        active
    )
VALUES
    (
        'test_tenant_a',
        'Test Tenant A',
        'Test Tenant A Limited',
        'tenant-a@example.test',
        'KE',
        'KES',
        'Africa/Nairobi',
        'active',
        'approved',
        NOW(),
        TRUE
    ),
    (
        'test_tenant_b',
        'Test Tenant B',
        'Test Tenant B Limited',
        'tenant-b@example.test',
        'KE',
        'KES',
        'Africa/Nairobi',
        'active',
        'approved',
        NOW(),
        TRUE
    )
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    subscription_status = 'active',
    approval_status = 'approved',
    approved_at = COALESCE(approved_at, NOW()),
    active = TRUE,
    deleted_at = NULL;


/*
 * Least-privilege users used only by the HTTP access-gate smoke test.
 *
 * These accounts use the documented test password from compose.test.yaml.
 * The restricted role intentionally receives no permissions.
 */
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
        'Security Test Restricted',
        'security_test_restricted',
        'Test-only role without dashboard access',
        FALSE,
        TRUE
    )
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_system = FALSE,
    active = TRUE;

INSERT INTO roles
    (
        role_id,
        name,
        code,
        description,
        is_system,
        active
    )
VALUES
    (
        9100,
        'Security Test Delegated Administrator',
        'security_test_delegated_admin',
        'Test-only administrator with bounded user and role authority',
        FALSE,
        TRUE
    ),
    (
        9101,
        'Security Test Privileged Role',
        'security_test_privileged_role',
        'Test-only role containing authority unavailable to the delegated administrator',
        FALSE,
        TRUE
    ),
    (
        9102,
        'Security Test Permission Target',
        'security_test_permission_target',
        'Test-only role used to probe permission escalation',
        FALSE,
        TRUE
    )
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_system = FALSE,
    active = TRUE;

INSERT INTO permissions
    (
        permission_id,
        name,
        code,
        module,
        description,
        active
    )
VALUES
    (
        9101,
        'Security Test Elevated Authority',
        'security_test.elevated',
        'security_test',
        'Test-only permission that delegated administrators must not grant',
        TRUE
    )
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    module = VALUES(module),
    description = VALUES(description),
    active = TRUE;

SET @restricted_role_id := (
    SELECT role_id
    FROM roles
    WHERE code = 'security_test_restricted'
    LIMIT 1
);

SET @executive_viewer_role_id := (
    SELECT role_id
    FROM roles
    WHERE code = 'executive_viewer'
    LIMIT 1
);

SET @company_owner_role_id := (
    SELECT role_id
    FROM roles
    WHERE code = 'company_owner'
    LIMIT 1
);

INSERT INTO users
    (
        username,
        email,
        password_hash,
        display_name,
        is_platform_admin,
        active,
        must_change_password,
        failed_login_count
    )
VALUES
    (
        'test_no_dashboard',
        'no-dashboard@example.test',
        '$2y$10$llAbMeWCFDcP2O5LoAxzlePCs.zee4oKKxGuezj7oKAqUq0CEvO96',
        'Test No Dashboard',
        FALSE,
        TRUE,
        FALSE,
        0
    ),
    (
        'test_password_change',
        'password-change@example.test',
        '$2y$10$llAbMeWCFDcP2O5LoAxzlePCs.zee4oKKxGuezj7oKAqUq0CEvO96',
        'Test Password Change',
        FALSE,
        TRUE,
        TRUE,
        0
    ),
    (
        'test_company_admin',
        'company-admin@example.test',
        '$2y$10$llAbMeWCFDcP2O5LoAxzlePCs.zee4oKKxGuezj7oKAqUq0CEvO96',
        'Test Company Administrator',
        FALSE,
        TRUE,
        FALSE,
        0
    )
ON DUPLICATE KEY UPDATE
    password_hash = VALUES(password_hash),
    display_name = VALUES(display_name),
    is_platform_admin = FALSE,
    active = TRUE,
    must_change_password =
        VALUES(must_change_password),
    failed_login_count = 0,
    locked_until = NULL,
    deleted_at = NULL;

SET @restricted_user_id := (
    SELECT user_id
    FROM users
    WHERE username = 'test_no_dashboard'
    LIMIT 1
);

SET @password_change_user_id := (
    SELECT user_id
    FROM users
    WHERE username = 'test_password_change'
    LIMIT 1
);

SET @company_admin_user_id := (
    SELECT user_id
    FROM users
    WHERE username = 'test_company_admin'
    LIMIT 1
);

SET @tenant_a_company_id := (
    SELECT company_id
    FROM companies
    WHERE code = 'test_tenant_a'
    LIMIT 1
);

SET @tenant_b_company_id := (
    SELECT company_id
    FROM companies
    WHERE code = 'test_tenant_b'
    LIMIT 1
);

INSERT INTO users
    (
        user_id,
        username,
        email,
        password_hash,
        display_name,
        is_platform_admin,
        active,
        must_change_password,
        failed_login_count
    )
VALUES
    (
        910001,
        'test_tenant_a_admin',
        'tenant-a-admin@example.test',
        '$2y$10$llAbMeWCFDcP2O5LoAxzlePCs.zee4oKKxGuezj7oKAqUq0CEvO96',
        'Test Tenant A Administrator',
        FALSE,
        TRUE,
        FALSE,
        0
    ),
    (
        910002,
        'test_tenant_b_user',
        'tenant-b-user@example.test',
        '$2y$10$llAbMeWCFDcP2O5LoAxzlePCs.zee4oKKxGuezj7oKAqUq0CEvO96',
        'Test Tenant B User',
        FALSE,
        TRUE,
        FALSE,
        0
    ),
    (
        910003,
        'test_tenant_a_delegated_admin',
        'tenant-a-delegated-admin@example.test',
        '$2y$10$llAbMeWCFDcP2O5LoAxzlePCs.zee4oKKxGuezj7oKAqUq0CEvO96',
        'Test Tenant A Delegated Administrator',
        FALSE,
        TRUE,
        FALSE,
        0
    ),
    (
        910004,
        'test_tenant_a_target',
        'tenant-a-target@example.test',
        '$2y$10$llAbMeWCFDcP2O5LoAxzlePCs.zee4oKKxGuezj7oKAqUq0CEvO96',
        'Test Tenant A Target',
        FALSE,
        TRUE,
        FALSE,
        0
    )
ON DUPLICATE KEY UPDATE
    password_hash = VALUES(password_hash),
    display_name = VALUES(display_name),
    is_platform_admin = FALSE,
    active = TRUE,
    must_change_password = FALSE,
    failed_login_count = 0,
    locked_until = NULL,
    deleted_at = NULL;

SET @tenant_a_admin_user_id := (
    SELECT user_id
    FROM users
    WHERE username = 'test_tenant_a_admin'
    LIMIT 1
);

SET @tenant_b_user_id := (
    SELECT user_id
    FROM users
    WHERE username = 'test_tenant_b_user'
    LIMIT 1
);

SET @tenant_a_delegated_admin_id := (
    SELECT user_id
    FROM users
    WHERE username =
        'test_tenant_a_delegated_admin'
    LIMIT 1
);

SET @tenant_a_target_user_id := (
    SELECT user_id
    FROM users
    WHERE username = 'test_tenant_a_target'
    LIMIT 1
);

INSERT INTO company_users
    (
        company_id,
        user_id,
        active,
        is_default,
        assigned_by
    )
VALUES
    (
        @default_company_id,
        @restricted_user_id,
        TRUE,
        TRUE,
        @test_user_id
    ),
    (
        @default_company_id,
        @password_change_user_id,
        TRUE,
        TRUE,
        @test_user_id
    ),
    (
        @default_company_id,
        @company_admin_user_id,
        TRUE,
        TRUE,
        @test_user_id
    ),
    (
        @tenant_a_company_id,
        @tenant_a_admin_user_id,
        TRUE,
        TRUE,
        @test_user_id
    ),
    (
        @tenant_b_company_id,
        @tenant_b_user_id,
        TRUE,
        TRUE,
        @test_user_id
    ),
    (
        @tenant_a_company_id,
        @tenant_a_delegated_admin_id,
        TRUE,
        TRUE,
        @tenant_a_admin_user_id
    ),
    (
        @tenant_a_company_id,
        @tenant_a_target_user_id,
        TRUE,
        TRUE,
        @tenant_a_admin_user_id
    )
ON DUPLICATE KEY UPDATE
    active = TRUE,
    is_default = TRUE,
    assigned_by = VALUES(assigned_by);

INSERT INTO user_roles
    (
        user_id,
        role_id,
        assigned_by
    )
VALUES
    (
        @restricted_user_id,
        @restricted_role_id,
        @test_user_id
    ),
    (
        @password_change_user_id,
        @executive_viewer_role_id,
        @test_user_id
    ),
    (
        @company_admin_user_id,
        @company_owner_role_id,
        @test_user_id
    ),
    (
        @tenant_a_admin_user_id,
        @company_owner_role_id,
        @test_user_id
    ),
    (
        @tenant_b_user_id,
        @executive_viewer_role_id,
        @test_user_id
    ),
    (
        @tenant_a_delegated_admin_id,
        9100,
        @tenant_a_admin_user_id
    ),
    (
        @tenant_a_target_user_id,
        @executive_viewer_role_id,
        @tenant_a_admin_user_id
    )
ON DUPLICATE KEY UPDATE
    assigned_by = VALUES(assigned_by);


/*
 * Phase B1 cross-company branch records.
 */
INSERT INTO organization_branches
    (
        branch_id,
        company_id,
        code,
        name,
        contact_email,
        contact_phone,
        address_line,
        city,
        country_code,
        timezone,
        is_head_office,
        active,
        created_by,
        updated_by
    )
VALUES
    (
        930001,
        @tenant_a_company_id,
        'TA-HQ',
        'Tenant A Headquarters',
        'hq-a@example.test',
        '+254700000001',
        'Tenant A Avenue',
        'Nairobi',
        'KE',
        'Africa/Nairobi',
        TRUE,
        TRUE,
        @tenant_a_admin_user_id,
        @tenant_a_admin_user_id
    ),
    (
        930002,
        @tenant_b_company_id,
        'TB-CONF',
        'Tenant B Confidential Branch',
        'confidential-b@example.test',
        '+254700000002',
        'Tenant B Close',
        'Mombasa',
        'KE',
        'Africa/Nairobi',
        TRUE,
        TRUE,
        @tenant_b_user_id,
        @tenant_b_user_id
    )
ON DUPLICATE KEY UPDATE
    company_id = VALUES(company_id),
    code = VALUES(code),
    name = VALUES(name),
    contact_email = VALUES(contact_email),
    contact_phone = VALUES(contact_phone),
    address_line = VALUES(address_line),
    city = VALUES(city),
    country_code = VALUES(country_code),
    timezone = VALUES(timezone),
    is_head_office = TRUE,
    active = TRUE,
    deleted_at = NULL;


/*
 * Phase B2 cross-company job-title records.
 */
INSERT INTO organization_job_titles
    (
        job_title_id,
        company_id,
        code,
        name,
        job_family,
        grade_level,
        description,
        active,
        created_by,
        updated_by
    )
VALUES
    (
        940001,
        @tenant_a_company_id,
        'TA-SEC-ANL',
        'Tenant A Security Analyst',
        'Information Security',
        'P3',
        'Tenant A job-title isolation fixture',
        TRUE,
        @tenant_a_admin_user_id,
        @tenant_a_admin_user_id
    ),
    (
        940002,
        @tenant_b_company_id,
        'TB-CONF-MGR',
        'Tenant B Confidential Manager',
        'Confidential Operations',
        'M2',
        'Tenant B job-title isolation fixture',
        TRUE,
        @tenant_b_user_id,
        @tenant_b_user_id
    )
ON DUPLICATE KEY UPDATE
    company_id = VALUES(company_id),
    code = VALUES(code),
    name = VALUES(name),
    job_family = VALUES(job_family),
    grade_level = VALUES(grade_level),
    description = VALUES(description),
    active = TRUE,
    deleted_at = NULL;


/*
 * Phase A6 cross-company HR and Finance records.
 */
INSERT INTO company_modules
    (
        company_id,
        module_id,
        enabled,
        license_status,
        licensed_at,
        expires_at,
        updated_by
    )
SELECT
    @tenant_b_company_id,
    modules.module_id,
    TRUE,
    'active',
    NOW(),
    NULL,
    @test_user_id
FROM erp_modules modules
WHERE modules.code = 'finance'
ON DUPLICATE KEY UPDATE
    enabled = TRUE,
    license_status = 'active',
    licensed_at = VALUES(licensed_at),
    expires_at = NULL,
    updated_by = VALUES(updated_by);

INSERT INTO hr_departments
    (
        department_id,
        company_id,
        code,
        name,
        parent_department_id,
        description,
        active,
        created_by,
        updated_by
    )
VALUES
    (
        9201,
        @tenant_a_company_id,
        'TA-SEC',
        'Tenant A Security',
        NULL,
        'Cross-company isolation fixture',
        TRUE,
        @tenant_a_admin_user_id,
        @tenant_a_admin_user_id
    ),
    (
        9202,
        @tenant_b_company_id,
        'TB-SEC',
        'Tenant B Confidential',
        NULL,
        'Cross-company isolation fixture',
        TRUE,
        @tenant_b_user_id,
        @tenant_b_user_id
    ),
    (
        9203,
        @tenant_a_company_id,
        'TA-SEC-OPS',
        'Tenant A Security Operations',
        9201,
        'Tenant A department hierarchy fixture',
        TRUE,
        @tenant_a_admin_user_id,
        @tenant_a_admin_user_id
    )
ON DUPLICATE KEY UPDATE
    company_id = VALUES(company_id),
    code = VALUES(code),
    name = VALUES(name),
    parent_department_id =
        VALUES(parent_department_id),
    description = VALUES(description),
    active = TRUE,
    deleted_at = NULL;

/*
 * Phase B4 cross-company position records.
 */
INSERT INTO organization_positions
    (
        position_id,
        company_id,
        code,
        name,
        branch_id,
        department_id,
        job_title_id,
        approved_headcount,
        status,
        description,
        created_by,
        updated_by
    )
VALUES
    (
        950001,
        @tenant_a_company_id,
        'TA-SEC-ANL-NBO',
        'Tenant A Security Analyst Position',
        930001,
        9201,
        940001,
        3,
        'open',
        'Tenant A position isolation fixture',
        @tenant_a_admin_user_id,
        @tenant_a_admin_user_id
    ),
    (
        950002,
        @tenant_b_company_id,
        'TB-CONF-MGR-MSA',
        'Tenant B Confidential Position',
        930002,
        9202,
        940002,
        1,
        'planned',
        'Tenant B position isolation fixture',
        @tenant_b_user_id,
        @tenant_b_user_id
    )
ON DUPLICATE KEY UPDATE
    company_id = VALUES(company_id),
    code = VALUES(code),
    name = VALUES(name),
    branch_id = VALUES(branch_id),
    department_id = VALUES(department_id),
    job_title_id = VALUES(job_title_id),
    approved_headcount =
        VALUES(approved_headcount),
    status = VALUES(status),
    description = VALUES(description),
    deleted_at = NULL;

INSERT INTO hr_employees
    (
        employee_id,
        company_id,
        employee_number,
        user_id,
        first_name,
        last_name,
        work_email,
        department_id,
        job_title,
        employment_type,
        employment_status,
        hire_date,
        created_by,
        updated_by
    )
VALUES
    (
        920001,
        @tenant_a_company_id,
        'TA-EMP-SEC',
        NULL,
        'Alice',
        'TenantA',
        'alice-tenant-a@example.test',
        9201,
        'Tenant A Analyst',
        'permanent',
        'active',
        '2026-01-01',
        @tenant_a_admin_user_id,
        @tenant_a_admin_user_id
    ),
    (
        920002,
        @tenant_b_company_id,
        'TB-EMP-CONFIDENTIAL',
        NULL,
        'Bob',
        'TenantB',
        'bob-tenant-b@example.test',
        9202,
        'Tenant B Confidential Analyst',
        'permanent',
        'active',
        '2026-01-01',
        @tenant_b_user_id,
        @tenant_b_user_id
    )
ON DUPLICATE KEY UPDATE
    company_id = VALUES(company_id),
    employee_number = VALUES(employee_number),
    user_id = NULL,
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    work_email = VALUES(work_email),
    department_id = VALUES(department_id),
    job_title = VALUES(job_title),
    employment_status = 'active',
    deleted_at = NULL;

INSERT INTO hr_employee_position_assignments
    (
        assignment_id,
        company_id,
        employee_id,
        position_id,
        effective_from,
        assignment_status,
        current_marker,
        position_code_snapshot,
        position_name_snapshot,
        department_name_snapshot,
        job_title_name_snapshot,
        branch_name_snapshot,
        notes,
        created_by,
        updated_by
    )
VALUES
    (
        960001,
        @tenant_a_company_id,
        920001,
        950001,
        '2026-01-01',
        'current',
        1,
        'TA-SEC-ANL-NBO',
        'Tenant A Security Analyst Position',
        'Tenant A Operations',
        'Tenant A Security Analyst',
        'Tenant A Nairobi Headquarters',
        'Initial assignment fixture',
        @tenant_a_admin_user_id,
        @tenant_a_admin_user_id
    )
ON DUPLICATE KEY UPDATE
    position_id = VALUES(position_id),
    effective_from = VALUES(effective_from),
    effective_to = NULL,
    assignment_status = 'current',
    current_marker = 1,
    position_code_snapshot =
        VALUES(position_code_snapshot),
    position_name_snapshot =
        VALUES(position_name_snapshot),
    department_name_snapshot =
        VALUES(department_name_snapshot),
    job_title_name_snapshot =
        VALUES(job_title_name_snapshot),
    branch_name_snapshot =
        VALUES(branch_name_snapshot),
    notes = VALUES(notes);

INSERT INTO finance_expense_categories
    (
        category_id,
        company_id,
        code,
        name,
        description,
        active,
        created_by,
        updated_by
    )
VALUES
    (
        9201,
        @tenant_a_company_id,
        'TA-SEC',
        'Tenant A Security Expense',
        'Cross-company isolation fixture',
        TRUE,
        @tenant_a_admin_user_id,
        @tenant_a_admin_user_id
    ),
    (
        9202,
        @tenant_b_company_id,
        'TB-SEC',
        'Tenant B Confidential Expense',
        'Cross-company isolation fixture',
        TRUE,
        @tenant_b_user_id,
        @tenant_b_user_id
    )
ON DUPLICATE KEY UPDATE
    company_id = VALUES(company_id),
    code = VALUES(code),
    name = VALUES(name),
    description = VALUES(description),
    active = TRUE,
    deleted_at = NULL;

INSERT INTO finance_expense_requests
    (
        expense_request_id,
        company_id,
        request_number,
        requested_by_employee_id,
        category_id,
        title,
        description,
        amount,
        currency,
        expense_date,
        status,
        submitted_at,
        created_by,
        updated_by
    )
VALUES
    (
        920001,
        @tenant_a_company_id,
        'TA-EXP-SEC',
        920001,
        9201,
        'Tenant A Security Expense',
        'Tenant A confidential finance fixture',
        1250.00,
        'KES',
        '2026-07-01',
        'submitted',
        NOW(),
        @tenant_a_admin_user_id,
        @tenant_a_admin_user_id
    ),
    (
        920002,
        @tenant_b_company_id,
        'TB-EXP-CONFIDENTIAL',
        920002,
        9202,
        'Tenant B Confidential Expense',
        'Tenant B confidential finance fixture',
        2450.00,
        'KES',
        '2026-07-02',
        'submitted',
        NOW(),
        @tenant_b_user_id,
        @tenant_b_user_id
    )
ON DUPLICATE KEY UPDATE
    company_id = VALUES(company_id),
    request_number = VALUES(request_number),
    requested_by_employee_id =
        VALUES(requested_by_employee_id),
    category_id = VALUES(category_id),
    title = VALUES(title),
    description = VALUES(description),
    amount = VALUES(amount),
    currency = VALUES(currency),
    expense_date = VALUES(expense_date),
    status = VALUES(status),
    deleted_at = NULL;

INSERT INTO company_user_roles
    (
        company_id,
        user_id,
        role_id,
        assigned_by
    )
VALUES
    (
        @default_company_id,
        @restricted_user_id,
        @restricted_role_id,
        @test_user_id
    ),
    (
        @default_company_id,
        @password_change_user_id,
        @executive_viewer_role_id,
        @test_user_id
    ),
    (
        @default_company_id,
        @company_admin_user_id,
        @company_owner_role_id,
        @test_user_id
    ),
    (
        @tenant_a_company_id,
        @tenant_a_admin_user_id,
        @company_owner_role_id,
        @test_user_id
    ),
    (
        @tenant_b_company_id,
        @tenant_b_user_id,
        @executive_viewer_role_id,
        @test_user_id
    ),
    (
        @tenant_a_company_id,
        @tenant_a_delegated_admin_id,
        9100,
        @tenant_a_admin_user_id
    ),
    (
        @tenant_a_company_id,
        @tenant_a_target_user_id,
        @executive_viewer_role_id,
        @tenant_a_admin_user_id
    )
ON DUPLICATE KEY UPDATE
    assigned_by = VALUES(assigned_by);

UPDATE companies
SET owner_user_id = @tenant_a_admin_user_id,
    approved_by = @test_user_id
WHERE company_id = @tenant_a_company_id;

INSERT INTO company_role_permissions
    (
        company_id,
        role_id,
        permission_id,
        granted_by
    )
SELECT
    tenant.company_id,
    templates.role_id,
    templates.permission_id,
    @test_user_id
FROM (
    SELECT @tenant_a_company_id AS company_id
    UNION ALL
    SELECT @tenant_b_company_id AS company_id
) tenant
CROSS JOIN role_permissions templates
WHERE tenant.company_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    granted_by = VALUES(granted_by);

DELETE FROM company_role_permissions
WHERE company_id = @tenant_a_company_id
  AND role_id IN (9100, 9101, 9102);

INSERT INTO company_role_permissions
    (
        company_id,
        role_id,
        permission_id,
        granted_by
    )
SELECT
    @tenant_a_company_id,
    9100,
    permissions.permission_id,
    @tenant_a_admin_user_id
FROM permissions
WHERE permissions.code IN (
    'dashboard.view',
    'administration.users.manage',
    'administration.roles.manage'
)
UNION ALL
SELECT
    @tenant_a_company_id,
    9101,
    permissions.permission_id,
    @tenant_a_admin_user_id
FROM permissions
WHERE permissions.code IN (
    'dashboard.view',
    'security_test.elevated'
)
UNION ALL
SELECT
    @tenant_a_company_id,
    9102,
    permissions.permission_id,
    @tenant_a_admin_user_id
FROM permissions
WHERE permissions.code = 'dashboard.view';

DELETE FROM company_role_permissions
WHERE company_id = @default_company_id
  AND role_id = @restricted_role_id;


/*
 * Phase A5 lifecycle and module-entitlement fixtures.
 *
 * Tenant A deliberately has HR licensed and enabled. Finance is marked
 * enabled but not licensed so direct-route checks must fail closed.
 */
INSERT INTO company_modules
    (
        company_id,
        module_id,
        enabled,
        license_status,
        licensed_at,
        expires_at,
        updated_by
    )
SELECT
    @tenant_a_company_id,
    modules.module_id,
    TRUE,
    CASE
        WHEN modules.code = 'hr'
            THEN 'active'
        ELSE 'not_licensed'
    END,
    CASE
        WHEN modules.code = 'hr'
            THEN NOW()
        ELSE NULL
    END,
    NULL,
    @test_user_id
FROM erp_modules modules
WHERE modules.code IN ('hr', 'finance')
ON DUPLICATE KEY UPDATE
    enabled = VALUES(enabled),
    license_status = VALUES(license_status),
    licensed_at = VALUES(licensed_at),
    expires_at = NULL,
    updated_by = VALUES(updated_by);

INSERT INTO companies
    (
        code,
        name,
        contact_email,
        country_code,
        default_currency,
        timezone,
        subscription_status,
        subscription_expires_at,
        approval_status,
        approved_at,
        active
    )
VALUES
    (
        'test_company_pending',
        'Test Pending Company',
        'pending-company@example.test',
        'KE',
        'KES',
        'Africa/Nairobi',
        'active',
        NULL,
        'pending',
        NULL,
        TRUE
    ),
    (
        'test_company_inactive',
        'Test Inactive Company',
        'inactive-company@example.test',
        'KE',
        'KES',
        'Africa/Nairobi',
        'active',
        NULL,
        'approved',
        NOW(),
        FALSE
    ),
    (
        'test_company_suspended',
        'Test Suspended Company',
        'suspended-company@example.test',
        'KE',
        'KES',
        'Africa/Nairobi',
        'suspended',
        NULL,
        'approved',
        NOW(),
        TRUE
    ),
    (
        'test_company_expired',
        'Test Expired Company',
        'expired-company@example.test',
        'KE',
        'KES',
        'Africa/Nairobi',
        'active',
        DATE_SUB(NOW(), INTERVAL 1 DAY),
        'approved',
        NOW(),
        TRUE
    )
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    contact_email = VALUES(contact_email),
    subscription_status =
        VALUES(subscription_status),
    subscription_expires_at =
        VALUES(subscription_expires_at),
    approval_status = VALUES(approval_status),
    approved_at = VALUES(approved_at),
    active = VALUES(active),
    deleted_at = NULL;

SET @pending_company_id := (
    SELECT company_id
    FROM companies
    WHERE code = 'test_company_pending'
    LIMIT 1
);

SET @inactive_company_id := (
    SELECT company_id
    FROM companies
    WHERE code = 'test_company_inactive'
    LIMIT 1
);

SET @suspended_company_id := (
    SELECT company_id
    FROM companies
    WHERE code = 'test_company_suspended'
    LIMIT 1
);

SET @expired_company_id := (
    SELECT company_id
    FROM companies
    WHERE code = 'test_company_expired'
    LIMIT 1
);

INSERT INTO users
    (
        user_id,
        username,
        email,
        password_hash,
        display_name,
        is_platform_admin,
        active,
        must_change_password,
        failed_login_count
    )
VALUES
    (
        910011,
        'test_company_pending_user',
        'pending-user@example.test',
        '$2y$10$llAbMeWCFDcP2O5LoAxzlePCs.zee4oKKxGuezj7oKAqUq0CEvO96',
        'Test Pending Company User',
        FALSE,
        TRUE,
        FALSE,
        0
    ),
    (
        910012,
        'test_company_inactive_user',
        'inactive-user@example.test',
        '$2y$10$llAbMeWCFDcP2O5LoAxzlePCs.zee4oKKxGuezj7oKAqUq0CEvO96',
        'Test Inactive Company User',
        FALSE,
        TRUE,
        FALSE,
        0
    ),
    (
        910013,
        'test_company_suspended_user',
        'suspended-user@example.test',
        '$2y$10$llAbMeWCFDcP2O5LoAxzlePCs.zee4oKKxGuezj7oKAqUq0CEvO96',
        'Test Suspended Company User',
        FALSE,
        TRUE,
        FALSE,
        0
    ),
    (
        910014,
        'test_company_expired_user',
        'expired-user@example.test',
        '$2y$10$llAbMeWCFDcP2O5LoAxzlePCs.zee4oKKxGuezj7oKAqUq0CEvO96',
        'Test Expired Company User',
        FALSE,
        TRUE,
        FALSE,
        0
    )
ON DUPLICATE KEY UPDATE
    password_hash = VALUES(password_hash),
    display_name = VALUES(display_name),
    is_platform_admin = FALSE,
    active = TRUE,
    must_change_password = FALSE,
    failed_login_count = 0,
    locked_until = NULL,
    deleted_at = NULL;

INSERT INTO company_users
    (
        company_id,
        user_id,
        active,
        is_default,
        assigned_by
    )
VALUES
    (
        @pending_company_id,
        910011,
        TRUE,
        TRUE,
        @test_user_id
    ),
    (
        @inactive_company_id,
        910012,
        TRUE,
        TRUE,
        @test_user_id
    ),
    (
        @suspended_company_id,
        910013,
        TRUE,
        TRUE,
        @test_user_id
    ),
    (
        @expired_company_id,
        910014,
        TRUE,
        TRUE,
        @test_user_id
    )
ON DUPLICATE KEY UPDATE
    active = TRUE,
    is_default = TRUE,
    assigned_by = VALUES(assigned_by);

INSERT INTO company_user_roles
    (
        company_id,
        user_id,
        role_id,
        assigned_by
    )
VALUES
    (
        @pending_company_id,
        910011,
        @executive_viewer_role_id,
        @test_user_id
    ),
    (
        @inactive_company_id,
        910012,
        @executive_viewer_role_id,
        @test_user_id
    ),
    (
        @suspended_company_id,
        910013,
        @executive_viewer_role_id,
        @test_user_id
    ),
    (
        @expired_company_id,
        910014,
        @executive_viewer_role_id,
        @test_user_id
    )
ON DUPLICATE KEY UPDATE
    assigned_by = VALUES(assigned_by);
