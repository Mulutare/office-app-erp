<?php

declare(strict_types=1);

use App\Database\MigrationRunner;
use App\Database\ReferenceDataSynchronizer;
use App\Database\SqlStatementSplitter;

require_once __DIR__
    . '/../app/helpers/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$confirmation = getenv(
    'OFFICEAPP_INSTALL_CONFIRM'
);

if ($confirmation !== 'INSTALL_EMPTY_DATABASE') {
    $fail(
        'Set OFFICEAPP_INSTALL_CONFIRM=INSTALL_EMPTY_DATABASE'
        . ' to confirm a reviewed fresh installation.'
    );
}

$driver = databaseDriver()->name();
$connection = db();

if ($driver === 'mysql') {
    $tableCount = (int) $connection->query(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()'
    )->fetchColumn();
} elseif ($driver === 'oracle') {
    $tableCount = (int) $connection->query(
        'SELECT COUNT(*) FROM user_tables'
    )->fetchColumn();
} else {
    $fail(
        'No fresh-database installer is available'
        . ' for the configured driver.'
    );
}

if ($tableCount !== 0) {
    $fail(
        'Fresh installation refused because the database'
        . ' is not empty. Use bin/migrate.php for upgrades.'
    );
}

try {
    if ($driver === 'mysql') {
        $migrationDirectory = __DIR__
            . '/../database/migrations';
        $files = glob(
            $migrationDirectory
            . DIRECTORY_SEPARATOR
            . '*.sql'
        );

        if (!is_array($files) || $files === []) {
            throw new RuntimeException(
                'No reviewed MySQL installation migrations were found.'
            );
        }

        sort($files, SORT_STRING);
        $splitter = new SqlStatementSplitter();

        foreach ($files as $file) {
            $sql = file_get_contents($file);

            if (!is_string($sql)) {
                throw new RuntimeException(
                    'Unable to read migration '
                    . basename($file)
                    . '.'
                );
            }

            $statements = $splitter->split($sql);

            if ($statements === []) {
                throw new RuntimeException(
                    'Migration '
                    . basename($file)
                    . ' contains no statements.'
                );
            }

            foreach (
                $statements
                as $index => $statement
            ) {
                try {
                    $connection->exec($statement);
                } catch (Throwable $exception) {
                    throw new RuntimeException(
                        sprintf(
                            'Fresh installation failed in %s'
                            . ' at statement %d.',
                            basename($file),
                            $index + 1
                        ),
                        0,
                        $exception
                    );
                }
            }
        }
    }

    $migrationResult = (new MigrationRunner(
        $connection,
        $driver
    ))->run(
        __DIR__
        . '/../database/migrations/'
        . $driver
    );

    $referenceFileCount = 0;

    if ($driver === 'mysql') {
        $referenceResult = (
            new ReferenceDataSynchronizer(
                $connection,
                $driver
            )
        )->run(
            __DIR__ . '/../database/seeds'
        );
        $referenceFileCount = count(
            $referenceResult['files']
        );
    }
} catch (Throwable $exception) {
    $fail(
        $exception->getMessage()
        . ' Recreate the empty database before retrying;'
        . ' database DDL may already have committed.'
    );
}

fwrite(
    STDOUT,
    sprintf(
        'Fresh database installed: %d migrations applied,'
        . ' %d baselined, %d reference files synchronized.%s',
        count($migrationResult['applied']),
        count($migrationResult['baselined']),
        $referenceFileCount,
        PHP_EOL
    )
);
