SET NAMES utf8mb4;

CREATE TABLE organization_branches (
    branch_id BIGINT UNSIGNED AUTO_INCREMENT
        PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(120) NOT NULL,
    contact_email VARCHAR(190) NULL,
    contact_phone VARCHAR(40) NULL,
    address_line VARCHAR(190) NULL,
    city VARCHAR(100) NULL,
    country_code CHAR(2) NOT NULL
        DEFAULT 'KE',
    timezone VARCHAR(80) NOT NULL
        DEFAULT 'Africa/Nairobi',
    is_head_office BOOLEAN NOT NULL
        DEFAULT FALSE,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,

    CONSTRAINT uq_org_branches_company_code
        UNIQUE (company_id, code),
    CONSTRAINT uq_org_branches_company_name
        UNIQUE (company_id, name),
    CONSTRAINT fk_org_branches_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_org_branches_creator
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_org_branches_updater
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_org_branches_company_active (
        company_id,
        active,
        deleted_at
    ),
    INDEX idx_org_branches_company_head (
        company_id,
        is_head_office,
        active,
        deleted_at
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
