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

if (!in_array(
    $driver,
    ['mysql', 'oracle'],
    true
)) {
    throw new RuntimeException(
        'The configured database driver is not available.'
    );
}

$defaultPort = $driver === 'oracle'
    ? '1521'
    : '3306';
$portValue = $environmentValue(
    'DB_PORT',
    $defaultPort
);
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

$defaultDatabase = $driver === 'oracle'
    ? ''
    : 'office_app_dev';
$defaultUsername = $driver === 'oracle'
    ? ''
    : 'root';
$defaultCharset = $driver === 'oracle'
    ? 'AL32UTF8'
    : 'utf8mb4';

return [
    'driver' => $driver,
    'host' => $environmentValue('DB_HOST', '127.0.0.1'),
    'port' => $port,
    'service_name' => $environmentValue(
        'DB_SERVICE_NAME'
    ),
    'database' => $environmentValue(
        'DB_DATABASE',
        $defaultDatabase
    ),
    'username' => $environmentValue(
        'DB_USERNAME',
        $defaultUsername
    ),
    'password' => $environmentValue('DB_PASSWORD'),
    'charset' => $environmentValue(
        'DB_CHARSET',
        $defaultCharset
    ),
];
