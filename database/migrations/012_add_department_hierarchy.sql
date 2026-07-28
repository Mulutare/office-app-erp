SET NAMES utf8mb4;

ALTER TABLE hr_departments
    ADD COLUMN parent_department_id
        INT UNSIGNED NULL
        AFTER name,
    MODIFY COLUMN description
        VARCHAR(500) NULL,
    ADD CONSTRAINT uq_hr_dept_company_identity
        UNIQUE (company_id, department_id);

ALTER TABLE hr_departments
    ADD CONSTRAINT fk_hr_dept_parent
        FOREIGN KEY (
            company_id,
            parent_department_id
        )
        REFERENCES hr_departments (
            company_id,
            department_id
        )
        ON DELETE RESTRICT,
    ADD INDEX idx_hr_dept_company_parent (
        company_id,
        parent_department_id,
        active,
        deleted_at
    );
