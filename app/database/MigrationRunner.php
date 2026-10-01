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
     *     baselined: list<string>,
     *     skipped: list<string>
     * }
     */
    public function run(string $directory, ?callable $observer = null): array
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
        $baselined = [];
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
            $checksum = $this->checksum($file);

            $existingChecksum = $this->appliedChecksum(
                $version
            );

            if ($existingChecksum !== null) {
                if (
                    !$this->checksumsMatchVersion(
                        $version,
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

            if ($observer !== null) {
                $observer($version, 'begin');
            }
            $preflight = $migration['preflight'];

            if ($preflight !== null) {
                $state = $preflight(
                    $this->connection
                );

                if ($state === 'baseline') {
                    $this->record(
                        $migration,
                        $checksum
                    );
                    $baselined[] = $version;

                    continue;
                }

                if ($state !== 'apply') {
                    throw new RuntimeException(
                        'Migration preflight returned an invalid state: '
                        . $version
                    );
                }
            }

            $this->apply(
                $migration,
                $checksum
            );

            $applied[] = $version;
            if ($observer !== null) {
                $observer($version, 'end');
            }
        }

        return [
            'applied' => $applied,
            'baselined' => $baselined,
            'skipped' => $skipped,
        ];
    }

    /** Validate an existing ledger without creating tables or invoking preflights. */
    public function auditAppliedMigrations(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new RuntimeException('The migration directory does not exist.');
        }
        $files = glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php');
        if (!is_array($files) || $files === []) {
            throw new RuntimeException('The migration catalog is empty or unreadable.');
        }
        sort($files, SORT_STRING);
        $catalog = [];
        foreach ($files as $file) {
            $migration = $this->definition($file);
            $version = $migration['version'];
            if (isset($catalog[$version])) {
                throw new RuntimeException('Duplicate migration version: ' . $version);
            }
            $catalog[$version] = $this->checksum($file);
        }
        $rows = $this->connection->query('SELECT version, checksum FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
        $versions = array_map('strval', array_keys($catalog));
        $applied = [];
        foreach ($rows as $index => $row) {
            $version = (string)$row['version'];
            if (!isset($versions[$index]) || $versions[$index] !== $version) {
                throw new RuntimeException('Migration ledger gap or divergence: ' . $version);
            }
            if (!$this->checksumsMatchVersion($version, rtrim((string)$row['checksum']), $catalog[$version])) {
                throw new RuntimeException('An applied migration was modified: ' . $version);
            }
            $applied[] = $version;
        }
        return ['applied_versions' => $applied, 'first_unapplied' => $versions[count($applied)] ?? null];
    }

    /** Evaluate only the next reviewed preflight under a database read-only transaction. */
    public function auditFirstUnappliedPreflight(string $directory): ?string
    {
        $audit = $this->auditAppliedMigrations($directory);
        if ($audit['first_unapplied'] === null) {
            return null;
        }
        if ($this->connection->inTransaction()) {
            throw new RuntimeException('Read-only preflight requires its own transaction.');
        }
        $this->connection->exec($this->driver === 'mysql' ? 'START TRANSACTION READ ONLY' : 'SET TRANSACTION READ ONLY');
        try {
            foreach (glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') as $file) {
                $migration = $this->definition($file);
                if ($migration['version'] !== $audit['first_unapplied']) {
                    continue;
                }
                $state = $migration['preflight'] === null ? 'apply' : ($migration['preflight'])($this->connection);
                if (!in_array($state, ['apply', 'baseline'], true)) {
                    throw new RuntimeException('Migration preflight returned an invalid state: ' . $migration['version']);
                }
                return $state;
            }
            throw new RuntimeException('First unapplied migration disappeared from the catalog.');
        } finally {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
        }
    }

    private function checksumsMatchVersion(
        string $version,
        string $appliedChecksum,
        string $currentChecksum
    ): bool {
        if (hash_equals($appliedChecksum, $currentChecksum)) {
            return true;
        }

        if (
            $version === '040'
            && hash_equals(
                $appliedChecksum,
                '0392c26a00f8ef3ff11d9b8fc496d207116b32b87f17a6e35d19c8c06e163fcb'
            )
            && hash_equals(
                $currentChecksum,
                'ed682b7b76dd6502a00f84384f1ca0dbe13b71fb660a0a36e50d4a71dffadd55'
            )
        ) {
            return true;
        }

        if (
            $version === '062'
            && hash_equals(
                $appliedChecksum,
                'c7afbf6e450702ed1c512c5ace9e41045402660c50b23e2ebab7a1a3faff5550'
            )
            && hash_equals(
                $currentChecksum,
                '1d85d826ec2d6fb1255e0e36ec6b6390e445788afbc3b72e15d1c61e13e0699e'
            )
        ) {
            return true;
        }

        return false;
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
            $this->connection->exec(
                'CREATE TABLE IF NOT EXISTS schema_migration_steps (
                    version VARCHAR(50) NOT NULL,
                    statement_number INT UNSIGNED NOT NULL,
                    migration_checksum CHAR(64) NOT NULL,
                    statement_checksum CHAR(64) NOT NULL,
                    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (version, statement_number)
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
     *     statements: list<string>,
     *     preflight: (callable(PDO): string)|null
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
        $preflight = $migration['preflight'] ?? null;
        $recoveryFile = dirname($file) . DIRECTORY_SEPARATOR
            . 'recovery' . DIRECTORY_SEPARATOR . (string) $version . '.php';
        if (is_file($recoveryFile)) {
            /*
             * A recovery preflight is maintained separately so an already
             * applied historical migration keeps its immutable checksum.
             * When present it is the authoritative, step-aware preflight.
             */
            $preflight = require $recoveryFile;
        }

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
            || (
                $preflight !== null
                && !is_callable($preflight)
            )
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
            'preflight' => $preflight,
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
     * Hash migration source independently of checkout line-ending style.
     *
     * Git may materialize the same reviewed migration with LF or CRLF line
     * endings. Normalizing text newlines prevents a false modification alert
     * while preserving checksum protection for every substantive change.
     */
    private function checksum(string $file): string
    {
        $contents = file_get_contents($file);

        if (!is_string($contents)) {
            throw new RuntimeException(
                'The migration checksum could not be calculated.'
            );
        }

        $normalizedContents = str_replace(
            ["\r\n", "\r"],
            "\n",
            $contents
        );

        return hash(
            'sha256',
            $normalizedContents
        );
    }

    /**
     * @param array{
     *     version: string,
     *     description: string,
     *     statements: list<string>,
     *     preflight: (callable(PDO): string)|null
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
            $statementNumber = $index + 1;
            $statementChecksum = hash('sha256', $sql);
            $completed = $this->connection->prepare(
                'SELECT migration_checksum, statement_checksum
                 FROM schema_migration_steps
                 WHERE version=:version AND statement_number=:statement_number'
            );
            $completed->execute([
                'version' => $migration['version'],
                'statement_number' => $statementNumber,
            ]);
            $step = $completed->fetch(PDO::FETCH_ASSOC);
            if (is_array($step)) {
                if (!hash_equals((string)$step['migration_checksum'], $checksum)
                    || !hash_equals((string)$step['statement_checksum'], $statementChecksum)) {
                    throw new RuntimeException('A partially applied migration was modified: '.$migration['version']);
                }
                continue;
            }
            try {
                $this->connection->exec($sql);
                $recordStep=$this->connection->prepare(
                    'INSERT INTO schema_migration_steps
                        (version,statement_number,migration_checksum,statement_checksum)
                     VALUES(:version,:statement_number,:migration_checksum,:statement_checksum)'
                );
                $recordStep->execute([
                    'version'=>$migration['version'],
                    'statement_number'=>$statementNumber,
                    'migration_checksum'=>$checksum,
                    'statement_checksum'=>$statementChecksum,
                ]);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    sprintf(
                        'Migration %s failed at statement %d: %s',
                        $migration['version'],
                        $index + 1,
                        $exception->getMessage()
                    ),
                    0,
                    $exception
                );
            }
        }

        $this->record(
            $migration,
            $checksum
        );
        $cleanup=$this->connection->prepare(
            'DELETE FROM schema_migration_steps WHERE version=:version'
        );
        $cleanup->execute(['version'=>$migration['version']]);
    }

    /**
     * @param array{
     *     version: string,
     *     description: string,
     *     statements: list<string>,
     *     preflight: (callable(PDO): string)|null
     * } $migration
     */
    private function record(
        array $migration,
        string $checksum
    ): void {
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
