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
 * Both accounts use the documented test password from compose.test.yaml.
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
    )
ON DUPLICATE KEY UPDATE
    assigned_by = VALUES(assigned_by);

DELETE FROM company_role_permissions
WHERE company_id = @default_company_id
  AND role_id = @restricted_role_id;
