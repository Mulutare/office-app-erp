<?php

declare(strict_types=1);

return [
    'version' => '078',
    'description' => 'Durable Central replenishment for existing Stock Requests and Quick Sales',
    'preflight' => static function (PDO $connection): string {
        $count = (int) $connection->query("SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema=DATABASE() AND table_name IN
            ('inventory_central_demands','inventory_central_transfer_links','inventory_central_procurement_links')")->fetchColumn();
        if ($count === 0) return 'apply';
        if ($count === 3) return 'baseline';
        throw new RuntimeException('Migration 078 found a partial Central replenishment schema. Restore or complete the migration before retrying.');
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE inventory_central_demands (
    demand_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    request_id BIGINT UNSIGNED NULL,
    quick_sale_id BIGINT UNSIGNED NULL,
    regional_authority_id BIGINT UNSIGNED NOT NULL,
    state VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_central_demand_identity(company_id,demand_id),
    UNIQUE KEY uq_central_demand_request(company_id,request_id),
    UNIQUE KEY uq_central_demand_sale(company_id,quick_sale_id),
    CONSTRAINT ck_central_demand_origin CHECK((request_id IS NULL) <> (quick_sale_id IS NULL)),
    CONSTRAINT fk_central_demand_request FOREIGN KEY(company_id,request_id) REFERENCES inventory_stock_requests(company_id,request_id),
    CONSTRAINT fk_central_demand_sale FOREIGN KEY(company_id,quick_sale_id) REFERENCES sales_quick_sales(company_id,quick_sale_id),
    CONSTRAINT fk_central_demand_authority FOREIGN KEY(company_id,regional_authority_id) REFERENCES inventory_stock_authorities(company_id,authority_id),
    CONSTRAINT fk_central_demand_creator FOREIGN KEY(company_id,created_by) REFERENCES company_users(company_id,user_id),
    INDEX idx_central_demand_state(company_id,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_central_transfer_links (
    company_id BIGINT UNSIGNED NOT NULL,
    demand_id BIGINT UNSIGNED NOT NULL,
    transfer_line_id BIGINT UNSIGNED NOT NULL,
    state VARCHAR(20) NOT NULL DEFAULT 'reserved',
    PRIMARY KEY(company_id,transfer_line_id),
    CONSTRAINT ck_central_transfer_state CHECK(state IN('reserved','in_transit','received','released')),
    CONSTRAINT fk_central_transfer_demand FOREIGN KEY(company_id,demand_id) REFERENCES inventory_central_demands(company_id,demand_id),
    CONSTRAINT fk_central_transfer_line FOREIGN KEY(company_id,transfer_line_id) REFERENCES inventory_transfer_lines(company_id,transfer_line_id),
    INDEX idx_central_transfer_demand(company_id,demand_id,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE inventory_central_procurement_links (
    company_id BIGINT UNSIGNED NOT NULL,
    demand_id BIGINT UNSIGNED NOT NULL,
    requisition_id BIGINT UNSIGNED NOT NULL,
    receiving_warehouse_id BIGINT UNSIGNED NOT NULL,
    receiving_location_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY(company_id,requisition_id),
    CONSTRAINT fk_central_procurement_demand FOREIGN KEY(company_id,demand_id) REFERENCES inventory_central_demands(company_id,demand_id),
    CONSTRAINT fk_central_procurement_req FOREIGN KEY(company_id,requisition_id) REFERENCES purchase_requisitions(company_id,requisition_id),
    CONSTRAINT fk_central_procurement_location FOREIGN KEY(company_id,receiving_warehouse_id,receiving_location_id) REFERENCES inventory_warehouse_locations(company_id,warehouse_id,location_id),
    INDEX idx_central_procurement_demand(company_id,demand_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
