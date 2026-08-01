<?php

declare(strict_types=1);

use App\Services\WebhookService;

require_once __DIR__ . '/../app/helpers/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

try {
    $result = (new WebhookService())->dispatch(100);
    fwrite(STDOUT, sprintf("API webhooks: %d delivered, %d failed.%s", $result['delivered'], $result['failed'], PHP_EOL));
    exit($result['failed'] === 0 ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Webhook dispatch failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
