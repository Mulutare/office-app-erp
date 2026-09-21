<?php

declare(strict_types=1);

return [
    'version' => '093',
    'description' => 'Explicit company-role module gates using the existing permission authority',
    'statements' => [
        <<<'SQL'
INSERT INTO permissions(name,code,module,description,active) VALUES
('Enable Dashboard','dashboard.module.enabled','dashboard','Enable this workspace; individual function grants and record scope still apply',TRUE),
('Enable Sales','sales.module.enabled','sales','Enable this workspace; individual function grants and record scope still apply',TRUE),
('Enable Inventory','inventory.module.enabled','inventory','Enable this workspace; individual function grants and record scope still apply',TRUE),
('Enable Finance','finance.module.enabled','finance','Enable this workspace; individual function grants and record scope still apply',TRUE),
('Enable Hr','hr.module.enabled','hr','Enable this workspace; individual function grants and record scope still apply',TRUE),
('Enable Attendance','attendance.module.enabled','attendance','Enable this workspace; individual function grants and record scope still apply',TRUE),
('Enable Procurement','procurement.module.enabled','procurement','Enable this workspace; individual function grants and record scope still apply',TRUE),
('Enable Assets','assets.module.enabled','assets','Enable this workspace; individual function grants and record scope still apply',TRUE),
('Enable Analytics','analytics.module.enabled','analytics','Enable this workspace; individual function grants and record scope still apply',TRUE),
('Enable Administration','administration.module.enabled','administration','Enable this workspace; individual function grants and record scope still apply',TRUE),
('Enable It','it.module.enabled','it','Enable this workspace; individual function grants and record scope still apply',TRUE),
('Enable Business','business.module.enabled','business','Enable this workspace; individual function grants and record scope still apply',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description)
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,gate.permission_id
FROM role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='dashboard.module.enabled'
WHERE prior.module='dashboard' AND prior.code<>'dashboard.module.enabled' AND (TRUE)
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,gate.permission_id,NULL
FROM company_role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='dashboard.module.enabled'
WHERE prior.module='dashboard' AND prior.code<>'dashboard.module.enabled' AND (TRUE)
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,gate.permission_id
FROM role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='sales.module.enabled'
WHERE prior.module='sales' AND prior.code<>'sales.module.enabled' AND (r.code IN ('company_owner','system_administrator','sales_user','sales_manager','sales_officer','sales_approver','sales_cashier','sales_inventory_controller','sales_commission_officer','sales_credit_controller'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,gate.permission_id,NULL
FROM company_role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='sales.module.enabled'
WHERE prior.module='sales' AND prior.code<>'sales.module.enabled' AND (r.code IN ('company_owner','system_administrator','sales_user','sales_manager','sales_officer','sales_approver','sales_cashier','sales_inventory_controller','sales_commission_officer','sales_credit_controller'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,gate.permission_id
FROM role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='inventory.module.enabled'
WHERE prior.module='inventory' AND prior.code<>'inventory.module.enabled' AND (r.code IN ('company_owner','system_administrator','warehouse_inventory_user','warehouse_sales_employee','stock_hierarchy_manager'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,gate.permission_id,NULL
FROM company_role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='inventory.module.enabled'
WHERE prior.module='inventory' AND prior.code<>'inventory.module.enabled' AND (r.code IN ('company_owner','system_administrator','warehouse_inventory_user','warehouse_sales_employee','stock_hierarchy_manager'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,gate.permission_id
FROM role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='finance.module.enabled'
WHERE prior.module='finance' AND prior.code<>'finance.module.enabled' AND (r.code IN ('company_owner','system_administrator','finance_officer','finance_approver','executive_viewer','auditor'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,gate.permission_id,NULL
FROM company_role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='finance.module.enabled'
WHERE prior.module='finance' AND prior.code<>'finance.module.enabled' AND (r.code IN ('company_owner','system_administrator','finance_officer','finance_approver','executive_viewer','auditor'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,gate.permission_id
FROM role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='hr.module.enabled'
WHERE prior.module='hr' AND prior.code<>'hr.module.enabled' AND (r.code IN ('company_owner','system_administrator','hr_administrator','employee_self_service'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,gate.permission_id,NULL
FROM company_role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='hr.module.enabled'
WHERE prior.module='hr' AND prior.code<>'hr.module.enabled' AND (r.code IN ('company_owner','system_administrator','hr_administrator','employee_self_service'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,gate.permission_id
FROM role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='attendance.module.enabled'
WHERE prior.module='attendance' AND prior.code<>'attendance.module.enabled' AND (r.code IN ('company_owner','system_administrator','hr_administrator','employee_self_service'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,gate.permission_id,NULL
FROM company_role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='attendance.module.enabled'
WHERE prior.module='attendance' AND prior.code<>'attendance.module.enabled' AND (r.code IN ('company_owner','system_administrator','hr_administrator','employee_self_service'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,gate.permission_id
FROM role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='procurement.module.enabled'
WHERE prior.module='procurement' AND prior.code<>'procurement.module.enabled' AND (r.code IN ('company_owner','system_administrator','procurement_requester','procurement_approver','purchasing_officer'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,gate.permission_id,NULL
FROM company_role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='procurement.module.enabled'
WHERE prior.module='procurement' AND prior.code<>'procurement.module.enabled' AND (r.code IN ('company_owner','system_administrator','procurement_requester','procurement_approver','purchasing_officer'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,gate.permission_id
FROM role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='assets.module.enabled'
WHERE prior.module='assets' AND prior.code<>'assets.module.enabled' AND (r.code IN ('company_owner','system_administrator','fixed_asset_viewer','fixed_asset_officer','fixed_asset_manager'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,gate.permission_id,NULL
FROM company_role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='assets.module.enabled'
WHERE prior.module='assets' AND prior.code<>'assets.module.enabled' AND (r.code IN ('company_owner','system_administrator','fixed_asset_viewer','fixed_asset_officer','fixed_asset_manager'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,gate.permission_id
FROM role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='analytics.module.enabled'
WHERE prior.module='analytics' AND prior.code<>'analytics.module.enabled' AND (r.code IN ('company_owner','system_administrator'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,gate.permission_id,NULL
FROM company_role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='analytics.module.enabled'
WHERE prior.module='analytics' AND prior.code<>'analytics.module.enabled' AND (r.code IN ('company_owner','system_administrator'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,gate.permission_id
FROM role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='administration.module.enabled'
WHERE prior.module IN ('administration','organization','audit') AND prior.code<>'administration.module.enabled' AND (TRUE)
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,gate.permission_id,NULL
FROM company_role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='administration.module.enabled'
WHERE prior.module IN ('administration','organization','audit') AND prior.code<>'administration.module.enabled' AND (TRUE)
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,gate.permission_id
FROM role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='it.module.enabled'
WHERE prior.module='it' AND prior.code<>'it.module.enabled' AND (r.code IN ('company_owner','system_administrator','it_administrator','executive_viewer','auditor'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,gate.permission_id,NULL
FROM company_role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='it.module.enabled'
WHERE prior.module='it' AND prior.code<>'it.module.enabled' AND (r.code IN ('company_owner','system_administrator','it_administrator','executive_viewer','auditor'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,gate.permission_id
FROM role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='business.module.enabled'
WHERE prior.module='business' AND prior.code<>'business.module.enabled' AND (r.code IN ('company_owner','system_administrator','business_development_officer','executive_viewer','auditor'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,gate.permission_id,NULL
FROM company_role_permissions existing JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions gate ON gate.code='business.module.enabled'
WHERE prior.module='business' AND prior.code<>'business.module.enabled' AND (r.code IN ('company_owner','system_administrator','business_development_officer','executive_viewer','auditor'))
SQL,
    ],
];
