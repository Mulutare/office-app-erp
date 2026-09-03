<?php

declare(strict_types=1);

return [
    'version' => '074',
    'description' => 'Stock-aware employee requests, manager stock authorities, procurement shortage links and regional reorder notifications',
    'preflight' => static function (\PDO $connection): string {
        $tables = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name IN(
                'inventory_stock_authorities',
                'inventory_stock_request_sequences',
                'inventory_stock_requests',
                'inventory_stock_request_lines',
                'inventory_stock_request_allocations',
                'inventory_stock_request_procurements',
                'inventory_reorder_thresholds'
             )"
        )->fetchColumn();
        $permissions = (int) $connection->query(
            "SELECT COUNT(*) FROM permissions WHERE code IN(
                'inventory.stock_requests.view',
                'inventory.stock_requests.create',
                'inventory.stock_requests.process',
                'inventory.stock_requests.issue',
                'inventory.stock_requests.receive',
                'inventory.stock_authorities.manage',
                'inventory.reorder_thresholds.manage'
             )"
        )->fetchColumn();
        if ($tables === 0 && $permissions === 0) return 'apply';
        if ($tables === 7 && $permissions === 7) return 'baseline';
        throw new \RuntimeException('Migration 074 found a partial stock-request routing schema.');
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE inventory_stock_authorities (
    authority_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    authority_level VARCHAR(20) NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    regional_scope TINYINT UNSIGNED
      GENERATED ALWAYS AS (
        CASE WHEN authority_level='regional' AND active=TRUE THEN 1 ELSE NULL END
      ) STORED,
    active_stock_scope TINYINT UNSIGNED
      GENERATED ALWAYS AS (
        CASE WHEN active=TRUE THEN 1 ELSE NULL END
      ) STORED,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_inventory_stock_authority_identity UNIQUE(company_id,authority_id),
    CONSTRAINT uq_inventory_stock_authority_user UNIQUE(company_id,user_id),
    CONSTRAINT uq_inventory_stock_authority_regional UNIQUE(company_id,regional_scope),
    CONSTRAINT uq_inventory_stock_authority_active_stock UNIQUE(company_id,warehouse_id,location_id,active_stock_scope),
    CONSTRAINT ck_inventory_stock_authority_level CHECK(authority_level IN('shop','district','regional')),
    CONSTRAINT fk_inventory_stock_authority_company_user FOREIGN KEY(company_id,user_id)
      REFERENCES company_users(company_id,user_id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_stock_authority_location FOREIGN KEY(company_id,warehouse_id,location_id)
      REFERENCES inventory_warehouse_locations(company_id,warehouse_id,location_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_stock_authority_creator FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_inventory_stock_authority_updater FOREIGN KEY(updated_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_inventory_stock_authority_level(company_id,authority_level,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_stock_request_sequences (
    company_id BIGINT UNSIGNED NOT NULL,
    request_year SMALLINT UNSIGNED NOT NULL,
    last_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY(company_id,request_year),
    CONSTRAINT fk_inventory_stock_request_sequence_company FOREIGN KEY(company_id)
      REFERENCES companies(company_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_stock_requests (
    request_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    request_number VARCHAR(40) NOT NULL,
    requester_user_id BIGINT UNSIGNED NOT NULL,
    requester_employee_id BIGINT UNSIGNED NOT NULL,
    requester_role_snapshot VARCHAR(120) NOT NULL,
    serving_authority_id BIGINT UNSIGNED NOT NULL,
    current_handler_user_id BIGINT UNSIGNED NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending_review',
    notes VARCHAR(1000) NULL,
    requested_at DATETIME NOT NULL,
    issued_by BIGINT UNSIGNED NULL,
    issued_at DATETIME NULL,
    received_by BIGINT UNSIGNED NULL,
    received_at DATETIME NULL,
    cancelled_by BIGINT UNSIGNED NULL,
    cancelled_at DATETIME NULL,
    cancellation_reason VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_inventory_stock_request_identity UNIQUE(company_id,request_id),
    CONSTRAINT uq_inventory_stock_request_number UNIQUE(company_id,request_number),
    CONSTRAINT ck_inventory_stock_request_status CHECK(status IN(
        'pending_review','awaiting_transfer','awaiting_procurement','ready_to_issue','issued','closed','cancelled'
    )),
    CONSTRAINT fk_inventory_stock_request_requester FOREIGN KEY(company_id,requester_user_id)
      REFERENCES company_users(company_id,user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_stock_request_employee FOREIGN KEY(company_id,requester_employee_id)
      REFERENCES hr_employees(company_id,employee_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_stock_request_serving_authority FOREIGN KEY(company_id,serving_authority_id)
      REFERENCES inventory_stock_authorities(company_id,authority_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_stock_request_handler FOREIGN KEY(current_handler_user_id)
      REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_inventory_stock_request_issuer FOREIGN KEY(issued_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_inventory_stock_request_receiver FOREIGN KEY(received_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_inventory_stock_request_canceller FOREIGN KEY(cancelled_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_inventory_stock_request_handler(company_id,current_handler_user_id,status,requested_at),
    INDEX idx_inventory_stock_request_requester(company_id,requester_user_id,status,requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_stock_request_lines (
    request_line_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    request_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    requested_quantity DECIMAL(18,3) NOT NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_inventory_stock_request_line_identity UNIQUE(company_id,request_id,request_line_id),
    CONSTRAINT uq_inventory_stock_request_product UNIQUE(company_id,request_id,product_id),
    CONSTRAINT ck_inventory_stock_request_line_quantity CHECK(requested_quantity>0),
    CONSTRAINT fk_inventory_stock_request_line_header FOREIGN KEY(company_id,request_id)
      REFERENCES inventory_stock_requests(company_id,request_id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_stock_request_line_product FOREIGN KEY(company_id,product_id)
      REFERENCES sales_products(company_id,product_id) ON DELETE RESTRICT,
    INDEX idx_inventory_stock_request_line_product(company_id,product_id,request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_stock_request_allocations (
    allocation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    request_id BIGINT UNSIGNED NOT NULL,
    request_line_id BIGINT UNSIGNED NOT NULL,
    authority_id BIGINT UNSIGNED NOT NULL,
    source_warehouse_id BIGINT UNSIGNED NOT NULL,
    source_location_id BIGINT UNSIGNED NOT NULL,
    destination_warehouse_id BIGINT UNSIGNED NOT NULL,
    destination_location_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(18,3) NOT NULL,
    status VARCHAR(24) NOT NULL,
    transfer_id BIGINT UNSIGNED NULL,
    transfer_line_id BIGINT UNSIGNED NULL,
    reserved_at DATETIME NOT NULL,
    dispatched_at DATETIME NULL,
    received_at DATETIME NULL,
    issued_at DATETIME NULL,
    released_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_inventory_stock_request_allocation_identity UNIQUE(company_id,allocation_id),
    CONSTRAINT uq_inventory_stock_request_transfer_line UNIQUE(company_id,transfer_line_id),
    CONSTRAINT ck_inventory_stock_request_allocation_quantity CHECK(quantity>0),
    CONSTRAINT ck_inventory_stock_request_allocation_status CHECK(status IN(
        'source_reserved','in_transit','shop_reserved','issued','released'
    )),
    CONSTRAINT fk_inventory_stock_request_allocation_header FOREIGN KEY(company_id,request_id)
      REFERENCES inventory_stock_requests(company_id,request_id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_stock_request_allocation_line FOREIGN KEY(company_id,request_id,request_line_id)
      REFERENCES inventory_stock_request_lines(company_id,request_id,request_line_id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_stock_request_allocation_authority FOREIGN KEY(company_id,authority_id)
      REFERENCES inventory_stock_authorities(company_id,authority_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_stock_request_allocation_source FOREIGN KEY(company_id,source_warehouse_id,source_location_id)
      REFERENCES inventory_warehouse_locations(company_id,warehouse_id,location_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_stock_request_allocation_destination FOREIGN KEY(company_id,destination_warehouse_id,destination_location_id)
      REFERENCES inventory_warehouse_locations(company_id,warehouse_id,location_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_stock_request_allocation_transfer FOREIGN KEY(company_id,transfer_line_id)
      REFERENCES inventory_transfer_lines(company_id,transfer_line_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_stock_request_allocation_creator FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_inventory_stock_request_allocation_request(company_id,request_id,status),
    INDEX idx_inventory_stock_request_allocation_transfer(company_id,transfer_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_stock_request_procurements (
    link_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    request_id BIGINT UNSIGNED NOT NULL,
    requisition_id BIGINT UNSIGNED NOT NULL,
    receiving_warehouse_id BIGINT UNSIGNED NOT NULL,
    receiving_location_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_inventory_stock_request_procurement_identity UNIQUE(company_id,link_id),
    CONSTRAINT uq_inventory_stock_request_procurement_requisition UNIQUE(company_id,requisition_id),
    CONSTRAINT fk_inventory_stock_request_procurement_request FOREIGN KEY(company_id,request_id)
      REFERENCES inventory_stock_requests(company_id,request_id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_stock_request_procurement_requisition FOREIGN KEY(company_id,requisition_id)
      REFERENCES purchase_requisitions(company_id,requisition_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_stock_request_procurement_destination FOREIGN KEY(company_id,receiving_warehouse_id,receiving_location_id)
      REFERENCES inventory_warehouse_locations(company_id,warehouse_id,location_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_stock_request_procurement_creator FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_inventory_stock_request_procurement_request(company_id,request_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_reorder_thresholds (
    company_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    notification_quantity DECIMAL(18,3) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY(company_id,warehouse_id,location_id,product_id),
    CONSTRAINT ck_inventory_reorder_threshold_quantity CHECK(notification_quantity>=0),
    CONSTRAINT fk_inventory_reorder_threshold_location FOREIGN KEY(company_id,warehouse_id,location_id)
      REFERENCES inventory_warehouse_locations(company_id,warehouse_id,location_id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_reorder_threshold_product FOREIGN KEY(company_id,product_id)
      REFERENCES sales_products(company_id,product_id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_reorder_threshold_updater FOREIGN KEY(updated_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_inventory_reorder_threshold_active(company_id,warehouse_id,location_id,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO permissions(name,code,module,description,active) VALUES
 ('View Stock Requests','inventory.stock_requests.view','inventory','View stock requests within the signed-in employee reporting scope',TRUE),
 ('Create Stock Requests','inventory.stock_requests.create','inventory','Create stock requests when the signed-in HR job title is DSA or DSP',TRUE),
 ('Process Stock Requests','inventory.stock_requests.process','inventory','Allocate represented stock and escalate the remaining quantity through the reporting hierarchy',TRUE),
 ('Issue Stock Requests','inventory.stock_requests.issue','inventory','Issue fully assembled shop stock to the requesting employee',TRUE),
 ('Confirm Stock Request Receipt','inventory.stock_requests.receive','inventory','Confirm personal receipt of an issued stock request',TRUE),
 ('Manage Stock Authorities','inventory.stock_authorities.manage','inventory','Map Shop, District and Regional managers to the stock location they represent',TRUE),
 ('Manage Reorder Notifications','inventory.reorder_thresholds.manage','inventory','Set Regional low-stock notification quantities without automatically creating procurement documents',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module),description=VALUES(description),active=TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p
WHERE
 (r.code IN('system_administrator','company_owner') AND p.code IN(
   'inventory.stock_requests.view','inventory.stock_requests.create','inventory.stock_requests.process',
   'inventory.stock_requests.issue','inventory.stock_requests.receive','inventory.stock_authorities.manage',
   'inventory.reorder_thresholds.manage'))
 OR (r.code IN('employee_self_service','sales_user','sales_manager') AND p.code IN(
   'inventory.stock_requests.view','inventory.stock_requests.create','inventory.stock_requests.process',
   'inventory.stock_requests.issue','inventory.stock_requests.receive','inventory.reorder_thresholds.manage'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT c.company_id,rp.role_id,rp.permission_id,c.provisioned_by
FROM companies c
CROSS JOIN role_permissions rp
INNER JOIN permissions p ON p.permission_id=rp.permission_id
WHERE c.deleted_at IS NULL AND p.code IN(
 'inventory.stock_requests.view','inventory.stock_requests.create','inventory.stock_requests.process',
 'inventory.stock_requests.issue','inventory.stock_requests.receive','inventory.stock_authorities.manage',
 'inventory.reorder_thresholds.manage')
SQL,
    ],
];
