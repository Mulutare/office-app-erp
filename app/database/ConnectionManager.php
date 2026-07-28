<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Selects an allowlisted database driver and owns one shared connection.
 */
final class ConnectionManager
{
    /**
     * @var array<string, mixed>
     */
    private array $config;

    private DatabaseDriver $driver;

    private ?PDO $connection = null;

    /**
     * @param array<string, mixed> $config
     */
    private function __construct(
        array $config,
        DatabaseDriver $driver
    ) {
        $this->config = $config;
        $this->driver = $driver;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(
        array $config
    ): self {
        $configuredDriver = $config['driver']
            ?? 'mysql';
        $driverName = is_string($configuredDriver)
            ? strtolower(trim($configuredDriver))
            : '';

        if ($driverName === 'mysql') {
            return new self(
                $config,
                new MySqlDriver()
            );
        }

        if ($driverName === 'oracle') {
            return new self(
                $config,
                new OracleDriver()
            );
        }

        throw new RuntimeException(
            'The configured database driver is not available.'
        );
    }

    public function driver(): DatabaseDriver
    {
        return $this->driver;
    }

    public function connection(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        try {
            $this->connection = $this->driver
                ->connect($this->config);
        } catch (PDOException $exception) {
            error_log(
                sprintf(
                    '[database] connection_failed'
                    . ' driver=%s class=%s code=%s',
                    $this->driver->name(),
                    $exception::class,
                    preg_replace(
                        '/[^A-Za-z0-9_.-]/',
                        '',
                        (string) $exception->getCode()
                    )
                )
            );

            throw new RuntimeException(
                'The application could not connect to the database.'
            );
        }

        return $this->connection;
    }
}
