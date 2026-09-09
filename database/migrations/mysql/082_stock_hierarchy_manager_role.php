<?php

declare(strict_types=1);

$permissionCodes = [
    'inventory.view',
    'inventory.stock.view',
    'inventory.warehouses.view',
    'inventory.warehouses.use',
    'inventory.stock_requests.view',
    'inventory.stock_requests.create',
    'inventory.stock_requests.process',
    'inventory.stock_requests.issue',
    'inventory.transfers.view',
    'inventory.transfers.dispatch',
    'inventory.transfers.receive',
];

$roleCode = 'stock_hierarchy_manager';
$roleName = 'Stock Hierarchy Manager';
$roleDescription =
    'Operate manager stock replenishment, peer transfers and scoped stock workflows without company-wide warehouse authority.';

return [
    'version' => '082',
    'description' =>
        'Create administrator-grantable Stock Hierarchy Manager role',

    'preflight' => static function (PDO $c) use (
        $permissionCodes,
        $roleCode,
        $roleName,
        $roleDescription
    ): string {
        $quotedPermissions = implode(
            ',',
            array_map(
                static fn (string $code): string => $c->quote($code),
                $permissionCodes
            )
        );

        $permissionCount = (int) $c->query(
            "SELECT COUNT(*)
             FROM permissions
             WHERE active=TRUE
               AND code IN ($quotedPermissions)"
        )->fetchColumn();

        if ($permissionCount !== count($permissionCodes)) {
            throw new RuntimeException(
                'Migration 082 requires all Stock Hierarchy Manager permissions to exist and be active.'
            );
        }

        $roleStatement = $c->prepare(
            'SELECT
                role_id,
                code,
                name,
                description,
                is_system,
                active
             FROM roles
             WHERE code=:code OR name=:name'
        );
        $roleStatement->execute([
            'code' => $roleCode,
            'name' => $roleName,
        ]);

        $roles = $roleStatement->fetchAll(PDO::FETCH_ASSOC);

        if ($roles === []) {
            return 'apply';
        }

        if (
            count($roles) !== 1
            || (string) $roles[0]['code'] !== $roleCode
        ) {
            throw new RuntimeException(
                'Migration 082 found a conflicting Stock Hierarchy Manager role.'
            );
        }

        $role = $roles[0];
        $roleId = (int) $role['role_id'];

        $templateCount = (int) $c->query(
            "SELECT COUNT(*)
             FROM role_permissions rp
             INNER JOIN permissions p
                ON p.permission_id=rp.permission_id
             WHERE rp.role_id={$roleId}
               AND p.code IN ($quotedPermissions)"
        )->fetchColumn();

        $templateExtra = (int) $c->query(
            "SELECT COUNT(*)
             FROM role_permissions rp
             INNER JOIN permissions p
                ON p.permission_id=rp.permission_id
             WHERE rp.role_id={$roleId}
               AND p.code NOT IN ($quotedPermissions)"
        )->fetchColumn();

        $companyCount = (int) $c->query(
            "SELECT COUNT(*)
             FROM companies
             WHERE deleted_at IS NULL"
        )->fetchColumn();

        $companyGrantCount = (int) $c->query(
            "SELECT COUNT(*)
             FROM company_role_permissions crp
             INNER JOIN companies company
                ON company.company_id=crp.company_id
               AND company.deleted_at IS NULL
             INNER JOIN permissions p
                ON p.permission_id=crp.permission_id
             WHERE crp.role_id={$roleId}
               AND p.code IN ($quotedPermissions)"
        )->fetchColumn();

        $companyExtra = (int) $c->query(
            "SELECT COUNT(*)
             FROM company_role_permissions crp
             INNER JOIN companies company
                ON company.company_id=crp.company_id
               AND company.deleted_at IS NULL
             INNER JOIN permissions p
                ON p.permission_id=crp.permission_id
             WHERE crp.role_id={$roleId}
               AND p.code NOT IN ($quotedPermissions)"
        )->fetchColumn();

        if (
            (string) $role['name'] === $roleName
            && (string) ($role['description'] ?? '') ===
                $roleDescription
            && (int) $role['is_system'] === 1
            && (int) $role['active'] === 1
            && $templateCount === count($permissionCodes)
            && $templateExtra === 0
            && $companyGrantCount ===
                ($companyCount * count($permissionCodes))
            && $companyExtra === 0
        ) {
            return 'baseline';
        }

        return 'apply';
    },

    'statements' => [
        <<<'SQL'
INSERT INTO roles (
    name,
    code,
    description,
    is_system,
    active
)
VALUES (
    'Stock Hierarchy Manager',
    'stock_hierarchy_manager',
    'Operate manager stock replenishment, peer transfers and scoped stock workflows without company-wide warehouse authority.',
    TRUE,
    TRUE
)
ON DUPLICATE KEY UPDATE
    name=VALUES(name),
    description=VALUES(description),
    is_system=TRUE,
    active=TRUE
SQL,

        <<<'SQL'
DELETE rp
FROM role_permissions rp
INNER JOIN roles r
    ON r.role_id=rp.role_id
INNER JOIN permissions p
    ON p.permission_id=rp.permission_id
WHERE r.code='stock_hierarchy_manager'
  AND p.code NOT IN (
      'inventory.view',
      'inventory.stock.view',
      'inventory.warehouses.view',
      'inventory.warehouses.use',
      'inventory.stock_requests.view',
      'inventory.stock_requests.create',
      'inventory.stock_requests.process',
      'inventory.stock_requests.issue',
      'inventory.transfers.view',
        'inventory.transfers.dispatch',
      'inventory.transfers.receive'
  )
SQL,

        <<<'SQL'
INSERT IGNORE INTO role_permissions (
    role_id,
    permission_id
)
SELECT
    r.role_id,
    p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.code='stock_hierarchy_manager'
  AND r.active=TRUE
  AND p.active=TRUE
  AND p.code IN (
      'inventory.view',
      'inventory.stock.view',
      'inventory.warehouses.view',
      'inventory.warehouses.use',
      'inventory.stock_requests.view',
      'inventory.stock_requests.create',
      'inventory.stock_requests.process',
      'inventory.stock_requests.issue',
      'inventory.transfers.view',
        'inventory.transfers.dispatch',
      'inventory.transfers.receive'
  )
SQL,

        <<<'SQL'
DELETE crp
FROM company_role_permissions crp
INNER JOIN roles r
    ON r.role_id=crp.role_id
INNER JOIN permissions p
    ON p.permission_id=crp.permission_id
WHERE r.code='stock_hierarchy_manager'
  AND p.code NOT IN (
      'inventory.view',
      'inventory.stock.view',
      'inventory.warehouses.view',
      'inventory.warehouses.use',
      'inventory.stock_requests.view',
      'inventory.stock_requests.create',
      'inventory.stock_requests.process',
      'inventory.stock_requests.issue',
      'inventory.transfers.view',
        'inventory.transfers.dispatch',
      'inventory.transfers.receive'
  )
SQL,

        <<<'SQL'
INSERT IGNORE INTO company_role_permissions (
    company_id,
    role_id,
    permission_id,
    granted_by
)
SELECT
    c.company_id,
    r.role_id,
    p.permission_id,
    NULL
FROM companies c
CROSS JOIN roles r
CROSS JOIN permissions p
WHERE c.deleted_at IS NULL
  AND r.code='stock_hierarchy_manager'
  AND r.active=TRUE
  AND p.active=TRUE
  AND p.code IN (
      'inventory.view',
      'inventory.stock.view',
      'inventory.warehouses.view',
      'inventory.warehouses.use',
      'inventory.stock_requests.view',
      'inventory.stock_requests.create',
      'inventory.stock_requests.process',
      'inventory.stock_requests.issue',
      'inventory.transfers.view',
        'inventory.transfers.dispatch',
      'inventory.transfers.receive'
  )
SQL,
    ],
];