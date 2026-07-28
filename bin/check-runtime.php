<?php

declare(strict_types=1);

$requirements = require __DIR__
    . '/../config/runtime.php';
$minimumVersion = (string) (
    $requirements['minimum_php_version']
    ?? '8.4.0'
);
$minimumVersionId = (int) (
    $requirements['minimum_php_version_id']
    ?? 80400
);
$requiredExtensions = is_array(
    $requirements['required_extensions']
    ?? null
)
    ? $requirements['required_extensions']
    : [];
$driverExtensions = is_array(
    $requirements[
        'database_driver_extensions'
    ] ?? null
)
    ? $requirements[
        'database_driver_extensions'
    ]
    : [];
$configuredDriver = getenv('DB_DRIVER');
$driver = is_string($configuredDriver)
    && trim($configuredDriver) !== ''
        ? strtolower(trim($configuredDriver))
        : 'mysql';
$requiredExtensions = array_values(
    array_unique(
        array_merge(
            $requiredExtensions,
            is_array($driverExtensions[$driver] ?? null)
                ? $driverExtensions[$driver]
                : []
        )
    )
);
$failures = [];

if (PHP_VERSION_ID < $minimumVersionId) {
    $failures[] = sprintf(
        'PHP %s or newer is required; %s is running.',
        $minimumVersion,
        PHP_VERSION
    );
}

foreach ($requiredExtensions as $extension) {
    if (
        is_string($extension)
        && !extension_loaded($extension)
    ) {
        $failures[] = sprintf(
            'Required PHP extension is missing: %s.',
            $extension
        );
    }
}

echo 'OfficeApp ERP runtime check' . PHP_EOL;
echo 'PHP: ' . PHP_VERSION . PHP_EOL;
echo 'Database driver: ' . $driver . PHP_EOL;

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo 'FAIL ' . $failure . PHP_EOL;
    }

    exit(1);
}

echo 'PASS PHP and required extensions are available.'
    . PHP_EOL;
