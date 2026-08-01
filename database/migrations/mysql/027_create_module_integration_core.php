<?php

declare(strict_types=1);

return [
    'version' => '027',
    'description' => 'Create modular integration outbox and Sales projections',
    'preflight' => static function (\PDO $connection): string {
        $statement = $connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN (
                    'integration_outbox',
                    'finance_sales_receivables',
                    'finance_sales_receipts',
                    'inventory_sales_commitments'
               )"
        );
        $count = (int) $statement->fetchColumn();
        if ($count === 0) {
            return 'apply';
        }
        if ($count === 4) {
            return 'baseline';
        }
        throw new \RuntimeException(
            'Migration 027 found a partial module-integration schema.'
        );
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE integration_outbox (
    event_id CHAR(36) PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    aggregate_type VARCHAR(60) NOT NULL,
    aggregate_id VARCHAR(80) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    processed_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_integration_outbox_status CHECK (
        status IN ('pending', 'processing', 'processed', 'failed')
    ),
    CONSTRAINT ck_integration_outbox_json CHECK (JSON_VALID(payload_json)),
    CONSTRAINT fk_integration_outbox_company
        FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    INDEX idx_integration_outbox_dispatch (status, available_at, created_at),
    INDEX idx_integration_outbox_aggregate (
        company_id, aggregate_type, aggregate_id, created_at
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE finance_sales_receivables (
    receivable_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    order_number VARCHAR(50) NOT NULL,
    currency CHAR(3) NOT NULL,
    original_amount DECIMAL(15,2) NOT NULL,
    paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    balance_amount DECIMAL(15,2) NOT NULL,
    due_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_finance_sales_order UNIQUE (company_id, order_id),
    CONSTRAINT ck_finance_sales_amounts CHECK (
        original_amount >= 0 AND paid_amount >= 0 AND balance_amount >= 0
    ),
    CONSTRAINT ck_finance_sales_status CHECK (
        status IN ('open', 'partially_paid', 'paid', 'cancelled')
    ),
    CONSTRAINT fk_finance_sales_company
        FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_sales_customer
        FOREIGN KEY (customer_id) REFERENCES sales_customers(customer_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_sales_order
        FOREIGN KEY (order_id) REFERENCES sales_orders(order_id) ON DELETE RESTRICT,
    INDEX idx_finance_sales_due (company_id, status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE finance_sales_receipts (
    posting_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    receipt_number VARCHAR(50) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method VARCHAR(30) NOT NULL,
    reference_number VARCHAR(100) NULL,
    posted_at DATETIME NOT NULL,
    CONSTRAINT uq_finance_sales_payment UNIQUE (company_id, payment_id),
    CONSTRAINT fk_finance_receipt_company
        FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_receipt_payment
        FOREIGN KEY (payment_id) REFERENCES sales_payments(payment_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_receipt_order
        FOREIGN KEY (order_id) REFERENCES sales_orders(order_id) ON DELETE RESTRICT,
    INDEX idx_finance_receipt_order (company_id, order_id, payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_sales_commitments (
    commitment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(15,3) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'reserved',
    reserved_at DATETIME NOT NULL,
    released_at DATETIME NULL,
    fulfilled_at DATETIME NULL,
    CONSTRAINT uq_inventory_sales_line UNIQUE (
        company_id, order_id, product_id
    ),
    CONSTRAINT ck_inventory_sales_quantity CHECK (quantity > 0),
    CONSTRAINT ck_inventory_sales_status CHECK (
        status IN ('reserved', 'released', 'fulfilled', 'cancelled')
    ),
    CONSTRAINT fk_inventory_sales_company
        FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_sales_order
        FOREIGN KEY (order_id) REFERENCES sales_orders(order_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_sales_product
        FOREIGN KEY (product_id) REFERENCES sales_products(product_id) ON DELETE RESTRICT,
    INDEX idx_inventory_sales_status (company_id, status, reserved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
