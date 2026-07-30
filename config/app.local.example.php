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
];
