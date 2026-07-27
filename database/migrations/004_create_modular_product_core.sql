SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE companies (
    company_id BIGINT UNSIGNED AUTO_INCREMENT
        PRIMARY KEY,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(150) NOT NULL,
    legal_name VARCHAR(190) NULL,
    default_currency CHAR(3) NOT NULL
        DEFAULT 'KES',
    timezone VARCHAR(80) NOT NULL
        DEFAULT 'Africa/Nairobi',
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,

    CONSTRAINT uq_companies_code
        UNIQUE (code),

    INDEX idx_companies_active (
        active,
        deleted_at
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE erp_modules (
    module_id INT UNSIGNED AUTO_INCREMENT
        PRIMARY KEY,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    navigation_label VARCHAR(100) NOT NULL,
    description VARCHAR(255) NOT NULL,
    route_path VARCHAR(120) NOT NULL,
    permission_namespace VARCHAR(80) NOT NULL,
    icon_text VARCHAR(10) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL
        DEFAULT 100,
    available BOOLEAN NOT NULL DEFAULT FALSE,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT uq_erp_modules_code
        UNIQUE (code),
    CONSTRAINT uq_erp_modules_route
        UNIQUE (route_path),

    INDEX idx_erp_modules_catalog (
        active,
        available,
        sort_order
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE company_modules (
    company_id BIGINT UNSIGNED NOT NULL,
    module_id INT UNSIGNED NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    license_status VARCHAR(30) NOT NULL
        DEFAULT 'not_licensed',
    licensed_at DATETIME NULL,
    expires_at DATETIME NULL,
    settings_json LONGTEXT NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (company_id, module_id),

    CONSTRAINT fk_company_modules_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_company_modules_module
        FOREIGN KEY (module_id)
        REFERENCES erp_modules(module_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_company_modules_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_company_modules_entitlement (
        company_id,
        enabled,
        license_status,
        expires_at
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


INSERT INTO companies
    (
        code,
        name,
        legal_name,
        default_currency,
        timezone
    )
VALUES
    (
        'default',
        'Default Company',
        NULL,
        'KES',
        'Africa/Nairobi'
    );


INSERT INTO erp_modules
    (
        code,
        name,
        navigation_label,
        description,
        route_path,
        permission_namespace,
        icon_text,
        sort_order,
        available
    )
VALUES
    (
        'hr',
        'Human Resources',
        'Human Resources',
        'Employee records, departments and workforce administration.',
        '/hr',
        'hr',
        'HR',
        10,
        TRUE
    ),
    (
        'finance',
        'Finance',
        'Finance',
        'Expense requests, approvals and financial operations.',
        '/finance',
        'finance',
        'FI',
        20,
        TRUE
    ),
    (
        'procurement',
        'Procurement',
        'Procurement',
        'Suppliers, purchase requests and purchasing workflows.',
        '/procurement',
        'procurement',
        'PR',
        30,
        FALSE
    ),
    (
        'inventory',
        'Inventory',
        'Inventory',
        'Stock, warehouses, transfers and inventory controls.',
        '/inventory',
        'inventory',
        'IN',
        40,
        FALSE
    ),
    (
        'sales',
        'Sales',
        'Sales',
        'Quotes, orders, invoicing and sales operations.',
        '/sales',
        'sales',
        'SA',
        50,
        FALSE
    ),
    (
        'crm',
        'Customer Relationship Management',
        'CRM',
        'Customers, leads, opportunities and relationship management.',
        '/crm',
        'crm',
        'CR',
        60,
        FALSE
    ),
    (
        'projects',
        'Projects',
        'Projects',
        'Projects, tasks, milestones, teams and delivery tracking.',
        '/projects',
        'projects',
        'PJ',
        70,
        FALSE
    ),
    (
        'help_desk',
        'Help Desk',
        'Help Desk',
        'Service requests, incidents, queues and support operations.',
        '/help-desk',
        'help_desk',
        'HD',
        80,
        FALSE
    ),
    (
        'it_assets',
        'IT Assets',
        'IT Assets',
        'Technology assets, assignment, lifecycle and maintenance.',
        '/it-assets',
        'it',
        'IT',
        90,
        FALSE
    ),
    (
        'payroll',
        'Payroll',
        'Payroll',
        'Payroll periods, earnings, deductions and payslips.',
        '/payroll',
        'payroll',
        'PY',
        100,
        FALSE
    ),
    (
        'attendance',
        'Attendance',
        'Attendance',
        'Time, attendance, shifts and leave integration.',
        '/attendance',
        'attendance',
        'AT',
        110,
        FALSE
    ),
    (
        'documents',
        'Documents',
        'Documents',
        'Company files, records, access and document workflows.',
        '/documents',
        'documents',
        'DC',
        120,
        FALSE
    );


INSERT INTO company_modules
    (
        company_id,
        module_id,
        enabled,
        license_status,
        licensed_at
    )
SELECT
    companies.company_id,
    erp_modules.module_id,
    erp_modules.available,
    CASE
        WHEN erp_modules.available = TRUE
            THEN 'active'
        ELSE 'not_licensed'
    END,
    CASE
        WHEN erp_modules.available = TRUE
            THEN NOW()
        ELSE NULL
    END
FROM companies
CROSS JOIN erp_modules
WHERE companies.code = 'default';

SET FOREIGN_KEY_CHECKS = 1;
