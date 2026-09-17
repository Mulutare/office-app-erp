<?php
declare(strict_types=1);

return [
    'version' => '084',
    'description' => 'Complete controlled Finance expense document and posting references',
    'statements' => [
        <<<'SQL'
ALTER TABLE finance_expense_requests
 ADD COLUMN expense_kind VARCHAR(24) NOT NULL DEFAULT 'company_paid',
 ADD COLUMN expense_account_id BIGINT UNSIGNED NULL,
 ADD COLUMN net_amount DECIMAL(15,2) NULL,
 ADD COLUMN tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
 ADD COLUMN tax_account_id BIGINT UNSIGNED NULL,
 ADD COLUMN settlement_method VARCHAR(24) NULL,
 ADD COLUMN payment_journal_id BIGINT UNSIGNED NULL,
 ADD COLUMN journal_batch_id BIGINT UNSIGNED NULL,
 ADD COLUMN recognition_batch_id BIGINT UNSIGNED NULL,
 ADD COLUMN recognized_by BIGINT UNSIGNED NULL,
 ADD COLUMN recognized_at DATETIME NULL,
 ADD COLUMN reversal_batch_id BIGINT UNSIGNED NULL,
 ADD COLUMN recognition_reversal_batch_id BIGINT UNSIGNED NULL,
 ADD COLUMN reversed_by BIGINT UNSIGNED NULL,
 ADD COLUMN reversed_at DATETIME NULL,
 ADD COLUMN reversal_reason VARCHAR(500) NULL,
 ADD COLUMN paid_by BIGINT UNSIGNED NULL,
 ADD COLUMN evidence_reference VARCHAR(500) NULL,
 ADD CONSTRAINT fk_expense_account FOREIGN KEY(company_id,expense_account_id) REFERENCES finance_accounts(company_id,account_id),
 ADD CONSTRAINT fk_expense_tax_account FOREIGN KEY(company_id,tax_account_id) REFERENCES finance_accounts(company_id,account_id),
 ADD CONSTRAINT fk_expense_payment_journal FOREIGN KEY(company_id,payment_journal_id) REFERENCES finance_journals(company_id,journal_id),
 ADD CONSTRAINT fk_expense_journal_batch FOREIGN KEY(company_id,journal_batch_id) REFERENCES finance_journal_batches(company_id,journal_batch_id),
 ADD CONSTRAINT fk_expense_recognition_batch FOREIGN KEY(company_id,recognition_batch_id) REFERENCES finance_journal_batches(company_id,journal_batch_id),
 ADD CONSTRAINT fk_expense_reversal_batch FOREIGN KEY(company_id,reversal_batch_id) REFERENCES finance_journal_batches(company_id,journal_batch_id),
 ADD CONSTRAINT fk_expense_recognition_reversal FOREIGN KEY(company_id,recognition_reversal_batch_id) REFERENCES finance_journal_batches(company_id,journal_batch_id),
 ADD CONSTRAINT fk_expense_recognized_by FOREIGN KEY(recognized_by) REFERENCES users(user_id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_expense_reversed_by FOREIGN KEY(reversed_by) REFERENCES users(user_id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_expense_paid_by FOREIGN KEY(paid_by) REFERENCES users(user_id) ON DELETE SET NULL,
 ADD CONSTRAINT ck_expense_kind CHECK(expense_kind IN('company_paid','reimbursement','petty_cash')),
 ADD CONSTRAINT ck_expense_tax_nonnegative CHECK(tax_amount>=0),
 ADD CONSTRAINT uq_expense_company_identity UNIQUE(company_id,expense_request_id),
 ADD INDEX ix_expense_posting(company_id,journal_batch_id)
SQL,
        <<<'SQL'
CREATE TABLE finance_expense_history (
 history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 expense_request_id BIGINT UNSIGNED NOT NULL,
 from_status VARCHAR(30) NULL,
 to_status VARCHAR(30) NOT NULL,
 action VARCHAR(30) NOT NULL,
 reason VARCHAR(500) NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_expense_history_company FOREIGN KEY(company_id) REFERENCES companies(company_id),
 CONSTRAINT fk_expense_history_expense FOREIGN KEY(company_id,expense_request_id) REFERENCES finance_expense_requests(company_id,expense_request_id),
 CONSTRAINT fk_expense_history_actor FOREIGN KEY(actor_id) REFERENCES users(user_id),
 INDEX ix_expense_history(company_id,expense_request_id,history_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
