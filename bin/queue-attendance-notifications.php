<?php

declare(strict_types=1);

use App\Services\AttendanceNotificationService;
use App\Services\AttendancePushService;

require_once __DIR__
    . '/../app/helpers/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

try {
    $result = (new AttendanceNotificationService())
        ->queueDue();
    $push = (new AttendancePushService())
        ->dispatchPending();

    fwrite(
        STDOUT,
        sprintf(
            "Attendance notifications: %d candidates, %d queued, %d skipped. Web Push: %s, %d delivered, %d retrying, %d failed.%s",
            $result['candidates'],
            $result['queued'],
            $result['skipped'],
            $push['configured']
                ? $push['candidates'] . ' candidates'
                : 'not configured',
            $push['delivered'],
            $push['retrying'],
            $push['failed'],
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
