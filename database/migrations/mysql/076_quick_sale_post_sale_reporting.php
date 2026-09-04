<?php

declare(strict_types=1);

return [
    'version' => '076',
    'description' =>
        'Add DSA/DSP Quick Sale post-sale reporting and manager confirmation',

    'preflight' => static function (PDO $connection): string {
        $tables = (int) $connection->query(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN (
                   'sales_quick_sale_reports',
                   'sales_quick_sale_report_lines'
               )"
        )->fetchColumn();

        $checkStatement = $connection->prepare(
            "SELECT CHECK_CLAUSE
             FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME = :constraint_name
             LIMIT 1"
        );

        $checkStatement->execute([
            'constraint_name' => 'ck_sales_quick_sale_status',
        ]);

        $checkClause =
            (string) ($checkStatement->fetchColumn() ?: '');

        $statusReady =
            str_contains($checkClause, "'reported'")
            && str_contains($checkClause, "'closed'");

        $identityReady = (int) $connection->query(
            "SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'sales_quick_sales'
               AND index_name = 'uq_sales_quick_sale_identity'"
        )->fetchColumn() > 0;

        if (
            $tables === 0
            && !$statusReady
            && !$identityReady
        ) {
            return 'apply';
        }

        if (
            $tables === 2
            && $statusReady
            && $identityReady
        ) {
            return 'baseline';
        }

        throw new RuntimeException(
            'Migration 076 found a partial Quick Sale reporting schema.'
        );
    },

    'statements' => [
        <<<'SQL'
ALTER TABLE sales_quick_sales
    DROP CONSTRAINT ck_sales_quick_sale_status
SQL,

        <<<'SQL'
ALTER TABLE sales_quick_sales
    ADD CONSTRAINT uq_sales_quick_sale_identity
        UNIQUE (company_id, quick_sale_id),

    ADD CONSTRAINT ck_sales_quick_sale_status
        CHECK (
            status IN (
                'submitted',
                'allocated',
                'reported',
                'closed',
                'sold',
                'return_requested',
                'returned',
                'cancelled'
            )
        )
SQL,

        <<<'SQL'
CREATE TABLE sales_quick_sale_reports (
    report_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    quick_sale_id BIGINT UNSIGNED NOT NULL,
    reported_by_user_id BIGINT UNSIGNED NOT NULL,

    status VARCHAR(24) NOT NULL DEFAULT 'submitted',

    invoice_reference VARCHAR(120) NULL,
    payment_method VARCHAR(40) NULL,
    payment_reference VARCHAR(120) NULL,
    report_note TEXT NULL,

    evidence_path VARCHAR(700) NULL,
    evidence_original_name VARCHAR(255) NULL,
    evidence_mime VARCHAR(100) NULL,
    evidence_size BIGINT UNSIGNED NULL,
    evidence_sha256 CHAR(64) NULL,

    reviewed_by_user_id BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_note TEXT NULL,

    finance_invoice_id BIGINT UNSIGNED NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT uq_sales_quick_sale_report_identity
        UNIQUE (company_id, report_id),

    INDEX idx_sales_quick_sale_report_workflow
        (company_id, quick_sale_id, status, created_at),

    INDEX idx_sales_quick_sale_report_actor
        (company_id, reported_by_user_id, status),

    CONSTRAINT ck_sales_quick_sale_report_status
        CHECK (
            status IN (
                'submitted',
                'correction_required',
                'confirmed'
            )
        ),

    CONSTRAINT fk_sales_quick_sale_report_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_sales_quick_sale_report_sale
        FOREIGN KEY (company_id, quick_sale_id)
        REFERENCES sales_quick_sales(company_id, quick_sale_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_sales_quick_sale_report_reporter
        FOREIGN KEY (reported_by_user_id)
        REFERENCES users(user_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_sales_quick_sale_report_reviewer
        FOREIGN KEY (reviewed_by_user_id)
        REFERENCES users(user_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_sales_quick_sale_report_invoice
        FOREIGN KEY (finance_invoice_id)
        REFERENCES finance_invoices(invoice_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,

        <<<'SQL'
CREATE TABLE sales_quick_sale_report_lines (
    report_line_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    report_id BIGINT UNSIGNED NOT NULL,

    picking_line_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,

    allocated_quantity DECIMAL(18,3) NOT NULL,
    sold_quantity DECIMAL(18,3) NOT NULL,
    returned_quantity DECIMAL(18,3) NOT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT uq_sales_quick_sale_report_line
        UNIQUE (
            company_id,
            report_id,
            picking_line_id
        ),

    INDEX idx_sales_quick_sale_report_line_product
        (company_id, product_id),

    CONSTRAINT ck_sales_quick_sale_report_quantities
        CHECK (
            allocated_quantity >= 0
            AND sold_quantity >= 0
            AND returned_quantity >= 0
            AND allocated_quantity =
                sold_quantity + returned_quantity
        ),

    CONSTRAINT fk_sales_quick_sale_report_line_report
        FOREIGN KEY (company_id, report_id)
        REFERENCES sales_quick_sale_reports(company_id, report_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_sales_quick_sale_report_line_picking
        FOREIGN KEY (picking_line_id)
        REFERENCES inventory_picking_lines(picking_line_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_sales_quick_sale_report_line_product
        FOREIGN KEY (product_id)
        REFERENCES sales_products(product_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];