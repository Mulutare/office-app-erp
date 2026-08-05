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

$testRunnerSource = $contents('tests/run.php');

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
        '$migrationLedgerCount === 25'
    ),
    'Main test runner expects twenty-five MySQL migrations'
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