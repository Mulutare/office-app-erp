<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

/**
 * Experimental Oracle PDO_OCI connection adapter.
 *
 * Oracle remains unavailable until real integration validation passes.
 */
final class OracleDriver implements DatabaseDriver
{
    private const ALLOWED_CHARSETS = [
        'AL32UTF8',
        'UTF8',
    ];

    public function name(): string
    {
        return 'oracle';
    }

    public function dialect(): SqlDialect
    {
        return new OracleDialect();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function connect(array $config): PDO
    {
        if (
            !extension_loaded('pdo_oci')
            || !in_array(
                'oci',
                PDO::getAvailableDrivers(),
                true
            )
        ) {
            throw new RuntimeException(
                'The Oracle PDO extension is not available.'
            );
        }

        $host = $this->dsnValue($config, 'host');
        $serviceName = $this->serviceName($config);
        $username = $this->requiredString(
            $config,
            'username'
        );
        $password = (string) (
            $config['password'] ?? ''
        );
        $port = $this->port($config);
        $charset = strtoupper(
            $this->requiredString(
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
                'The configured Oracle charset is not supported.'
            );
        }

        $dsn = sprintf(
            'oci:dbname=//%s:%d/%s;charset=%s',
            $host,
            $port,
            $serviceName,
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
            ]
        );
    }

    public function assertHealthy(PDO $connection): void
    {
        $connection
            ->query('SELECT 1 FROM DUAL')
            ->fetchColumn();
    }

    public function databaseName(PDO $connection): string
    {
        return (string) $connection
            ->query(
                'SELECT SYS_CONTEXT('
                . '\'USERENV\', \'CURRENT_SCHEMA\''
                . ') FROM DUAL'
            )
            ->fetchColumn();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function serviceName(array $config): string
    {
        $serviceName = $this->requiredString(
            $config,
            'service_name'
        );

        if (
            preg_match(
                '/^[A-Za-z0-9._-]+$/',
                $serviceName
            ) !== 1
        ) {
            throw new RuntimeException(
                'The Oracle service name is invalid.'
            );
        }

        return $serviceName;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function dsnValue(
        array $config,
        string $key
    ): string {
        $value = $this->requiredString(
            $config,
            $key
        );

        if (
            preg_match(
                '/[;\/\x00-\x1F\x7F]/',
                $value
            ) === 1
        ) {
            throw new RuntimeException(
                'The Oracle connection configuration is invalid.'
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
                'The Oracle connection configuration is incomplete.'
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
                'The configured Oracle port is invalid.'
            );
        }

        return $port;
    }
}
