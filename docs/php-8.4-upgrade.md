# PHP 8.4 Upgrade

## Supported runtime policy

OfficeApp ERP uses PHP 8.4 for its maintained container runtime and supports
PHP 8.1 or newer for native/cPanel hosting.

Supported execution modes are:

- Docker or another OCI-compatible container runtime using the supplied
  PHP 8.4 image.
- A native web-server deployment using PHP 8.1+ and MySQL 8 or MariaDB.

PHP 8.0 is no longer a supported fallback. The application performs a
centralized startup check and stops with a clear error on an older runtime.
Run `php bin/check-runtime.php` before configuring a native web server.

## Required PHP extensions

Runtime requirements are defined once in `config/runtime.php`. The current
required extensions are PDO, PDO MySQL, mbstring, OpenSSL, session and
OPcache.

No Oracle extension or Oracle database support is included or claimed.

## Runtime configuration

Container configuration is supplied through environment variables. The
ignored `config/database.php` remains supported for native installations.
When `DB_HOST` is supplied, environment configuration takes precedence.

The accepted database driver is currently only `mysql`, covering the tested
MariaDB/MySQL PDO path.

## Error handling

Application errors return a short incident reference. Exception messages,
stack traces and request credentials are not rendered in the browser.
Production disables PHP display errors and writes diagnostic context to the
container log.

## Native deployment

Systems that cannot run Docker must install PHP 8.1 or newer and follow
`docs/native-php-deployment.md`. They use the same source code, database
configuration, front controller and security controls as the container path.

Do not use an older bundled PHP runtime merely to preserve a previous local
toolchain.

## Rollback

Application rollback must restore the previous reviewed release while keeping
the database intact. Never delete a database volume or native database until
its contents are confirmed disposable or backed up.
