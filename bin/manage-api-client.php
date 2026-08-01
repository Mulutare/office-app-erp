<?php

declare(strict_types=1);

use App\Services\ApiClientService;

require_once __DIR__ . '/../app/helpers/bootstrap.php';

$action = strtolower((string) ($argv[1] ?? ''));
$service = new ApiClientService();
try {
    if ($action === 'create') {
        if (count($argv) < 7) {
            throw new RuntimeException('Usage: create COMPANY_ID SERVICE_USER_ID NAME CREATED_BY SCOPE[,SCOPE] [IP[,IP]]');
        }
        $result = $service->create(
            (int) $argv[2], (int) $argv[3], (string) $argv[4],
            explode(',', (string) $argv[6]), (int) $argv[5],
            isset($argv[7]) ? explode(',', (string) $argv[7]) : []
        );
        fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } elseif ($action === 'rotate') {
        fwrite(STDOUT, json_encode($service->rotate((string) ($argv[2] ?? '')), JSON_PRETTY_PRINT) . PHP_EOL);
    } elseif ($action === 'revoke') {
        $service->revoke((string) ($argv[2] ?? ''));
        fwrite(STDOUT, "API client revoked.\n");
    } elseif ($action === 'revoke-token') {
        $service->revokeToken((string) ($argv[2] ?? ''));
        fwrite(STDOUT, "API token revoked.\n");
    } else {
        throw new RuntimeException('Action must be create, rotate, revoke or revoke-token.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'API client operation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
