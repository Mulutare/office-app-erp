<?php

declare(strict_types=1);
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/view.php';

date_default_timezone_set(
    (string) config('timezone', 'UTC')
);

session_name(
    (string) config('session_name', 'office_app_session')
);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ((bool) config('debug', false)) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
}

set_exception_handler(
    static function (Throwable $exception): void {
        error_log(
            sprintf(
                '[%s] %s in %s:%d',
                date('Y-m-d H:i:s'),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine()
            )
        );

        http_response_code(500);

        if ((bool) config('debug', false)) {
            echo '<h1>Application Error</h1>';
            echo '<pre>';
            echo htmlspecialchars(
                $exception->__toString(),
                ENT_QUOTES,
                'UTF-8'
            );
            echo '</pre>';

            return;
        }

        echo '<h1>OfficeApp ERP</h1>';
        echo '<p>An unexpected application error occurred.</p>';
    }
);