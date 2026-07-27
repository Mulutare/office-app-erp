<?php

declare(strict_types=1);

/**
 * Render a view file.
 *
 * @param array<string, mixed> $data
 */
function view(string $view, array $data = []): void
{
    $viewPath = __DIR__
        . '/../../resources/views/'
        . str_replace('.', '/', $view)
        . '.php';

    if (!is_file($viewPath)) {
        throw new RuntimeException(
            sprintf('View [%s] was not found.', $view)
        );
    }

    require $viewPath;
}

/**
 * Safely escape output for HTML.
 */
function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * Build a cache-safe public asset URL.
 */
function assetUrl(string $path): string
{
    $path = ltrim($path, '/');
    $filePath = __DIR__
        . '/../../public/assets/'
        . $path;
    $url = '/office_app/public/assets/'
        . str_replace(
            '%2F',
            '/',
            rawurlencode($path)
        );

    if (!is_file($filePath)) {
        return $url;
    }

    $modifiedAt = filemtime($filePath);

    return $modifiedAt === false
        ? $url
        : $url . '?v=' . $modifiedAt;
}
