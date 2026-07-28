<?php

declare(strict_types=1);

$environmentValue = static function (
    string $name,
    string $default = ''
): string {
    $value = getenv($name);

    return is_string($value) && trim($value) !== ''
        ? trim($value)
        : $default;
};

$driver = strtolower(
    $environmentValue('DB_DRIVER', 'mysql')
);

if ($driver !== 'mysql') {
    throw new RuntimeException(
        'The configured database driver is not available.'
    );
}

$portValue = $environmentValue('DB_PORT', '3306');
$port = filter_var(
    $portValue,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
            'max_range' => 65535,
        ],
    ]
);

if (!is_int($port)) {
    throw new RuntimeException(
        'The configured database port is invalid.'
    );
}

return [
    'driver' => $driver,
    'host' => $environmentValue('DB_HOST', '127.0.0.1'),
    'port' => $port,
    'database' => $environmentValue(
        'DB_DATABASE',
        'office_app_dev'
    ),
    'username' => $environmentValue(
        'DB_USERNAME',
        'root'
    ),
    'password' => $environmentValue('DB_PASSWORD'),
    'charset' => $environmentValue(
        'DB_CHARSET',
        'utf8mb4'
    ),
];
