<?php

declare(strict_types=1);

return [
    'version' => '038',
    'description' =>
        'Create Inventory permissions and owner grants',

    'preflight' => static function (
        \PDO $connection
    ): string {
        $permissionCount = (int) $connection->query(
            "SELECT COUNT(*)
             FROM permissions
             WHERE code IN (
                'inventory.view',
                'inventory.warehouses.view',
                'inventory.warehouses.manage',
                'inventory.stock.view',
                'inventory.stock.adjust',
                'inventory.receipts.view',
                'inventory.receipts.create',
                'inventory.receipts.approve',
                'inventory.receipts.post',
                'inventory.transfers.view',
                'inventory.transfers.manage'
             )"
        )->fetchColumn();

        if ($permissionCount === 0) {
            return 'apply';
        }

        if ($permissionCount === 11) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 038 found a partial Inventory permission catalog.'
        );
    },

    'statements' => [
        <<<'SQL'
INSERT INTO permissions (
    name,
    code,
    module,
    description,
    active
) VALUES
    (
        'View Inventory',
        'inventory.view',
        'inventory',
        'View Inventory dashboards and navigation',
        TRUE
    ),
    (
        'View Warehouses',
        'inventory.warehouses.view',
        'inventory',
        'View warehouses and storage locations',
        TRUE
    ),
    (
        'Manage Warehouses',
        'inventory.warehouses.manage',
        'inventory',
        'Create and maintain warehouses and locations',
        TRUE
    ),
    (
        'View Stock',
        'inventory.stock.view',
        'inventory',
        'View stock quantities, availability and valuation',
        TRUE
    ),
    (
        'Adjust Stock',
        'inventory.stock.adjust',
        'inventory',
        'Create authorized stock adjustments',
        TRUE
    ),
    (
        'View Goods Receipts',
        'inventory.receipts.view',
        'inventory',
        'View inventory goods receipts',
        TRUE
    ),
    (
        'Create Goods Receipts',
        'inventory.receipts.create',
        'inventory',
        'Create inventory goods receipts',
        TRUE
    ),
    (
        'Approve Goods Receipts',
        'inventory.receipts.approve',
        'inventory',
        'Approve inventory goods receipts',
        TRUE
    ),
    (
        'Post Goods Receipts',
        'inventory.receipts.post',
        'inventory',
        'Post approved receipts into inventory',
        TRUE
    ),
    (
        'View Stock Transfers',
        'inventory.transfers.view',
        'inventory',
        'View warehouse stock transfers',
        TRUE
    ),
    (
        'Manage Stock Transfers',
        'inventory.transfers.manage',
        'inventory',
        'Create and complete warehouse stock transfers',
        TRUE
    )
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    module = VALUES(module),
    description = VALUES(description),
    active = TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions (
    role_id,
    permission_id
)
SELECT
    roles.role_id,
    permissions.permission_id
FROM roles
CROSS JOIN permissions
WHERE roles.name = 'Company Owner'
  AND permissions.module = 'inventory'
  AND permissions.active = TRUE
SQL,
    ],
];