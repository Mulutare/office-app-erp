<?php

declare(strict_types=1);

use App\Database\ReferenceDataSynchronizer;

require_once __DIR__
    . '/../app/helpers/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$driver = databaseDriver()->name();

if ($driver === 'oracle') {
    fwrite(
        STDOUT,
        'Oracle reference data is managed by versioned migrations.'
        . PHP_EOL
    );

    exit(0);
}

try {
    $result = (new ReferenceDataSynchronizer(
        db(),
        $driver
    ))->run(
        __DIR__ . '/../database/seeds'
    );

    fwrite(
        STDOUT,
        sprintf(
            'Reference data synchronized: %d files, %d statements.%s',
            count($result['files']),
            $result['statementCount'],
            PHP_EOL
        )
    );
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'Reference-data synchronization failed: '
        . $exception->getMessage()
        . PHP_EOL
    );

    exit(1);
}
