<?php

declare(strict_types=1);

return [
    'version' => '053',
    'description' => 'Complete procurement roles, vendor returns and workflow controls',
    'statements' => [
        <<<'SQL'
CREATE TABLE procurement_vendor_returns (
    vendor_return_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    purchase_order_id BIGINT UNSIGNED NOT NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    return_number VARCHAR(60) NOT NULL,
    return_date DATE NOT NULL,
    reason VARCHAR(500) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'posted',
    idempotency_key VARCHAR(100) NOT NULL,
    posted_by BIGINT UNSIGNED NULL,
    posted_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_procurement_vendor_return_identity UNIQUE (company_id, vendor_return_id),
    CONSTRAINT uq_procurement_vendor_return_number UNIQUE (company_id, return_number),
    CONSTRAINT uq_procurement_vendor_return_key UNIQUE (company_id, idempotency_key),
    CONSTRAINT ck_procurement_vendor_return_status CHECK (status IN ('posted')),
    CONSTRAINT fk_procurement_vendor_return_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_procurement_vendor_return_order FOREIGN KEY (company_id, purchase_order_id) REFERENCES purchase_orders(company_id, purchase_order_id) ON DELETE RESTRICT,
    CONSTRAINT fk_procurement_vendor_return_supplier FOREIGN KEY (company_id, supplier_id) REFERENCES purchase_suppliers(company_id, supplier_id) ON DELETE RESTRICT,
    CONSTRAINT fk_procurement_vendor_return_warehouse FOREIGN KEY (company_id, warehouse_id) REFERENCES inventory_warehouses(company_id, warehouse_id) ON DELETE RESTRICT,
    CONSTRAINT fk_procurement_vendor_return_actor FOREIGN KEY (posted_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_procurement_vendor_return_order (company_id, purchase_order_id, posted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE procurement_vendor_return_lines (
    vendor_return_line_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    vendor_return_id BIGINT UNSIGNED NOT NULL,
    purchase_order_line_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(18,3) NOT NULL,
    unit_cost DECIMAL(18,4) NOT NULL,
    stock_movement_id BIGINT UNSIGNED NOT NULL,
    CONSTRAINT uq_procurement_vendor_return_line_identity UNIQUE (company_id, vendor_return_line_id),
    CONSTRAINT uq_procurement_vendor_return_po_line UNIQUE (company_id, vendor_return_id, purchase_order_line_id),
    CONSTRAINT ck_procurement_vendor_return_line_quantity CHECK (quantity > 0 AND unit_cost >= 0),
    CONSTRAINT fk_procurement_vendor_return_line_return FOREIGN KEY (company_id, vendor_return_id) REFERENCES procurement_vendor_returns(company_id, vendor_return_id) ON DELETE RESTRICT,
    CONSTRAINT fk_procurement_vendor_return_line_order FOREIGN KEY (company_id, purchase_order_line_id) REFERENCES purchase_order_lines(company_id, purchase_order_line_id) ON DELETE RESTRICT,
    CONSTRAINT fk_procurement_vendor_return_line_product FOREIGN KEY (product_id) REFERENCES sales_products(product_id) ON DELETE RESTRICT,
    CONSTRAINT fk_procurement_vendor_return_line_movement FOREIGN KEY (company_id, stock_movement_id) REFERENCES inventory_stock_movements(company_id, movement_id) ON DELETE RESTRICT,
    INDEX idx_procurement_vendor_return_line_order (company_id, purchase_order_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO permissions(name, code, module, description, active) VALUES
('Post Vendor Returns', 'procurement.returns.post', 'procurement', 'Return received goods to suppliers through Inventory', TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), active=TRUE
SQL,
        <<<'SQL'
INSERT INTO roles(name, code, description, is_system, active) VALUES
('Procurement Requester', 'procurement_requester', 'Creates and submits purchase requisitions', TRUE, TRUE),
('Procurement Approver', 'procurement_approver', 'Approves requisitions and purchase orders', TRUE, TRUE),
('Purchasing Officer', 'purchasing_officer', 'Manages suppliers and purchasing documents', TRUE, TRUE),
('Warehouse / Inventory User', 'warehouse_inventory_user', 'Receives goods and performs authorized inventory operations', TRUE, TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), is_system=TRUE, active=TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON
    (r.code='procurement_requester' AND p.code IN ('procurement.view','procurement.requisitions.create')) OR
    (r.code='procurement_approver' AND p.code IN ('procurement.view','procurement.requisitions.approve','procurement.orders.approve')) OR
    (r.code='purchasing_officer' AND p.code IN ('procurement.view','procurement.suppliers.manage','procurement.orders.create','procurement.orders.confirm')) OR
    (r.code='warehouse_inventory_user' AND p.code IN ('procurement.view','procurement.receipts.create','procurement.returns.post','inventory.view','inventory.receipts.view','inventory.receipts.create','inventory.receipts.approve','inventory.receipts.post')) OR
    (r.code IN ('company_owner','system_administrator') AND p.code='procurement.returns.post') OR
    (r.code='finance_officer' AND p.code IN ('procurement.view','procurement.bills.create','procurement.bills.post','procurement.payments.post')) OR
    (r.code='finance_approver' AND p.code IN ('procurement.view','procurement.bills.reverse'))
SQL,
    ],
];
