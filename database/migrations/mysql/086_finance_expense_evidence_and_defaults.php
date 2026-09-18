<?php

declare(strict_types=1);

return [
    'version' => '086',
    'description' => 'Complete expense evidence, category account defaults, and optional non-reimbursement employee',
    'statements' => [
        <<<'SQL'
CREATE TABLE finance_expense_evidence (
    evidence_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    expense_request_id BIGINT UNSIGNED NOT NULL,
    sequence TINYINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_finance_expense_evidence_sequence UNIQUE (company_id, expense_request_id, sequence),
    CONSTRAINT ck_finance_expense_evidence_sequence CHECK (sequence BETWEEN 1 AND 10),
    CONSTRAINT fk_finance_expense_evidence_request FOREIGN KEY (company_id, expense_request_id)
        REFERENCES finance_expense_requests (company_id, expense_request_id) ON DELETE RESTRICT,
    CONSTRAINT fk_finance_expense_evidence_uploader FOREIGN KEY (uploaded_by_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
ALTER TABLE finance_expense_categories
    ADD COLUMN default_expense_account_id BIGINT UNSIGNED NULL,
    ADD COLUMN default_recoverable_tax_account_id BIGINT UNSIGNED NULL,
    ADD CONSTRAINT fk_finance_expense_category_default_expense
        FOREIGN KEY (company_id, default_expense_account_id)
        REFERENCES finance_accounts (company_id, account_id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_finance_expense_category_default_tax
        FOREIGN KEY (company_id, default_recoverable_tax_account_id)
        REFERENCES finance_accounts (company_id, account_id) ON DELETE RESTRICT
SQL,
        <<<'SQL'
ALTER TABLE finance_expense_requests
    MODIFY COLUMN requested_by_employee_id BIGINT UNSIGNED NULL,
    ADD CONSTRAINT ck_finance_expense_reimbursement_employee
        CHECK (expense_kind <> 'reimbursement' OR requested_by_employee_id IS NOT NULL)
SQL,
    ],
];
