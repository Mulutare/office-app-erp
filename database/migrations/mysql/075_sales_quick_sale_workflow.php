<?php

declare(strict_types=1);

return [
    'version' => '075',
    'description' => 'Add DSA/DSP simplified Quick Sale workflow metadata',
    'preflight' => static function (PDO $connection): string {
        $statement = $connection->query(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = 'sales_quick_sales'"
        );

        return (int) $statement->fetchColumn() === 0
            ? 'apply'
            : 'baseline';
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE sales_quick_sales (
    quick_sale_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    quotation_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    team_id BIGINT UNSIGNED NOT NULL,
    manager_user_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'submitted',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT uq_sales_quick_sale_quotation
        UNIQUE (company_id, quotation_id),

    INDEX idx_sales_quick_sale_user
        (company_id, user_id, status),

    INDEX idx_sales_quick_sale_manager
        (company_id, manager_user_id, status),

    INDEX idx_sales_quick_sale_warehouse
        (company_id, warehouse_id, status),

    CONSTRAINT ck_sales_quick_sale_status
        CHECK (
            status IN (
                'submitted',
                'allocated',
                'sold',
                'return_requested',
                'returned',
                'cancelled'
            )
        ),

    CONSTRAINT fk_sales_quick_sale_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_sales_quick_sale_quotation
        FOREIGN KEY (company_id, quotation_id)
        REFERENCES sales_quotations(company_id, quotation_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_sales_quick_sale_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_sales_quick_sale_agent
        FOREIGN KEY (agent_id)
        REFERENCES sales_agents(agent_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_sales_quick_sale_team
        FOREIGN KEY (team_id)
        REFERENCES sales_teams(team_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_sales_quick_sale_manager
        FOREIGN KEY (manager_user_id)
        REFERENCES users(user_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_sales_quick_sale_warehouse
        FOREIGN KEY (warehouse_id)
        REFERENCES inventory_warehouses(warehouse_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];