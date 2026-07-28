<?php

declare(strict_types=1);

$runtimeRequirements = require __DIR__
    . '/../../config/runtime.php';
$minimumPhpVersion = (string) (
    $runtimeRequirements['minimum_php_version']
    ?? '8.4.0'
);
$minimumPhpVersionId = (int) (
    $runtimeRequirements['minimum_php_version_id']
    ?? 80400
);
$requiredExtensions = is_array(
    $runtimeRequirements['required_extensions']
    ?? null
)
    ? $runtimeRequirements['required_extensions']
    : [];
$runtimeFailure = null;

if (PHP_VERSION_ID < $minimumPhpVersionId) {
    $runtimeFailure = sprintf(
        'OfficeApp ERP requires PHP %s or newer.'
        . ' Current runtime: %s.',
        $minimumPhpVersion,
        PHP_VERSION
    );
} else {
    $missingExtensions = [];

    foreach ($requiredExtensions as $extension) {
        if (
            is_string($extension)
            && !extension_loaded($extension)
        ) {
            $missingExtensions[] = $extension;
        }
    }

    if ($missingExtensions !== []) {
        $runtimeFailure = sprintf(
            'OfficeApp ERP is missing required PHP'
            . ' extensions: %s.',
            implode(', ', $missingExtensions)
        );
    }
}

if (is_string($runtimeFailure)) {
    error_log(
        '[runtime] requirements_failed php='
        . PHP_VERSION
    );

    if (PHP_SAPI === 'cli') {
        fwrite(
            STDERR,
            $runtimeFailure . PHP_EOL
        );
    } else {
        http_response_code(500);
        header(
            'Content-Type: text/plain; charset=UTF-8'
        );
        echo $runtimeFailure;
    }

    exit(1);
}

unset(
    $runtimeRequirements,
    $minimumPhpVersion,
    $minimumPhpVersionId,
    $requiredExtensions,
    $runtimeFailure,
    $missingExtensions
);

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/view.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/request.php';
require_once __DIR__ . '/csrf.php';
date_default_timezone_set(
    (string) config('timezone', 'UTC')
);

session_name(
    (string) config(
        'session_name',
        'office_app_session'
    )
);

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/office_app/public',
        'domain' => '',
        'secure' => (bool) config(
            'session_cookie_secure',
            false
        ),
        'httponly' => true,
        'samesite' => (string) config(
            'session_cookie_samesite',
            'Lax'
        ),
    ]);

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
        try {
            $incidentId = bin2hex(random_bytes(8));
        } catch (Throwable $randomException) {
            $incidentId = substr(
                hash(
                    'sha256',
                    microtime(true)
                    . $exception->getFile()
                    . $exception->getLine()
                ),
                0,
                16
            );
        }

        error_log(
            sprintf(
                '[%s] incident=%s class=%s code=%s file=%s line=%d',
                date('Y-m-d H:i:s'),
                $incidentId,
                $exception::class,
                preg_replace(
                    '/[^A-Za-z0-9_.-]/',
                    '',
                    (string) $exception->getCode()
                ),
                $exception->getFile(),
                $exception->getLine()
            )
        );

        http_response_code(500);

        if ((bool) config('debug', false)) {
            echo '<h1>Application Error</h1>';
            echo '<p>Incident reference: <code>';
            echo htmlspecialchars(
                $incidentId,
                ENT_QUOTES,
                'UTF-8'
            );
            echo '</code></p>';
            echo '<p>Review the application log for the'
                . ' exception class and source location.</p>';

            return;
        }

        echo '<h1>OfficeApp ERP</h1>';
        echo '<p>An unexpected application error occurred.'
            . ' Reference: ';
        echo htmlspecialchars(
            $incidentId,
            ENT_QUOTES,
            'UTF-8'
        );
        echo '.</p>';
    }
);
