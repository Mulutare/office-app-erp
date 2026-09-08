<?php

declare(strict_types=1);

return [
    'version' => '080',
    'description' => 'Quick Sale staged Regional-District-Shop fulfilment with durable origin identity',
    'preflight' => static function (PDO $connection): string {
        $columnCount = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema=DATABASE() AND table_name='sales_quick_sales'
               AND column_name IN('origin_manager_user_id','origin_warehouse_id','fulfilment_state')"
        )->fetchColumn();
        $tableCount = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name='sales_quick_sale_transfer_links'"
        )->fetchColumn();
        if ($columnCount === 0 && $tableCount === 0) {
            return 'apply';
        }
        if ($columnCount === 3 && $tableCount === 1) {
            return 'baseline';
        }
        throw new RuntimeException('Migration 080 found a partial Quick Sale staged-fulfilment schema. Complete or restore it before retrying.');
    },
    'statements' => [
        <<<'SQL'
ALTER TABLE sales_quick_sales
  ADD COLUMN origin_manager_user_id BIGINT UNSIGNED NULL AFTER manager_user_id,
  ADD COLUMN origin_warehouse_id BIGINT UNSIGNED NULL AFTER warehouse_id,
  ADD COLUMN fulfilment_state VARCHAR(30) NOT NULL DEFAULT 'at_origin' AFTER status,
  ADD INDEX idx_quick_sale_origin_manager(company_id,origin_manager_user_id),
  ADD INDEX idx_quick_sale_origin_warehouse(company_id,origin_warehouse_id),
  ADD INDEX idx_quick_sale_fulfilment(company_id,fulfilment_state,status),
  ADD CONSTRAINT fk_quick_sale_origin_manager
    FOREIGN KEY(company_id,origin_manager_user_id)
    REFERENCES company_users(company_id,user_id),
  ADD CONSTRAINT fk_quick_sale_origin_warehouse
    FOREIGN KEY(company_id,origin_warehouse_id)
    REFERENCES inventory_warehouses(company_id,warehouse_id)
SQL,
        <<<'SQL'
UPDATE sales_quick_sales
SET origin_manager_user_id=COALESCE(origin_manager_user_id,manager_user_id),
    origin_warehouse_id=COALESCE(origin_warehouse_id,warehouse_id)
WHERE origin_manager_user_id IS NULL OR origin_warehouse_id IS NULL
SQL,
        <<<'SQL'
ALTER TABLE sales_quick_sales
  MODIFY origin_manager_user_id BIGINT UNSIGNED NOT NULL,
  MODIFY origin_warehouse_id BIGINT UNSIGNED NOT NULL
SQL,
        <<<'SQL'
CREATE TABLE sales_quick_sale_transfer_links (
    company_id BIGINT UNSIGNED NOT NULL,
    quick_sale_id BIGINT UNSIGNED NOT NULL,
    transfer_line_id BIGINT UNSIGNED NOT NULL,
    state VARCHAR(20) NOT NULL DEFAULT 'reserved',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(company_id,transfer_line_id),
    CONSTRAINT ck_quick_sale_transfer_state
      CHECK(state IN('reserved','in_transit','received','released')),
    CONSTRAINT fk_quick_sale_transfer_sale
      FOREIGN KEY(company_id,quick_sale_id)
      REFERENCES sales_quick_sales(company_id,quick_sale_id),
    CONSTRAINT fk_quick_sale_transfer_line
      FOREIGN KEY(company_id,transfer_line_id)
      REFERENCES inventory_transfer_lines(company_id,transfer_line_id),
    INDEX idx_quick_sale_transfer_sale(company_id,quick_sale_id,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
