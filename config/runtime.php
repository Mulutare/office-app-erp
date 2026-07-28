<?php

declare(strict_types=1);

return [
    'minimum_php_version' => '8.4.0',
    'minimum_php_version_id' => 80400,
    'required_extensions' => [
        'PDO',
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
