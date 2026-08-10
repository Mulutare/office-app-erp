<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$results = [];
$failures = 0;

$check = static function (
    bool $condition,
    string $description
) use (&$results, &$failures): void {
    $results[] = [
        'passed' => $condition,
        'description' => $description,
    ];

    if (!$condition) {
        $failures++;
    }
};

$contents = static function (
    string $path
) use ($root): string {
    $contents = file_get_contents(
        $root . DIRECTORY_SEPARATOR . $path
    );

    if (!is_string($contents)) {
        throw new RuntimeException(
            'Unable to read ' . $path . '.'
        );
    }

    return $contents;
};

$migrationPath =
    'database/migrations/mysql/'
    . '039_professionalize_inventory_operations.php';
$migration = require
    $root
    . DIRECTORY_SEPARATOR
    . $migrationPath;

$check(
    is_array($migration)
    && ($migration['version'] ?? null) === '039'
    && is_callable($migration['preflight'] ?? null)
    && is_array($migration['statements'] ?? null),
    'Migration 039 has a protected migration definition'
);

$migrationSource = $contents($migrationPath);
$requiredMigrationTokens = [
    'CREATE TABLE inventory_operation_types',
    "'receipt'",
    "'internal_transfer'",
    "'delivery'",
    "'adjustment'",
    'default_source_location_id',
    'default_destination_location_id',
    'requires_approval',
    'auto_reserve',
    'allow_partial',
    'create_backorder',
    'ADD COLUMN operation_type_id',
    'fk_inventory_goods_receipt_operation_type',
    'fk_inventory_transfer_operation_type',
    'fk_inventory_adjustment_operation_type',
    'DROP CONSTRAINT ck_inventory_transfer_warehouses',
    'ck_inventory_transfer_line_route',
    'source_location_id',
    'destination_location_id',
];

foreach ($requiredMigrationTokens as $token) {
    $check(
        str_contains($migrationSource, $token),
        'Migration 039 contains: ' . $token
    );
}

$movementMigrationPath =
    'database/migrations/mysql/040_location_aware_stock_movements.php';
$movementMigration = require $root . DIRECTORY_SEPARATOR . $movementMigrationPath;
$movementMigrationSource = $contents($movementMigrationPath);
$check(
    is_array($movementMigration)
    && ($movementMigration['version'] ?? null) === '040'
    && is_callable($movementMigration['preflight'] ?? null),
    'Migration 040 has a protected migration definition'
);
foreach ([
    'source_location_id',
    'destination_location_id',
    'requested_quantity',
    'completed_quantity',
    'ck_inventory_movement_distinct_locations',
    'uq_inventory_movement_idempotency',
] as $token) {
    $check(
        str_contains($movementMigrationSource, $token)
        || str_contains($contents('database/migrations/mysql/034_create_inventory_stock_core.php'), $token),
        'Authoritative movement schema contains: ' . $token
    );
}

$warehouseContractPath =
    'app/repositories/WarehouseRepository.php';
$warehouseMySqlPath =
    'app/repositories/MySql/WarehouseRepository.php';
$warehouseServicePath =
    'app/services/WarehouseManagementService.php';
$warehouseControllerPath =
    'app/controllers/WarehouseController.php';
$locationContractPath =
    'app/repositories/WarehouseLocationRepository.php';
$locationMySqlPath =
    'app/repositories/MySql/WarehouseLocationRepository.php';
$locationServicePath =
    'app/services/WarehouseLocationManagementService.php';
$locationControllerPath =
    'app/controllers/WarehouseLocationController.php';

foreach ([
    $warehouseContractPath,
    $warehouseMySqlPath,
    $warehouseServicePath,
    $warehouseControllerPath,
    $locationContractPath,
    $locationMySqlPath,
    $locationServicePath,
    $locationControllerPath,
    'resources/views/inventory/index.php',
    'resources/views/inventory/warehouses/index.php',
    'resources/views/inventory/warehouses/form.php',
    'resources/views/inventory/locations/index.php',
    'resources/views/inventory/locations/form.php',
] as $path) {
    $check(
        is_file($root . DIRECTORY_SEPARATOR . $path),
        'Warehouse slice contains: ' . $path
    );
}

