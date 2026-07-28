<?php

declare(strict_types=1);

/**
 * Bounded HTTP regression checks for the central access gate.
 *
 * Run against the isolated test database and test fixture accounts.
 */

$baseUrl = rtrim(
    (string) (
        $argv[1]
        ?? 'http://127.0.0.1:8080/office_app/public'
    ),
    '/'
);
$password = (string) (
    getenv('TEST_ADMIN_PASSWORD')
    ?: 'OfficeApp-Test!2026'
);
$results = [];
$failures = 0;

$check = static function (
    bool $condition,
    string $description
) use (&$results, &$failures): void {
    $results[] = [
        'passed' => $condition,
        'description' => $description,
    ];

    if (!$condition) {
        $failures++;
    }
};

/**
 * @param array<string, string> $cookies
 * @param array<string, string> $form
 *
 * @return array{
 *     status: int,
 *     location: string,
 *     body: string
 * }
 */
function httpRequest(
    string $baseUrl,
    string $path,
    array &$cookies,
    string $method = 'GET',
    array $form = []
): array {
    $headers = [
        'Accept: text/html,application/json',
        'Connection: close',
    ];

    if ($cookies !== []) {
        $cookiePairs = [];

        foreach ($cookies as $name => $value) {
            $cookiePairs[] = $name . '=' . $value;
        }

        $headers[] = 'Cookie: ' . implode('; ', $cookiePairs);
    }

    $content = '';

    if ($method === 'POST') {
        $content = http_build_query($form);
        $headers[] =
            'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: '
            . strlen($content);
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 8,
            'protocol_version' => 1.1,
        ],
    ]);

    $body = @file_get_contents(
        $baseUrl . $path,
        false,
        $context
    );
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    $location = '';

    foreach ($responseHeaders as $header) {
        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d{3})/',
                $header,
                $matches
            ) === 1
        ) {
            $status = (int) $matches[1];
        }

        if (
            stripos($header, 'Location:') === 0
        ) {
            $location = trim(substr($header, 9));
        }

        if (
            preg_match(
                '/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i',
                $header,
                $matches
            ) === 1
        ) {
            $cookies[$matches[1]] = $matches[2];
        }
    }

    return [
        'status' => $status,
        'location' => $location,
        'body' => is_string($body) ? $body : '',
    ];
}

/**
 * @param array<string, string> $cookies
 *
 * @return array{
 *     status: int,
 *     location: string,
 *     body: string
 * }
 */
function login(
    string $baseUrl,
    string $username,
    string $password,
    array &$cookies
): array {
    $loginPage = httpRequest(
        $baseUrl,
        '/login',
        $cookies
    );

    if (
        preg_match(
            '/name="_token"\s+value="([^"]+)"/',
            $loginPage['body'],
            $matches
        ) !== 1
    ) {
        return [
            'status' => 0,
            'location' => '',
            'body' => 'CSRF token was not found.',
        ];
    }

    return httpRequest(
        $baseUrl,
        '/login',
        $cookies,
        'POST',
        [
            '_token' => html_entity_decode(
                $matches[1],
                ENT_QUOTES,
                'UTF-8'
            ),
            'login' => $username,
            'password' => $password,
        ]
    );
}

$publicCookies = [];
$diagnostics = httpRequest(
    $baseUrl,
    '/diagnostics/user-model',
    $publicCookies
);
$check(
    $diagnostics['status'] === 404,
    'Public diagnostics route returns 404'
);

$publicCookies = [];
$root = httpRequest(
    $baseUrl,
    '/',
    $publicCookies
);
$check(
    $root['status'] === 302
    && str_ends_with(
        $root['location'],
        '/login'
    ),
    'Public root redirects to login'
);
$check(
    stripos($root['body'], 'database') === false
    && stripos($root['body'], 'office_app') === false,
    'Public root exposes no database metadata'
);

$restrictedCookies = [];
$restrictedLogin = login(
    $baseUrl,
    'test_no_dashboard',
    $password,
    $restrictedCookies
);
$check(
    $restrictedLogin['status'] === 302
    && str_ends_with(
        $restrictedLogin['location'],
        '/dashboard'
    ),
    'Restricted test user authenticates'
);
$restrictedDashboard = httpRequest(
    $baseUrl,
    '/dashboard',
    $restrictedCookies
);
$check(
    $restrictedDashboard['status'] === 403,
    'Dashboard denies a user without dashboard.view'
);

$passwordCookies = [];
$passwordLogin = login(
    $baseUrl,
    'test_password_change',
    $password,
    $passwordCookies
);
$check(
    $passwordLogin['status'] === 302
    && str_ends_with(
        $passwordLogin['location'],
        '/dashboard'
    ),
    'Password-change test user authenticates'
);
$passwordDashboard = httpRequest(
    $baseUrl,
    '/dashboard',
    $passwordCookies
);
$check(
    $passwordDashboard['status'] === 302
    && str_ends_with(
        $passwordDashboard['location'],
        '/change-password'
    ),
    'Forced-password account is redirected by the access gate'
);
$passwordForm = httpRequest(
    $baseUrl,
    '/change-password',
    $passwordCookies
);
$check(
    $passwordForm['status'] === 200,
    'Forced-password account can open the change-password form'
);

foreach ($results as $result) {
    echo $result['passed'] ? 'PASS ' : 'FAIL ';
    echo $result['description'];
    echo PHP_EOL;
}

echo PHP_EOL;
echo sprintf(
    '%d checks, %d failures',
    count($results),
    $failures
);
echo PHP_EOL;

exit($failures === 0 ? 0 : 1);
