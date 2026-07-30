<?php

declare(strict_types=1);

use App\Services\AttendanceNotificationService;

require_once __DIR__
    . '/../app/helpers/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

try {
    $result = (new AttendanceNotificationService())
        ->queueDue();

    fwrite(
        STDOUT,
        sprintf(
            "Attendance notifications: %d candidates, %d queued, %d skipped.%s",
            $result['candidates'],
            $result['queued'],
            $result['skipped'],
            PHP_EOL
        )
    );
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'Attendance notification dispatch failed: '
        . $exception->getMessage()
        . PHP_EOL
    );
    exit(1);
}
