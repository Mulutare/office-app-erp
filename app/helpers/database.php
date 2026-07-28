<?php

declare(strict_types=1);

use App\Database\ConnectionManager;
use App\Database\DatabaseDriver;

/**
 * Returns the shared database connection manager.
 */
function databaseManager(): ConnectionManager
{
    static $manager = null;

    if ($manager instanceof ConnectionManager) {
        return $manager;
    }

    $localConfigPath =
        __DIR__ . '/../../config/database.php';
    $runtimeConfigPath =
        __DIR__ . '/../../config/database.runtime.php';
    $runtimeHost = getenv('DB_HOST');
    $hasRuntimeDatabaseConfig =
        is_string($runtimeHost)
        && trim($runtimeHost) !== '';

    $config = !$hasRuntimeDatabaseConfig
        && is_file($localConfigPath)
        ? require $localConfigPath
        : require $runtimeConfigPath;

    if (!is_array($config)) {
        throw new RuntimeException(
            'The database configuration is invalid.'
        );
    }

    $manager = ConnectionManager::fromConfig(
        $config
    );

    return $manager;
}

/**
 * Returns the active allowlisted database driver.
 */
function databaseDriver(): DatabaseDriver
{
    return databaseManager()->driver();
}

/**
 * Backward-compatible PDO access for existing models and services.
 */
function db(): PDO
{
    return databaseManager()->connection();
}
