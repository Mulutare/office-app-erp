<?php
declare(strict_types=1);
return [
 'version'=>'043','description'=>'Create quotations, pricelists and sales teams',
 'preflight'=>static function(PDO $c):string{$n=(int)$c->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('sales_pricelists','sales_pricelist_rules','sales_teams','sales_team_members','sales_quotations','sales_quotation_lines')")->fetchColumn();if($n===0)return 'apply';if($n===6)return 'baseline';throw new RuntimeException('Migration 043 found a partial Sales commercial schema.');},
 'statements'=>[
<<<'SQL'
CREATE TABLE sales_pricelists (
 pricelist_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(120) NOT NULL, currency CHAR(3) NOT NULL, valid_from DATE NULL, valid_to DATE NULL,
 active BOOLEAN NOT NULL DEFAULT TRUE, created_by BIGINT UNSIGNED NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_sales_pricelist_name(company_id,name), UNIQUE KEY uq_sales_pricelist_identity(company_id,pricelist_id),
 CONSTRAINT ck_sales_pricelist_dates CHECK(valid_to IS NULL OR valid_from IS NULL OR valid_to>=valid_from),
 FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
 FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE sales_pricelist_rules (
 rule_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL, pricelist_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NULL, category VARCHAR(80) NULL, minimum_quantity DECIMAL(15,3) NOT NULL DEFAULT 1,
 calculation VARCHAR(20) NOT NULL, fixed_price DECIMAL(15,2) NULL, percentage_adjustment DECIMAL(9,4) NULL,
 valid_from DATE NULL, valid_to DATE NULL, priority INT NOT NULL DEFAULT 100, active BOOLEAN NOT NULL DEFAULT TRUE,
 CONSTRAINT ck_sales_pricelist_rule_calc CHECK((calculation='fixed' AND fixed_price IS NOT NULL AND fixed_price>=0 AND percentage_adjustment IS NULL) OR (calculation='percentage' AND percentage_adjustment IS NOT NULL AND fixed_price IS NULL)),
 CONSTRAINT ck_sales_pricelist_rule_qty CHECK(minimum_quantity>0),
 FOREIGN KEY(company_id,pricelist_id) REFERENCES sales_pricelists(company_id,pricelist_id) ON DELETE CASCADE,
 FOREIGN KEY(product_id) REFERENCES sales_products(product_id) ON DELETE CASCADE,
 INDEX idx_sales_price_resolution(company_id,pricelist_id,product_id,minimum_quantity,priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE sales_teams (
 team_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL, name VARCHAR(120) NOT NULL,
 leader_agent_id BIGINT UNSIGNED NULL, territory_id BIGINT UNSIGNED NULL, active BOOLEAN NOT NULL DEFAULT TRUE,
 created_by BIGINT UNSIGNED NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_sales_team_name(company_id,name), UNIQUE KEY uq_sales_team_identity(company_id,team_id),
 FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
 FOREIGN KEY(leader_agent_id) REFERENCES sales_agents(agent_id) ON DELETE SET NULL,
 FOREIGN KEY(territory_id) REFERENCES sales_territories(territory_id) ON DELETE SET NULL,
 FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE sales_team_members (
 company_id BIGINT UNSIGNED NOT NULL, team_id BIGINT UNSIGNED NOT NULL, agent_id BIGINT UNSIGNED NOT NULL,
 joined_at DATETIME NOT NULL, PRIMARY KEY(company_id,team_id,agent_id),
 FOREIGN KEY(company_id,team_id) REFERENCES sales_teams(company_id,team_id) ON DELETE CASCADE,
 FOREIGN KEY(agent_id) REFERENCES sales_agents(agent_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE sales_quotations (
 quotation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL, quotation_number VARCHAR(50) NOT NULL,
 customer_id BIGINT UNSIGNED NOT NULL, agent_id BIGINT UNSIGNED NULL, team_id BIGINT UNSIGNED NULL, pricelist_id BIGINT UNSIGNED NULL,
 sales_order_id BIGINT UNSIGNED NULL, quotation_date DATE NOT NULL, expiration_date DATE NULL, payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 currency CHAR(3) NOT NULL, billing_address VARCHAR(500) NULL, delivery_address VARCHAR(500) NULL, notes VARCHAR(1000) NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'draft', untaxed_amount DECIMAL(15,2) NOT NULL, tax_amount DECIMAL(15,2) NOT NULL, total_amount DECIMAL(15,2) NOT NULL,
 sent_at DATETIME NULL, confirmed_at DATETIME NULL, cancelled_at DATETIME NULL, created_by BIGINT UNSIGNED NULL, updated_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_sales_quotation_number(company_id,quotation_number), UNIQUE KEY uq_sales_quotation_identity(company_id,quotation_id),
 UNIQUE KEY uq_sales_quotation_order(company_id,sales_order_id),
 CONSTRAINT ck_sales_quotation_status CHECK(status IN('draft','sent','confirmed','cancelled','expired')),
 CONSTRAINT ck_sales_quotation_dates CHECK(expiration_date IS NULL OR expiration_date>=quotation_date),
 FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE CASCADE, FOREIGN KEY(customer_id) REFERENCES sales_customers(customer_id) ON DELETE RESTRICT,
 FOREIGN KEY(agent_id) REFERENCES sales_agents(agent_id) ON DELETE SET NULL, FOREIGN KEY(company_id,team_id) REFERENCES sales_teams(company_id,team_id) ON DELETE RESTRICT,
 FOREIGN KEY(company_id,pricelist_id) REFERENCES sales_pricelists(company_id,pricelist_id) ON DELETE RESTRICT, FOREIGN KEY(sales_order_id) REFERENCES sales_orders(order_id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE SET NULL, FOREIGN KEY(updated_by) REFERENCES users(user_id) ON DELETE SET NULL,
 INDEX idx_sales_quotation_status(company_id,status,quotation_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE sales_quotation_lines (
 quotation_line_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL, quotation_id BIGINT UNSIGNED NOT NULL,
 sequence INT NOT NULL, product_id BIGINT UNSIGNED NOT NULL, description VARCHAR(255) NOT NULL, quantity DECIMAL(15,3) NOT NULL,
 unit_of_measure VARCHAR(20) NOT NULL, unit_price DECIMAL(15,2) NOT NULL, discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
 tax_rate DECIMAL(7,4) NOT NULL DEFAULT 0, untaxed_amount DECIMAL(15,2) NOT NULL, tax_amount DECIMAL(15,2) NOT NULL, line_total DECIMAL(15,2) NOT NULL,
 UNIQUE KEY uq_sales_quotation_line(company_id,quotation_id,sequence),
 FOREIGN KEY(company_id,quotation_id) REFERENCES sales_quotations(company_id,quotation_id) ON DELETE CASCADE,
 FOREIGN KEY(product_id) REFERENCES sales_products(product_id) ON DELETE RESTRICT,
 CONSTRAINT ck_sales_quotation_line CHECK(quantity>0 AND unit_price>=0 AND discount_amount>=0 AND tax_rate BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
ALTER TABLE sales_customers ADD COLUMN pricelist_id BIGINT UNSIGNED NULL AFTER agent_id, ADD COLUMN team_id BIGINT UNSIGNED NULL AFTER pricelist_id,
 ADD COLUMN legal_name VARCHAR(190) NULL AFTER name, ADD COLUMN mobile VARCHAR(40) NULL AFTER phone,
 ADD COLUMN street VARCHAR(190) NULL AFTER address, ADD COLUMN street2 VARCHAR(190) NULL AFTER street, ADD COLUMN city VARCHAR(100) NULL AFTER street2,
 ADD COLUMN state_region VARCHAR(100) NULL AFTER city, ADD COLUMN postal_code VARCHAR(30) NULL AFTER state_region, ADD COLUMN country VARCHAR(100) NULL AFTER postal_code,
 ADD CONSTRAINT fk_sales_customer_pricelist FOREIGN KEY(company_id,pricelist_id) REFERENCES sales_pricelists(company_id,pricelist_id) ON DELETE RESTRICT,
 ADD CONSTRAINT fk_sales_customer_team FOREIGN KEY(company_id,team_id) REFERENCES sales_teams(company_id,team_id) ON DELETE RESTRICT
SQL
 ]];
