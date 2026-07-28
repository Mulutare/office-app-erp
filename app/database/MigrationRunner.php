<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Executes reviewed, driver-specific migration definitions.
 *
 * Database DDL can auto-commit, especially on Oracle. A migration is recorded
 * only after every statement succeeds, but operators must still use a clean
 * schema or a verified backup when recovering from partially applied DDL.
 */
final class MigrationRunner
{
    private const ALLOWED_DRIVERS = [
        'mysql',
        'oracle',
    ];

    public function __construct(
        private PDO $connection,
        private string $driver
    ) {
        if (
            !in_array(
                $this->driver,
                self::ALLOWED_DRIVERS,
                true
            )
        ) {
            throw new RuntimeException(
                'The migration database driver is not available.'
            );
        }
    }

    /**
     * @return array{
     *     applied: list<string>,
     *     skipped: list<string>
     * }
     */
    public function run(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new RuntimeException(
                'The migration directory does not exist.'
            );
        }

        $this->ensureMigrationLedger();

        $files = glob(
            rtrim($directory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . '*.php'
        );

        if (!is_array($files)) {
            throw new RuntimeException(
                'The migration directory could not be read.'
            );
        }

        sort($files, SORT_STRING);

        $seenVersions = [];
        $applied = [];
        $skipped = [];

        foreach ($files as $file) {
            $migration = $this->definition($file);
            $version = $migration['version'];

            if (isset($seenVersions[$version])) {
                throw new RuntimeException(
                    'Duplicate migration version: '
                    . $version
                );
            }

            $seenVersions[$version] = true;
            $checksum = hash_file('sha256', $file);

            if (!is_string($checksum)) {
                throw new RuntimeException(
                    'The migration checksum could not be calculated.'
                );
            }

            $existingChecksum = $this->appliedChecksum(
                $version
            );

            if ($existingChecksum !== null) {
                if (
                    !hash_equals(
                        $existingChecksum,
                        $checksum
                    )
                ) {
                    throw new RuntimeException(
                        'An applied migration was modified: '
                        . $version
                    );
                }

                $skipped[] = $version;

                continue;
            }

            $this->apply(
                $migration,
                $checksum
            );

            $applied[] = $version;
        }

        return [
            'applied' => $applied,
            'skipped' => $skipped,
        ];
    }

    private function ensureMigrationLedger(): void
    {
        if ($this->driver === 'mysql') {
            $this->connection->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    version VARCHAR(50) PRIMARY KEY,
                    description VARCHAR(255) NOT NULL,
                    checksum CHAR(64) NOT NULL,
                    applied_at TIMESTAMP NOT NULL
                        DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );

            return;
        }

        $statement = $this->connection->prepare(
            'SELECT COUNT(*)
             FROM user_tables
             WHERE table_name = :table_name'
        );
        $statement->execute([
            'table_name' => 'SCHEMA_MIGRATIONS',
        ]);

        if ((int) $statement->fetchColumn() > 0) {
            return;
        }

        $this->connection->exec(
            'CREATE TABLE schema_migrations (
                version VARCHAR2(50 CHAR) PRIMARY KEY,
                description VARCHAR2(255 CHAR) NOT NULL,
                checksum CHAR(64 CHAR) NOT NULL,
                applied_at TIMESTAMP(6)
                    DEFAULT SYSTIMESTAMP NOT NULL
            )'
        );
    }

    /**
     * @return array{
     *     version: string,
     *     description: string,
     *     statements: list<string>
     * }
     */
    private function definition(string $file): array
    {
        $migration = require $file;

        if (!is_array($migration)) {
            throw new RuntimeException(
                'A migration definition is invalid.'
            );
        }

        $version = $migration['version'] ?? null;
        $description = $migration['description'] ?? null;
        $statements = $migration['statements'] ?? null;

        if (
            !is_string($version)
            || preg_match(
                '/^[0-9]{3,20}$/',
                $version
            ) !== 1
            || !is_string($description)
            || trim($description) === ''
            || strlen($description) > 255
            || !is_array($statements)
            || $statements === []
        ) {
            throw new RuntimeException(
                'A migration definition is invalid.'
            );
        }

        $validatedStatements = [];

        foreach ($statements as $statement) {
            if (
                !is_string($statement)
                || trim($statement) === ''
            ) {
                throw new RuntimeException(
                    'A migration statement is invalid.'
                );
            }

            $validatedStatements[] = trim($statement);
        }

        return [
            'version' => $version,
            'description' => trim($description),
            'statements' => $validatedStatements,
        ];
    }

    private function appliedChecksum(
        string $version
    ): ?string {
        $statement = $this->connection->prepare(
            'SELECT checksum
             FROM schema_migrations
             WHERE version = :version'
        );
        $statement->execute([
            'version' => $version,
        ]);

        $checksum = $statement->fetchColumn();

        return is_string($checksum)
            ? rtrim($checksum)
            : null;
    }

    /**
     * @param array{
     *     version: string,
     *     description: string,
     *     statements: list<string>
     * } $migration
     */
    private function apply(
        array $migration,
        string $checksum
    ): void {
        foreach (
            $migration['statements']
            as $index => $sql
        ) {
            try {
                $this->connection->exec($sql);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    sprintf(
                        'Migration %s failed at statement %d.',
                        $migration['version'],
                        $index + 1
                    ),
                    0,
                    $exception
                );
            }
        }

        $statement = $this->connection->prepare(
            'INSERT INTO schema_migrations
                (
                    version,
                    description,
                    checksum
                )
             VALUES
                (
                    :version,
                    :description,
                    :checksum
                )'
        );
        $statement->execute([
            'version' => $migration['version'],
            'description' =>
                $migration['description'],
            'checksum' => $checksum,
        ]);
    }
}
