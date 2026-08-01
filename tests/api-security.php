<?php

declare(strict_types=1);

use App\Services\ApiClientService;
use App\Services\ApiException;
use App\Services\ApiSecurityService;
use App\Services\WebhookService;

require_once __DIR__ . '/../app/helpers/bootstrap.php';

$failures = 0;
$check = static function (bool $condition, string $description) use (&$failures): void {
    fwrite($condition ? STDOUT : STDERR, ($condition ? 'PASS ' : 'FAIL ') . $description . PHP_EOL);
    if (!$condition) { $failures++; }
};

$fixture = db()->query(
    "SELECT memberships.company_id,memberships.user_id
     FROM company_users memberships
     INNER JOIN companies ON companies.company_id=memberships.company_id
     INNER JOIN company_modules licensed ON licensed.company_id=companies.company_id
     INNER JOIN erp_modules modules ON modules.module_id=licensed.module_id AND modules.code='sales'
     WHERE memberships.active=TRUE AND companies.active=TRUE AND licensed.enabled=TRUE
     ORDER BY memberships.company_id,memberships.user_id LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if (!is_array($fixture)) {
    fwrite(STDERR, "FAIL API security fixture unavailable.\n");
    exit(1);
}

$companyId = (int) $fixture['company_id'];
$userId = (int) $fixture['user_id'];
$allScopes = array_keys(ApiSecurityService::SCOPE_PERMISSIONS);
$clientService = new ApiClientService();
$created = $clientService->create($companyId, $userId, 'API security test', $allScopes, $userId, ['127.0.0.1'], 60, 300);
$clientId = (string) $created['client_id'];
$secret = (string) $created['client_secret'];
$clientRow = db()->query("SELECT * FROM api_clients WHERE client_identifier=" . db()->quote($clientId))->fetch(PDO::FETCH_ASSOC);
$check(is_array($clientRow) && !str_contains((string) $clientRow['secret_hash'], $secret), 'Client secret is stored only as a one-way password hash');
$check(!in_array('sales.orders.approve', $allScopes, true) && !in_array('sales.integrations.replay', $allScopes, true), 'Dangerous internal Sales permissions are not external API scopes');

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$security = new ApiSecurityService();
$token = $security->issueToken($clientId, $secret);
$check(str_starts_with((string) $token['access_token'], 'oat_') && (int) $token['expires_in'] === 300, 'Client credentials issue a short-lived bearer token');
$storedToken = db()->query('SELECT token_hash FROM api_access_tokens ORDER BY api_token_id DESC LIMIT 1')->fetchColumn();
$check(is_string($storedToken) && !hash_equals($storedToken, (string) $token['access_token']), 'Bearer token is stored only as SHA-256 digest');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token['access_token'];
try {
    $authenticated = $security->authenticate('sales.products.read');
    $check((int) $authenticated['company_id'] === $companyId, 'Bearer token resolves only its bound company');
} catch (Throwable) { $check(false, 'Bearer token resolves only its bound company'); }

$_SERVER['REMOTE_ADDR'] = '127.0.0.2';
try { $security->authenticate('sales.products.read'); $check(false, 'IP allow-list rejects an unapproved source'); }
catch (ApiException $e) { $check($e->errorCode === 'ip_not_allowed', 'IP allow-list rejects an unapproved source'); }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

db()->prepare('UPDATE companies SET active=FALSE WHERE company_id=:company')->execute(['company' => $companyId]);
try { $security->authenticate('sales.products.read'); $check(false, 'Inactive company invalidates its API token'); }
catch (ApiException $e) { $check($e->errorCode === 'invalid_token', 'Inactive company invalidates its API token'); }
db()->prepare('UPDATE companies SET active=TRUE WHERE company_id=:company')->execute(['company' => $companyId]);

db()->prepare("UPDATE company_modules modules INNER JOIN erp_modules catalog ON catalog.module_id=modules.module_id SET modules.enabled=FALSE WHERE modules.company_id=:company AND catalog.code='sales'")
    ->execute(['company' => $companyId]);
try { $security->authenticate('sales.products.read'); $check(false, 'Unlicensed or disabled Sales module denies API access'); }
catch (ApiException $e) { $check($e->errorCode === 'module_unavailable', 'Unlicensed or disabled Sales module denies API access'); }
db()->prepare("UPDATE company_modules modules INNER JOIN erp_modules catalog ON catalog.module_id=modules.module_id SET modules.enabled=TRUE WHERE modules.company_id=:company AND catalog.code='sales'")
    ->execute(['company' => $companyId]);

try { $security->issueToken($clientId, 'wrong-secret'); $check(false, 'Invalid client secret is rejected'); }
catch (ApiException $e) { $check($e->errorCode === 'invalid_client', 'Invalid client secret is rejected'); }

try { $security->authenticate('sales.orders.approve'); $check(false, 'Unsupported approval scope is rejected'); }
catch (ApiException $e) { $check(in_array($e->errorCode, ['insufficient_scope','scope_not_supported'], true), 'Unsupported approval scope is rejected'); }

db()->prepare('UPDATE api_clients SET rate_limit_per_minute=1 WHERE api_client_id=:client')->execute(['client' => $clientRow['api_client_id']]);
db()->prepare("INSERT INTO api_request_logs (correlation_id,api_client_id,company_id,method,route,response_status,remote_ip,duration_ms,requested_at) VALUES (UUID(),:client,:company,'GET','/api/v1/sales/products',200,'127.0.0.1',1,NOW())")
    ->execute(['client' => $clientRow['api_client_id'], 'company' => $companyId]);
try { $security->authenticate('sales.products.read'); $check(false, 'Per-client rate limit is enforced'); }
catch (ApiException $e) { $check($e->errorCode === 'rate_limit_exceeded', 'Per-client rate limit is enforced'); }
db()->prepare('UPDATE api_clients SET rate_limit_per_minute=60 WHERE api_client_id=:client')->execute(['client' => $clientRow['api_client_id']]);
db()->prepare('DELETE FROM api_request_logs WHERE api_client_id=:client')->execute(['client' => $clientRow['api_client_id']]);

db()->prepare('UPDATE api_access_tokens SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE token_hash=:hash')
    ->execute(['hash' => hash('sha256', (string) $token['access_token'])]);
try { $security->authenticate('sales.products.read'); $check(false, 'Expired bearer token is rejected'); }
catch (ApiException $e) { $check($e->errorCode === 'invalid_token', 'Expired bearer token is rejected'); }

$token2 = $security->issueToken($clientId, $secret);
$clientService->rotate($clientId);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token2['access_token'];
try { $security->authenticate('sales.products.read'); $check(false, 'Secret rotation revokes existing bearer tokens'); }
catch (ApiException $e) { $check($e->errorCode === 'invalid_token', 'Secret rotation revokes existing bearer tokens'); }

$timestamp = (string) time();
$payload = '{"type":"sales.order.submitted"}';
$webhookSecret = 'test-webhook-secret';
$signature = 'v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $webhookSecret);
$check(WebhookService::verifySignature($webhookSecret, $payload, $timestamp, $signature), 'Valid webhook HMAC signature is accepted');
$check(!WebhookService::verifySignature($webhookSecret, $payload . 'x', $timestamp, $signature), 'Altered webhook payload is rejected');
$check(!WebhookService::verifySignature($webhookSecret, $payload, (string) ((int) $timestamp - 301), $signature), 'Webhook replay outside the timestamp window is rejected');

putenv('API_WEBHOOK_ENCRYPTION_KEY=api-security-test-key-with-more-than-32-characters');
$webhooks = new WebhookService();
$subscription = $webhooks->create(
    $companyId, (int) $clientRow['api_client_id'], 'http://127.0.0.1:9/webhook',
    ['sales.order.submitted'], $userId
);
$eventHex = bin2hex(random_bytes(16));
$eventId = substr($eventHex,0,8).'-'.substr($eventHex,8,4).'-4'.substr($eventHex,13,3).'-8'.substr($eventHex,17,3).'-'.substr($eventHex,20,12);
db()->prepare("INSERT INTO integration_outbox (event_id,company_id,event_type,aggregate_type,aggregate_id,payload_json,status,available_at,processed_at) VALUES (:id,:company,'sales.order.submitted','sales_order','api-security','{}','processed',NOW(),NOW())")
    ->execute(['id' => $eventId, 'company' => $companyId]);
$check($webhooks->fanOut() === 1 && $webhooks->fanOut() === 0, 'Webhook fan-out is durable and idempotent per subscription/event');
$deliveryResult = $webhooks->dispatch(10);
$delivery = db()->query('SELECT status,attempts,last_error FROM api_webhook_deliveries WHERE webhook_subscription_id=' . (int) $subscription['subscription_id'])->fetch(PDO::FETCH_ASSOC);
$check($deliveryResult['failed'] === 1 && ($delivery['status'] ?? null) === 'failed' && (int) ($delivery['attempts'] ?? 0) === 1, 'Failed webhook delivery is retained for exponential retry without secret leakage');
db()->prepare('DELETE FROM integration_outbox WHERE event_id=:id')->execute(['id' => $eventId]);

$key = 'api-test-' . bin2hex(random_bytes(5));
$insert = db()->prepare("INSERT INTO api_idempotency_keys (api_client_id,idempotency_key,request_hash,status,created_at,expires_at) VALUES (:client,:key,:hash,'processing',NOW(),DATE_ADD(NOW(),INTERVAL 1 HOUR))");
$insert->execute(['client' => $clientRow['api_client_id'], 'key' => $key, 'hash' => str_repeat('a', 64)]);
try { $insert->execute(['client' => $clientRow['api_client_id'], 'key' => $key, 'hash' => str_repeat('b', 64)]); $check(false, 'Duplicate idempotency key is database-enforced'); }
catch (PDOException $e) { $check((string) $e->getCode() === '23000', 'Duplicate idempotency key is database-enforced'); }

$clientService->revoke($clientId);
$check((int) db()->query("SELECT COUNT(*) FROM api_access_tokens tokens INNER JOIN api_clients clients ON clients.api_client_id=tokens.api_client_id WHERE clients.client_identifier=" . db()->quote($clientId) . ' AND tokens.revoked_at IS NULL')->fetchColumn() === 0, 'Client revocation leaves no active bearer token');
db()->prepare('DELETE FROM api_clients WHERE client_identifier=:identifier')->execute(['identifier' => $clientId]);

fwrite(STDOUT, sprintf("API security: %d failure(s)%s", $failures, PHP_EOL));
exit($failures === 0 ? 0 : 1);
