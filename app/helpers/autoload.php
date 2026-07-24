<?php

declare(strict_types=1);

/**
 * Lightweight OfficeApp class autoloader.
 *
 * Example:
 * App\Controllers\HomeController
 *
 * maps to:
 * app/controllers/HomeController.php
 */
spl_autoload_register(
    static function (string $className): void {
        $prefix = 'App\\';

        if (!str_starts_with($className, $prefix)) {
            return;
        }

        $relativeClass = substr(
            $className,
            strlen($prefix)
        );

        $segments = explode('\\', $relativeClass);

        if ($segments === []) {
            return;
        }

        /*
         * Current project directories use lowercase names:
         * Controllers -> controllers
         * Models      -> models
         * Services    -> services
         * Middleware  -> middleware
         */
        $segments[0] = strtolower($segments[0]);

        $filePath = __DIR__
            . '/../'
            . implode(DIRECTORY_SEPARATOR, $segments)
            . '.php';

        if (!is_file($filePath)) {
            throw new RuntimeException(
                sprintf(
                    'Class file for [%s] was not found at [%s].',
                    $className,
                    $filePath
                )
            );
        }

        require_once $filePath;
    }
);