<?php

declare(strict_types=1);

return [
    'version' => '077',
    'description' =>
        'Add Shop Manager to Finance handoff for confirmed Quick Sales',

    'preflight' => static function (PDO $connection): string {
        $columnStatement = $connection->prepare(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'sales_quick_sale_reports'
               AND column_name IN (
                   'finance_handoff_by_user_id',
                   'finance_handoff_at'
               )"
        );
        $columnStatement->execute();
        $columns = (int) $columnStatement->fetchColumn();

        $indexStatement = $connection->prepare(
            "SELECT COUNT(DISTINCT index_name)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'sales_quick_sale_reports'
               AND index_name =
                   'idx_sales_quick_sale_report_finance_handoff'"
        );
        $indexStatement->execute();
        $indexReady = (int) $indexStatement->fetchColumn() === 1;

        $fkStatement = $connection->prepare(
            "SELECT COUNT(*)
             FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = 'sales_quick_sale_reports'
               AND CONSTRAINT_NAME =
                   'fk_sales_quick_sale_report_finance_handoff_by'"
        );
        $fkStatement->execute();
        $fkReady = (int) $fkStatement->fetchColumn() === 1;

        if ($columns === 0 && !$indexReady && !$fkReady) {
            return 'apply';
        }

        if ($columns === 2 && $indexReady && $fkReady) {
            return 'baseline';
        }

        throw new RuntimeException(
            'Migration 077 found a partial Quick Sale Finance handoff schema.'
        );
    },

    'statements' => [
        <<<'SQL'
ALTER TABLE sales_quick_sale_reports
    ADD COLUMN finance_handoff_by_user_id BIGINT UNSIGNED NULL
        AFTER finance_invoice_id,

    ADD COLUMN finance_handoff_at DATETIME NULL
        AFTER finance_handoff_by_user_id,

    ADD INDEX idx_sales_quick_sale_report_finance_handoff
        (company_id, status, finance_handoff_at),

    ADD CONSTRAINT fk_sales_quick_sale_report_finance_handoff_by
        FOREIGN KEY (finance_handoff_by_user_id)
        REFERENCES users(user_id)
        ON DELETE RESTRICT
SQL,
    ],
];