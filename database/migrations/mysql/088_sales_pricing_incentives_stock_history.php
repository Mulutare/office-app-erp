<?php

declare(strict_types=1);

return [
    'version' => '088',
    'description' => 'Controlled sales pricing, immutable corrections, product variants, reservation cutover and DSA incentive operations',
    'statements' => [
        <<<'SQL'
CREATE TABLE sales_product_brands (
 brand_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(120) NOT NULL,
 active BOOLEAN NOT NULL DEFAULT TRUE,
 created_by BIGINT UNSIGNED NOT NULL,
 updated_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT uq_sales_brand_identity UNIQUE(company_id,brand_id),
 CONSTRAINT uq_sales_brand_name UNIQUE(company_id,name),
 CONSTRAINT fk_sales_brand_company FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE RESTRICT,
 CONSTRAINT fk_sales_brand_creator FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 CONSTRAINT fk_sales_brand_updater FOREIGN KEY(updated_by) REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE sales_product_models (
 model_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 brand_id BIGINT UNSIGNED NOT NULL,
 product_family VARCHAR(12) NOT NULL,
 mifi_subtype VARCHAR(20) NULL,
 mifi_subtype_key VARCHAR(20) GENERATED ALWAYS AS (COALESCE(mifi_subtype,'')) STORED,
 model_name VARCHAR(160) NOT NULL,
 active BOOLEAN NOT NULL DEFAULT TRUE,
 created_by BIGINT UNSIGNED NOT NULL,
 updated_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT uq_sales_model_identity UNIQUE(company_id,model_id),
 CONSTRAINT uq_sales_model_brand_family UNIQUE(company_id,brand_id,product_family,mifi_subtype_key,model_name),
 CONSTRAINT ck_sales_model_family CHECK((product_family='mobile' AND mifi_subtype IS NULL) OR (product_family='mifi' AND mifi_subtype IN('portable','non_portable'))),
 CONSTRAINT fk_sales_model_brand FOREIGN KEY(company_id,brand_id) REFERENCES sales_product_brands(company_id,brand_id) ON DELETE RESTRICT,
 CONSTRAINT fk_sales_model_creator FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 CONSTRAINT fk_sales_model_updater FOREIGN KEY(updated_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 INDEX idx_sales_model_selection(company_id,product_family,mifi_subtype,brand_id,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
ALTER TABLE sales_products
 ADD COLUMN model_id BIGINT UNSIGNED NULL AFTER category,
 ADD CONSTRAINT fk_sales_product_model FOREIGN KEY(company_id,model_id) REFERENCES sales_product_models(company_id,model_id) ON DELETE RESTRICT,
 ADD INDEX idx_sales_product_model(company_id,model_id,active)
SQL,
        <<<'SQL'
CREATE TABLE sales_product_price_changes (
 price_change_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 old_price DECIMAL(15,2) NOT NULL,
 proposed_price DECIMAL(15,2) NOT NULL,
 approved_discount_per_unit DECIMAL(15,2) NOT NULL DEFAULT 0,
 currency CHAR(3) NOT NULL,
 effective_from DATETIME NOT NULL,
 reason VARCHAR(1000) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'submitted',
 requested_by BIGINT UNSIGNED NOT NULL,
 requested_at DATETIME NOT NULL,
 approved_by BIGINT UNSIGNED NULL,
 approved_at DATETIME NULL,
 rejected_by BIGINT UNSIGNED NULL,
 rejected_at DATETIME NULL,
 decision_reason VARCHAR(1000) NULL,
 CONSTRAINT uq_sales_price_change_identity UNIQUE(company_id,price_change_id),
 CONSTRAINT ck_sales_price_change_values CHECK(proposed_price>0 AND approved_discount_per_unit>=0 AND approved_discount_per_unit<proposed_price AND old_price>=0),
 CONSTRAINT ck_sales_price_change_status CHECK(status IN('submitted','approved','rejected')),
 CONSTRAINT fk_sales_price_change_product FOREIGN KEY(company_id,product_id) REFERENCES sales_products(company_id,product_id) ON DELETE RESTRICT,
 CONSTRAINT fk_sales_price_change_requester FOREIGN KEY(company_id,requested_by) REFERENCES company_users(company_id,user_id) ON DELETE RESTRICT,
 CONSTRAINT fk_sales_price_change_approver FOREIGN KEY(company_id,approved_by) REFERENCES company_users(company_id,user_id) ON DELETE RESTRICT,
 CONSTRAINT fk_sales_price_change_rejector FOREIGN KEY(company_id,rejected_by) REFERENCES company_users(company_id,user_id) ON DELETE RESTRICT,
 INDEX idx_sales_price_effective(company_id,product_id,status,effective_from,price_change_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
ALTER TABLE sales_orders ADD CONSTRAINT uq_sales_order_company_identity UNIQUE(company_id,order_id)
SQL,
        <<<'SQL'
CREATE TABLE sales_order_revisions (
 revision_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 order_id BIGINT UNSIGNED NOT NULL,
 revision_number INT UNSIGNED NOT NULL,
 source_event VARCHAR(40) NOT NULL,
 previous_status VARCHAR(30) NOT NULL,
 reason VARCHAR(1000) NULL,
 header_snapshot_json JSON NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 CONSTRAINT uq_sales_order_revision_identity UNIQUE(company_id,revision_id),
 CONSTRAINT uq_sales_order_revision_number UNIQUE(company_id,order_id,revision_number),
 CONSTRAINT fk_sales_order_revision_order FOREIGN KEY(company_id,order_id) REFERENCES sales_orders(company_id,order_id) ON DELETE RESTRICT,
 CONSTRAINT fk_sales_order_revision_actor FOREIGN KEY(company_id,actor_id) REFERENCES company_users(company_id,user_id) ON DELETE RESTRICT,
 INDEX idx_sales_order_revision_timeline(company_id,order_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE sales_order_revision_lines (
 revision_line_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 revision_id BIGINT UNSIGNED NOT NULL,
 original_line_id BIGINT UNSIGNED NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 sku VARCHAR(60) NOT NULL,
 description VARCHAR(255) NOT NULL,
 quantity DECIMAL(15,3) NOT NULL,
 unit_price DECIMAL(15,2) NOT NULL,
 discount_amount DECIMAL(15,2) NOT NULL,
 tax_rate DECIMAL(7,4) NOT NULL,
 line_total DECIMAL(15,2) NOT NULL,
 CONSTRAINT fk_sales_order_revision_line_header FOREIGN KEY(company_id,revision_id) REFERENCES sales_order_revisions(company_id,revision_id) ON DELETE RESTRICT,
 CONSTRAINT fk_sales_order_revision_line_product FOREIGN KEY(company_id,product_id) REFERENCES sales_products(company_id,product_id) ON DELETE RESTRICT,
 INDEX idx_sales_order_revision_lines(company_id,revision_id,revision_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_stock_request_events (
 request_event_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 request_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(40) NOT NULL,
 from_status VARCHAR(30) NULL,
 to_status VARCHAR(30) NOT NULL,
 previous_values_json JSON NULL,
 new_values_json JSON NULL,
 reason VARCHAR(1000) NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 occurred_at DATETIME NOT NULL,
 CONSTRAINT fk_stock_request_event_header FOREIGN KEY(company_id,request_id) REFERENCES inventory_stock_requests(company_id,request_id) ON DELETE RESTRICT,
 CONSTRAINT fk_stock_request_event_actor FOREIGN KEY(company_id,actor_id) REFERENCES company_users(company_id,user_id) ON DELETE RESTRICT,
 INDEX idx_stock_request_event_timeline(company_id,request_id,occurred_at,request_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
ALTER TABLE inventory_stock_requests
 ADD COLUMN rejected_by BIGINT UNSIGNED NULL AFTER cancellation_reason,
 ADD COLUMN rejected_at DATETIME NULL AFTER rejected_by,
 ADD COLUMN rejection_reason VARCHAR(1000) NULL AFTER rejected_at,
 DROP CONSTRAINT ck_inventory_stock_request_status,
 ADD CONSTRAINT ck_inventory_stock_request_status CHECK(status IN('pending_review','awaiting_transfer','awaiting_procurement','ready_to_issue','issued','closed','cancelled','rejected')),
 ADD CONSTRAINT fk_stock_request_rejector FOREIGN KEY(company_id,rejected_by) REFERENCES company_users(company_id,user_id) ON DELETE RESTRICT
SQL,
        <<<'SQL'
CREATE TABLE inventory_reservation_cutovers (
 company_id BIGINT UNSIGNED PRIMARY KEY,
 cutover_at DATETIME(6) NOT NULL,
 cutover_event_id BIGINT UNSIGNED NOT NULL,
 label VARCHAR(100) NOT NULL DEFAULT '088 cutover reservation baseline',
 CONSTRAINT fk_reservation_cutover_company FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_reservation_events (
 reservation_event_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 stock_balance_id BIGINT UNSIGNED NOT NULL,
 warehouse_id BIGINT UNSIGNED NOT NULL,
 location_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 quantity_delta DECIMAL(18,3) NOT NULL,
 quantity_after DECIMAL(18,3) NOT NULL,
 event_type VARCHAR(40) NOT NULL,
 reference_type VARCHAR(60) NOT NULL,
 reference_id BIGINT UNSIGNED NULL,
 actor_id BIGINT UNSIGNED NULL,
 occurred_at DATETIME(6) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT ck_reservation_event_type CHECK(event_type IN('088_cutover_baseline','balance_change')),
 CONSTRAINT fk_reservation_event_balance FOREIGN KEY(company_id,stock_balance_id) REFERENCES inventory_stock_balances(company_id,stock_balance_id) ON DELETE RESTRICT,
 CONSTRAINT fk_reservation_event_actor FOREIGN KEY(company_id,actor_id) REFERENCES company_users(company_id,user_id) ON DELETE RESTRICT,
 INDEX idx_reservation_event_history(company_id,warehouse_id,location_id,product_id,occurred_at,reservation_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TRIGGER trg_088_reservation_balance_update AFTER UPDATE ON inventory_stock_balances FOR EACH ROW
INSERT INTO inventory_reservation_events(company_id,stock_balance_id,warehouse_id,location_id,product_id,quantity_delta,quantity_after,event_type,reference_type,reference_id,occurred_at)
SELECT NEW.company_id,NEW.stock_balance_id,NEW.warehouse_id,NEW.location_id,NEW.product_id,NEW.quantity_reserved-OLD.quantity_reserved,NEW.quantity_reserved,'balance_change','inventory_stock_balances',NEW.stock_balance_id,NOW(6)
WHERE NEW.quantity_reserved<>OLD.quantity_reserved
SQL,
        <<<'SQL'
CREATE TRIGGER trg_088_reservation_balance_insert AFTER INSERT ON inventory_stock_balances FOR EACH ROW
INSERT INTO inventory_reservation_events(company_id,stock_balance_id,warehouse_id,location_id,product_id,quantity_delta,quantity_after,event_type,reference_type,reference_id,occurred_at)
VALUES(NEW.company_id,NEW.stock_balance_id,NEW.warehouse_id,NEW.location_id,NEW.product_id,NEW.quantity_reserved,NEW.quantity_reserved,'balance_change','inventory_stock_balances',NEW.stock_balance_id,NOW(6))
SQL,
        <<<'SQL'
LOCK TABLES inventory_stock_balances WRITE, inventory_reservation_events WRITE, inventory_reservation_cutovers WRITE, companies READ, schema_migration_steps WRITE
SQL,
        <<<'SQL'
INSERT INTO inventory_reservation_events(company_id,stock_balance_id,warehouse_id,location_id,product_id,quantity_delta,quantity_after,event_type,reference_type,reference_id,occurred_at)
SELECT company_id,stock_balance_id,warehouse_id,location_id,product_id,quantity_reserved,quantity_reserved,'088_cutover_baseline','migration_088_cutover',stock_balance_id,NOW(6)
FROM inventory_stock_balances
SQL,
        <<<'SQL'
INSERT INTO inventory_reservation_cutovers(company_id,cutover_at,cutover_event_id)
SELECT company_id,NOW(6),COALESCE((SELECT MAX(reservation_event_id) FROM inventory_reservation_events),0)
FROM companies WHERE deleted_at IS NULL
SQL,
        <<<'SQL'
UNLOCK TABLES
SQL,
        <<<'SQL'
CREATE TABLE sales_dsa_float_issuances (
 float_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 dsa_dsp_user_id BIGINT UNSIGNED NOT NULL,
 manager_user_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(18,2) NOT NULL,
 currency CHAR(3) NOT NULL,
 issued_date DATE NOT NULL,
 reference VARCHAR(120) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'issued',
 correction_of_id BIGINT UNSIGNED NULL,
 reason VARCHAR(1000) NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 CONSTRAINT uq_dsa_float_identity UNIQUE(company_id,float_id),
 CONSTRAINT uq_dsa_float_reference UNIQUE(company_id,reference),
 CONSTRAINT ck_dsa_float_amount CHECK(amount>0),
 CONSTRAINT ck_dsa_float_status CHECK(status IN('issued','reversed')),
 CONSTRAINT fk_dsa_float_user FOREIGN KEY(company_id,dsa_dsp_user_id) REFERENCES company_users(company_id,user_id) ON DELETE RESTRICT,
 CONSTRAINT fk_dsa_float_manager FOREIGN KEY(company_id,manager_user_id) REFERENCES company_users(company_id,user_id) ON DELETE RESTRICT,
 CONSTRAINT fk_dsa_float_creator FOREIGN KEY(company_id,created_by) REFERENCES company_users(company_id,user_id) ON DELETE RESTRICT,
 CONSTRAINT fk_dsa_float_correction FOREIGN KEY(company_id,correction_of_id) REFERENCES sales_dsa_float_issuances(company_id,float_id) ON DELETE RESTRICT,
 INDEX idx_dsa_float_register(company_id,dsa_dsp_user_id,issued_date,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE sales_incentive_claims (
 incentive_claim_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 originating_report_id BIGINT UNSIGNED NOT NULL,
 float_id BIGINT UNSIGNED NOT NULL,
 dsa_dsp_user_id BIGINT UNSIGNED NOT NULL,
 responsible_manager_id BIGINT UNSIGNED NOT NULL,
 currency CHAR(3) NOT NULL,
 proposed_amount DECIMAL(18,2) NOT NULL,
 approved_amount DECIMAL(18,2) NULL,
 external_body VARCHAR(80) NOT NULL DEFAULT 'Safaricom',
 external_reference VARCHAR(190) NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'submitted',
 submitted_by BIGINT UNSIGNED NOT NULL,
 submitted_at DATETIME NOT NULL,
 approved_by BIGINT UNSIGNED NULL,
 approved_at DATETIME NULL,
 rejected_by BIGINT UNSIGNED NULL,
 rejected_at DATETIME NULL,
 rejection_reason VARCHAR(1000) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT uq_incentive_claim_identity UNIQUE(company_id,incentive_claim_id),
 CONSTRAINT uq_incentive_claim_report UNIQUE(company_id,originating_report_id),
 CONSTRAINT uq_incentive_claim_float UNIQUE(company_id,float_id),
 CONSTRAINT ck_incentive_claim_amount CHECK(proposed_amount>0 AND (approved_amount IS NULL OR (approved_amount>0 AND approved_amount<=proposed_amount))),
 CONSTRAINT ck_incentive_claim_body CHECK(external_body='Safaricom'),
 CONSTRAINT ck_incentive_claim_status CHECK(status IN('submitted','approved','rejected','partially_settled','settled')),
 CONSTRAINT fk_incentive_claim_report FOREIGN KEY(company_id,originating_report_id) REFERENCES sales_quick_sale_reports(company_id,report_id) ON DELETE RESTRICT,
 CONSTRAINT fk_incentive_claim_float FOREIGN KEY(company_id,float_id) REFERENCES sales_dsa_float_issuances(company_id,float_id) ON DELETE RESTRICT,
 CONSTRAINT fk_incentive_claim_dsa FOREIGN KEY(company_id,dsa_dsp_user_id) REFERENCES company_users(company_id,user_id) ON DELETE RESTRICT,
 CONSTRAINT fk_incentive_claim_manager FOREIGN KEY(company_id,responsible_manager_id) REFERENCES company_users(company_id,user_id) ON DELETE RESTRICT,
 CONSTRAINT fk_incentive_claim_submitter FOREIGN KEY(company_id,submitted_by) REFERENCES company_users(company_id,user_id) ON DELETE RESTRICT,
 CONSTRAINT fk_incentive_claim_approver FOREIGN KEY(company_id,approved_by) REFERENCES company_users(company_id,user_id) ON DELETE RESTRICT,
 CONSTRAINT fk_incentive_claim_rejector FOREIGN KEY(company_id,rejected_by) REFERENCES company_users(company_id,user_id) ON DELETE RESTRICT,
 INDEX idx_incentive_claim_register(company_id,status,submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE sales_incentive_events (
 incentive_event_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 incentive_claim_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(40) NOT NULL,
 from_status VARCHAR(24) NULL,
 to_status VARCHAR(24) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 reason_reference VARCHAR(1000) NULL,
 occurred_at DATETIME NOT NULL,
 CONSTRAINT fk_incentive_event_claim FOREIGN KEY(company_id,incentive_claim_id) REFERENCES sales_incentive_claims(company_id,incentive_claim_id) ON DELETE RESTRICT,
 CONSTRAINT fk_incentive_event_actor FOREIGN KEY(company_id,actor_id) REFERENCES company_users(company_id,user_id) ON DELETE RESTRICT,
 INDEX idx_incentive_event_timeline(company_id,incentive_claim_id,occurred_at,incentive_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE sales_incentive_settlements (
 incentive_settlement_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 incentive_claim_id BIGINT UNSIGNED NOT NULL,
 settlement_date DATE NOT NULL,
 amount DECIMAL(18,2) NOT NULL,
 currency CHAR(3) NOT NULL,
 external_body VARCHAR(80) NOT NULL DEFAULT 'Safaricom',
 external_payment_reference VARCHAR(190) NOT NULL,
 evidence_reference VARCHAR(500) NULL,
 idempotency_key VARCHAR(120) NOT NULL,
 entered_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 reversed_by BIGINT UNSIGNED NULL,
 reversed_at DATETIME NULL,
 reversal_reason VARCHAR(1000) NULL,
 CONSTRAINT uq_incentive_settlement_identity UNIQUE(company_id,incentive_settlement_id),
 CONSTRAINT uq_incentive_settlement_idempotency UNIQUE(company_id,idempotency_key),
 CONSTRAINT uq_incentive_settlement_reference UNIQUE(company_id,external_body,external_payment_reference),
 CONSTRAINT ck_incentive_settlement_amount CHECK(amount>0),
 CONSTRAINT ck_incentive_settlement_body CHECK(external_body='Safaricom'),
 CONSTRAINT fk_incentive_settlement_claim FOREIGN KEY(company_id,incentive_claim_id) REFERENCES sales_incentive_claims(company_id,incentive_claim_id) ON DELETE RESTRICT,
 CONSTRAINT fk_incentive_settlement_enterer FOREIGN KEY(company_id,entered_by) REFERENCES company_users(company_id,user_id) ON DELETE RESTRICT,
 CONSTRAINT fk_incentive_settlement_reverser FOREIGN KEY(company_id,reversed_by) REFERENCES company_users(company_id,user_id) ON DELETE RESTRICT,
 INDEX idx_incentive_settlement_claim(company_id,incentive_claim_id,settlement_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO permissions(name,code,module,description,active) VALUES
('View Sales Pricing','sales.pricing.view','sales','View approved and pending SKU prices',TRUE),
('Manage Sales Pricing','sales.pricing.manage','sales','Submit SKU price and exact discount changes',TRUE),
('Approve Sales Pricing','sales.pricing.approve','sales','Independently approve SKU price and discount changes',TRUE),
('View Sales Incentives','sales.incentive.view','sales','View scoped DSA Safaricom incentive register',TRUE),
('Submit Sales Incentives','sales.incentive.submit','sales','Submit DSA Safaricom incentive claims',TRUE),
('Approve Sales Incentives','sales.incentive.approve','sales','Review scoped DSA Safaricom incentive claims',TRUE),
('Settle Sales Incentives','sales.incentive.settle','sales','Record Safaricom incentive settlements',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),active=TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p
WHERE r.active=TRUE AND p.active=TRUE AND (
 (r.code IN('company_owner','system_administrator') AND p.code IN('sales.pricing.view','sales.pricing.manage','sales.pricing.approve','sales.incentive.view','sales.incentive.submit','sales.incentive.approve','sales.incentive.settle'))
 OR (r.code='sales_manager' AND p.code IN('sales.pricing.view','sales.pricing.manage','sales.incentive.view','sales.incentive.approve'))
 OR (r.code='sales_approver' AND p.code IN('sales.pricing.view','sales.pricing.approve'))
 OR (r.code='sales_officer' AND p.code IN('sales.pricing.view','sales.incentive.view','sales.incentive.submit')))
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT DISTINCT cur.company_id,cur.role_id,rp.permission_id,NULL
FROM company_user_roles cur
JOIN companies c ON c.company_id=cur.company_id AND c.deleted_at IS NULL
JOIN roles r ON r.role_id=cur.role_id AND r.active=TRUE
JOIN role_permissions rp ON rp.role_id=r.role_id
JOIN permissions p ON p.permission_id=rp.permission_id AND p.active=TRUE
WHERE r.code IN('company_owner','system_administrator','sales_manager','sales_approver','sales_officer')
 AND p.code IN('sales.pricing.view','sales.pricing.manage','sales.pricing.approve','sales.incentive.view','sales.incentive.submit','sales.incentive.approve','sales.incentive.settle')
 AND EXISTS(SELECT 1 FROM company_users cu JOIN users u ON u.user_id=cu.user_id
            WHERE cu.company_id=cur.company_id AND cu.user_id=cur.user_id
              AND cu.active=TRUE AND u.active=TRUE AND u.deleted_at IS NULL)
SQL,
    ],
];
