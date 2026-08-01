<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$require = static function (string $file, array $needles) use ($root, &$failures): void {
    $text = file_get_contents($root . '/' . $file);
    foreach ($needles as $needle) {
        if (!is_string($text) || !str_contains($text, $needle)) { $failures[] = $file . ' missing ' . $needle; }
    }
};

$require('routes/web.php', [
    "'/api/v1/oauth/token'", "'/api/v1/sales/products'", "'/api/v1/sales/products/{id}'",
    "'/api/v1/sales/customers'", "'/api/v1/sales/orders'", "'/api/v1/sales/orders/{id}/submit'",
    "'/api/v1/sales/orders/{id}/cancel'", "'/api/v1/sales/orders/{id}/payments'", "'/api/v1/sales/receivables'",
    "'/api/v1/sales/reports/summary'",
]);
$require('database/migrations/mysql/031_create_third_party_api.php', [
    'api_clients', 'api_client_scopes', 'api_access_tokens', 'api_idempotency_keys',
    'api_request_logs', 'api_webhook_subscriptions', 'api_webhook_deliveries', 'external_reference',
]);
$require('database/migrations/mysql/032_harden_webhook_delivery_claims.php', [
    'claimed_by', 'claimed_at', 'idx_webhook_delivery_claim',
]);
$require('app/services/ApiSecurityService.php', [
    'password_verify', "hash('sha256'", 'rate_limit_exceeded', 'module_unavailable',
    'permission_denied', 'ip_not_allowed', 'SCOPE_PERMISSIONS',
]);
$require('app/services/WebhookService.php', [
    'hash_hmac', 'X-OfficeApp-Delivery', 'X-OfficeApp-Timestamp', 'X-OfficeApp-Signature',
    'dead_letter', 'API_WEBHOOK_ENCRYPTION_KEY', 'verifySignature',
]);
$require('app/controllers/ApiV1SalesController.php', [
    'HTTP_IDEMPOTENCY_KEY', 'idempotency_conflict', 'X-Correlation-ID',
    'RepositoryFactory::sales()', 'SalesService', 'sales.reports.read',
]);

require_once $root . '/app/helpers/bootstrap.php';
require_once $root . '/app/helpers/router.php';
$router = new Router();
$router->get('/api/v1/router-test/{id}', static function (string $id): void { echo $id; });
ob_start();
$router->dispatch('GET', '/api/v1/router-test/42');
$dynamicRouteOutput = ob_get_clean();
if ($dynamicRouteOutput !== '42') { $failures[] = 'Router dynamic API path resolution failed'; }

foreach ($failures as $failure) { fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL); }
if ($failures === []) { fwrite(STDOUT, "PASS API contract\n"); }
exit($failures === [] ? 0 : 1);
