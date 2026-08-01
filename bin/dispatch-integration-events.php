<?php

declare(strict_types=1);

use App\Services\IntegrationDispatcherService;

require_once __DIR__ . '/../app/helpers/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

try {
    $result = (new IntegrationDispatcherService())->dispatch(200);
    fwrite(STDOUT, sprintf(
        "Integration events: %d processed, %d failed.%s",
        $result['processed'],
        $result['failed'],
        PHP_EOL
    ));
    exit($result['failed'] === 0 ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Integration dispatch failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
