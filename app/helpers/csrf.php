<?php

declare(strict_types=1);

/**
 * Return the current CSRF token, creating one when needed.
 */
function csrfToken(): string
{
    if (
        !isset($_SESSION['_csrf_token'])
        || !is_string($_SESSION['_csrf_token'])
    ) {
        $_SESSION['_csrf_token'] = bin2hex(
            random_bytes(32)
        );
    }

    return $_SESSION['_csrf_token'];
}

/**
 * Render a hidden CSRF form field.
 */
function csrfField(): string
{
    return sprintf(
        '<input type="hidden" name="_token" value="%s">',
        e(csrfToken())
    );
}

/**
 * Verify a submitted CSRF token.
 */
function verifyCsrfToken(?string $submittedToken): bool
{
    $sessionToken = $_SESSION['_csrf_token'] ?? null;

    if (
        !is_string($sessionToken)
        || !is_string($submittedToken)
    ) {
        return false;
    }

    return hash_equals(
        $sessionToken,
        $submittedToken
    );
}