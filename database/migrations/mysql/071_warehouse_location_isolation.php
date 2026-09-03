<?php

declare(strict_types=1);

return [
    'version'=>'071',
    'description'=>'Central warehouse and location isolation with explicit company-wide bypass',
    'preflight'=>static function(\PDO $connection):string{
        $permission=(int)$connection->query("SELECT COUNT(*) FROM permissions WHERE code='inventory.warehouses.all_access'")->fetchColumn();
        $view=(int)$connection->query("SELECT COUNT(*) FROM information_schema.views WHERE table_schema=DATABASE() AND table_name='vw_user_warehouse_location_scope'")->fetchColumn();
        if($permission===0&&$view===0)return 'apply';
        if($permission===1&&$view===1)return 'baseline';
        throw new \RuntimeException('Migration 071 found a partial warehouse-isolation schema.');
    },
    'statements'=>[
        <<<'SQL'
INSERT INTO permissions(name,code,module,description,active) VALUES
 ('Company-wide Warehouse Access','inventory.warehouses.all_access','inventory','Explicitly bypass warehouse assignments for authorized tenant management identities',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module),description=VALUES(description),active=TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p
WHERE (r.code IN('system_administrator','company_owner') AND p.code='inventory.warehouses.all_access')
 OR (r.code='purchasing_officer' AND p.code='procurement.receiving_destinations.use')
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT c.company_id,r.role_id,p.permission_id,c.provisioned_by
FROM companies c CROSS JOIN roles r CROSS JOIN permissions p
WHERE c.deleted_at IS NULL AND (
 (r.code IN('system_administrator','company_owner') AND p.code='inventory.warehouses.all_access')
 OR (r.code='purchasing_officer' AND p.code='procurement.receiving_destinations.use')
)
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_user_warehouse_location_scope AS
SELECT wa.company_id,wa.user_id,wa.warehouse_id,la.location_id
FROM inventory_user_warehouse_access wa
INNER JOIN inventory_warehouses w ON w.company_id=wa.company_id AND w.warehouse_id=wa.warehouse_id
INNER JOIN inventory_user_location_access la ON la.company_id=wa.company_id AND la.user_id=wa.user_id AND la.warehouse_id=wa.warehouse_id
INNER JOIN inventory_warehouse_locations l ON l.company_id=la.company_id AND l.warehouse_id=la.warehouse_id AND l.location_id=la.location_id
WHERE wa.active=TRUE AND la.active=TRUE
 AND w.active=TRUE AND w.deleted_at IS NULL
 AND l.active=TRUE AND l.deleted_at IS NULL
SQL,
    ],
];
