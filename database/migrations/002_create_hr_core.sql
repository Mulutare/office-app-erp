SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE hr_departments (
    department_id INT UNSIGNED AUTO_INCREMENT
        PRIMARY KEY,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,

    CONSTRAINT uq_hr_departments_code
        UNIQUE (code),
    CONSTRAINT uq_hr_departments_name
        UNIQUE (name),
    CONSTRAINT fk_hr_departments_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_hr_departments_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_hr_departments_active (
        active,
        deleted_at
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE hr_employees (
    employee_id BIGINT UNSIGNED AUTO_INCREMENT
        PRIMARY KEY,
    employee_number VARCHAR(50) NOT NULL,
    user_id BIGINT UNSIGNED NULL,

    first_name VARCHAR(80) NOT NULL,
    middle_name VARCHAR(80) NULL,
    last_name VARCHAR(80) NOT NULL,
    preferred_name VARCHAR(80) NULL,

    work_email VARCHAR(190) NOT NULL,
    work_phone VARCHAR(40) NULL,

    department_id INT UNSIGNED NULL,
    job_title VARCHAR(120) NOT NULL,
    employment_type VARCHAR(30) NOT NULL,
    employment_status VARCHAR(30) NOT NULL
        DEFAULT 'active',

    hire_date DATE NOT NULL,
    termination_date DATE NULL,
    manager_employee_id BIGINT UNSIGNED NULL,

    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,

    CONSTRAINT uq_hr_employees_number
        UNIQUE (employee_number),
    CONSTRAINT uq_hr_employees_user
        UNIQUE (user_id),
    CONSTRAINT uq_hr_employees_work_email
        UNIQUE (work_email),

    CONSTRAINT fk_hr_employees_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_hr_employees_department
        FOREIGN KEY (department_id)
        REFERENCES hr_departments(department_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_hr_employees_manager
        FOREIGN KEY (manager_employee_id)
        REFERENCES hr_employees(employee_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_hr_employees_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_hr_employees_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_hr_employees_status (
        employment_status,
        deleted_at
    ),
    INDEX idx_hr_employees_department (
        department_id,
        employment_status
    ),
    INDEX idx_hr_employees_name (
        last_name,
        first_name
    ),
    INDEX idx_hr_employees_manager (
        manager_employee_id
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
