<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

/**
 * MySQL 8 and MariaDB PDO connection adapter.
 */
final class MySqlDriver implements DatabaseDriver
{
    private const ALLOWED_CHARSETS = [
        'utf8',
        'utf8mb4',
    ];

    public function name(): string
    {
        return 'mysql';
    }

    public function dialect(): SqlDialect
    {
        return new MySqlDialect();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function connect(array $config): PDO
    {
        $host = $this->requiredDsnValue(
            $config,
            'host'
        );
        $database = $this->requiredDsnValue(
            $config,
            'database'
        );
        $username = $this->requiredString(
            $config,
            'username'
        );
        $password = (string) (
            $config['password'] ?? ''
        );
        $port = $this->port($config);
        $charset = strtolower(
            $this->requiredDsnValue(
                $config,
                'charset'
            )
        );

        if (
            !in_array(
                $charset,
                self::ALLOWED_CHARSETS,
                true
            )
        ) {
            throw new RuntimeException(
                'The configured database charset is not supported.'
            );
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset
        );

        return new PDO(
            $dsn,
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE =>
                    PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE =>
                    PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    public function assertHealthy(PDO $connection): void
    {
        $connection
            ->query('SELECT 1')
            ->fetchColumn();
    }

    public function databaseName(PDO $connection): string
    {
        return (string) $connection
            ->query('SELECT DATABASE()')
            ->fetchColumn();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function requiredDsnValue(
        array $config,
        string $key
    ): string {
        $value = $this->requiredString(
            $config,
            $key
        );

        if (
            preg_match(
                '/[;\x00-\x1F\x7F]/',
                $value
            ) === 1
        ) {
            throw new RuntimeException(
                'The database connection configuration is invalid.'
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function requiredString(
        array $config,
        string $key
    ): string {
        $value = $config[$key] ?? null;

        if (
            !is_string($value)
            || trim($value) === ''
        ) {
            throw new RuntimeException(
                'The database connection configuration is incomplete.'
            );
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function port(array $config): int
    {
        $port = filter_var(
            $config['port'] ?? null,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 65535,
                ],
            ]
        );

        if (!is_int($port)) {
            throw new RuntimeException(
                'The configured database port is invalid.'
            );
        }

        return $port;
    }
}