$warehouseContractSource = $contents(
    $warehouseContractPath
);
$warehouseMySqlSource = $contents($warehouseMySqlPath);
$warehouseServiceSource = $contents($warehouseServicePath);
$warehouseControllerSource = $contents(
    $warehouseControllerPath
);
$locationContractSource = $contents(
    $locationContractPath
);
$locationMySqlSource = $contents($locationMySqlPath);
$locationServiceSource = $contents($locationServicePath);
$locationControllerSource = $contents(
    $locationControllerPath
);
$factorySource = $contents(
    'app/repositories/RepositoryFactory.php'
);
$routeSource = $contents('routes/web.php');
$testRunnerSource = $contents('tests/run.php');

$check(
    str_contains(
        $warehouseMySqlSource,
        'implements WarehouseRepositoryContract'
    ),
    'MySQL WarehouseRepository implements its contract'
);

$check(
    str_contains(
        $warehouseMySqlSource,
        'company_users manager_memberships'
    )
    && str_contains(
        $warehouseMySqlSource,
        'manager_memberships.company_id'
    )
    && str_contains(
        $warehouseMySqlSource,
        'manager_memberships.user_id'
    ),
    'Warehouse listing company-scopes manager identities'
);
foreach ([
    'lockCompany',
    'listForCompany',
    'codeExists',
    'defaultWarehouseId',
    'branchBelongsToCompany',
    'managerBelongsToCompany',
    'activeBranchesForCompany',
    'activeManagersForCompany',
    'createDefaultOperationTypes',
] as $method) {
    $check(
        str_contains(
            $warehouseContractSource,
            'function ' . $method
        ),
        'WarehouseRepository exposes ' . $method
    );
}

$check(
    str_contains(
        $locationMySqlSource,
        'implements WarehouseLocationRepositoryContract'
    ),
    'MySQL WarehouseLocationRepository implements its contract'
);

foreach ([
    'warehouseForUpdate',
    'activeWarehousesForCompany',
    'warehouseBelongsToCompany',
    'listForCompany',
    'codeExists',
    'barcodeExists',
    'parentBelongsToWarehouse',
    'provisionOperationalDefaults',
    'configureDefaultOperationLocations',
    'readinessForCompany',
] as $method) {
    $check(
        str_contains(
            $locationContractSource,
            'function ' . $method
        ),
        'WarehouseLocationRepository exposes ' . $method
    );
}

foreach ([
    "'ROOT'",
    "'INPUT'",
    "'STOCK'",
    "'OUTPUT'",
    "'RETURNS'",
    "'QUARANTINE'",
] as $token) {
    $check(
        str_contains($locationMySqlSource, $token),
        'Operational location provisioning contains: ' . $token
    );
}

foreach ([
    'receipt',
    'internal_transfer',
    'delivery',
    'adjustment',
    'default_source_location_id',
    'default_destination_location_id',
] as $token) {
    $check(
        str_contains($locationMySqlSource, $token),
        'Operation location mapping contains: ' . $token
    );
}

$check(
    str_contains(
        $factorySource,
        'function warehouses()'
    )
    && str_contains(
        $factorySource,
        'new MySqlWarehouseRepository()'
    ),
    'RepositoryFactory exposes MySQL warehouse repositories'
);

$check(
    str_contains(
        $factorySource,
        'function warehouseLocations()'
    )
    && str_contains(
        $factorySource,
        'new MySqlWarehouseLocationRepository()'
    ),
    'RepositoryFactory exposes MySQL warehouse-location repositories'
);

foreach ([
    "'/inventory/warehouses'",
    "'/inventory/warehouses/create'",
    "[\$warehouseController, 'index']",
    "[\$warehouseController, 'create']",
    "[\$warehouseController, 'store']",
    "'/inventory/locations'",
    "'/inventory/locations/create'",
    "'/inventory/locations/provision'",
    "[\$warehouseLocationController, 'index']",
    "[\$warehouseLocationController, 'create']",
    "[\$warehouseLocationController, 'store']",
    "[\$warehouseLocationController, 'provision']",
] as $token) {
    $check(
        str_contains($routeSource, $token),
        'Warehouse routes contain: ' . $token
    );
}

$check(
    str_contains(
        $warehouseControllerSource,
        "'inventory.warehouses.view'"
    ),
    'Warehouse listing requires inventory.warehouses.view'
);

$check(
    substr_count(
        $warehouseControllerSource,
        "'inventory.warehouses.manage'"
    ) >= 2,
    'Warehouse creation requires inventory.warehouses.manage'
);

$check(
    str_contains(
        $locationControllerSource,
        "'inventory.warehouses.view'"
    ),
    'Location listing requires inventory.warehouses.view'
);

