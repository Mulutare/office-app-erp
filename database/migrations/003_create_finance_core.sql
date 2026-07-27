SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE finance_expense_categories (
    category_id INT UNSIGNED AUTO_INCREMENT
        PRIMARY KEY,
    code VARCHAR(50) NOT NULL,
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

    CONSTRAINT uq_finance_categories_code
        UNIQUE (code),
    CONSTRAINT uq_finance_categories_name
        UNIQUE (name),
    CONSTRAINT fk_finance_categories_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_finance_categories_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_finance_categories_active (
        active,
        deleted_at
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE finance_expense_requests (
    expense_request_id BIGINT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,
    request_number VARCHAR(50) NOT NULL,
    requested_by_employee_id BIGINT UNSIGNED
        NOT NULL,
    category_id INT UNSIGNED NULL,

    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    expense_date DATE NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',

    submitted_at DATETIME NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_notes VARCHAR(500) NULL,
    paid_at DATETIME NULL,

    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,

    CONSTRAINT uq_finance_expense_request_number
        UNIQUE (request_number),
    CONSTRAINT fk_finance_expense_employee
        FOREIGN KEY (requested_by_employee_id)
        REFERENCES hr_employees(employee_id),
    CONSTRAINT fk_finance_expense_category
        FOREIGN KEY (category_id)
        REFERENCES finance_expense_categories(
            category_id
        )
        ON DELETE SET NULL,
    CONSTRAINT fk_finance_expense_reviewer
        FOREIGN KEY (reviewed_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_finance_expense_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_finance_expense_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_finance_expense_status (
        status,
        deleted_at
    ),
    INDEX idx_finance_expense_date (
        expense_date,
        status
    ),
    INDEX idx_finance_expense_employee (
        requested_by_employee_id,
        status
    ),
    INDEX idx_finance_expense_category (
        category_id,
        status
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
