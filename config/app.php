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
    'company_code' => $stringEnvironment(
        'APP_COMPANY_CODE',
        'default'
    ),
    'base_path' => $stringEnvironment(
        'APP_BASE_PATH',
        '/office_app/public'
    ),
];
