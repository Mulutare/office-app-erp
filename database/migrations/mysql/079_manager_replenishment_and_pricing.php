<?php

declare(strict_types=1);

return [
    'version' => '079',
    'description' => 'Manager proactive stock replenishment, hierarchical Inventory visibility and explicit Central PR pricing',
    'preflight' => static function (\PDO $connection): string {
        $column = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema=DATABASE() AND table_name='inventory_stock_requests' AND column_name='request_kind'"
        )->fetchColumn();
        if ($column === 0) return 'apply';
        if ($column === 1) return 'baseline';
        throw new \RuntimeException('Migration 079 found an invalid stock-request request_kind schema state.');
    },
    'statements' => [
        <<<'SQL'
ALTER TABLE inventory_stock_requests
  ADD COLUMN request_kind VARCHAR(24) NOT NULL DEFAULT 'employee_issue' AFTER request_number,
  ADD INDEX idx_inventory_stock_request_kind(company_id,request_kind,status,requested_at)
SQL,
        <<<'SQL'
UPDATE purchase_requisitions r
INNER JOIN inventory_central_procurement_links cp
  ON cp.company_id=r.company_id AND cp.requisition_id=r.requisition_id
SET r.status='draft',r.rejection_reason=NULL,r.approved_by=NULL,r.approved_at=NULL
WHERE r.status='submitted'
  AND EXISTS(
      SELECT 1 FROM purchase_requisition_lines l
      WHERE l.company_id=r.company_id AND l.requisition_id=r.requisition_id
        AND l.estimated_unit_price<=0
  )
  AND NOT EXISTS(
      SELECT 1 FROM purchase_orders po
      WHERE po.company_id=r.company_id AND po.requisition_id=r.requisition_id
  )
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id
FROM roles r
INNER JOIN permissions p ON p.code='inventory.stock_requests.create' AND p.active=TRUE
WHERE r.active=TRUE AND r.code IN('warehouse_inventory_user','warehouse_sales_employee')
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT c.company_id,rp.role_id,rp.permission_id,c.provisioned_by
FROM companies c
INNER JOIN role_permissions rp ON 1=1
INNER JOIN permissions p ON p.permission_id=rp.permission_id AND p.code='inventory.stock_requests.create'
INNER JOIN roles r ON r.role_id=rp.role_id AND r.code IN('warehouse_inventory_user','warehouse_sales_employee')
WHERE c.deleted_at IS NULL
SQL,
    ],
];
