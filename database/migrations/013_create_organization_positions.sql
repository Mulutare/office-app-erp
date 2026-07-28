SET NAMES utf8mb4;

ALTER TABLE organization_branches
    ADD CONSTRAINT uq_org_branches_company_id
        UNIQUE (company_id, branch_id);

ALTER TABLE organization_job_titles
    ADD CONSTRAINT uq_org_job_titles_company_id
        UNIQUE (company_id, job_title_id);

CREATE TABLE organization_positions (
    position_id BIGINT UNSIGNED AUTO_INCREMENT
        PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(140) NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    department_id INT UNSIGNED NOT NULL,
    job_title_id BIGINT UNSIGNED NOT NULL,
    approved_headcount INT UNSIGNED NOT NULL
        DEFAULT 1,
    status VARCHAR(20) NOT NULL
        DEFAULT 'planned',
    description VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,

    CONSTRAINT uq_org_positions_company_code
        UNIQUE (company_id, code),
    CONSTRAINT ck_org_positions_headcount
        CHECK (
            approved_headcount BETWEEN 1 AND 10000
        ),
    CONSTRAINT ck_org_positions_status
        CHECK (
            status IN (
                'planned',
                'open',
                'frozen',
                'closed'
            )
        ),
    CONSTRAINT fk_org_positions_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_org_positions_branch
        FOREIGN KEY (company_id, branch_id)
        REFERENCES organization_branches(
            company_id,
            branch_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_org_positions_department
        FOREIGN KEY (company_id, department_id)
        REFERENCES hr_departments(
            company_id,
            department_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_org_positions_job_title
        FOREIGN KEY (company_id, job_title_id)
        REFERENCES organization_job_titles(
            company_id,
            job_title_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_org_positions_creator
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_org_positions_updater
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_org_positions_company_status (
        company_id,
        status,
        deleted_at
    ),
    INDEX idx_org_positions_company_department (
        company_id,
        department_id,
        status,
        deleted_at
    ),
    INDEX idx_org_positions_company_branch (
        company_id,
        branch_id,
        status,
        deleted_at
    ),
    INDEX idx_org_positions_company_job_title (
        company_id,
        job_title_id,
        status,
        deleted_at
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
