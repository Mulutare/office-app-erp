<?php

declare(strict_types=1);

return [
    'version' => '037',
    'description' =>
        'Create tenant-scoped finance general ledger core',
    'preflight' => static function (
        \PDO $connection
    ): string {
        $count = (int) $connection->query(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN (
                    'finance_accounts',
                    'finance_journal_batches',
                    'finance_journal_entries',
                    'finance_account_balances'
               )"
        )->fetchColumn();

        if ($count === 0) {
            return 'apply';
        }

        if ($count === 4) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 037 found a partial finance general-ledger schema.'
        );
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE finance_accounts (
    account_id BIGINT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    account_code VARCHAR(50) NOT NULL,
    account_name VARCHAR(150) NOT NULL,
    account_type VARCHAR(30) NOT NULL,
    normal_balance VARCHAR(10) NOT NULL,
    system_key VARCHAR(80) NULL,
    parent_account_id BIGINT UNSIGNED NULL,
    currency CHAR(3) NULL,
    description VARCHAR(500) NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    allow_manual_posting BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,

    CONSTRAINT uq_finance_account_identity
        UNIQUE (
            company_id,
            account_id
        ),

    CONSTRAINT uq_finance_account_code
        UNIQUE (
            company_id,
            account_code
        ),

    CONSTRAINT uq_finance_account_system_key
        UNIQUE (
            company_id,
            system_key
        ),

    CONSTRAINT ck_finance_account_type
        CHECK (
            account_type IN (
                'asset',
                'liability',
                'equity',
                'revenue',
                'expense'
            )
        ),

    CONSTRAINT ck_finance_account_normal_balance
        CHECK (
            normal_balance IN (
                'debit',
                'credit'
            )
        ),

    CONSTRAINT ck_finance_account_currency
        CHECK (
            currency IS NULL
            OR currency REGEXP '^[A-Z]{3}$'
        ),

    CONSTRAINT fk_finance_account_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_finance_account_parent
        FOREIGN KEY (
            company_id,
            parent_account_id
        )
        REFERENCES finance_accounts(
            company_id,
            account_id
        )
        ON DELETE RESTRICT,

    CONSTRAINT fk_finance_account_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    CONSTRAINT fk_finance_account_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_finance_account_catalogue (
        company_id,
        account_type,
        active,
        deleted_at
    ),

    INDEX idx_finance_account_parent (
        company_id,
        parent_account_id
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE finance_journal_batches (
    journal_batch_id BIGINT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    batch_number VARCHAR(60) NOT NULL,
    source_type VARCHAR(60) NOT NULL,
    source_id VARCHAR(100) NULL,
    source_number VARCHAR(100) NULL,
    posting_date DATE NOT NULL,
    currency CHAR(3) NOT NULL,
    description VARCHAR(500) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    total_debit DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_credit DECIMAL(18,2) NOT NULL DEFAULT 0,
    idempotency_key VARCHAR(190) NOT NULL,
    reversal_of_batch_id BIGINT UNSIGNED NULL,
    posted_by BIGINT UNSIGNED NULL,
    posted_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT uq_finance_journal_batch_identity
        UNIQUE (
            company_id,
            journal_batch_id
        ),

    CONSTRAINT uq_finance_journal_batch_number
        UNIQUE (
            company_id,
            batch_number
        ),

    CONSTRAINT uq_finance_journal_idempotency
        UNIQUE (
            company_id,
            idempotency_key
        ),

    CONSTRAINT ck_finance_journal_batch_status
        CHECK (
            status IN (
                'draft',
                'posted',
                'reversed',
                'cancelled'
            )
        ),

    CONSTRAINT ck_finance_journal_batch_totals
        CHECK (
            total_debit >= 0
            AND total_credit >= 0
        ),

    CONSTRAINT ck_finance_journal_batch_currency
        CHECK (
            currency REGEXP '^[A-Z]{3}$'
        ),

    CONSTRAINT fk_finance_journal_batch_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_finance_journal_batch_reversal
        FOREIGN KEY (
            company_id,
            reversal_of_batch_id
        )
        REFERENCES finance_journal_batches(
            company_id,
            journal_batch_id
        )
        ON DELETE RESTRICT,

    CONSTRAINT fk_finance_journal_batch_posted_by
        FOREIGN KEY (posted_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    CONSTRAINT fk_finance_journal_batch_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_finance_journal_posting (
        company_id,
        status,
        posting_date,
        journal_batch_id
    ),

    INDEX idx_finance_journal_source (
        company_id,
        source_type,
        source_id
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE finance_journal_entries (
    journal_entry_id BIGINT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    journal_batch_id BIGINT UNSIGNED NOT NULL,
    line_number INT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    debit_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    credit_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL,
    description VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT uq_finance_journal_entry_identity
        UNIQUE (
            company_id,
            journal_entry_id
        ),

    CONSTRAINT uq_finance_journal_entry_line
        UNIQUE (
            company_id,
            journal_batch_id,
            line_number
        ),

    CONSTRAINT ck_finance_journal_entry_amount
        CHECK (
            (
                debit_amount > 0
                AND credit_amount = 0
            )
            OR (
                credit_amount > 0
                AND debit_amount = 0
            )
        ),

    CONSTRAINT ck_finance_journal_entry_currency
        CHECK (
            currency REGEXP '^[A-Z]{3}$'
        ),

    CONSTRAINT fk_finance_journal_entry_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_finance_journal_entry_batch
        FOREIGN KEY (
            company_id,
            journal_batch_id
        )
        REFERENCES finance_journal_batches(
            company_id,
            journal_batch_id
        )
        ON DELETE CASCADE,

    CONSTRAINT fk_finance_journal_entry_account
        FOREIGN KEY (
            company_id,
            account_id
        )
        REFERENCES finance_accounts(
            company_id,
            account_id
        )
        ON DELETE RESTRICT,

    CONSTRAINT fk_finance_journal_entry_branch
        FOREIGN KEY (
            company_id,
            branch_id
        )
        REFERENCES organization_branches(
            company_id,
            branch_id
        )
        ON DELETE RESTRICT,

    INDEX idx_finance_journal_entry_account (
        company_id,
        account_id,
        journal_batch_id
    ),

    INDEX idx_finance_journal_entry_branch (
        company_id,
        branch_id,
        journal_batch_id
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE finance_account_balances (
    account_balance_id BIGINT UNSIGNED
        AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    debit_total DECIMAL(18,2) NOT NULL DEFAULT 0,
    credit_total DECIMAL(18,2) NOT NULL DEFAULT 0,
    balance_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    version_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_posted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT uq_finance_account_balance
        UNIQUE (
            company_id,
            account_id,
            currency
        ),

    CONSTRAINT ck_finance_account_balance_currency
        CHECK (
            currency REGEXP '^[A-Z]{3}$'
        ),

    CONSTRAINT ck_finance_account_balance_totals
        CHECK (
            debit_total >= 0
            AND credit_total >= 0
        ),

    CONSTRAINT fk_finance_account_balance_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_finance_account_balance_account
        FOREIGN KEY (
            company_id,
            account_id
        )
        REFERENCES finance_accounts(
            company_id,
            account_id
        )
        ON DELETE RESTRICT,

    INDEX idx_finance_account_balance_lookup (
        company_id,
        currency,
        account_id
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];