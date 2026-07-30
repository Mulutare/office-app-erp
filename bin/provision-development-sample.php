<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\DevelopmentSampleCompanyService;

require_once __DIR__
    . '/../app/helpers/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

if (config('environment') !== 'development') {
    fwrite(
        STDERR,
        'Development sample provisioning is disabled outside development.'
        . PHP_EOL
    );

    exit(1);
}

$adminUsername = getenv('DEV_ADMIN_USERNAME');
$adminUsername = is_string($adminUsername)
    && trim($adminUsername) !== ''
        ? strtolower(trim($adminUsername))
        : 'admin';
$administrator = (new User())
    ->findForAuthentication($adminUsername);

if (
    $administrator === null
    || empty($administrator['is_platform_admin'])
) {
    fwrite(
        STDERR,
        'The configured development platform administrator was not found.'
        . PHP_EOL
    );

    exit(1);
}

try {
    $result = (
        new DevelopmentSampleCompanyService()
    )->create(
        (int) $administrator['user_id']
    );
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'Sample provisioning failed: '
        . $exception->getMessage()
        . PHP_EOL
    );

    exit(1);
}

fwrite(
    STDOUT,
    PHP_EOL
    . 'Development sample company created.'
    . PHP_EOL
    . 'Company code: '
    . $result['companyCode']
    . PHP_EOL
    . PHP_EOL
    . 'Company owner username: '
    . $result['ownerUsername']
    . PHP_EOL
    . 'Company owner temporary password: '
    . $result['ownerTemporaryPassword']
    . PHP_EOL
    . PHP_EOL
    . 'Employee username: '
    . $result['employeeUsername']
    . PHP_EOL
    . 'Employee temporary password: '
    . $result['employeeTemporaryPassword']
    . PHP_EOL
    . PHP_EOL
    . 'Save these temporary passwords now. They are not stored in plaintext'
    . ' and both accounts must change password at first login.'
    . PHP_EOL
);
