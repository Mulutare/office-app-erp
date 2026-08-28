<?php

declare(strict_types=1);

use App\Services\IntegrationDispatcherService;

require_once __DIR__ . '/../app/helpers/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$positiveInteger = static function (
    string $name,
    int $default,
    int $minimum,
    int $maximum
): int {
    $value = getenv($name);

    if (
        !is_string($value)
        || preg_match('/^\d+$/', $value) !== 1
    ) {
        return $default;
    }

    return max($minimum, min($maximum, (int) $value));
};

$batchSize = $positiveInteger(
    'INTEGRATION_WORKER_BATCH_SIZE',
    200,
    1,
    1000
);

$idleSeconds = $positiveInteger(
    'INTEGRATION_WORKER_IDLE_SECONDS',
    5,
    1,
    300
);

$errorSeconds = $positiveInteger(
    'INTEGRATION_WORKER_ERROR_SECONDS',
    15,
    1,
    300
);

$running = true;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);

    pcntl_signal(
        SIGTERM,
        static function () use (&$running): void {
            $running = false;
        }
    );

    pcntl_signal(
        SIGINT,
        static function () use (&$running): void {
            $running = false;
        }
    );
}

fwrite(
    STDOUT,
    sprintf(
        "Integration worker started: batch=%d, idle=%ds, error=%ds.%s",
        $batchSize,
        $idleSeconds,
        $errorSeconds,
        PHP_EOL
    )
);

while ($running) {
    try {
        $result = (
            new IntegrationDispatcherService()
        )->dispatch($batchSize);

        $processed = (int) ($result['processed'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);
        $handled = $processed + $failed;

        if ($handled > 0) {
            fwrite(
                STDOUT,
                sprintf(
                    "Integration events: %d processed, %d failed.%s",
                    $processed,
                    $failed,
                    PHP_EOL
                )
            );
        }

        if ($handled < $batchSize && $running) {
            sleep($idleSeconds);
        }
    } catch (Throwable $exception) {
        fwrite(
            STDERR,
            sprintf(
                "Integration worker error: %s: %s%s",
                $exception::class,
                $exception->getMessage(),
                PHP_EOL
            )
        );

        $driverCode = $exception instanceof PDOException
            ? (int) ($exception->errorInfo[1] ?? 0)
            : 0;
        if (
            $exception instanceof PDOException
            && in_array($driverCode, [2006, 2013], true)
        ) {
            fwrite(
                STDERR,
                'Integration worker lost its database connection; exiting for supervised restart.' . PHP_EOL
            );
            exit(1);
        }

        if ($running) {
            sleep($errorSeconds);
        }
    }
}

fwrite(STDOUT, 'Integration worker stopped.' . PHP_EOL);

exit(0);
