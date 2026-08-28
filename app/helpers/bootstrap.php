<?php

declare(strict_types=1);

require_once __DIR__ . '/runtime.php';

$runtimeRequirements = require __DIR__
    . '/../../config/runtime.php';
$minimumPhpVersion = (string) (
    $runtimeRequirements['minimum_php_version']
    ?? '8.1.0'
);
$minimumPhpVersionId = (int) (
    $runtimeRequirements['minimum_php_version_id']
    ?? 80100
);
$requiredExtensions = is_array(
    $runtimeRequirements['required_extensions']
    ?? null
)
    ? $runtimeRequirements['required_extensions']
    : [];
$driverExtensions = is_array(
    $runtimeRequirements[
        'database_driver_extensions'
    ] ?? null
)
    ? $runtimeRequirements[
        'database_driver_extensions'
    ]
    : [];
$configuredDriver = getenv('DB_DRIVER');
$runtimeDriver = is_string($configuredDriver)
    && trim($configuredDriver) !== ''
        ? strtolower(trim($configuredDriver))
        : 'mysql';
$requiredExtensions = array_merge(
    $requiredExtensions,
    is_array($driverExtensions[$runtimeDriver] ?? null)
        ? $driverExtensions[$runtimeDriver]
        : []
);
$requiredExtensions = array_values(
    array_unique($requiredExtensions)
);
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
            && !runtimeExtensionLoaded($extension)
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
    $driverExtensions,
    $configuredDriver,
    $runtimeDriver,
    $runtimeFailure,
    $missingExtensions
);

$composerAutoloaders = [
    __DIR__ . '/../../vendor/autoload.php',
    '/opt/officeapp/vendor/autoload.php',
];

foreach ($composerAutoloaders as $composerAutoloader) {
    if (is_file($composerAutoloader)) {
        require_once $composerAutoloader;
        break;
    }
}

unset($composerAutoloaders, $composerAutoloader);

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
    ini_set('session.gc_maxlifetime', (string) config('session_lifetime_seconds', 28800));
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => appBasePath() === ''
            ? '/'
            : appBasePath(),
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
        $error = (new \App\Services\AppErrorReporter())->report(
            'SYS-UNEXPECTED-001',
            $exception,
            ['route' => $_SERVER['REQUEST_URI'] ?? null]
        );
        http_response_code(500);
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        echo '<h1>'.$escape($error['code']).' — '.$escape($error['title']).'</h1>';
        echo '<p><strong>Cause:</strong> '.$escape($error['cause']).'</p>';
        echo '<p><strong>What to do:</strong> '.$escape($error['suggested_action']).'</p>';
        echo '<p><strong>Reference:</strong> '.$escape($error['incident_reference']).'</p>';
    }
);
