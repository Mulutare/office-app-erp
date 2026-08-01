<?php

declare(strict_types=1);

return [
    'version' => '030',
    'description' => 'Harden Sales credit, numbering, transition history and outbox claiming',
    'preflight' => static function (\PDO $connection): string {
        $tables = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ('sales_document_sequences','sales_order_status_history')"
        )->fetchColumn();
        $customerColumns = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'sales_customers'
               AND column_name IN ('credit_mode','credit_status','preferred_currency','branch_id','agent_id')"
        )->fetchColumn();
        $outboxColumns = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'integration_outbox'
               AND column_name IN ('claimed_by','claimed_at','dead_lettered_at')"
        )->fetchColumn();
        if ($tables === 0 && $customerColumns === 0 && $outboxColumns === 0) {
            return 'apply';
        }
        if ($tables === 2 && $customerColumns === 5 && $outboxColumns === 3) {
            return 'baseline';
        }
        throw new \RuntimeException('Migration 030 found a partial Sales hardening schema.');
    },
    'statements' => [
        <<<'SQL'
ALTER TABLE sales_customers
    ADD COLUMN branch_id BIGINT UNSIGNED NULL AFTER company_id,
    ADD COLUMN agent_id BIGINT UNSIGNED NULL AFTER territory_id,
    ADD COLUMN preferred_currency CHAR(3) NOT NULL DEFAULT 'ETB' AFTER address,
    ADD COLUMN credit_mode VARCHAR(20) NOT NULL DEFAULT 'no_credit' AFTER preferred_currency,
    ADD COLUMN credit_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER credit_limit,
    ADD CONSTRAINT ck_sales_customer_credit_mode CHECK (credit_mode IN ('no_credit','unlimited','fixed')),
    ADD CONSTRAINT ck_sales_customer_credit_status CHECK (credit_status IN ('active','hold','blocked')),
    ADD CONSTRAINT fk_sales_customer_branch FOREIGN KEY (branch_id) REFERENCES organization_branches(branch_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_sales_customer_agent FOREIGN KEY (agent_id) REFERENCES sales_agents(agent_id) ON DELETE SET NULL,
    ADD INDEX idx_sales_customer_branch (company_id, branch_id, active),
    ADD INDEX idx_sales_customer_credit (company_id, credit_status, credit_mode)
SQL,
        <<<'SQL'
UPDATE sales_customers
SET credit_mode = CASE WHEN credit_limit > 0 THEN 'fixed' ELSE 'unlimited' END
SQL,
        <<<'SQL'
CREATE TABLE sales_document_sequences (
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    document_type VARCHAR(30) NOT NULL,
    prefix VARCHAR(20) NOT NULL,
    next_number BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    branch_scope BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (company_id, branch_scope, document_type),
    CONSTRAINT ck_sales_sequence_branch_scope CHECK (
        (branch_id IS NULL AND branch_scope = 0)
        OR branch_scope = branch_id
    ),
    CONSTRAINT ck_sales_document_type CHECK (document_type IN ('quotation','order','invoice','receipt','credit_note','return')),
    CONSTRAINT ck_sales_document_next CHECK (next_number > 0),
    CONSTRAINT fk_sales_sequence_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_sequence_branch FOREIGN KEY (branch_id) REFERENCES organization_branches(branch_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE sales_order_status_history (
    history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(24) NULL,
    to_status VARCHAR(24) NOT NULL,
    action VARCHAR(40) NOT NULL,
    reason VARCHAR(500) NULL,
    actor_id BIGINT UNSIGNED NULL,
    occurred_at DATETIME NOT NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    CONSTRAINT uq_sales_transition_retry UNIQUE (company_id, idempotency_key),
    CONSTRAINT fk_sales_history_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_history_order FOREIGN KEY (order_id) REFERENCES sales_orders(order_id) ON DELETE RESTRICT,
    CONSTRAINT fk_sales_history_actor FOREIGN KEY (actor_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_sales_history_order (company_id, order_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
ALTER TABLE integration_outbox
    ADD COLUMN claimed_by VARCHAR(80) NULL AFTER available_at,
    ADD COLUMN claimed_at DATETIME NULL AFTER claimed_by,
    ADD COLUMN dead_lettered_at DATETIME NULL AFTER processed_at,
    ADD INDEX idx_integration_outbox_claim (status, available_at, claimed_at, outbox_sequence)
SQL,
    ],
];
