<?php

declare(strict_types=1);

return [
    'version' => '051',
    'description' => 'Create company-scoped procurement, receipt matching and AP links',
    'preflight' => static function (\PDO $connection): string {
        $tables=['purchase_suppliers','purchase_requisitions','purchase_requisition_lines','purchase_orders','purchase_order_lines'];
        $found=[];
        $statement=$connection->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'purchase_%'");
        foreach($statement->fetchAll(\PDO::FETCH_COLUMN) as $table){if(in_array($table,$tables,true))$found[]=$table;}
        if($found===[])return 'apply';
        $prefix=array_slice($tables,0,count($found));sort($found);$sortedPrefix=$prefix;sort($sortedPrefix);
        if($found!==$sortedPrefix)throw new \RuntimeException('Migration 051 found a non-prefix partial procurement schema that requires operator review.');
        $required=['purchase_suppliers'=>['supplier_id','company_id','supplier_code','business_name','currency','active'],
            'purchase_requisitions'=>['requisition_id','company_id','requester_user_id','department_id','status'],
            'purchase_requisition_lines'=>['requisition_line_id','company_id','requisition_id','product_id','quantity'],
            'purchase_orders'=>['purchase_order_id','company_id','supplier_id','warehouse_id','status','total_amount'],
            'purchase_order_lines'=>['purchase_order_line_id','company_id','purchase_order_id','ordered_quantity','received_quantity','billed_quantity']];
        foreach($prefix as $table){$q=$connection->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:table');$q->execute(['table'=>$table]);$columns=$q->fetchAll(\PDO::FETCH_COLUMN);foreach($required[$table] as $column)if(!in_array($column,$columns,true))throw new \RuntimeException("Migration 051 found malformed table {$table}: missing {$column}.");}
        if(count($found)<5)return 'apply';
        $linked=(int)$connection->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND ((table_name='inventory_goods_receipts' AND column_name='purchase_order_id') OR (table_name='inventory_goods_receipt_lines' AND column_name='purchase_order_line_id') OR (table_name='finance_invoices' AND column_name IN('purchase_order_id','supplier_invoice_number')) OR (table_name='finance_invoice_lines' AND column_name='purchase_order_line_id'))")->fetchColumn();
        $permissions=(int)$connection->query("SELECT COUNT(*) FROM permissions WHERE module='procurement' AND code LIKE 'procurement.%'")->fetchColumn();
        if($linked===5&&$permissions===11)return 'baseline';
        return 'apply';
    },
    'statements' => [
<<<'SQL'
CREATE TABLE IF NOT EXISTS purchase_suppliers (
 supplier_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 supplier_code VARCHAR(40) NOT NULL, business_name VARCHAR(180) NOT NULL, contact_person VARCHAR(120) NULL,
 phone VARCHAR(50) NULL, email VARCHAR(190) NULL, address VARCHAR(500) NULL, tax_number VARCHAR(80) NULL,
 payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 0, currency CHAR(3) NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE,
 created_by BIGINT UNSIGNED NULL, updated_by BIGINT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT uq_purchase_supplier_identity UNIQUE(company_id,supplier_id), CONSTRAINT uq_purchase_supplier_code UNIQUE(company_id,supplier_code),
 CONSTRAINT ck_purchase_supplier_currency CHECK(currency REGEXP '^[A-Z]{3}$'),
 CONSTRAINT fk_purchase_supplier_company FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
 CONSTRAINT fk_purchase_supplier_creator FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE SET NULL,
 CONSTRAINT fk_purchase_supplier_updater FOREIGN KEY(updated_by) REFERENCES users(user_id) ON DELETE SET NULL,
 INDEX idx_purchase_supplier_active(company_id,active,business_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS purchase_requisitions (
 requisition_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL, requisition_number VARCHAR(60) NOT NULL,
 requester_user_id BIGINT UNSIGNED NOT NULL, department_id INT UNSIGNED NULL, requested_date DATE NOT NULL, required_by_date DATE NULL,
 justification VARCHAR(1000) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft', rejection_reason VARCHAR(500) NULL,
 approved_by BIGINT UNSIGNED NULL, approved_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT uq_purchase_requisition_identity UNIQUE(company_id,requisition_id), CONSTRAINT uq_purchase_requisition_number UNIQUE(company_id,requisition_number),
 CONSTRAINT ck_purchase_requisition_status CHECK(status IN('draft','submitted','approved','rejected','converted','cancelled')),
 CONSTRAINT fk_purchase_requisition_company FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
 CONSTRAINT fk_purchase_requisition_requester FOREIGN KEY(requester_user_id) REFERENCES users(user_id) ON DELETE RESTRICT,
 CONSTRAINT fk_purchase_requisition_department FOREIGN KEY(company_id,department_id) REFERENCES hr_departments(company_id,department_id) ON DELETE RESTRICT,
 CONSTRAINT fk_purchase_requisition_approver FOREIGN KEY(approved_by) REFERENCES users(user_id) ON DELETE SET NULL,
 INDEX idx_purchase_requisition_status(company_id,status,requested_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS purchase_requisition_lines (
 requisition_line_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL, requisition_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL, description VARCHAR(255) NOT NULL, quantity DECIMAL(18,3) NOT NULL, estimated_unit_price DECIMAL(18,4) NOT NULL DEFAULT 0,
 preferred_supplier_id BIGINT UNSIGNED NULL, warehouse_id BIGINT UNSIGNED NULL,
 CONSTRAINT uq_purchase_requisition_line_identity UNIQUE(company_id,requisition_line_id), CONSTRAINT ck_purchase_requisition_line_quantity CHECK(quantity>0),
 CONSTRAINT fk_purchase_requisition_line_header FOREIGN KEY(company_id,requisition_id) REFERENCES purchase_requisitions(company_id,requisition_id) ON DELETE CASCADE,
 CONSTRAINT fk_purchase_requisition_line_product FOREIGN KEY(product_id) REFERENCES sales_products(product_id) ON DELETE RESTRICT,
 CONSTRAINT fk_purchase_requisition_line_supplier FOREIGN KEY(company_id,preferred_supplier_id) REFERENCES purchase_suppliers(company_id,supplier_id) ON DELETE RESTRICT,
 CONSTRAINT fk_purchase_requisition_line_warehouse FOREIGN KEY(company_id,warehouse_id) REFERENCES inventory_warehouses(company_id,warehouse_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS purchase_orders (
 purchase_order_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL, po_number VARCHAR(60) NOT NULL,
 supplier_id BIGINT UNSIGNED NOT NULL, requisition_id BIGINT UNSIGNED NULL, warehouse_id BIGINT UNSIGNED NOT NULL,
 order_date DATE NOT NULL, expected_date DATE NULL, currency CHAR(3) NOT NULL, payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 supplier_reference VARCHAR(120) NULL, notes VARCHAR(1000) NULL, subtotal DECIMAL(18,2) NOT NULL, tax_amount DECIMAL(18,2) NOT NULL,
 discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0, total_amount DECIMAL(18,2) NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'draft',
 created_by BIGINT UNSIGNED NULL, approved_by BIGINT UNSIGNED NULL, approved_at DATETIME NULL, confirmed_by BIGINT UNSIGNED NULL, confirmed_at DATETIME NULL,
 closed_by BIGINT UNSIGNED NULL, closed_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT uq_purchase_order_identity UNIQUE(company_id,purchase_order_id), CONSTRAINT uq_purchase_order_number UNIQUE(company_id,po_number),
 CONSTRAINT uq_purchase_order_requisition UNIQUE(company_id,requisition_id),
 CONSTRAINT ck_purchase_order_status CHECK(status IN('draft','submitted','approved','confirmed','partially_received','received','partially_billed','billed','closed','rejected','cancelled')),
 CONSTRAINT ck_purchase_order_amounts CHECK(subtotal>=0 AND tax_amount>=0 AND discount_amount>=0 AND total_amount>=0),
 CONSTRAINT fk_purchase_order_company FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
 CONSTRAINT fk_purchase_order_supplier FOREIGN KEY(company_id,supplier_id) REFERENCES purchase_suppliers(company_id,supplier_id) ON DELETE RESTRICT,
 CONSTRAINT fk_purchase_order_requisition FOREIGN KEY(company_id,requisition_id) REFERENCES purchase_requisitions(company_id,requisition_id) ON DELETE RESTRICT,
 CONSTRAINT fk_purchase_order_warehouse FOREIGN KEY(company_id,warehouse_id) REFERENCES inventory_warehouses(company_id,warehouse_id) ON DELETE RESTRICT,
 CONSTRAINT fk_purchase_order_creator FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE SET NULL,
 CONSTRAINT fk_purchase_order_approver FOREIGN KEY(approved_by) REFERENCES users(user_id) ON DELETE SET NULL,
 CONSTRAINT fk_purchase_order_confirmer FOREIGN KEY(confirmed_by) REFERENCES users(user_id) ON DELETE SET NULL,
 CONSTRAINT fk_purchase_order_closer FOREIGN KEY(closed_by) REFERENCES users(user_id) ON DELETE SET NULL,
 INDEX idx_purchase_order_status(company_id,status,order_date), INDEX idx_purchase_order_supplier(company_id,supplier_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS purchase_order_lines (
 purchase_order_line_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL, purchase_order_id BIGINT UNSIGNED NOT NULL,
 requisition_line_id BIGINT UNSIGNED NULL, product_id BIGINT UNSIGNED NOT NULL, description VARCHAR(255) NOT NULL,
 ordered_quantity DECIMAL(18,3) NOT NULL, received_quantity DECIMAL(18,3) NOT NULL DEFAULT 0, billed_quantity DECIMAL(18,3) NOT NULL DEFAULT 0,
 unit_price DECIMAL(18,4) NOT NULL, discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0, tax_rate DECIMAL(9,4) NOT NULL DEFAULT 0,
 untaxed_amount DECIMAL(18,2) NOT NULL, tax_amount DECIMAL(18,2) NOT NULL, line_total DECIMAL(18,2) NOT NULL,
 CONSTRAINT uq_purchase_order_line_identity UNIQUE(company_id,purchase_order_line_id),
 CONSTRAINT ck_purchase_order_line_quantities CHECK(ordered_quantity>0 AND received_quantity>=0 AND received_quantity<=ordered_quantity AND billed_quantity>=0 AND billed_quantity<=received_quantity),
 CONSTRAINT ck_purchase_order_line_amounts CHECK(unit_price>=0 AND discount_amount>=0 AND tax_rate BETWEEN 0 AND 100 AND untaxed_amount>=0 AND tax_amount>=0 AND line_total>=0),
 CONSTRAINT fk_purchase_order_line_header FOREIGN KEY(company_id,purchase_order_id) REFERENCES purchase_orders(company_id,purchase_order_id) ON DELETE CASCADE,
 CONSTRAINT fk_purchase_order_line_requisition FOREIGN KEY(company_id,requisition_line_id) REFERENCES purchase_requisition_lines(company_id,requisition_line_id) ON DELETE RESTRICT,
 CONSTRAINT fk_purchase_order_line_product FOREIGN KEY(product_id) REFERENCES sales_products(product_id) ON DELETE RESTRICT,
 INDEX idx_purchase_order_line_header(company_id,purchase_order_id), INDEX idx_purchase_order_line_product(company_id,product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
"ALTER TABLE inventory_goods_receipts ADD COLUMN purchase_order_id BIGINT UNSIGNED NULL AFTER supplier_reference, ADD CONSTRAINT fk_inventory_receipt_purchase_order FOREIGN KEY(company_id,purchase_order_id) REFERENCES purchase_orders(company_id,purchase_order_id) ON DELETE RESTRICT, ADD INDEX idx_inventory_receipt_purchase_order(company_id,purchase_order_id,status)",
"ALTER TABLE inventory_goods_receipt_lines ADD COLUMN purchase_order_line_id BIGINT UNSIGNED NULL AFTER product_id, ADD CONSTRAINT fk_inventory_receipt_line_purchase FOREIGN KEY(company_id,purchase_order_line_id) REFERENCES purchase_order_lines(company_id,purchase_order_line_id) ON DELETE RESTRICT, ADD INDEX idx_inventory_receipt_line_purchase(company_id,purchase_order_line_id)",
"ALTER TABLE finance_invoices ADD COLUMN purchase_order_id BIGINT UNSIGNED NULL AFTER sales_order_id, ADD COLUMN supplier_invoice_number VARCHAR(120) NULL AFTER invoice_number, ADD CONSTRAINT fk_finance_invoice_purchase_order FOREIGN KEY(company_id,purchase_order_id) REFERENCES purchase_orders(company_id,purchase_order_id) ON DELETE RESTRICT, ADD INDEX idx_finance_invoice_purchase(company_id,purchase_order_id,status)",
"ALTER TABLE finance_invoice_lines ADD COLUMN purchase_order_line_id BIGINT UNSIGNED NULL AFTER sales_order_line_id, ADD CONSTRAINT fk_finance_invoice_line_purchase FOREIGN KEY(company_id,purchase_order_line_id) REFERENCES purchase_order_lines(company_id,purchase_order_line_id) ON DELETE RESTRICT, ADD INDEX idx_finance_invoice_line_purchase(company_id,purchase_order_line_id)",
<<<'SQL'
UPDATE erp_modules SET available=TRUE, active=TRUE, release_status='released', introduced_migration='051' WHERE code='procurement'
SQL,
<<<'SQL'
INSERT INTO erp_module_dependencies(module_id,required_module_id,dependency_type)
SELECT p.module_id,r.module_id,'required' FROM erp_modules p JOIN erp_modules r ON r.code IN('inventory','finance') WHERE p.code='procurement'
ON DUPLICATE KEY UPDATE dependency_type=VALUES(dependency_type)
SQL,
<<<'SQL'
INSERT INTO permissions(name,code,module,description,active) VALUES
('View Procurement','procurement.view','procurement','View procurement workspace',TRUE),
('Manage Suppliers','procurement.suppliers.manage','procurement','Create and maintain suppliers',TRUE),
('Create Requisitions','procurement.requisitions.create','procurement','Create and submit purchase requisitions',TRUE),
('Approve Requisitions','procurement.requisitions.approve','procurement','Approve or reject purchase requisitions',TRUE),
('Create Purchase Orders','procurement.orders.create','procurement','Create and submit purchase orders',TRUE),
('Approve Purchase Orders','procurement.orders.approve','procurement','Approve purchase orders',TRUE),
('Confirm Purchase Orders','procurement.orders.confirm','procurement','Confirm approved purchase orders',TRUE),
('Receive Purchase Orders','procurement.receipts.create','procurement','Create linked goods receipts',TRUE),
('Create Supplier Bills','procurement.bills.create','procurement','Create supplier bills from received quantities',TRUE),
('Post Supplier Bills','procurement.bills.post','procurement','Post supplier bills to accounts payable',TRUE),
('Pay Supplier Bills','procurement.payments.post','procurement','Post controlled supplier payments',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),active=TRUE
SQL,
"INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p WHERE r.name='Company Owner' AND p.module='procurement' AND p.active=TRUE",
    ],
];
