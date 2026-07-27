SET NAMES utf8mb4;

ALTER TABLE users
    ADD COLUMN is_platform_admin BOOLEAN NOT NULL
        DEFAULT FALSE
        AFTER display_name,
    ADD INDEX idx_users_platform_admin (
        is_platform_admin,
        active,
        deleted_at
    );

UPDATE users
SET is_platform_admin = TRUE
WHERE username = 'admin'
  AND deleted_at IS NULL;

ALTER TABLE companies
    ADD COLUMN approval_status VARCHAR(30) NOT NULL
        DEFAULT 'pending'
        AFTER brand_primary_color,
    ADD COLUMN approved_at DATETIME NULL
        AFTER approval_status,
    ADD COLUMN approved_by BIGINT UNSIGNED NULL
        AFTER approved_at,
    ADD COLUMN owner_user_id BIGINT UNSIGNED NULL
        AFTER approved_by,

    ADD CONSTRAINT fk_companies_approved_by
        FOREIGN KEY (approved_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_companies_owner_user
        FOREIGN KEY (owner_user_id)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    ADD INDEX idx_companies_approval (
        approval_status,
        active,
        deleted_at
    ),
    ADD INDEX idx_companies_owner (
        owner_user_id
    );

UPDATE companies
SET approval_status = 'approved',
    approved_at = COALESCE(
        approved_at,
        created_at
    ),
    approved_by = COALESCE(
        approved_by,
        provisioned_by
    );

CREATE TABLE company_role_permissions (
    company_id BIGINT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    granted_by BIGINT UNSIGNED NULL,
    granted_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (
        company_id,
        role_id,
        permission_id
    ),

    CONSTRAINT fk_company_role_permissions_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_company_role_permissions_role
        FOREIGN KEY (role_id)
        REFERENCES roles(role_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_company_role_permissions_permission
        FOREIGN KEY (permission_id)
        REFERENCES permissions(permission_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_company_role_permissions_granted_by
        FOREIGN KEY (granted_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_company_role_permissions_lookup (
        company_id,
        permission_id,
        role_id
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

INSERT INTO company_role_permissions
    (
        company_id,
        role_id,
        permission_id,
        granted_by
    )
SELECT
    companies.company_id,
    templates.role_id,
    templates.permission_id,
    companies.provisioned_by
FROM companies
CROSS JOIN role_permissions templates
WHERE companies.deleted_at IS NULL;
