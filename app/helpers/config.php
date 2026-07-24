<?php

declare(strict_types=1);

/**
 * Returns the complete application configuration.
 *
 * @return array<string, mixed>
 */
function appConfig(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $config = require __DIR__ . '/../../config/app.php';

    return $config;
}

/**
 * Reads one application configuration value.
 */
function config(string $key, mixed $default = null): mixed
{
    $configuration = appConfig();

    return $configuration[$key] ?? $default;
}