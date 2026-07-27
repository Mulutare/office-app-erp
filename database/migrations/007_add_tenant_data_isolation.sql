SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE hr_departments
    ADD COLUMN company_id BIGINT UNSIGNED NULL
        AFTER department_id;

ALTER TABLE hr_employees
    ADD COLUMN company_id BIGINT UNSIGNED NULL
        AFTER employee_id;

ALTER TABLE finance_expense_categories
    ADD COLUMN company_id BIGINT UNSIGNED NULL
        AFTER category_id;

ALTER TABLE finance_expense_requests
    ADD COLUMN company_id BIGINT UNSIGNED NULL
        AFTER expense_request_id;

ALTER TABLE audit_logs
    ADD COLUMN company_id BIGINT UNSIGNED NULL
        AFTER audit_log_id;

ALTER TABLE login_attempts
    ADD COLUMN company_id BIGINT UNSIGNED NULL
        AFTER login_attempt_id;

UPDATE hr_departments
SET company_id = (
    SELECT company_id
    FROM companies
    WHERE code = 'default'
      AND deleted_at IS NULL
    LIMIT 1
)
WHERE company_id IS NULL;

UPDATE hr_employees
SET company_id = (
    SELECT company_id
    FROM companies
    WHERE code = 'default'
      AND deleted_at IS NULL
    LIMIT 1
)
WHERE company_id IS NULL;

UPDATE finance_expense_categories
SET company_id = (
    SELECT company_id
    FROM companies
    WHERE code = 'default'
      AND deleted_at IS NULL
    LIMIT 1
)
WHERE company_id IS NULL;

UPDATE finance_expense_requests
SET company_id = (
    SELECT company_id
    FROM companies
    WHERE code = 'default'
      AND deleted_at IS NULL
    LIMIT 1
)
WHERE company_id IS NULL;

UPDATE audit_logs
SET company_id = (
    SELECT company_id
    FROM companies
    WHERE code = 'default'
      AND deleted_at IS NULL
    LIMIT 1
)
WHERE company_id IS NULL;

UPDATE login_attempts
SET company_id = (
    SELECT company_id
    FROM companies
    WHERE code = 'default'
      AND deleted_at IS NULL
    LIMIT 1
)
WHERE company_id IS NULL;

ALTER TABLE hr_departments
    MODIFY company_id BIGINT UNSIGNED NOT NULL,
    DROP INDEX uq_hr_departments_code,
    DROP INDEX uq_hr_departments_name,
    ADD CONSTRAINT uq_hr_departments_company_code
        UNIQUE (company_id, code),
    ADD CONSTRAINT uq_hr_departments_company_name
        UNIQUE (company_id, name),
    ADD CONSTRAINT fk_hr_departments_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE RESTRICT,
    ADD INDEX idx_hr_departments_company_active (
        company_id,
        active,
        deleted_at
    );

ALTER TABLE hr_employees
    MODIFY company_id BIGINT UNSIGNED NOT NULL,
    DROP INDEX uq_hr_employees_number,
    DROP INDEX uq_hr_employees_user,
    DROP INDEX uq_hr_employees_work_email,
    ADD CONSTRAINT uq_hr_employees_company_number
        UNIQUE (company_id, employee_number),
    ADD CONSTRAINT uq_hr_employees_company_user
        UNIQUE (company_id, user_id),
    ADD CONSTRAINT uq_hr_employees_company_email
        UNIQUE (company_id, work_email),
    ADD CONSTRAINT fk_hr_employees_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE RESTRICT,
    ADD INDEX idx_hr_employees_company_status (
        company_id,
        employment_status,
        deleted_at
    ),
    ADD INDEX idx_hr_employees_company_department (
        company_id,
        department_id,
        employment_status
    );

ALTER TABLE finance_expense_categories
    MODIFY company_id BIGINT UNSIGNED NOT NULL,
    DROP INDEX uq_finance_categories_code,
    DROP INDEX uq_finance_categories_name,
    ADD CONSTRAINT uq_finance_categories_company_code
        UNIQUE (company_id, code),
    ADD CONSTRAINT uq_finance_categories_company_name
        UNIQUE (company_id, name),
    ADD CONSTRAINT fk_finance_categories_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE RESTRICT,
    ADD INDEX idx_finance_categories_company_active (
        company_id,
        active,
        deleted_at
    );

ALTER TABLE finance_expense_requests
    MODIFY company_id BIGINT UNSIGNED NOT NULL,
    DROP INDEX uq_finance_expense_request_number,
    ADD CONSTRAINT uq_finance_expense_company_number
        UNIQUE (company_id, request_number),
    ADD CONSTRAINT fk_finance_expense_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE RESTRICT,
    ADD INDEX idx_finance_expense_company_status (
        company_id,
        status,
        deleted_at
    ),
    ADD INDEX idx_finance_expense_company_date (
        company_id,
        expense_date,
        status
    );

ALTER TABLE audit_logs
    ADD CONSTRAINT fk_audit_logs_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE SET NULL,
    ADD INDEX idx_audit_logs_company_time (
        company_id,
        created_at
    ),
    ADD INDEX idx_audit_logs_company_record (
        company_id,
        table_name,
        record_id
    );

ALTER TABLE login_attempts
    ADD CONSTRAINT fk_login_attempts_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE SET NULL,
    ADD INDEX idx_login_attempts_company_time (
        company_id,
        attempted_at
    ),
    ADD INDEX idx_login_attempts_company_user_time (
        company_id,
        user_id,
        attempted_at
    );

SET FOREIGN_KEY_CHECKS = 1;
