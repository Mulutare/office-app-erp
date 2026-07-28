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
