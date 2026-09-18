<?php

declare(strict_types=1);

return [
    'version' => '087',
    'description' => 'Add durable bank GL ownership, statements, clearing and reconciliation history',
    'statements' => [
        <<<'SQL'
CREATE TABLE finance_bank_gl_bindings (
 binding_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL, bank_account_id BIGINT UNSIGNED NOT NULL,
 finance_account_id BIGINT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT uq_fb_binding_identity UNIQUE(company_id,binding_id),
 CONSTRAINT uq_fb_binding_gl_owner UNIQUE(company_id,finance_account_id),
 CONSTRAINT uq_fb_binding_mapping_ref UNIQUE(company_id,binding_id,bank_account_id,finance_account_id,currency),
 CONSTRAINT fk_fb_binding_bank FOREIGN KEY(company_id,bank_account_id) REFERENCES company_bank_accounts(company_id,bank_account_id) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_binding_gl FOREIGN KEY(company_id,finance_account_id) REFERENCES finance_accounts(company_id,account_id) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_binding_creator FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 INDEX idx_fb_binding_bank(company_id,bank_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE finance_bank_account_gl_mappings (
 mapping_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL, bank_account_id BIGINT UNSIGNED NOT NULL,
 finance_account_id BIGINT UNSIGNED NOT NULL, binding_id BIGINT UNSIGNED NOT NULL,
 currency CHAR(3) NOT NULL,
 effective_from DATE NOT NULL, effective_to DATE NULL,
 opening_gl_balance DECIMAL(18,2) NOT NULL, cutover_reason VARCHAR(1000) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'draft',
 replaces_mapping_id BIGINT UNSIGNED NULL, superseded_by_mapping_id BIGINT UNSIGNED NULL,
 current_bank_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status='approved' AND effective_to IS NULL THEN bank_account_id ELSE NULL END) STORED,
 current_gl_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status='approved' AND effective_to IS NULL THEN finance_account_id ELSE NULL END) STORED,
 created_by BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 approved_by BIGINT UNSIGNED NULL, approved_at DATETIME NULL, superseded_at DATETIME NULL,
 CONSTRAINT uq_fb_mapping_identity UNIQUE(company_id,mapping_id),
 CONSTRAINT uq_fb_mapping_bank_identity UNIQUE(company_id,mapping_id,bank_account_id),
 CONSTRAINT uq_fb_mapping_current_bank UNIQUE(company_id,current_bank_id),
 CONSTRAINT uq_fb_mapping_current_gl UNIQUE(company_id,current_gl_id),
 CONSTRAINT ck_fb_mapping_status CHECK(status IN('draft','approved','superseded')),
 CONSTRAINT ck_fb_mapping_dates CHECK(effective_to IS NULL OR effective_to>=effective_from),
 CONSTRAINT fk_fb_mapping_bank FOREIGN KEY(company_id,bank_account_id) REFERENCES company_bank_accounts(company_id,bank_account_id) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_mapping_gl FOREIGN KEY(company_id,finance_account_id) REFERENCES finance_accounts(company_id,account_id) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_mapping_binding FOREIGN KEY(company_id,binding_id,bank_account_id,finance_account_id,currency) REFERENCES finance_bank_gl_bindings(company_id,binding_id,bank_account_id,finance_account_id,currency) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_mapping_creator FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_mapping_approver FOREIGN KEY(approved_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_mapping_replaces FOREIGN KEY(company_id,replaces_mapping_id) REFERENCES finance_bank_account_gl_mappings(company_id,mapping_id) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_mapping_successor FOREIGN KEY(company_id,superseded_by_mapping_id) REFERENCES finance_bank_account_gl_mappings(company_id,mapping_id) ON DELETE RESTRICT,
 INDEX idx_fb_mapping_effective(company_id,bank_account_id,effective_from,effective_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE finance_bank_statements (
 statement_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL, bank_account_id BIGINT UNSIGNED NOT NULL,
 mapping_id BIGINT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL,
 statement_reference VARCHAR(190) NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL,
 statement_date DATE NOT NULL, opening_balance DECIMAL(18,2) NOT NULL,
 ending_balance DECIMAL(18,2) NOT NULL, source VARCHAR(20) NOT NULL DEFAULT 'manual',
 original_file_name VARCHAR(255) NULL, storage_path VARCHAR(500) NULL,
 mime_type VARCHAR(100) NULL, file_size BIGINT UNSIGNED NULL, sha256 CHAR(64) NULL,
 created_by BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT uq_fb_statement_identity UNIQUE(company_id,statement_id),
 CONSTRAINT uq_fb_statement_bank_identity UNIQUE(company_id,statement_id,bank_account_id),
 CONSTRAINT uq_fb_statement_reference UNIQUE(company_id,bank_account_id,statement_reference),
 CONSTRAINT ck_fb_statement_dates CHECK(period_start<=period_end AND statement_date>=period_end),
 CONSTRAINT ck_fb_statement_source CHECK(source IN('manual','file_evidence')),
 CONSTRAINT fk_fb_statement_mapping FOREIGN KEY(company_id,mapping_id,bank_account_id) REFERENCES finance_bank_account_gl_mappings(company_id,mapping_id,bank_account_id) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_statement_creator FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 INDEX idx_fb_statement_period(company_id,bank_account_id,period_start,period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE finance_bank_statement_lines (
 statement_line_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL, statement_id BIGINT UNSIGNED NOT NULL,
 bank_account_id BIGINT UNSIGNED NOT NULL, line_number INT UNSIGNED NOT NULL,
 transaction_date DATE NOT NULL, value_date DATE NULL, reference_number VARCHAR(190) NULL,
 description VARCHAR(1000) NOT NULL, direction VARCHAR(10) NOT NULL,
 amount DECIMAL(18,2) NOT NULL, currency CHAR(3) NOT NULL,
 provenance VARCHAR(20) NOT NULL DEFAULT 'manual', external_reference VARCHAR(190) NULL,
 row_fingerprint CHAR(64) NULL, created_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT uq_fb_line_identity UNIQUE(company_id,statement_line_id),
 CONSTRAINT uq_fb_line_number UNIQUE(company_id,statement_id,line_number),
 CONSTRAINT ck_fb_line_amount CHECK(amount>0),
 CONSTRAINT ck_fb_line_direction CHECK(direction IN('credit','debit')),
 CONSTRAINT ck_fb_line_provenance CHECK(provenance IN('manual','csv')),
 CONSTRAINT fk_fb_line_statement FOREIGN KEY(company_id,statement_id,bank_account_id) REFERENCES finance_bank_statements(company_id,statement_id,bank_account_id) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_line_creator FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 INDEX idx_fb_line_date(company_id,statement_id,transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE finance_bank_reconciliations (
 reconciliation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL, bank_account_id BIGINT UNSIGNED NOT NULL,
 mapping_id BIGINT UNSIGNED NOT NULL, statement_id BIGINT UNSIGNED NOT NULL,
 version_number INT UNSIGNED NOT NULL DEFAULT 1, currency CHAR(3) NOT NULL,
 gl_account_id BIGINT UNSIGNED NOT NULL,
 statement_opening_balance DECIMAL(18,2) NOT NULL, statement_ending_balance DECIMAL(18,2) NOT NULL,
 gl_closing_balance DECIMAL(18,2) NULL, deposits_in_transit DECIMAL(18,2) NULL,
 outstanding_payments DECIMAL(18,2) NULL, adjusted_bank_balance DECIMAL(18,2) NULL,
 reconciliation_difference DECIMAL(18,2) NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'draft',
 prepared_by BIGINT UNSIGNED NOT NULL, prepared_at DATETIME NOT NULL,
 reviewed_by BIGINT UNSIGNED NULL, reviewed_at DATETIME NULL,
 completed_by BIGINT UNSIGNED NULL, completed_at DATETIME NULL,
 review_reason VARCHAR(1000) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT uq_fb_rec_identity UNIQUE(company_id,reconciliation_id),
 CONSTRAINT uq_fb_rec_statement UNIQUE(company_id,statement_id,version_number),
 CONSTRAINT ck_fb_rec_status CHECK(status IN('draft','in_review','completed','superseded')),
 CONSTRAINT fk_fb_rec_statement FOREIGN KEY(company_id,statement_id,bank_account_id) REFERENCES finance_bank_statements(company_id,statement_id,bank_account_id) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_rec_mapping FOREIGN KEY(company_id,mapping_id,bank_account_id) REFERENCES finance_bank_account_gl_mappings(company_id,mapping_id,bank_account_id) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_rec_gl FOREIGN KEY(company_id,gl_account_id) REFERENCES finance_accounts(company_id,account_id) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_rec_preparer FOREIGN KEY(prepared_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_rec_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_rec_completer FOREIGN KEY(completed_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 INDEX idx_fb_rec_bank(company_id,bank_account_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE finance_bank_reconciliation_matches (
 match_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL, reconciliation_id BIGINT UNSIGNED NOT NULL,
 statement_line_id BIGINT UNSIGNED NOT NULL, journal_entry_id BIGINT UNSIGNED NOT NULL,
 applied_amount DECIMAL(18,2) NOT NULL, created_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 removed_by BIGINT UNSIGNED NULL, removed_at DATETIME NULL,
 CONSTRAINT uq_fb_match_identity UNIQUE(company_id,match_id),
 CONSTRAINT ck_fb_match_amount CHECK(applied_amount>0),
 CONSTRAINT fk_fb_match_rec FOREIGN KEY(company_id,reconciliation_id) REFERENCES finance_bank_reconciliations(company_id,reconciliation_id) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_match_line FOREIGN KEY(company_id,statement_line_id) REFERENCES finance_bank_statement_lines(company_id,statement_line_id) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_match_book FOREIGN KEY(company_id,journal_entry_id) REFERENCES finance_journal_entries(company_id,journal_entry_id) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_match_creator FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_match_remover FOREIGN KEY(removed_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 INDEX idx_fb_match_line(company_id,statement_line_id,removed_at),
 INDEX idx_fb_match_book(company_id,journal_entry_id,removed_at),
 INDEX idx_fb_match_rec(company_id,reconciliation_id,removed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE finance_bank_reconciliation_events (
 event_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL, reconciliation_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(60) NOT NULL, from_status VARCHAR(20) NULL, to_status VARCHAR(20) NULL,
 reason VARCHAR(1000) NULL, reference VARCHAR(190) NULL,
 actor_id BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_fb_event_rec FOREIGN KEY(company_id,reconciliation_id) REFERENCES finance_bank_reconciliations(company_id,reconciliation_id) ON DELETE RESTRICT,
 CONSTRAINT fk_fb_event_actor FOREIGN KEY(actor_id) REFERENCES users(user_id) ON DELETE RESTRICT,
 INDEX idx_fb_event_timeline(company_id,reconciliation_id,created_at,event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO permissions(name,code,module,description,active) VALUES
('View Bank Reconciliation','finance.bank_reconciliation.view','finance','View company bank reconciliation',TRUE),
('Prepare Bank Reconciliation','finance.bank_reconciliation.prepare','finance','Prepare bank statements and matches',TRUE),
('Review Bank Reconciliation','finance.bank_reconciliation.review','finance','Independently complete bank reconciliations',TRUE),
('Approve Bank GL Mapping','finance.bank_reconciliation.mapping','finance','Approve explicit bank to GL mapping and cutover',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),active=TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p
WHERE (r.code IN('company_owner','system_administrator') AND p.code IN('finance.bank_reconciliation.view','finance.bank_reconciliation.prepare','finance.bank_reconciliation.review','finance.bank_reconciliation.mapping'))
 OR (r.code='finance_officer' AND p.code IN('finance.bank_reconciliation.view','finance.bank_reconciliation.prepare'))
 OR (r.code='finance_approver' AND p.code IN('finance.bank_reconciliation.view','finance.bank_reconciliation.review'))
SQL,
    ],
];
