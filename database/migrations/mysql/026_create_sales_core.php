<?php

declare(strict_types=1);

return [
    'version' => '026',
    'description' => 'Create tenant-scoped sales order-to-cash core',
    'preflight' => static function (\PDO $connection): string {
        $statement = $connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN (
                    'sales_territories', 'sales_customers',
                    'sales_products', 'sales_agents', 'sales_targets',
                    'sales_orders', 'sales_order_lines',
                    'sales_payments', 'sales_commissions'
               )"
        );
        $count = (int) $statement->fetchColumn();

        if ($count === 0) {
            return 'apply';
        }
        if ($count === 9) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 026 found a partial sales schema.'
        );
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE sales_territories (
    territory_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    CONSTRAINT uq_sales_territory UNIQUE (company_id, code),
    CONSTRAINT fk_sales_territory_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_territory_branch FOREIGN KEY (branch_id) REFERENCES organization_branches(branch_id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_territory_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_sales_territory_active (company_id, active, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE sales_customers (
    customer_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    territory_id BIGINT UNSIGNED NULL,
    customer_number VARCHAR(40) NOT NULL,
    name VARCHAR(160) NOT NULL,
    customer_type VARCHAR(30) NOT NULL DEFAULT 'business',
    tax_number VARCHAR(60) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(40) NULL,
    address VARCHAR(255) NULL,
    credit_limit DECIMAL(15,2) NOT NULL DEFAULT 0,
    payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    CONSTRAINT uq_sales_customer_number UNIQUE (company_id, customer_number),
    CONSTRAINT ck_sales_customer_type CHECK (customer_type IN ('business','individual','agent','government')),
    CONSTRAINT ck_sales_customer_credit CHECK (credit_limit >= 0),
    CONSTRAINT fk_sales_customer_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_customer_territory FOREIGN KEY (territory_id) REFERENCES sales_territories(territory_id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_customer_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_sales_customer_name (company_id, name, active),
    INDEX idx_sales_customer_territory (company_id, territory_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE sales_products (
    product_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    sku VARCHAR(60) NOT NULL,
    name VARCHAR(160) NOT NULL,
    category VARCHAR(80) NULL,
    product_type VARCHAR(30) NOT NULL DEFAULT 'telecom_product',
    unit_of_measure VARCHAR(20) NOT NULL DEFAULT 'unit',
    unit_price DECIMAL(15,2) NOT NULL,
    commission_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
    serial_tracking BOOLEAN NOT NULL DEFAULT FALSE,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    CONSTRAINT uq_sales_product_sku UNIQUE (company_id, sku),
    CONSTRAINT ck_sales_product_price CHECK (unit_price >= 0),
    CONSTRAINT ck_sales_product_commission CHECK (commission_rate BETWEEN 0 AND 100),
    CONSTRAINT fk_sales_product_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_product_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_sales_product_catalogue (company_id, active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE sales_agents (
    agent_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    territory_id BIGINT UNSIGNED NULL,
    employee_id BIGINT UNSIGNED NULL,
    agent_code VARCHAR(40) NOT NULL,
    name VARCHAR(160) NOT NULL,
    agent_type VARCHAR(10) NOT NULL,
    phone VARCHAR(40) NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    CONSTRAINT uq_sales_agent_code UNIQUE (company_id, agent_code),
    CONSTRAINT ck_sales_agent_type CHECK (agent_type IN ('DSA','DSP')),
    CONSTRAINT fk_sales_agent_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_agent_territory FOREIGN KEY (territory_id) REFERENCES sales_territories(territory_id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_agent_employee FOREIGN KEY (employee_id) REFERENCES hr_employees(employee_id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_agent_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_sales_agent_active (company_id, agent_type, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE sales_targets (
    target_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    territory_id BIGINT UNSIGNED NULL,
    agent_id BIGINT UNSIGNED NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    target_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    target_quantity DECIMAL(15,3) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_sales_target_period CHECK (period_end >= period_start),
    CONSTRAINT ck_sales_target_values CHECK (target_amount >= 0 AND target_quantity >= 0),
    CONSTRAINT fk_sales_target_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_target_territory FOREIGN KEY (territory_id) REFERENCES sales_territories(territory_id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_target_agent FOREIGN KEY (agent_id) REFERENCES sales_agents(agent_id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_target_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_sales_target_period (company_id, period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE sales_orders (
    order_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    territory_id BIGINT UNSIGNED NULL,
    agent_id BIGINT UNSIGNED NULL,
    order_number VARCHAR(50) NOT NULL,
    order_date DATE NOT NULL,
    due_date DATE NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'draft',
    currency CHAR(3) NOT NULL DEFAULT 'ETB',
    subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    notes VARCHAR(500) NULL,
    confirmed_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    CONSTRAINT uq_sales_order_number UNIQUE (company_id, order_number),
    CONSTRAINT ck_sales_order_status CHECK (status IN ('draft','confirmed','partially_paid','paid','cancelled')),
    CONSTRAINT ck_sales_order_amounts CHECK (subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND total_amount >= 0 AND paid_amount >= 0),
    CONSTRAINT fk_sales_order_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_order_branch FOREIGN KEY (branch_id) REFERENCES organization_branches(branch_id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_order_customer FOREIGN KEY (customer_id) REFERENCES sales_customers(customer_id) ON DELETE RESTRICT,
    CONSTRAINT fk_sales_order_territory FOREIGN KEY (territory_id) REFERENCES sales_territories(territory_id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_order_agent FOREIGN KEY (agent_id) REFERENCES sales_agents(agent_id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_order_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_order_updater FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_sales_order_status (company_id, status, order_date),
    INDEX idx_sales_order_receivable (company_id, due_date, status, total_amount, paid_amount),
    INDEX idx_sales_order_customer (company_id, customer_id, order_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE sales_order_lines (
    order_line_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(15,3) NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL,
    discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    tax_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
    line_total DECIMAL(15,2) NOT NULL,
    commission_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
    serial_numbers_json LONGTEXT NULL,
    CONSTRAINT ck_sales_line_values CHECK (quantity > 0 AND unit_price >= 0 AND discount_amount >= 0 AND tax_rate BETWEEN 0 AND 100 AND line_total >= 0 AND commission_rate BETWEEN 0 AND 100),
    CONSTRAINT fk_sales_line_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_line_order FOREIGN KEY (order_id) REFERENCES sales_orders(order_id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_line_product FOREIGN KEY (product_id) REFERENCES sales_products(product_id) ON DELETE RESTRICT,
    INDEX idx_sales_line_order (company_id, order_id),
    INDEX idx_sales_line_product (company_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE sales_payments (
    payment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    receipt_number VARCHAR(50) NOT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    payment_method VARCHAR(30) NOT NULL,
    reference_number VARCHAR(100) NULL,
    notes VARCHAR(255) NULL,
    recorded_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_sales_receipt UNIQUE (company_id, receipt_number),
    CONSTRAINT ck_sales_payment_amount CHECK (amount > 0),
    CONSTRAINT fk_sales_payment_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_payment_order FOREIGN KEY (order_id) REFERENCES sales_orders(order_id) ON DELETE RESTRICT,
    CONSTRAINT fk_sales_payment_recorder FOREIGN KEY (recorded_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_sales_payment_order (company_id, order_id, payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE sales_commissions (
    commission_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    commission_amount DECIMAL(15,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'accrued',
    accrued_at DATETIME NOT NULL,
    paid_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_sales_commission_order_agent UNIQUE (company_id, order_id, agent_id),
    CONSTRAINT ck_sales_commission_amount CHECK (commission_amount >= 0),
    CONSTRAINT ck_sales_commission_status CHECK (status IN ('accrued','approved','paid','reversed')),
    CONSTRAINT fk_sales_commission_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_commission_order FOREIGN KEY (order_id) REFERENCES sales_orders(order_id) ON DELETE RESTRICT,
    CONSTRAINT fk_sales_commission_agent FOREIGN KEY (agent_id) REFERENCES sales_agents(agent_id) ON DELETE RESTRICT,
    INDEX idx_sales_commission_status (company_id, status, accrued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
