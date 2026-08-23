<?php
declare(strict_types=1);
return [
 'version'=>'055','description'=>'License Analytics and create tenant-scoped Power BI configuration','statements'=>[
<<<'SQL'
INSERT INTO erp_modules(code,name,navigation_label,description,route_path,permission_namespace,icon_text,sort_order,available,active,release_status,first_release_version,introduced_migration)
VALUES('analytics','Analytics','Analytics','Company-scoped analytics and Power BI reporting','/analytics','analytics','BI',70,TRUE,TRUE,'released','1.0.0','055')
ON DUPLICATE KEY UPDATE name=VALUES(name),navigation_label=VALUES(navigation_label),description=VALUES(description),route_path=VALUES(route_path),permission_namespace=VALUES(permission_namespace),icon_text=VALUES(icon_text),sort_order=VALUES(sort_order),available=TRUE,active=TRUE,release_status='released',introduced_migration='055'
SQL,
<<<'SQL'
CREATE TABLE company_power_bi_configurations (
 company_id BIGINT UNSIGNED PRIMARY KEY, enabled BOOLEAN NOT NULL DEFAULT TRUE,
 authentication_mode VARCHAR(30) NOT NULL DEFAULT 'user_owns_data', microsoft_tenant_id CHAR(36) NOT NULL,
 workspace_id CHAR(36) NULL, report_id CHAR(36) NOT NULL, dataset_id CHAR(36) NULL,
 report_name VARCHAR(190) NOT NULL, client_id CHAR(36) NULL, client_secret_ciphertext TEXT NULL,
 credential_reference VARCHAR(255) NULL, configuration_status VARCHAR(30) NOT NULL DEFAULT 'enabled_not_configured',
 last_successful_validation_at DATETIME NULL, updated_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT ck_power_bi_auth_mode CHECK(authentication_mode IN('user_owns_data','platform_managed','company_managed')),
 CONSTRAINT ck_power_bi_status CHECK(configuration_status IN('enabled_not_configured','configuration_invalid','ready')),
 CONSTRAINT fk_power_bi_company FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
 CONSTRAINT fk_power_bi_updater FOREIGN KEY(updated_by) REFERENCES users(user_id) ON DELETE SET NULL,
 INDEX idx_power_bi_status(company_id,enabled,configuration_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
INSERT INTO permissions(name,code,module,description,active) VALUES
('View Analytics','analytics.view','analytics','View company-scoped Analytics reports',TRUE),
('Configure Analytics','analytics.configure','analytics','Manage the current company Power BI configuration',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module),description=VALUES(description),active=TRUE
SQL,
<<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p WHERE
 (r.code='company_owner' AND p.code IN('analytics.view','analytics.configure')) OR
 (r.code='executive_viewer' AND p.code='analytics.view') OR
 (r.code='system_administrator' AND p.code IN('analytics.view','analytics.configure'))
SQL,
<<<'SQL'
INSERT INTO company_modules(company_id,module_id,license_status,enabled,licensed_at,expires_at,updated_by,updated_at)
SELECT c.company_id,m.module_id,'active',TRUE,NOW(),c.subscription_expires_at,NULL,NOW() FROM companies c JOIN erp_modules m ON m.code='analytics' WHERE c.code='sample-company'
ON DUPLICATE KEY UPDATE license_status=VALUES(license_status),enabled=VALUES(enabled),licensed_at=COALESCE(company_modules.licensed_at,VALUES(licensed_at)),expires_at=VALUES(expires_at),updated_at=NOW()
SQL,
<<<'SQL'
INSERT INTO company_power_bi_configurations(company_id,enabled,authentication_mode,microsoft_tenant_id,workspace_id,report_id,dataset_id,report_name,configuration_status,last_successful_validation_at)
SELECT company_id,TRUE,'user_owns_data','a7aab42f-0a27-4f54-967b-32c5b0ae2271',NULL,'0d5b5815-9d8f-4542-b3bc-38498f382ba4',NULL,'Passion Sales Report','ready',NOW() FROM companies WHERE code='sample-company'
ON DUPLICATE KEY UPDATE company_id=VALUES(company_id)
SQL,
 ]];
