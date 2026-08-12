<?php

declare(strict_types=1);

$base = rtrim((string) ($argv[1] ?? 'http://127.0.0.1:8080/office_app/public'), '/');
$password = getenv('TEST_ADMIN_PASSWORD') ?: 'OfficeApp-Test!2026';
$checks = 0;
$failures = 0;
$check = static function (bool $ok, string $label) use (&$checks, &$failures): void {
    $checks++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $failures += $ok ? 0 : 1;
};

function request(string $base, string $path, string $jar, string $method = 'GET', array $form = []): array
{
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $method === 'POST' ? http_build_query($form) : null,
    ]);
    $raw = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);
    return ['status' => $status, 'body' => substr($raw, $headerSize)];
}

function authenticate(string $base, string $username, string $password, string $jar): bool
{
    $page = request($base, '/login', $jar);
    if (preg_match('/name="_token"\s+value="([^"]+)"/', $page['body'], $match) !== 1) {
        return false;
    }
    $response = request($base, '/login', $jar, 'POST', [
        '_token' => html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'),
        'login' => $username,
        'password' => $password,
    ]);
    return $response['status'] === 302 && !str_contains($response['body'], 'Invalid');
}

$a = tempnam(sys_get_temp_dir(), 'officeapp-a-');
$b = tempnam(sys_get_temp_dir(), 'officeapp-b-');
$check(is_string($a) && authenticate($base, 'test_tenant_a_admin', $password, $a), 'Company A authorized user authenticates');
$check(is_string($b) && authenticate($base, 'test_tenant_b_user', $password, $b), 'Company B user authenticates');

$aGet = request($base, '/assets-management', $a);
$check($aGet['status'] === 200 && str_contains($aGet['body'], 'Assets'), 'Company A licensed, enabled, authorized GET /assets-management succeeds');
$bGet = request($base, '/assets-management', $b);
$check(in_array($bGet['status'], [403, 404], true), 'Company B unlicensed GET /assets-management is denied');

foreach (['/assets-management', '/assets-management/categories', '/assets-management/1/activate', '/assets-management/1/depreciation/1/post', '/assets-management/1/dispose'] as $path) {
    $response = request($base, $path, $b, 'POST', ['_token' => 'direct-manipulation']);
    $check(in_array($response['status'], [403, 404], true), 'Company B unlicensed POST ' . $path . ' is denied');
}

$roadmap = request($base, '/it-assets', $a);
$check(in_array($roadmap['status'], [403, 404], true), 'Roadmap IT Assets direct URL is unavailable');

@unlink($a);
@unlink($b);
echo sprintf("%d module HTTP checks, %d failures\n", $checks, $failures);
exit($failures === 0 ? 0 : 1);
