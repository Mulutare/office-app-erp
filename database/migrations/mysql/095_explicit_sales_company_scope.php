<?php

declare(strict_types=1);
return [
    'version'=>'095',
    'description'=>'Replace Sales company-wide role-name scope exception with an explicit grant',
    'statements'=>[
        "INSERT INTO permissions(name,code,module,description,active) VALUES('View company-wide Sales records','sales.scope.company','sales','Expand Sales record scope to the current company; individual function and action grants are still required',TRUE) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description)",
        "INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT rp.role_id,p.permission_id FROM role_permissions rp JOIN roles r ON r.role_id=rp.role_id JOIN permissions old ON old.permission_id=rp.permission_id AND old.code='sales.view' JOIN permissions p ON p.code='sales.scope.company' WHERE r.code IN ('company_owner','system_administrator')",
        "INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by) SELECT rp.company_id,rp.role_id,p.permission_id,NULL FROM company_role_permissions rp JOIN roles r ON r.role_id=rp.role_id JOIN permissions old ON old.permission_id=rp.permission_id AND old.code='sales.view' JOIN permissions p ON p.code='sales.scope.company' WHERE r.code IN ('company_owner','system_administrator')",
    ],
];
