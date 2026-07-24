<?php

declare(strict_types=1);

/**
 * Redirect to an OfficeApp route and stop execution.
 */
function redirect(string $path): never
{
    $basePath = '/office_app/public';

    $normalizedPath = '/' . ltrim($path, '/');

    header('Location: ' . $basePath . $normalizedPath);
    exit;
}