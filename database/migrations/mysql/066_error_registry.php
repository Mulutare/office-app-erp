<?php

declare(strict_types=1);

return [
    'version' => '066',
    'description' => 'Create centralized application error catalog and incident registry',
    'preflight' => static function (\PDO $connection): string {
        $tables = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_name IN('app_error_catalog','app_error_incidents')"
        )->fetchColumn();
        if ($tables === 0) return 'apply';
        if ($tables === 2) return 'baseline';
        throw new \RuntimeException('Migration 066 found a partial error registry schema.');
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE app_error_catalog (
 error_code VARCHAR(40) PRIMARY KEY,
 module VARCHAR(40) NOT NULL,
 title VARCHAR(160) NOT NULL,
 cause TEXT NOT NULL,
 suggested_action TEXT NOT NULL,
 severity VARCHAR(20) NOT NULL DEFAULT 'error',
 user_visible BOOLEAN NOT NULL DEFAULT TRUE,
 log_incident BOOLEAN NOT NULL DEFAULT TRUE,
 active BOOLEAN NOT NULL DEFAULT TRUE,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT ck_app_error_severity CHECK(severity IN('info','warning','error','critical')),
 INDEX idx_app_error_module(module,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE app_error_incidents (
 incident_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 incident_reference VARCHAR(40) NOT NULL,
 error_code VARCHAR(40) NOT NULL,
 company_id BIGINT UNSIGNED NULL,
 user_id BIGINT UNSIGNED NULL,
 module VARCHAR(40) NOT NULL,
 route VARCHAR(255) NULL,
 entity_type VARCHAR(80) NULL,
 entity_id VARCHAR(100) NULL,
 exception_class VARCHAR(255) NULL,
 safe_internal_message VARCHAR(1000) NULL,
 context_json LONGTEXT NULL,
 occurred_at DATETIME NOT NULL,
 CONSTRAINT uq_app_error_incident_reference UNIQUE(incident_reference),
 CONSTRAINT fk_app_error_incident_catalog FOREIGN KEY(error_code) REFERENCES app_error_catalog(error_code) ON DELETE RESTRICT,
 CONSTRAINT fk_app_error_incident_company FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE SET NULL,
 CONSTRAINT fk_app_error_incident_user FOREIGN KEY(user_id) REFERENCES users(user_id) ON DELETE SET NULL,
 INDEX idx_app_error_incident_code(error_code,occurred_at),
 INDEX idx_app_error_incident_company(company_id,occurred_at),
 INDEX idx_app_error_incident_user(user_id,occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO app_error_catalog(error_code,module,title,cause,suggested_action,severity,user_visible,log_incident,active) VALUES
('SAL-PRC-001','sales','Invalid fixed sales price','A fixed pricelist rule has a price of zero or less.','Enter a fixed price greater than zero, then save the pricelist rule again.','error',TRUE,TRUE,TRUE),
('SAL-QUO-001','sales','Quotation line has an invalid sales price','The selected product and pricing configuration resolved the sales price to zero or less.','Review the product sales price or selected pricelist, correct the pricing, then save the quotation again.','error',TRUE,TRUE,TRUE),
('FIN-INV-001','finance','Invoice cannot be posted','The customer invoice total is zero, so a valid accounting journal cannot be created.','Review the source Sales Order or quotation and its product/pricelist pricing. Correct the pricing and create a valid invoice before posting.','error',TRUE,TRUE,TRUE),
('FIN-JRN-001','finance','Accounting journal could not be posted','The generated accounting journal did not satisfy the accounting posting rules.','Review the source document and accounting configuration. If the values appear correct, provide the incident reference to an administrator.','error',TRUE,TRUE,TRUE),
('AST-OPR-001','assets','Asset operation could not be completed','The requested asset operation did not satisfy the asset lifecycle rules.','Review the asset status and entered information. If the problem continues, provide the incident reference to an administrator.','error',TRUE,TRUE,TRUE),
('PRO-PO-001','procurement','Procurement operation could not be completed','The requested procurement operation did not satisfy the procurement workflow rules.','Review the purchase document status and entered information, then try again.','error',TRUE,TRUE,TRUE),
('INV-STK-001','inventory','Inventory operation could not be completed','The requested inventory operation did not satisfy stock control rules.','Review the product, warehouse, quantity and document status, then try again.','error',TRUE,TRUE,TRUE),
('AUTH-001','authentication','Authentication operation failed','The authentication operation could not be completed safely.','Try again. If the problem continues, contact an administrator.','error',TRUE,TRUE,TRUE),
('SYS-UNEXPECTED-001','system','Unexpected application error','An unexpected error prevented the operation from completing.','Try again. If it continues, provide the incident reference to an administrator.','critical',TRUE,TRUE,TRUE)
SQL,
    ],
];
