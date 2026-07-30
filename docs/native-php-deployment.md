# Native PHP 8.1+ deployment

This deployment mode is for systems where Docker or another container runtime
is unavailable. It is a first-class runtime path, not a PHP 8.0 fallback.

## Requirements

- PHP 8.1 or newer.
- Apache, Nginx, IIS or another supported PHP web server.
- MySQL 8 or MariaDB 10.11.
- PDO, PDO MySQL, curl, mbstring, OpenSSL, session and OPcache.
- URL rewriting to the `public/index.php` front controller.
- Write access for the web-server account only to `storage/`.

Run before installation:

```text
php bin/check-runtime.php
```

The command must report `PASS`.

Install the locked PHP dependencies before serving or packaging OfficeApp:

```text
composer install --no-dev --optimize-autoloader
```

Background attendance notifications remain optional. Generate a stable VAPID
identity with `php bin/generate-web-push-keys.php`, enable `web_push` in the
ignored local configuration, and schedule
`bin/queue-attendance-notifications.php` every minute. The private
in-application inbox continues to work when Web Push is disabled.

## Database configuration

Choose one method:

1. Set the `DB_*` environment variables for the web-server process.
2. Copy `config/database.example.php` to the ignored
   `config/database.php` file and enter native MySQL/MariaDB credentials.

Never commit `config/database.php`, `.env` or production credentials.

## Web-server layout

The current release uses this application base path:

```text
/office_app/public
```

Install the repository as the `office_app` directory beneath the web root and
open:

```text
https://your-host.example/office_app/public/login
```

The supplied root `.htaccess` blocks direct Apache access to application
source and configuration. The supplied `public/.htaccess` routes application
requests to `public/index.php`.

For Apache:

- Enable URL rewriting.
- Allow `.htaccess` rules for the `office_app` directory.
- Keep directory indexes disabled.
- Grant the web-server account write access only to `storage/`.
- Enable HTTPS and secure session cookies.

For Nginx or IIS, configure the equivalent rules:

- Serve requests only from `office_app/public`.
- Route missing public files and directories to `public/index.php`.
- Reject direct requests to every non-public application directory.
- Preserve `/office_app/public` as the application base path.

A domain mounted directly at `public/` is not a supported layout in this
release because generated application URLs use `/office_app/public`.

## Windows without Docker

Install a supported PHP 8.1+ build that matches the selected web server. Do not
point OfficeApp at an older PHP executable bundled with an existing XAMPP
installation.

The MySQL/MariaDB service may remain independently installed. PHP and the
database server do not need to come from the same software bundle.

Confirm the executable used by the web server:

```powershell
php -v
php bin/check-runtime.php
```

## Linux without Docker

Install PHP 8.1+, the required extensions, a web server and MySQL/MariaDB using
the operating system's supported repositories. Configure PHP-FPM or the Apache
PHP integration deliberately, then run the same runtime check.

The production web-server account must not own the application source. Grant
it write access only to `storage/`.

## Validation

After database provisioning and web-server configuration:

1. Run `php bin/check-runtime.php`.
2. Open `/office_app/public/health` and confirm HTTP 200 with
   `database: connected`.
3. Open `/office_app/public/login`.
4. Sign in with a controlled administrator account.
5. Confirm forced temporary-password change, RBAC and tenant isolation.

Native and container deployments must use the same integration tests. The
repository validates both the PHP 8.1 hosting baseline and PHP 8.4 containers.
