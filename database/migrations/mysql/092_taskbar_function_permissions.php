<?php

declare(strict_types=1);

return [
    'version' => '092',
    'description' => 'Separate Quick Sale and DSA report function grants from broad Sales viewing',
    'statements' => [
        <<<'SQL'
INSERT INTO permissions(name,code,module,description,active) VALUES
('Use Quick Sale','sales.quick_sale.use','sales','Create and view own Quick Sales; data remains limited by assignment',TRUE),
('Review Quick Sale','sales.quick_sale.review','sales','Review and allocate Quick Sales within assigned manager scope',TRUE),
('Submit DSA Sales Report','sales.report.submit','sales','Submit outcomes for own allocated Quick Sales',TRUE),
('Review DSA Sales Report','sales.report.review','sales','Review submitted reports within assigned manager scope',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),active=TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r JOIN permissions p ON p.active=TRUE
WHERE (r.code IN ('company_owner','system_administrator','sales_manager') AND p.code IN ('sales.quick_sale.review','sales.report.review'))
   OR (r.code IN ('company_owner','system_administrator','sales_user','sales_officer') AND p.code IN ('sales.quick_sale.use','sales.report.submit'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT existing.company_id,existing.role_id,p.permission_id,NULL
FROM company_role_permissions existing
JOIN permissions prior ON prior.permission_id=existing.permission_id AND prior.code='sales.view' AND prior.active=TRUE
JOIN roles r ON r.role_id=existing.role_id AND r.active=TRUE
JOIN permissions p ON p.active=TRUE
WHERE (r.code IN ('company_owner','system_administrator','sales_manager') AND p.code IN ('sales.quick_sale.review','sales.report.review'))
   OR (r.code IN ('company_owner','system_administrator','sales_user','sales_officer') AND p.code IN ('sales.quick_sale.use','sales.report.submit'))
SQL,
    ],
];
