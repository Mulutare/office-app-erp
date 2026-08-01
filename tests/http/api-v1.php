<?php

declare(strict_types=1);

use App\Services\ApiClientService;

require_once __DIR__ . '/../../app/helpers/bootstrap.php';

$baseUrl = rtrim((string) ($argv[1] ?? 'http://127.0.0.1:8080/office_app/public/api/v1'), '/');
$failures = 0;
$check = static function (bool $condition, string $description) use (&$failures): void {
    fwrite($condition ? STDOUT : STDERR, ($condition ? 'PASS ' : 'FAIL ') . $description . PHP_EOL);
    if (!$condition) { $failures++; }
};

/** @param list<string> $headers @return array{status:int,json:array<string,mixed>} */
function apiHttp(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $curl = curl_init($url);
    if ($curl === false) { throw new RuntimeException('Unable to initialize HTTP client.'); }
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $json = is_string($response) ? json_decode($response, true) : null;
    return ['status' => $status, 'json' => is_array($json) ? $json : []];
}

$fixture = db()->query(
    "SELECT memberships.company_id,memberships.user_id
     FROM company_users memberships
     INNER JOIN companies ON companies.company_id=memberships.company_id AND companies.active=TRUE
     INNER JOIN company_user_roles assignment ON assignment.company_id=memberships.company_id AND assignment.user_id=memberships.user_id
     INNER JOIN company_role_permissions grant_row ON grant_row.company_id=assignment.company_id AND grant_row.role_id=assignment.role_id
     INNER JOIN permissions ON permissions.permission_id=grant_row.permission_id AND permissions.code='sales.view'
     INNER JOIN company_modules licensed ON licensed.company_id=companies.company_id AND licensed.enabled=TRUE
     INNER JOIN erp_modules module ON module.module_id=licensed.module_id AND module.code='sales'
     WHERE memberships.active=TRUE LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if (!is_array($fixture)) { fwrite(STDERR, "FAIL API HTTP fixture unavailable.\n"); exit(1); }

$service = new ApiClientService();
$created = $service->create((int) $fixture['company_id'], (int) $fixture['user_id'], 'HTTP API test', ['sales.products.read','sales.orders.read'], (int) $fixture['user_id'], ['127.0.0.1'], 60, 300);
try {
    $basic = base64_encode($created['client_id'] . ':' . $created['client_secret']);
    $tokenResponse = apiHttp('POST', $baseUrl . '/oauth/token', ['Authorization: Basic ' . $basic, 'Content-Type: application/x-www-form-urlencoded'], 'grant_type=client_credentials');
    $token = (string) ($tokenResponse['json']['access_token'] ?? '');
    $check($tokenResponse['status'] === 200 && str_starts_with($token, 'oat_'), 'HTTP client credentials endpoint returns an opaque token');

    $products = apiHttp('GET', $baseUrl . '/sales/products', ['Authorization: Bearer ' . $token]);
    $check($products['status'] === 200 && is_array($products['json']['data'] ?? null), 'HTTP products endpoint returns structured tenant data');
    $firstProduct = $products['json']['data'][0]['product_id'] ?? null;
    if (is_int($firstProduct) || ctype_digit((string) $firstProduct)) {
        $product = apiHttp('GET', $baseUrl . '/sales/products/' . $firstProduct, ['Authorization: Bearer ' . $token]);
        $check($product['status'] === 200 && (int) ($product['json']['data']['product_id'] ?? 0) === (int) $firstProduct, 'Dynamic HTTP product route resolves a tenant-owned resource');
    }

    $invalid = apiHttp('GET', $baseUrl . '/sales/products', ['Authorization: Bearer invalid']);
    $check($invalid['status'] === 401 && ($invalid['json']['error']['code'] ?? null) === 'invalid_token', 'HTTP invalid bearer token returns structured 401');

    $wrongScope = apiHttp('POST', $baseUrl . '/sales/orders', ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Idempotency-Key: http-wrong-scope'], '{}');
    $check($wrongScope['status'] === 403 && ($wrongScope['json']['error']['code'] ?? null) === 'insufficient_scope', 'HTTP write with wrong scope is denied before domain execution');
} finally {
    $service->revoke((string) $created['client_id']);
    db()->prepare('DELETE FROM api_clients WHERE client_identifier=:identifier')->execute(['identifier' => $created['client_id']]);
}

fwrite(STDOUT, sprintf("API HTTP: %d failure(s)%s", $failures, PHP_EOL));
exit($failures === 0 ? 0 : 1);
