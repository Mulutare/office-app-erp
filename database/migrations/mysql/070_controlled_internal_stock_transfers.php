<?php

declare(strict_types=1);

return [
    'version'=>'070',
    'description'=>'Controlled internal stock transfer dispatch and receipt lifecycle',
    'preflight'=>static function(\PDO $connection):string{
        $columns=(int)$connection->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='inventory_transfers' AND column_name IN('submitted_by','dispatched_by','received_by')")->fetchColumn();
        $lineColumns=(int)$connection->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='inventory_transfer_lines' AND column_name IN('dispatched_quantity','received_quantity')")->fetchColumn();
        $permissions=(int)$connection->query("SELECT COUNT(*) FROM permissions WHERE code IN('inventory.transfers.create','inventory.transfers.approve','inventory.transfers.dispatch','inventory.transfers.receive')")->fetchColumn();
        if($columns===0&&$lineColumns===0&&$permissions===0)return 'apply';
        if($columns===3&&$lineColumns===2&&$permissions===4)return 'baseline';
        throw new \RuntimeException('Migration 070 found a partial controlled transfer schema.');
    },
    'statements'=>[
        <<<'SQL'
ALTER TABLE inventory_transfers
 DROP CONSTRAINT ck_inventory_transfer_status,
 ADD COLUMN reason VARCHAR(500) NULL AFTER notes,
 ADD COLUMN submitted_by BIGINT UNSIGNED NULL AFTER created_by,
 ADD COLUMN submitted_at DATETIME NULL AFTER submitted_by,
 ADD COLUMN dispatched_by BIGINT UNSIGNED NULL AFTER approved_at,
 ADD COLUMN dispatched_at DATETIME NULL AFTER dispatched_by,
 ADD COLUMN received_by BIGINT UNSIGNED NULL AFTER dispatched_at,
 ADD COLUMN received_at DATETIME NULL AFTER received_by,
 ADD CONSTRAINT ck_inventory_transfer_status CHECK(status IN('draft','submitted','approved','in_transit','done','cancelled')),
 ADD CONSTRAINT fk_inventory_transfer_submitter FOREIGN KEY(submitted_by) REFERENCES users(user_id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_inventory_transfer_dispatcher FOREIGN KEY(dispatched_by) REFERENCES users(user_id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_inventory_transfer_receiver FOREIGN KEY(received_by) REFERENCES users(user_id) ON DELETE SET NULL
SQL,
        <<<'SQL'
ALTER TABLE inventory_transfer_lines
 ADD COLUMN dispatched_quantity DECIMAL(18,3) NOT NULL DEFAULT 0 AFTER quantity,
 ADD COLUMN received_quantity DECIMAL(18,3) NOT NULL DEFAULT 0 AFTER dispatched_quantity,
 ADD CONSTRAINT ck_inventory_transfer_line_progress CHECK(dispatched_quantity>=0 AND dispatched_quantity<=quantity AND received_quantity>=0 AND received_quantity<=dispatched_quantity)
SQL,
        <<<'SQL'
INSERT INTO permissions(name,code,module,description,active) VALUES
 ('Create Stock Transfers','inventory.transfers.create','inventory','Create and submit internal stock transfers between authorized locations',TRUE),
 ('Approve Stock Transfers','inventory.transfers.approve','inventory','Approve submitted internal stock transfers',TRUE),
 ('Dispatch Stock Transfers','inventory.transfers.dispatch','inventory','Move approved stock from its exact source into controlled transit',TRUE),
 ('Receive Stock Transfers','inventory.transfers.receive','inventory','Receive dispatched stock from transit into its exact destination',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module),description=VALUES(description),active=TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p
WHERE (r.code IN('system_administrator','company_owner') AND p.code IN('inventory.transfers.create','inventory.transfers.approve','inventory.transfers.dispatch','inventory.transfers.receive'))
 OR (r.code='warehouse_inventory_user' AND p.code IN('inventory.transfers.view','inventory.transfers.create','inventory.transfers.dispatch','inventory.transfers.receive'))
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT c.company_id,r.role_id,p.permission_id,c.provisioned_by FROM companies c CROSS JOIN roles r CROSS JOIN permissions p
WHERE c.deleted_at IS NULL AND r.code IN('system_administrator','company_owner')
 AND p.code IN('inventory.transfers.create','inventory.transfers.approve','inventory.transfers.dispatch','inventory.transfers.receive')
SQL,
    ],
];

