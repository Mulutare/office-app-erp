<?php

declare(strict_types=1);

return [
    'version' => '042',
    'description' => 'Create finance journals, invoices, payments, allocations and period controls',
    'preflight' => static function (\PDO $connection): string {
        $count = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name IN (
                'finance_journals','finance_taxes','finance_invoices','finance_invoice_lines',
                'finance_payments','finance_payment_allocations','finance_reconciliations',
                'finance_reconciliation_lines','finance_accounting_periods'
             )"
        )->fetchColumn();
        if ($count === 0) { return 'apply'; }
        if ($count === 9) { return 'baseline'; }
        throw new \RuntimeException('Migration 042 found a partial finance document schema.');
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE finance_journals (
    journal_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    journal_code VARCHAR(20) NOT NULL,
    journal_name VARCHAR(120) NOT NULL,
    journal_type VARCHAR(20) NOT NULL,
    default_debit_account_id BIGINT UNSIGNED NULL,
    default_credit_account_id BIGINT UNSIGNED NULL,
    next_number BIGINT UNSIGNED NOT NULL DEFAULT 1,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    system_required BOOLEAN NOT NULL DEFAULT FALSE,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_finance_journal_identity UNIQUE (company_id, journal_id),
    CONSTRAINT uq_finance_journal_code UNIQUE (company_id, journal_code),
    CONSTRAINT ck_finance_journal_type CHECK (journal_type IN ('sales','purchase','bank','cash','general')),
    CONSTRAINT fk_finance_journal_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_journal_debit FOREIGN KEY (company_id, default_debit_account_id) REFERENCES finance_accounts(company_id, account_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_journal_credit FOREIGN KEY (company_id, default_credit_account_id) REFERENCES finance_accounts(company_id, account_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_journal_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_finance_journal_type (company_id, journal_type, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE finance_taxes (
    tax_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    tax_code VARCHAR(30) NOT NULL,
    tax_name VARCHAR(120) NOT NULL,
    tax_scope VARCHAR(20) NOT NULL,
    rate DECIMAL(9,4) NOT NULL,
    price_included BOOLEAN NOT NULL DEFAULT FALSE,
    account_id BIGINT UNSIGNED NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_finance_tax_identity UNIQUE (company_id, tax_id),
    CONSTRAINT uq_finance_tax_code UNIQUE (company_id, tax_code),
    CONSTRAINT ck_finance_tax_scope CHECK (tax_scope IN ('sale','purchase','both')),
    CONSTRAINT ck_finance_tax_rate CHECK (rate BETWEEN 0 AND 100),
    CONSTRAINT fk_finance_tax_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_tax_account FOREIGN KEY (company_id, account_id) REFERENCES finance_accounts(company_id, account_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_tax_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE finance_invoices (
    invoice_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    journal_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    vendor_id BIGINT UNSIGNED NULL,
    sales_order_id BIGINT UNSIGNED NULL,
    original_invoice_id BIGINT UNSIGNED NULL,
    journal_batch_id BIGINT UNSIGNED NULL,
    document_type VARCHAR(20) NOT NULL,
    invoice_number VARCHAR(60) NOT NULL,
    invoice_date DATE NOT NULL,
    due_date DATE NOT NULL,
    currency CHAR(3) NOT NULL,
    payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    invoice_policy VARCHAR(20) NOT NULL DEFAULT 'ordered',
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
    untaxed_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    residual_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    notes VARCHAR(500) NULL,
    posted_by BIGINT UNSIGNED NULL,
    posted_at DATETIME NULL,
    reversed_by_invoice_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_finance_invoice_identity UNIQUE (company_id, invoice_id),
    CONSTRAINT uq_finance_invoice_number UNIQUE (company_id, invoice_number),
    CONSTRAINT ck_finance_invoice_type CHECK (document_type IN ('customer_invoice','customer_credit','customer_debit','vendor_bill','vendor_credit')),
    CONSTRAINT ck_finance_invoice_status CHECK (status IN ('draft','posted','reversed','cancelled')),
    CONSTRAINT ck_finance_invoice_payment CHECK (payment_status IN ('unpaid','partially_paid','paid','credit')),
    CONSTRAINT ck_finance_invoice_policy CHECK (invoice_policy IN ('ordered','delivered')),
    CONSTRAINT ck_finance_invoice_amounts CHECK (untaxed_amount >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND total_amount >= 0 AND residual_amount >= 0),
    CONSTRAINT ck_finance_invoice_party CHECK ((document_type LIKE 'customer_%' AND customer_id IS NOT NULL AND vendor_id IS NULL) OR (document_type LIKE 'vendor_%' AND vendor_id IS NOT NULL AND customer_id IS NULL)),
    CONSTRAINT fk_finance_invoice_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_invoice_journal FOREIGN KEY (company_id, journal_id) REFERENCES finance_journals(company_id, journal_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_invoice_customer FOREIGN KEY (customer_id) REFERENCES sales_customers(customer_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_invoice_sales_order FOREIGN KEY (sales_order_id) REFERENCES sales_orders(order_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_invoice_original FOREIGN KEY (company_id, original_invoice_id) REFERENCES finance_invoices(company_id, invoice_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_invoice_batch FOREIGN KEY (company_id, journal_batch_id) REFERENCES finance_journal_batches(company_id, journal_batch_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_invoice_reversal FOREIGN KEY (company_id, reversed_by_invoice_id) REFERENCES finance_invoices(company_id, invoice_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_invoice_poster FOREIGN KEY (posted_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_finance_invoice_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_finance_invoice_open (company_id, document_type, status, payment_status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE finance_invoice_lines (
    invoice_line_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    sales_order_line_id BIGINT UNSIGNED NULL,
    product_id BIGINT UNSIGNED NULL,
    tax_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(18,3) NOT NULL,
    unit_price DECIMAL(18,4) NOT NULL,
    discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    tax_rate DECIMAL(9,4) NOT NULL DEFAULT 0,
    untaxed_amount DECIMAL(18,2) NOT NULL,
    tax_amount DECIMAL(18,2) NOT NULL,
    total_amount DECIMAL(18,2) NOT NULL,
    CONSTRAINT uq_finance_invoice_line_identity UNIQUE (company_id, invoice_line_id),
    CONSTRAINT uq_finance_invoice_sales_line UNIQUE (company_id, invoice_id, sales_order_line_id),
    CONSTRAINT ck_finance_invoice_line_amounts CHECK (quantity > 0 AND unit_price >= 0 AND discount_amount >= 0 AND tax_rate BETWEEN 0 AND 100 AND untaxed_amount >= 0 AND tax_amount >= 0 AND total_amount >= 0),
    CONSTRAINT fk_finance_invoice_line_invoice FOREIGN KEY (company_id, invoice_id) REFERENCES finance_invoices(company_id, invoice_id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_invoice_line_sales FOREIGN KEY (sales_order_line_id) REFERENCES sales_order_lines(order_line_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_invoice_line_product FOREIGN KEY (product_id) REFERENCES sales_products(product_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_invoice_line_tax FOREIGN KEY (company_id, tax_id) REFERENCES finance_taxes(company_id, tax_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE finance_payments (
    payment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    journal_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    vendor_id BIGINT UNSIGNED NULL,
    journal_batch_id BIGINT UNSIGNED NULL,
    payment_number VARCHAR(60) NOT NULL,
    direction VARCHAR(10) NOT NULL,
    payment_date DATE NOT NULL,
    currency CHAR(3) NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    allocated_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    unallocated_amount DECIMAL(18,2) NOT NULL,
    method VARCHAR(30) NOT NULL,
    reference_number VARCHAR(120) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    posted_by BIGINT UNSIGNED NULL,
    posted_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_finance_payment_identity UNIQUE (company_id, payment_id),
    CONSTRAINT uq_finance_payment_number UNIQUE (company_id, payment_number),
    CONSTRAINT ck_finance_payment_direction CHECK (direction IN ('inbound','outbound')),
    CONSTRAINT ck_finance_payment_status CHECK (status IN ('draft','posted','reversed','cancelled')),
    CONSTRAINT ck_finance_payment_amounts CHECK (amount > 0 AND allocated_amount >= 0 AND unallocated_amount >= 0 AND allocated_amount + unallocated_amount = amount),
    CONSTRAINT ck_finance_payment_party CHECK ((customer_id IS NOT NULL AND vendor_id IS NULL) OR (vendor_id IS NOT NULL AND customer_id IS NULL)),
    CONSTRAINT fk_finance_payment_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_payment_journal FOREIGN KEY (company_id, journal_id) REFERENCES finance_journals(company_id, journal_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_payment_customer FOREIGN KEY (customer_id) REFERENCES sales_customers(customer_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_payment_batch FOREIGN KEY (company_id, journal_batch_id) REFERENCES finance_journal_batches(company_id, journal_batch_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_payment_poster FOREIGN KEY (posted_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_finance_payment_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_finance_payment_party (company_id, customer_id, vendor_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE finance_payment_allocations (
    allocation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    allocated_by BIGINT UNSIGNED NULL,
    allocated_at DATETIME NOT NULL,
    CONSTRAINT uq_finance_payment_allocation UNIQUE (company_id, payment_id, invoice_id),
    CONSTRAINT ck_finance_payment_allocation_amount CHECK (amount > 0),
    CONSTRAINT fk_finance_allocation_payment FOREIGN KEY (company_id, payment_id) REFERENCES finance_payments(company_id, payment_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_allocation_invoice FOREIGN KEY (company_id, invoice_id) REFERENCES finance_invoices(company_id, invoice_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_allocation_actor FOREIGN KEY (allocated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE finance_reconciliations (
    reconciliation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    reconciliation_number VARCHAR(60) NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    vendor_id BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'posted',
    reconciled_by BIGINT UNSIGNED NULL,
    reconciled_at DATETIME NOT NULL,
    CONSTRAINT uq_finance_reconciliation_identity UNIQUE (company_id, reconciliation_id),
    CONSTRAINT uq_finance_reconciliation_number UNIQUE (company_id, reconciliation_number),
    CONSTRAINT ck_finance_reconciliation_status CHECK (status IN ('posted','reversed')),
    CONSTRAINT fk_finance_reconciliation_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_reconciliation_customer FOREIGN KEY (customer_id) REFERENCES sales_customers(customer_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_reconciliation_actor FOREIGN KEY (reconciled_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE finance_reconciliation_lines (
    reconciliation_line_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    reconciliation_id BIGINT UNSIGNED NOT NULL,
    payment_allocation_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    CONSTRAINT ck_finance_reconciliation_line_amount CHECK (amount > 0),
    CONSTRAINT fk_finance_reconciliation_line_header FOREIGN KEY (company_id, reconciliation_id) REFERENCES finance_reconciliations(company_id, reconciliation_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_reconciliation_line_allocation FOREIGN KEY (payment_allocation_id) REFERENCES finance_payment_allocations(allocation_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE finance_accounting_periods (
    period_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    period_name VARCHAR(80) NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    locked_by BIGINT UNSIGNED NULL,
    locked_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_finance_period_name UNIQUE (company_id, period_name),
    CONSTRAINT ck_finance_period_dates CHECK (date_to >= date_from),
    CONSTRAINT ck_finance_period_status CHECK (status IN ('open','closed','locked')),
    CONSTRAINT fk_finance_period_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_period_locker FOREIGN KEY (locked_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_finance_period_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_finance_period_dates (company_id, date_from, date_to, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