$check(
    substr_count(
        $locationControllerSource,
        "'inventory.warehouses.manage'"
    ) >= 3,
    'Location creation and provisioning require inventory.warehouses.manage'
);

$check(
    str_contains(
        $warehouseServiceSource,
        'provisionOperationalDefaults'
    )
    && str_contains(
        $warehouseServiceSource,
        'configureDefaultOperationLocations'
    ),
    'Warehouse creation provisions and maps operational locations'
);

$check(
    str_contains(
        $locationServiceSource,
        "'inventory_warehouse_locations'"
    )
    && str_contains(
        $locationServiceSource,
        "'CREATE'"
    ),
    'Location creation writes a company-scoped audit record'
);

foreach (['RCPT', 'INT', 'DLV', 'ADJ'] as $code) {
    $check(
        str_contains($warehouseServiceSource, "'{$code}'")
        || str_contains($warehouseMySqlSource, "'{$code}'"),
        'Warehouse provisioning contains operation code ' . $code
    );
}

foreach ([
    'receipt',
    'internal_transfer',
    'delivery',
    'adjustment',
] as $kind) {
    $check(
        str_contains($warehouseMySqlSource, $kind),
        'Warehouse provisioning contains operation kind ' . $kind
    );
}

$check(
    str_contains(
        $warehouseServiceSource,
        "'inventory_warehouses'"
    )
    && str_contains(
        $warehouseServiceSource,
        "'CREATE'"
    ),
    'Warehouse creation writes a company-scoped audit record'
);

$check(
    str_contains(
        $testRunnerSource,
        'new WarehouseManagementService()'
    ),
    'Main test runner creates receipt warehouses through the service'
);

$check(
    !str_contains(
        $testRunnerSource,
        'INSERT INTO inventory_operation_types'
    ),
    'Main receipt fixture no longer inserts operation types directly'
);

$check(
    str_contains(
        $testRunnerSource,
        'Warehouse creation provisions eleven Odoo-style operational and virtual locations'
    ),
    'Main test runner verifies operational location provisioning'
);

$check(
    str_contains(
        $testRunnerSource,
        'Warehouse operation types use the operational location routes'
    ),
    'Main test runner verifies operation location mappings'
);

$check(
    substr_count(
        $testRunnerSource,
        "'039'"
    ) >= 1,
    'Main test runner expects migration 039'
);

$check(
    str_contains(
        $testRunnerSource,
        '$migrationLedgerCount === 31'
    ),
    'Main test runner expects thirty-one MySQL migrations'
);

$inventoryRepositorySource = $contents(
    'app/repositories/MySql/InventoryRepository.php'
);
$check(
    str_contains($inventoryRepositorySource, "locations.location_usage = 'internal'")
    && str_contains($inventoryRepositorySource, 'locations.is_virtual = FALSE'),
    'Sales reservation excludes virtual customer/vendor stock after a shortage retry'
);
$check(
    str_contains($inventoryRepositorySource, '$releasedCommitments')
    && str_contains($inventoryRepositorySource, '$commitmentReuse')
    && str_contains($inventoryRepositorySource, 'ON DUPLICATE KEY UPDATE'),
    'A released shortage reservation can reserve the same commitment and allocation after stock is received'
);
$check(
    str_contains($inventoryRepositorySource, 'UPDATE inventory_picking_lines SET source_location_id=:source')
    && !str_contains($inventoryRepositorySource, "DELETE FROM inventory_picking_lines WHERE company_id=:company_id AND picking_id=:picking_id')->execute"),
    'Retry reserves the same existing picking line instead of replacing the picking document'
);
$check(
    str_contains($inventoryRepositorySource, "if((string)\$picking['status']!=='waiting_stock')")
    && str_contains($inventoryRepositorySource, 'Only a delivery waiting for stock can be reserved.'),
    'Reservation retry cannot downgrade a completed delivery to ready'
);

foreach ($results as $result) {
    fwrite(
        STDOUT,
        sprintf(
            '[%s] %s%s',
            $result['passed'] ? 'PASS' : 'FAIL',
            $result['description'],
            PHP_EOL
        )
    );
}

fwrite(
    STDOUT,
    sprintf(
        'Inventory module contract: %d check(s), %d failure(s).%s',
        count($results),
        $failures,
        PHP_EOL
    )
);

exit($failures === 0 ? 0 : 1);
