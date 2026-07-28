<?php

declare(strict_types=1);

use App\Database\MigrationRunner;

require_once __DIR__
    . '/../app/helpers/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$driver = databaseDriver()->name();
$directory = __DIR__
    . '/../database/migrations/'
    . $driver;

try {
    $result = (new MigrationRunner(
        db(),
        $driver
    ))->run($directory);

    foreach ($result['applied'] as $version) {
        fwrite(
            STDOUT,
            'Applied migration '
            . $version
            . PHP_EOL
        );
    }

    foreach ($result['skipped'] as $version) {
        fwrite(
            STDOUT,
            'Already applied '
            . $version
            . PHP_EOL
        );
    }

    fwrite(
        STDOUT,
        sprintf(
            'Migration complete: %d applied, %d unchanged.%s',
            count($result['applied']),
            count($result['skipped']),
            PHP_EOL
        )
    );
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'Migration failed: '
        . $exception->getMessage()
        . PHP_EOL
    );

    exit(1);
}
