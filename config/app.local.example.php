<?php

declare(strict_types=1);

/*
 * Copy this file to app.local.php on hosts where environment variables
 * cannot be managed conveniently. app.local.php is ignored by Git.
 */
return [
    'environment' => 'production',
    'debug' => false,
    'base_path' => '',
    'timezone' => 'Africa/Nairobi',
    'session_cookie_secure' => true,
    'session_cookie_samesite' => 'Lax',
    'web_push' => [
        'enabled' => false,
        'subject' =>
            'mailto:erp-admin@example.com',
        'public_key' => '',
        'private_key' => '',
        'allowed_hosts' => [
            'fcm.googleapis.com',
            'updates.push.services.mozilla.com',
            'push.services.mozilla.com',
            'web.push.apple.com',
            '*.notify.windows.com',
        ],
    ],
];
