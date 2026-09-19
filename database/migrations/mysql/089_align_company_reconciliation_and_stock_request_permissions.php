<?php

declare(strict_types=1);

return [
    'version' => '089',
    'description' => 'Align company-scoped bank reconciliation and warehouse sales stock request permissions',
    'statements' => [
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id
FROM roles r
JOIN permissions p ON p.code='inventory.stock_requests.view' AND p.active=TRUE
WHERE r.code='warehouse_sales_employee' AND r.active=TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT c.company_id,rp.role_id,rp.permission_id,c.provisioned_by
FROM companies c
JOIN role_permissions rp ON 1=1
JOIN roles r ON r.role_id=rp.role_id AND r.active=TRUE
JOIN permissions p ON p.permission_id=rp.permission_id AND p.active=TRUE
WHERE c.deleted_at IS NULL
  AND (
    (p.code IN ('finance.bank_reconciliation.view','finance.bank_reconciliation.prepare',
                'finance.bank_reconciliation.review','finance.bank_reconciliation.mapping')
     AND r.code IN ('company_owner','system_administrator','finance_officer','finance_approver'))
    OR (p.code IN ('inventory.stock_requests.view','inventory.stock_requests.create')
        AND r.code='warehouse_sales_employee')
  )
SQL,
    ],
];
