<?php

declare(strict_types=1);

require_once __DIR__
    . '/../app/helpers/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

if (!class_exists(
    \Minishlink\WebPush\VAPID::class
)) {
    fwrite(
        STDERR,
        'Install Composer dependencies before generating VAPID keys.'
        . PHP_EOL
    );
    exit(1);
}

$keys = \Minishlink\WebPush\VAPID::createVapidKeys();

fwrite(
    STDOUT,
    'WEB_PUSH_ENABLED=true' . PHP_EOL
    . 'WEB_PUSH_SUBJECT=mailto:erp-admin@example.com'
    . PHP_EOL
    . 'WEB_PUSH_PUBLIC_KEY='
    . $keys['publicKey'] . PHP_EOL
    . 'WEB_PUSH_PRIVATE_KEY='
    . $keys['privateKey'] . PHP_EOL
);
