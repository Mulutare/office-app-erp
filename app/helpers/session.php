<?php

declare(strict_types=1);

/**
 * Store a temporary session message.
 */
function flash(string $key, mixed $value): void
{
    $_SESSION['_flash'][$key] = $value;
}

/**
 * Retrieve and remove a temporary session message.
 */
function getFlash(string $key, mixed $default = null): mixed
{
    if (!isset($_SESSION['_flash'][$key])) {
        return $default;
    }

    $value = $_SESSION['_flash'][$key];

    unset($_SESSION['_flash'][$key]);

    return $value;
}

/**
 * Preserve previous form input temporarily.
 *
 * @param array<string, mixed> $input
 */
function flashInput(array $input): void
{
    unset($input['password']);

    flash('old_input', $input);
}

/**
 * Retrieve one previous form field.
 */
function old(string $key, string $default = ''): string
{
    static $oldInput = null;

    if ($oldInput === null) {
        $value = getFlash('old_input', []);
        $oldInput = is_array($value) ? $value : [];
    }

    return isset($oldInput[$key])
        ? (string) $oldInput[$key]
        : $default;
}