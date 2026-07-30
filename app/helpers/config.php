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
    $localPath = __DIR__
        . '/../../config/app.local.php';

    if (is_file($localPath)) {
        $local = require $localPath;

        if (!is_array($local)) {
            throw new RuntimeException(
                'config/app.local.php must return an array.'
            );
        }

        $config = array_replace($config, $local);
    }

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

/**
 * Return the normalized URL path where OfficeApp is mounted.
 *
 * An empty path means that public/ is the domain document root.
 */
function appBasePath(): string
{
    $basePath = trim((string) config(
        'base_path',
        '/office_app/public'
    ));

    if (
        str_contains($basePath, '..')
        || str_contains($basePath, '?')
        || str_contains($basePath, '#')
    ) {
        throw new RuntimeException(
            'APP_BASE_PATH contains unsafe characters.'
        );
    }

    if ($basePath === '' || $basePath === '/') {
        return '';
    }

    return '/' . trim($basePath, '/');
}

/**
 * Build an application route URL for any deployment base path.
 */
function appUrl(string $path = '/'): string
{
    $path = '/' . ltrim($path, '/');

    return appBasePath()
        . ($path === '//' ? '/' : $path);
}
