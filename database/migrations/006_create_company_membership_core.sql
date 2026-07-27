SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE company_users (
    company_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    assigned_by BIGINT UNSIGNED NULL,
    joined_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (company_id, user_id),

    CONSTRAINT fk_company_users_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_company_users_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_company_users_assigned_by
        FOREIGN KEY (assigned_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_company_users_user_access (
        user_id,
        active,
        is_default
    ),
    INDEX idx_company_users_company_access (
        company_id,
        active
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE company_user_roles (
    company_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NULL,
    assigned_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (
        company_id,
        user_id,
        role_id
    ),

    CONSTRAINT fk_company_user_roles_membership
        FOREIGN KEY (company_id, user_id)
        REFERENCES company_users(company_id, user_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_company_user_roles_role
        FOREIGN KEY (role_id)
        REFERENCES roles(role_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_company_user_roles_assigned_by
        FOREIGN KEY (assigned_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_company_user_roles_role (
        company_id,
        role_id,
        user_id
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


INSERT INTO company_users
    (
        company_id,
        user_id,
        active,
        is_default,
        assigned_by
    )
SELECT
    companies.company_id,
    users.user_id,
    users.active,
    TRUE,
    NULL
FROM companies
CROSS JOIN users
WHERE companies.code = 'default'
  AND companies.deleted_at IS NULL
  AND users.deleted_at IS NULL;


INSERT INTO company_users
    (
        company_id,
        user_id,
        active,
        is_default,
        assigned_by
    )
SELECT DISTINCT
    companies.company_id,
    users.user_id,
    users.active,
    companies.code = 'default',
    NULL
FROM companies
CROSS JOIN users
INNER JOIN user_roles
    ON user_roles.user_id = users.user_id
INNER JOIN roles
    ON roles.role_id = user_roles.role_id
WHERE roles.code = 'system_administrator'
  AND companies.deleted_at IS NULL
  AND users.deleted_at IS NULL
ON DUPLICATE KEY UPDATE
    active = VALUES(active);


INSERT INTO company_user_roles
    (
        company_id,
        user_id,
        role_id,
        assigned_by,
        assigned_at
    )
SELECT
    companies.company_id,
    user_roles.user_id,
    user_roles.role_id,
    user_roles.assigned_by,
    user_roles.assigned_at
FROM companies
CROSS JOIN user_roles
WHERE companies.code = 'default'
  AND companies.deleted_at IS NULL;


INSERT INTO company_user_roles
    (
        company_id,
        user_id,
        role_id,
        assigned_by,
        assigned_at
    )
SELECT
    companies.company_id,
    user_roles.user_id,
    user_roles.role_id,
    user_roles.assigned_by,
    user_roles.assigned_at
FROM companies
CROSS JOIN user_roles
INNER JOIN roles
    ON roles.role_id = user_roles.role_id
WHERE roles.code = 'system_administrator'
  AND companies.deleted_at IS NULL
ON DUPLICATE KEY UPDATE
    assigned_by = VALUES(assigned_by);

SET FOREIGN_KEY_CHECKS = 1;
