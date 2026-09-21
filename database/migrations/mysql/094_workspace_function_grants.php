<?php

declare(strict_types=1);

return [
    'version' => '094',
    'description' => 'Separate independently configurable workspace functions from broad read grants',
    'statements' => [
        <<<'SQL'
INSERT INTO permissions(name,code,module,description,active) VALUES
('View orders','sales.orders.view','sales','Open this function; record scope and action permissions still apply',TRUE),
('View quotations','sales.quotations.view','sales','Open this function; record scope and action permissions still apply',TRUE),
('View customers','sales.customers.view','sales','Open this function; record scope and action permissions still apply',TRUE),
('View products','sales.products.view','sales','Open this function; record scope and action permissions still apply',TRUE),
('View deliveries','sales.deliveries.view','sales','Open this function; record scope and action permissions still apply',TRUE),
('View overview','procurement.overview.view','procurement','Open this function; record scope and action permissions still apply',TRUE),
('View requisitions','procurement.requisitions.view','procurement','Open this function; record scope and action permissions still apply',TRUE),
('View orders','procurement.orders.view','procurement','Open this function; record scope and action permissions still apply',TRUE),
('View suppliers','procurement.suppliers.view','procurement','Open this function; record scope and action permissions still apply',TRUE),
('View bills','procurement.bills.view','procurement','Open this function; record scope and action permissions still apply',TRUE),
('View dashboard','finance.dashboard.view','finance','Open this function; record scope and action permissions still apply',TRUE),
('View receivables','finance.receivables.view','finance','Open this function; record scope and action permissions still apply',TRUE),
('View invoices','finance.invoices.view','finance','Open this function; record scope and action permissions still apply',TRUE),
('View receipts','finance.receipts.view','finance','Open this function; record scope and action permissions still apply',TRUE),
('View payables','finance.payables.view','finance','Open this function; record scope and action permissions still apply',TRUE),
('View expenses','finance.expenses.view','finance','Open this function; record scope and action permissions still apply',TRUE),
('View legacy expenses','finance.expense_history.view','finance','Open this function; record scope and action permissions still apply',TRUE),
('View staff loans','finance.staff_loans.view','finance','Open this function; record scope and action permissions still apply',TRUE),
('View cash bank','finance.cash_bank.view','finance','Open this function; record scope and action permissions still apply',TRUE),
('View accounts','finance.accounts.view','finance','Open this function; record scope and action permissions still apply',TRUE),
('View journals','finance.journals.view','finance','Open this function; record scope and action permissions still apply',TRUE),
('View ledger','finance.ledger.view','finance','Open this function; record scope and action permissions still apply',TRUE),
('View reports','finance.reports.view','finance','Open this function; record scope and action permissions still apply',TRUE),
('View movements','inventory.movements.view','inventory','Open this function; record scope and action permissions still apply',TRUE),
('View stock daily history','inventory.history.view','inventory','Open this function; record scope and action permissions still apply',TRUE),
('View locations','inventory.locations.view','inventory','Open this function; record scope and action permissions still apply',TRUE),
('Manage direct','assets.direct.manage','assets','Open this function; record scope and action permissions still apply',TRUE),
('Manage categories','assets.categories.manage','assets','Open this function; record scope and action permissions still apply',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description)
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='sales.view' AND prior.active=TRUE
JOIN permissions p ON p.code='sales.orders.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='sales.module.enabled'
WHERE TRUE AND r.code IN ('company_owner','system_administrator','sales_manager','sales_approver','sales_cashier','sales_inventory_controller','sales_commission_officer','sales_credit_controller')
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='sales.view' AND prior.active=TRUE
JOIN permissions p ON p.code='sales.orders.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='sales.module.enabled'
WHERE TRUE AND r.code IN ('company_owner','system_administrator','sales_manager','sales_approver','sales_cashier','sales_inventory_controller','sales_commission_officer','sales_credit_controller')
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='sales.view' AND prior.active=TRUE
JOIN permissions p ON p.code='sales.quotations.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='sales.module.enabled'
WHERE TRUE AND r.code IN ('company_owner','system_administrator','sales_manager','sales_approver','sales_cashier','sales_inventory_controller','sales_commission_officer','sales_credit_controller')
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='sales.view' AND prior.active=TRUE
JOIN permissions p ON p.code='sales.quotations.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='sales.module.enabled'
WHERE TRUE AND r.code IN ('company_owner','system_administrator','sales_manager','sales_approver','sales_cashier','sales_inventory_controller','sales_commission_officer','sales_credit_controller')
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='sales.view' AND prior.active=TRUE
JOIN permissions p ON p.code='sales.customers.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='sales.module.enabled'
WHERE TRUE AND r.code IN ('company_owner','system_administrator','sales_manager','sales_approver','sales_cashier','sales_inventory_controller','sales_commission_officer','sales_credit_controller')
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='sales.view' AND prior.active=TRUE
JOIN permissions p ON p.code='sales.customers.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='sales.module.enabled'
WHERE TRUE AND r.code IN ('company_owner','system_administrator','sales_manager','sales_approver','sales_cashier','sales_inventory_controller','sales_commission_officer','sales_credit_controller')
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='sales.view' AND prior.active=TRUE
JOIN permissions p ON p.code='sales.products.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='sales.module.enabled'
WHERE TRUE AND r.code IN ('company_owner','system_administrator','sales_manager','sales_approver','sales_cashier','sales_inventory_controller','sales_commission_officer','sales_credit_controller')
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='sales.view' AND prior.active=TRUE
JOIN permissions p ON p.code='sales.products.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='sales.module.enabled'
WHERE TRUE AND r.code IN ('company_owner','system_administrator','sales_manager','sales_approver','sales_cashier','sales_inventory_controller','sales_commission_officer','sales_credit_controller')
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='sales.view' AND prior.active=TRUE
JOIN permissions p ON p.code='sales.deliveries.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='sales.module.enabled'
WHERE TRUE AND r.code IN ('company_owner','system_administrator','sales_manager','sales_approver','sales_cashier','sales_inventory_controller','sales_commission_officer','sales_credit_controller')
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='sales.view' AND prior.active=TRUE
JOIN permissions p ON p.code='sales.deliveries.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='sales.module.enabled'
WHERE TRUE AND r.code IN ('company_owner','system_administrator','sales_manager','sales_approver','sales_cashier','sales_inventory_controller','sales_commission_officer','sales_credit_controller')
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='procurement.view' AND prior.active=TRUE
JOIN permissions p ON p.code='procurement.overview.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='procurement.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='procurement.view' AND prior.active=TRUE
JOIN permissions p ON p.code='procurement.overview.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='procurement.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='procurement.view' AND prior.active=TRUE
JOIN permissions p ON p.code='procurement.requisitions.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='procurement.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='procurement.view' AND prior.active=TRUE
JOIN permissions p ON p.code='procurement.requisitions.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='procurement.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='procurement.view' AND prior.active=TRUE
JOIN permissions p ON p.code='procurement.orders.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='procurement.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='procurement.view' AND prior.active=TRUE
JOIN permissions p ON p.code='procurement.orders.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='procurement.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='procurement.view' AND prior.active=TRUE
JOIN permissions p ON p.code='procurement.suppliers.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='procurement.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='procurement.view' AND prior.active=TRUE
JOIN permissions p ON p.code='procurement.suppliers.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='procurement.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='procurement.view' AND prior.active=TRUE
JOIN permissions p ON p.code='procurement.bills.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='procurement.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='procurement.view' AND prior.active=TRUE
JOIN permissions p ON p.code='procurement.bills.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='procurement.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.dashboard.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.dashboard.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.receivables.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.receivables.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.invoices.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.invoices.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.receipts.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.receipts.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.payables.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.payables.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.expenses.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.expenses.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.expense_history.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.expense_history.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.staff_loans.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.staff_loans.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.cash_bank.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.cash_bank.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.accounts.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.accounts.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.journals.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.journals.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.ledger.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.ledger.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.reports.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='finance.records.view' AND prior.active=TRUE
JOIN permissions p ON p.code='finance.reports.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='finance.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='inventory.stock.view' AND prior.active=TRUE
JOIN permissions p ON p.code='inventory.movements.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='inventory.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='inventory.stock.view' AND prior.active=TRUE
JOIN permissions p ON p.code='inventory.movements.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='inventory.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='inventory.stock.view' AND prior.active=TRUE
JOIN permissions p ON p.code='inventory.history.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='inventory.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='inventory.stock.view' AND prior.active=TRUE
JOIN permissions p ON p.code='inventory.history.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='inventory.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='inventory.warehouses.view' AND prior.active=TRUE
JOIN permissions p ON p.code='inventory.locations.view'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='inventory.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='inventory.warehouses.view' AND prior.active=TRUE
JOIN permissions p ON p.code='inventory.locations.view'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='inventory.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='assets.manage' AND prior.active=TRUE
JOIN permissions p ON p.code='assets.direct.manage'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='assets.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='assets.manage' AND prior.active=TRUE
JOIN permissions p ON p.code='assets.direct.manage'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='assets.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT existing.role_id,p.permission_id FROM role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='assets.manage' AND prior.active=TRUE
JOIN permissions p ON p.code='assets.categories.manage'
JOIN role_permissions gates ON gates.role_id=existing.role_id 
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='assets.module.enabled'
WHERE TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL FROM company_role_permissions existing
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='assets.manage' AND prior.active=TRUE
JOIN permissions p ON p.code='assets.categories.manage'
JOIN company_role_permissions gates ON gates.role_id=existing.role_id AND gates.company_id=existing.company_id
JOIN permissions gate ON gate.permission_id=gates.permission_id AND gate.code='assets.module.enabled'
WHERE TRUE
SQL,
    ],
];
