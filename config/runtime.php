<?php

declare(strict_types=1);

return [
    'minimum_php_version' => '8.1.0',
    'minimum_php_version_id' => 80100,
    'required_extensions' => [
        'PDO',
        'curl',
        'mbstring',
        'openssl',
        'session',
        'Zend OPcache',
    ],
    'database_driver_extensions' => [
        'mysql' => [
            'pdo_mysql',
        ],
        'oracle' => [
            'pdo_oci',
        ],
    ],
];
