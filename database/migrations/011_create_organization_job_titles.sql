SET NAMES utf8mb4;

CREATE TABLE organization_job_titles (
    job_title_id BIGINT UNSIGNED AUTO_INCREMENT
        PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(120) NOT NULL,
    job_family VARCHAR(100) NULL,
    grade_level VARCHAR(40) NULL,
    description VARCHAR(500) NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,

    CONSTRAINT uq_org_job_titles_company_code
        UNIQUE (company_id, code),
    CONSTRAINT uq_org_job_titles_company_name
        UNIQUE (company_id, name),
    CONSTRAINT fk_org_job_titles_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_org_job_titles_creator
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_org_job_titles_updater
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_org_job_titles_company_active (
        company_id,
        active,
        deleted_at
    ),
    INDEX idx_org_job_titles_company_family (
        company_id,
        job_family,
        active,
        deleted_at
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
