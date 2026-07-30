<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Applies additive, repeatable MySQL reference-data seeds in numeric order.
 */
final class ReferenceDataSynchronizer
{
    private SqlStatementSplitter $splitter;

    public function __construct(
        private PDO $connection,
        private string $driver,
        ?SqlStatementSplitter $splitter = null
    ) {
        if ($this->driver !== 'mysql') {
            throw new RuntimeException(
                'Reference-data synchronization is only available for MySQL-compatible databases.'
            );
        }

        $this->splitter = $splitter
            ?? new SqlStatementSplitter();
    }

    /**
     * @return array{
     *     files: list<string>,
     *     statementCount: int
     * }
     */
    public function run(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new RuntimeException(
                'The reference-data directory does not exist.'
            );
        }

        $files = glob(
            rtrim($directory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . '*.sql'
        );

        if (!is_array($files)) {
            throw new RuntimeException(
                'The reference-data directory could not be read.'
            );
        }

        sort($files, SORT_STRING);
        $appliedFiles = [];
        $statementCount = 0;
        $currentFile = '';
        $currentStatement = 0;

        try {
            $this->connection->beginTransaction();

            foreach ($files as $file) {
                $currentFile = basename($file);
                $currentStatement = 0;
                $sql = file_get_contents($file);

                if (!is_string($sql)) {
                    throw new RuntimeException(
                        'A reference-data file could not be read: '
                        . basename($file)
                    );
                }

                $statements = $this->splitter
                    ->split($sql);

                if ($statements === []) {
                    throw new RuntimeException(
                        'A reference-data file is empty: '
                        . basename($file)
                    );
                }

                foreach ($statements as $statement) {
                    $currentStatement++;
                    $this->connection->exec($statement);
                    $statementCount++;
                }

                $appliedFiles[] = $currentFile;
            }

            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw new RuntimeException(
                sprintf(
                    'Reference-data synchronization failed in %s at statement %d.',
                    $currentFile !== ''
                        ? $currentFile
                        : 'unknown file',
                    $currentStatement
                ),
                0,
                $exception
            );
        }

        return [
            'files' => $appliedFiles,
            'statementCount' => $statementCount,
        ];
    }
}
