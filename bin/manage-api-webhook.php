<?php

declare(strict_types=1);

use App\Services\WebhookService;

require_once __DIR__ . '/../app/helpers/bootstrap.php';

$service = new WebhookService();
$action = strtolower((string) ($argv[1] ?? ''));
try {
    $result = match ($action) {
        'create' => $service->create((int) ($argv[2] ?? 0), (int) ($argv[3] ?? 0), (string) ($argv[4] ?? ''), explode(',', (string) ($argv[5] ?? '')), (int) ($argv[6] ?? 0)),
        'rotate' => $service->rotate((int) ($argv[2] ?? 0)),
        'replay' => (function () use ($service, $argv): array { $service->replay((string) ($argv[2] ?? '')); return ['replayed' => true]; })(),
        default => throw new RuntimeException('Action must be create, rotate or replay.'),
    };
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Webhook operation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
