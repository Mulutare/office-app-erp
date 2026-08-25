<?php

declare(strict_types=1);

$environmentValue = getenv('APP_ENV');
$environment = is_string($environmentValue)
    && in_array(
        strtolower($environmentValue),
        ['development', 'testing', 'production'],
        true
    )
        ? strtolower($environmentValue)
        : 'development';

$booleanEnvironment = static function (
    string $name,
    bool $default
): bool {
    $value = getenv($name);

    if (!is_string($value) || $value === '') {
        return $default;
    }

    $normalized = filter_var(
        $value,
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    );

    return is_bool($normalized)
        ? $normalized
        : $default;
};

$stringEnvironment = static function (
    string $name,
    string $default
): string {
    $value = getenv($name);

    return is_string($value) && trim($value) !== ''
        ? trim($value)
        : $default;
};

$listEnvironment = static function (
    string $name,
    array $default
): array {
    $value = getenv($name);

    if (!is_string($value) || trim($value) === '') {
        return $default;
    }

    $items = array_filter(
        array_map(
            static fn (string $item): string =>
                strtolower(trim($item)),
            explode(',', $value)
        ),
        static fn (string $item): bool => $item !== ''
    );

    return array_values(array_unique($items));
};

$integerEnvironment = static function (string $name, int $default, int $minimum): int {
    $value = getenv($name);
    return is_string($value) && ctype_digit($value)
        ? max($minimum, (int) $value)
        : $default;
};

$sameSite = ucfirst(strtolower(
    $stringEnvironment(
        'SESSION_COOKIE_SAMESITE',
        'Lax'
    )
));

if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
    $sameSite = 'Lax';
}

$secureCookies = $booleanEnvironment(
    'SESSION_COOKIE_SECURE',
    $environment === 'production'
);

if ($sameSite === 'None' && !$secureCookies) {
    $sameSite = 'Lax';
}

return [
    'name' => $stringEnvironment(
        'APP_NAME',
        'OfficeApp ERP'
    ),
    'environment' => $environment,
    'debug' => $booleanEnvironment(
        'APP_DEBUG',
        $environment !== 'production'
    ),
    'timezone' => $stringEnvironment(
        'APP_TIMEZONE',
        'Africa/Addis_Ababa'
    ),
    'session_name' => $stringEnvironment(
        'SESSION_NAME',
        'office_app_session'
    ),
    'session_cookie_secure' => $secureCookies,
    'session_cookie_samesite' => $sameSite,
    'session_lifetime_seconds' => $integerEnvironment('SESSION_LIFETIME_SECONDS', 28800, 300),
    'session_activity_throttle_seconds' => $integerEnvironment('SESSION_ACTIVITY_THROTTLE_SECONDS', 300, 60),
    'company_code' => $stringEnvironment(
        'APP_COMPANY_CODE',
        'default'
    ),
    'base_path' => $stringEnvironment(
        'APP_BASE_PATH',
        '/office_app/public'
    ),
    'web_push' => [
        'enabled' => $booleanEnvironment(
            'WEB_PUSH_ENABLED',
            false
        ),
        'subject' => $stringEnvironment(
            'WEB_PUSH_SUBJECT',
            'mailto:admin@example.test'
        ),
        'public_key' => $stringEnvironment(
            'WEB_PUSH_PUBLIC_KEY',
            ''
        ),
        'private_key' => $stringEnvironment(
            'WEB_PUSH_PRIVATE_KEY',
            ''
        ),
        'allowed_hosts' => $listEnvironment(
            'WEB_PUSH_ALLOWED_HOSTS',
            [
                'fcm.googleapis.com',
                'updates.push.services.mozilla.com',
                'push.services.mozilla.com',
                'web.push.apple.com',
                '*.notify.windows.com',
            ]
        ),
    ],
];
