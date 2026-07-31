<?php

declare(strict_types=1);

/**
 * Determine whether a required PHP extension is available.
 *
 * OPcache is loaded as a Zend extension. Some cPanel MultiPHP/FPM builds
 * expose it through the OPcache API or the Zend-extension registry while
 * extension_loaded('Zend OPcache') returns false.
 */
function runtimeExtensionLoaded(string $extension): bool
{
    if (extension_loaded($extension)) {
        return true;
    }

    if (strcasecmp($extension, 'Zend OPcache') !== 0) {
        return false;
    }

    if (function_exists('opcache_get_status')) {
        $status = @opcache_get_status(false);

        if (
            is_array($status)
            && !empty($status['opcache_enabled'])
        ) {
            return true;
        }
    }

    foreach (get_loaded_extensions(true) as $zendExtension) {
        if (
            is_string($zendExtension)
            && strcasecmp(
                $zendExtension,
                'Zend OPcache'
            ) === 0
        ) {
            return true;
        }
    }

    if (function_exists('opcache_get_configuration')) {
        $configuration = @opcache_get_configuration();
        $directives = is_array($configuration)
            && is_array($configuration['directives'] ?? null)
                ? $configuration['directives']
                : [];

        if (!empty($directives['opcache.enable'])) {
            return true;
        }
    }

    return false;
}
