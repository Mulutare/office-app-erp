<?php

declare(strict_types=1);

return [
    'version' => '054',
    'description' => 'Create company banking, commercial branding and Sales settlement reconciliation',
    'statements' => [
        <<<'SQL'
CREATE TABLE company_bank_accounts (
 bank_account_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 bank_name VARCHAR(190) NOT NULL, account_name VARCHAR(190) NOT NULL, account_number VARCHAR(100) NOT NULL,
 branch VARCHAR(190) NULL, currency CHAR(3) NOT NULL DEFAULT 'ETB', swift_bic VARCHAR(20) NULL,
 provider_code VARCHAR(60) NULL, is_default BOOLEAN NOT NULL DEFAULT FALSE, active BOOLEAN NOT NULL DEFAULT TRUE,
 created_by BIGINT UNSIGNED NULL, updated_by BIGINT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT uq_company_bank_identity UNIQUE(company_id,bank_account_id),
 CONSTRAINT uq_company_bank_number UNIQUE(company_id,account_number,currency),
 CONSTRAINT fk_company_bank_company FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
 CONSTRAINT fk_company_bank_creator FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE SET NULL,
 CONSTRAINT fk_company_bank_updater FOREIGN KEY(updated_by) REFERENCES users(user_id) ON DELETE SET NULL,
 INDEX idx_company_bank_default(company_id,active,is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE company_document_branding (
 company_id BIGINT UNSIGNED PRIMARY KEY, tin VARCHAR(80) NULL, vat_registration_number VARCHAR(80) NULL,
 document_address VARCHAR(500) NULL, document_phone VARCHAR(60) NULL, document_email VARCHAR(190) NULL,
 website VARCHAR(190) NULL, payment_terms VARCHAR(1000) NULL, footer_text VARCHAR(1000) NULL,
 signatory_name VARCHAR(190) NULL, signatory_title VARCHAR(190) NULL,
 logo_path VARCHAR(500) NULL, stamp_path VARCHAR(500) NULL, signature_path VARCHAR(500) NULL,
 updated_by BIGINT UNSIGNED NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_document_branding_company FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
 CONSTRAINT fk_document_branding_actor FOREIGN KEY(updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE sales_settlements (
 settlement_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 settlement_number VARCHAR(60) NOT NULL, bank_account_id BIGINT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL,
 expected_amount DECIMAL(18,2) NOT NULL, confirmed_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
 variance_amount DECIMAL(18,2) NOT NULL DEFAULT 0, remaining_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
 reconciliation_status VARCHAR(30) NOT NULL DEFAULT 'awaiting_confirmation', workflow_status VARCHAR(30) NOT NULL DEFAULT 'draft',
 notes VARCHAR(1000) NULL, return_reason VARCHAR(1000) NULL, created_by BIGINT UNSIGNED NOT NULL,
 submitted_by BIGINT UNSIGNED NULL, supervisor_reviewed_by BIGINT UNSIGNED NULL, finance_reconciled_by BIGINT UNSIGNED NULL,
 approved_by BIGINT UNSIGNED NULL, submitted_at DATETIME NULL, supervisor_reviewed_at DATETIME NULL,
 finance_reconciled_at DATETIME NULL, approved_at DATETIME NULL, closed_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT uq_sales_settlement_identity UNIQUE(company_id,settlement_id),
 CONSTRAINT uq_sales_settlement_number UNIQUE(company_id,settlement_number),
 CONSTRAINT ck_sales_settlement_amounts CHECK(expected_amount>0 AND confirmed_amount>=0),
 CONSTRAINT ck_sales_settlement_workflow CHECK(workflow_status IN('draft','submitted','supervisor_reviewed','finance_reconciled','approved','closed','returned','cancelled')),
 CONSTRAINT ck_sales_settlement_reconciliation CHECK(reconciliation_status IN('awaiting_confirmation','matched','partial','mismatch','review_required')),
 CONSTRAINT fk_sales_settlement_company FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
 CONSTRAINT fk_sales_settlement_bank FOREIGN KEY(company_id,bank_account_id) REFERENCES company_bank_accounts(company_id,bank_account_id) ON DELETE RESTRICT,
 CONSTRAINT fk_sales_settlement_creator FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 INDEX idx_sales_settlement_state(company_id,workflow_status,reconciliation_status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE sales_settlement_lines (
 settlement_line_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 settlement_id BIGINT UNSIGNED NOT NULL, sales_order_id BIGINT UNSIGNED NOT NULL, finance_payment_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(18,2) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT uq_sales_settlement_line_identity UNIQUE(company_id,settlement_line_id),
 CONSTRAINT uq_sales_settlement_payment UNIQUE(company_id,finance_payment_id),
 CONSTRAINT ck_sales_settlement_line_amount CHECK(amount>0),
 CONSTRAINT fk_sales_settlement_line_header FOREIGN KEY(company_id,settlement_id) REFERENCES sales_settlements(company_id,settlement_id) ON DELETE RESTRICT,
 CONSTRAINT fk_sales_settlement_line_order FOREIGN KEY(sales_order_id) REFERENCES sales_orders(order_id) ON DELETE RESTRICT,
 CONSTRAINT fk_sales_settlement_line_payment FOREIGN KEY(company_id,finance_payment_id) REFERENCES finance_payments(company_id,payment_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE bank_confirmations (
 confirmation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 settlement_id BIGINT UNSIGNED NOT NULL, bank_reference VARCHAR(190) NOT NULL, transaction_date DATE NOT NULL,
 confirmed_amount DECIMAL(18,2) NOT NULL, currency CHAR(3) NOT NULL, evidence_path VARCHAR(500) NOT NULL,
 evidence_original_name VARCHAR(255) NOT NULL, evidence_mime VARCHAR(100) NOT NULL, evidence_size BIGINT UNSIGNED NOT NULL,
 evidence_sha256 CHAR(64) NOT NULL, source VARCHAR(30) NOT NULL DEFAULT 'manual', created_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT uq_bank_confirmation_identity UNIQUE(company_id,confirmation_id),
 CONSTRAINT uq_bank_confirmation_reference UNIQUE(company_id,settlement_id,bank_reference),
 CONSTRAINT ck_bank_confirmation_amount CHECK(confirmed_amount>0),
 CONSTRAINT fk_bank_confirmation_settlement FOREIGN KEY(company_id,settlement_id) REFERENCES sales_settlements(company_id,settlement_id) ON DELETE RESTRICT,
 CONSTRAINT fk_bank_confirmation_actor FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 INDEX idx_bank_confirmation_settlement(company_id,settlement_id,transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE sales_settlement_events (
 event_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL, settlement_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(80) NOT NULL, from_status VARCHAR(30) NULL, to_status VARCHAR(30) NULL, reason VARCHAR(1000) NULL,
 actor_id BIGINT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_settlement_event_header FOREIGN KEY(company_id,settlement_id) REFERENCES sales_settlements(company_id,settlement_id) ON DELETE RESTRICT,
 CONSTRAINT fk_settlement_event_actor FOREIGN KEY(actor_id) REFERENCES users(user_id) ON DELETE SET NULL,
 INDEX idx_settlement_event_timeline(company_id,settlement_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE bank_transactions (
 bank_transaction_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL, bank_account_id BIGINT UNSIGNED NOT NULL,
 provider VARCHAR(60) NOT NULL, external_transaction_id VARCHAR(190) NOT NULL, transaction_reference VARCHAR(190) NULL,
 booking_date DATE NOT NULL, value_date DATE NULL, amount DECIMAL(18,2) NOT NULL, currency CHAR(3) NOT NULL,
 debit_credit VARCHAR(10) NOT NULL, remittance_information VARCHAR(1000) NULL, source VARCHAR(30) NOT NULL,
 reconciliation_status VARCHAR(30) NOT NULL DEFAULT 'unmatched', imported_by BIGINT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT uq_bank_transaction_external UNIQUE(company_id,bank_account_id,provider,external_transaction_id),
 CONSTRAINT fk_bank_transaction_account FOREIGN KEY(company_id,bank_account_id) REFERENCES company_bank_accounts(company_id,bank_account_id) ON DELETE RESTRICT,
 CONSTRAINT fk_bank_transaction_actor FOREIGN KEY(imported_by) REFERENCES users(user_id) ON DELETE SET NULL,
 INDEX idx_bank_transaction_matching(company_id,bank_account_id,currency,amount,booking_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO permissions(name,code,module,description,active) VALUES
('View Sales Settlements','sales.settlements.view','sales','View company Sales settlements',TRUE),
('Create Sales Settlements','sales.settlements.create','sales','Create settlements from posted customer payments',TRUE),
('Submit Sales Settlements','sales.settlements.submit','sales','Submit a settlement for review',TRUE),
('Review Sales Settlements','sales.settlements.review','sales','Perform supervisor settlement review',TRUE),
('View Settlement Reconciliation','finance.settlements.view','finance','View settlement reconciliation records',TRUE),
('Reconcile Settlements','finance.settlements.reconcile','finance','Verify bank evidence and reconcile settlements',TRUE),
('Approve Settlements','finance.settlements.approve','finance','Final approve reconciled settlements',TRUE),
('Add Bank Confirmations','finance.bank_confirmations.create','finance','Add protected bank confirmation evidence',TRUE),
('Manage Company Bank Accounts','finance.bank_accounts.manage','finance','Manage company-scoped bank accounts',TRUE),
('Download Commercial Documents','commercial_documents.download','sales','Download authorized commercial documents',TRUE),
('Manage Document Branding','company.document_branding.manage','administration','Manage protected commercial branding and signatures',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),active=TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p WHERE
 (r.code IN('company_owner','system_administrator') AND p.code IN('sales.settlements.view','sales.settlements.create','sales.settlements.submit','sales.settlements.review','finance.settlements.view','finance.settlements.reconcile','finance.settlements.approve','finance.bank_confirmations.create','finance.bank_accounts.manage','commercial_documents.download','company.document_branding.manage')) OR
 (r.code IN('sales_officer','sales_cashier','sales_manager') AND p.code IN('sales.settlements.view','sales.settlements.create','sales.settlements.submit','commercial_documents.download')) OR
 (r.code='sales_manager' AND p.code='sales.settlements.review') OR
 (r.code='finance_officer' AND p.code IN('finance.settlements.view','finance.settlements.reconcile','finance.bank_confirmations.create','commercial_documents.download')) OR
 (r.code='finance_approver' AND p.code IN('finance.settlements.view','finance.settlements.approve','commercial_documents.download'))
SQL,
    ],
];
