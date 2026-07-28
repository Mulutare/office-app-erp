SET NAMES utf8mb4;

ALTER TABLE hr_employees
    ADD CONSTRAINT uq_hr_employees_company_id
        UNIQUE (company_id, employee_id);

ALTER TABLE organization_positions
    ADD CONSTRAINT uq_org_positions_company_id
        UNIQUE (company_id, position_id);

CREATE TABLE hr_employee_position_assignments (
    assignment_id BIGINT UNSIGNED AUTO_INCREMENT
        PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    assignment_status VARCHAR(20) NOT NULL
        DEFAULT 'current',
    current_marker TINYINT UNSIGNED NULL
        DEFAULT 1,
    position_code_snapshot VARCHAR(40) NOT NULL,
    position_name_snapshot VARCHAR(140) NOT NULL,
    department_name_snapshot VARCHAR(100) NOT NULL,
    job_title_name_snapshot VARCHAR(120) NOT NULL,
    branch_name_snapshot VARCHAR(120) NULL,
    notes VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT uq_hr_assignment_current
        UNIQUE (
            company_id,
            employee_id,
            current_marker
        ),
    CONSTRAINT ck_hr_assignment_status
        CHECK (
            assignment_status IN (
                'current',
                'ended'
            )
        ),
    CONSTRAINT ck_hr_assignment_current_marker
        CHECK (
            (
                assignment_status = 'current'
                AND current_marker = 1
                AND effective_to IS NULL
            )
            OR (
                assignment_status = 'ended'
                AND current_marker IS NULL
                AND effective_to IS NOT NULL
            )
        ),
    CONSTRAINT ck_hr_assignment_dates
        CHECK (
            effective_to IS NULL
            OR effective_to > effective_from
        ),
    CONSTRAINT fk_hr_assignment_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_hr_assignment_employee
        FOREIGN KEY (company_id, employee_id)
        REFERENCES hr_employees(
            company_id,
            employee_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_hr_assignment_position
        FOREIGN KEY (company_id, position_id)
        REFERENCES organization_positions(
            company_id,
            position_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_hr_assignment_creator
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_hr_assignment_updater
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_hr_assignment_position_current (
        company_id,
        position_id,
        current_marker
    ),
    INDEX idx_hr_assignment_employee_history (
        company_id,
        employee_id,
        effective_from
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
