<?php

declare(strict_types=1);

return [
    'version' => '029',
    'description' => 'Add Sales approvals, serial registry and commission controls',
    'preflight' => static function (\PDO $connection): string {
        $columns = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'sales_orders'
               AND column_name IN (
                    'submitted_at', 'approved_by', 'approved_at',
                    'cancelled_by', 'cancelled_at', 'cancellation_reason'
               )"
        )->fetchColumn();
        $tables = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN (
                    'sales_serial_numbers',
                    'sales_order_line_serials'
               )"
        )->fetchColumn();
        $commissionColumns = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'sales_commissions'
               AND column_name IN ('approved_by', 'approved_at', 'paid_by')"
        )->fetchColumn();

        if ($columns === 0 && $tables === 0 && $commissionColumns === 0) {
            return 'apply';
        }
        if ($columns === 6 && $tables === 2 && $commissionColumns === 3) {
            return 'baseline';
        }
        throw new \RuntimeException(
            'Migration 029 found a partial Sales control schema.'
        );
    },
    'statements' => [
        <<<'SQL'
ALTER TABLE sales_orders
    DROP CONSTRAINT ck_sales_order_status,
    ADD COLUMN submitted_at DATETIME NULL AFTER confirmed_at,
    ADD COLUMN approved_by BIGINT UNSIGNED NULL AFTER submitted_at,
    ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
    ADD COLUMN cancelled_by BIGINT UNSIGNED NULL AFTER approved_at,
    ADD COLUMN cancelled_at DATETIME NULL AFTER cancelled_by,
    ADD COLUMN cancellation_reason VARCHAR(500) NULL AFTER cancelled_at,
    ADD CONSTRAINT ck_sales_order_status CHECK (
        status IN (
            'draft', 'submitted', 'confirmed', 'approved',
            'partially_paid', 'paid', 'fulfilled', 'cancelled', 'returned'
        )
    ),
    ADD CONSTRAINT fk_sales_order_approver
        FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_sales_order_canceller
        FOREIGN KEY (cancelled_by) REFERENCES users(user_id) ON DELETE SET NULL
SQL,
        <<<'SQL'
ALTER TABLE sales_commissions
    ADD COLUMN approved_by BIGINT UNSIGNED NULL AFTER status,
    ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
    ADD COLUMN paid_by BIGINT UNSIGNED NULL AFTER approved_at,
    ADD CONSTRAINT fk_sales_commission_approver
        FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_sales_commission_payer
        FOREIGN KEY (paid_by) REFERENCES users(user_id) ON DELETE SET NULL
SQL,
        <<<'SQL'
CREATE TABLE sales_serial_numbers (
    serial_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    serial_number VARCHAR(190) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'available',
    registered_by BIGINT UNSIGNED NULL,
    registered_at DATETIME NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_sales_serial UNIQUE (company_id, serial_number),
    CONSTRAINT ck_sales_serial_status CHECK (
        status IN ('available', 'reserved', 'sold', 'returned', 'blocked')
    ),
    CONSTRAINT fk_sales_serial_company
        FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_serial_product
        FOREIGN KEY (product_id) REFERENCES sales_products(product_id) ON DELETE RESTRICT,
    CONSTRAINT fk_sales_serial_registrar
        FOREIGN KEY (registered_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_sales_serial_lookup (company_id, product_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE sales_order_line_serials (
    company_id BIGINT UNSIGNED NOT NULL,
    order_line_id BIGINT UNSIGNED NOT NULL,
    serial_id BIGINT UNSIGNED NOT NULL,
    allocated_by BIGINT UNSIGNED NULL,
    allocated_at DATETIME NOT NULL,
    PRIMARY KEY (company_id, order_line_id, serial_id),
    CONSTRAINT uq_sales_order_serial UNIQUE (company_id, serial_id),
    CONSTRAINT fk_sales_line_serial_company
        FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_line_serial_line
        FOREIGN KEY (order_line_id) REFERENCES sales_order_lines(order_line_id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_line_serial_serial
        FOREIGN KEY (serial_id) REFERENCES sales_serial_numbers(serial_id) ON DELETE RESTRICT,
    CONSTRAINT fk_sales_line_serial_allocator
        FOREIGN KEY (allocated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
