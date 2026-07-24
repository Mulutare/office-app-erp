<?php

declare(strict_types=1);

/**
 * Read a string value from submitted POST data.
 */
function postString(string $key): string
{
    $value = $_POST[$key] ?? '';

    if (!is_string($value)) {
        return '';
    }

    return trim($value);
}

/**
 * Return the request IP address.
 */
function requestIp(): string
{
    $value = $_SERVER['REMOTE_ADDR'] ?? '';

    return is_string($value)
        ? substr($value, 0, 45)
        : '';
}

/**
 * Return a limited user-agent value.
 */
function requestUserAgent(): string
{
    $value = $_SERVER['HTTP_USER_AGENT'] ?? '';

    return is_string($value)
        ? substr($value, 0, 500)
        : '';
}