<?php

declare(strict_types=1);

/**
 * Redirect to an OfficeApp route and stop execution.
 */
function redirect(string $path): never
{
    header('Location: ' . appUrl($path));
    exit;
}
