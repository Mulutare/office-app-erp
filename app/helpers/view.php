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

    ob_start();

    try {
        require $viewPath;
        $contents = ob_get_clean();
    } catch (Throwable $exception) {
        ob_end_clean();
        throw $exception;
    }

    if (!is_string($contents)) {
        return;
    }

    /*
     * Existing templates use the canonical development mount path.
     * Translate it only at the final rendering boundary so the same
     * views work when public/ is a cPanel domain document root.
     */
    $basePath = appBasePath();

    if ($basePath !== '/office_app/public') {
        $contents = str_replace(
            '/office_app/public',
            $basePath,
            $contents
        );
    }

    echo $contents;
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
    $url = appUrl('/assets/')
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
