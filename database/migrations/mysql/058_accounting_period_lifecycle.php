<?php

declare(strict_types=1);

return [
    'version' => '058',
    'description' => 'Add controlled fiscal-year and accounting-period lifecycle',
    'statements' => [
        <<<'SQL'
CREATE TABLE finance_fiscal_years (
 fiscal_year_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 fiscal_year_name VARCHAR(80) NOT NULL, date_from DATE NOT NULL, date_to DATE NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'open', created_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT uq_finance_fiscal_year_identity UNIQUE(company_id,fiscal_year_id),
 CONSTRAINT uq_finance_fiscal_year_name UNIQUE(company_id,fiscal_year_name),
 CONSTRAINT ck_finance_fiscal_year_dates CHECK(date_to>=date_from),
 CONSTRAINT ck_finance_fiscal_year_status CHECK(status IN('open','closed','locked')),
 CONSTRAINT fk_finance_fiscal_year_company FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
 CONSTRAINT fk_finance_fiscal_year_actor FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE SET NULL,
 INDEX idx_finance_fiscal_year_dates(company_id,date_from,date_to,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
ALTER TABLE finance_accounting_periods
 ADD COLUMN fiscal_year_id BIGINT UNSIGNED NULL AFTER company_id,
 ADD COLUMN closed_by BIGINT UNSIGNED NULL AFTER status,
 ADD COLUMN closed_at DATETIME NULL AFTER closed_by,
 ADD CONSTRAINT uq_finance_period_identity UNIQUE(company_id,period_id),
 ADD CONSTRAINT fk_finance_period_fiscal_year FOREIGN KEY(company_id,fiscal_year_id) REFERENCES finance_fiscal_years(company_id,fiscal_year_id),
 ADD CONSTRAINT fk_finance_period_closer FOREIGN KEY(closed_by) REFERENCES users(user_id) ON DELETE SET NULL,
 ADD INDEX idx_finance_period_year(company_id,fiscal_year_id,date_from,date_to)
SQL,
        <<<'SQL'
CREATE TABLE finance_accounting_period_history (
 period_history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 period_id BIGINT UNSIGNED NOT NULL, action VARCHAR(20) NOT NULL, status_from VARCHAR(20) NULL,
 status_to VARCHAR(20) NOT NULL, reason VARCHAR(500) NULL, acted_by BIGINT UNSIGNED NOT NULL,
 acted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_finance_period_history_period FOREIGN KEY(company_id,period_id) REFERENCES finance_accounting_periods(company_id,period_id),
 CONSTRAINT fk_finance_period_history_actor FOREIGN KEY(acted_by) REFERENCES users(user_id),
 CONSTRAINT ck_finance_period_history_action CHECK(action IN('created','opened','closed','locked','reopened')),
 INDEX idx_finance_period_history(company_id,period_id,acted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO finance_fiscal_years(company_id,fiscal_year_name,date_from,date_to,status,created_by)
SELECT company_id,CONCAT('FY ',YEAR(CURRENT_DATE)),MAKEDATE(YEAR(CURRENT_DATE),1),
       DATE_SUB(MAKEDATE(YEAR(CURRENT_DATE)+1,1),INTERVAL 1 DAY),'open',owner_user_id
FROM companies WHERE deleted_at IS NULL
ON DUPLICATE KEY UPDATE fiscal_year_id=fiscal_year_id
SQL,
        <<<'SQL'
INSERT INTO finance_accounting_periods(company_id,fiscal_year_id,period_name,date_from,date_to,status,created_by)
SELECT y.company_id,y.fiscal_year_id,CONCAT('FY ',YEAR(CURRENT_DATE),' Open'),y.date_from,y.date_to,'open',y.created_by
FROM finance_fiscal_years y
WHERE y.fiscal_year_name=CONCAT('FY ',YEAR(CURRENT_DATE))
  AND NOT EXISTS(SELECT 1 FROM finance_accounting_periods p WHERE p.company_id=y.company_id AND p.date_from<=y.date_to AND p.date_to>=y.date_from)
SQL,
        <<<'SQL'
INSERT INTO permissions(code,name,description,module,active)
VALUES
 ('finance.period.view','View accounting periods','View company fiscal years and accounting periods','finance',TRUE),
 ('finance.period.manage','Manage accounting periods','Create fiscal years and open accounting periods','finance',TRUE),
 ('finance.period.close','Close accounting periods','Close and lock accounting periods','finance',TRUE),
 ('finance.period.reopen','Reopen accounting periods','Reopen closed accounting periods with a reason','finance',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),active=TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p
WHERE ((r.code IN('system_administrator','company_owner') AND p.code IN('finance.period.view','finance.period.manage','finance.period.close','finance.period.reopen'))
    OR (r.code='finance_officer' AND p.code IN('finance.period.view','finance.period.close')))
  AND p.active=TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT c.company_id,rp.role_id,rp.permission_id,c.owner_user_id
FROM companies c CROSS JOIN role_permissions rp INNER JOIN permissions p ON p.permission_id=rp.permission_id
WHERE p.code IN('finance.period.view','finance.period.manage','finance.period.close','finance.period.reopen') AND p.active=TRUE
SQL,
    ],
];
