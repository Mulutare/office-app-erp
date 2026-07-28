<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

/**
 * Creates a database connection for one supported engine.
 *
 * SQL dialect and repository behavior remain separate concerns.
 */
interface DatabaseDriver
{
    public function name(): string;

    public function dialect(): SqlDialect;

    /**
     * @param array<string, mixed> $config
     */
    public function connect(array $config): PDO;

    public function assertHealthy(PDO $connection): void;

    public function databaseName(PDO $connection): string;
}
