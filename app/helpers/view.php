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