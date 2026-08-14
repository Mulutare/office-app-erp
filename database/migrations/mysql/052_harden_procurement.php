<?php
declare(strict_types=1);
return [
 'version'=>'052',
 'description'=>'Harden procurement payments and reversal controls',
 'preflight'=>static function(\PDO $c):string{
   $table=(int)$c->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='procurement_idempotency_keys'")->fetchColumn();
   $column=(int)$c->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='purchase_order_lines' AND column_name='returned_quantity'")->fetchColumn();
   if($table===0&&$column===0)return'apply';if($table===1&&$column===1)return'baseline';throw new \RuntimeException('Migration 052 found a partial procurement hardening schema.');
 },
 'statements'=>[
  "ALTER TABLE purchase_order_lines ADD COLUMN returned_quantity DECIMAL(18,3) NOT NULL DEFAULT 0 AFTER received_quantity, DROP CONSTRAINT ck_purchase_order_line_quantities, ADD CONSTRAINT ck_purchase_order_line_quantities CHECK(ordered_quantity>0 AND received_quantity>=0 AND received_quantity<=ordered_quantity AND returned_quantity>=0 AND returned_quantity<=received_quantity AND billed_quantity>=0 AND billed_quantity<=received_quantity-returned_quantity)",
  <<<'SQL'
CREATE TABLE procurement_idempotency_keys (
 company_id BIGINT UNSIGNED NOT NULL,
 idempotency_key VARCHAR(100) NOT NULL,
 operation VARCHAR(40) NOT NULL,
 record_id BIGINT UNSIGNED NULL,
 result_id BIGINT UNSIGNED NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(company_id,idempotency_key),
 CONSTRAINT fk_procurement_key_company FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
 CONSTRAINT fk_procurement_key_actor FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE SET NULL,
 INDEX idx_procurement_key_record(company_id,operation,record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
  "ALTER TABLE finance_invoices ADD CONSTRAINT uq_finance_supplier_invoice UNIQUE(company_id,vendor_id,supplier_invoice_number,document_type)",
  "INSERT INTO permissions(name,code,module,description,active) VALUES('Reverse Supplier Bills','procurement.bills.reverse','procurement','Reverse posted supplier bills and restore billable quantities',TRUE) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),active=TRUE",
  "INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p WHERE r.name='Company Owner' AND p.module='procurement' AND p.active=TRUE"
 ]
];
