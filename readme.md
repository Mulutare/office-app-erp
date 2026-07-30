# OfficeApp ERP

OfficeApp ERP is a modular, multi-company enterprise application.

## Runtime requirement

PHP 8.1 or newer is required. The native cPanel baseline is PHP 8.1,
while the container runtime and continuous tests use PHP 8.4.

Run:

```text
php bin/check-runtime.php
```

For a new empty native-hosting database, use the guarded
`bin/install-database.php` workflow in `docs/cpanel-deployment.md`.
Existing databases continue to use `bin/migrate.php` followed by
`bin/sync-reference-data.php`.

## Docker deployment

Start the development application:

```text
docker compose up --build -d
```

Development startup upgrades an existing database and synchronizes current
role and permission templates before the web server starts.

Open:

```text
http://localhost:8080/office_app/public/login
```

See `docs/container-operations.md`.

Create an approved sample company with a company owner, reporting manager and
employee self-service account:

```text
docker compose exec app php bin/provision-development-sample.php
```

## Deployment without Docker

Install PHP 8.1 or newer, the required extensions, Apache/Nginx/IIS and
MySQL/MariaDB directly on the host. The application source and business
behavior are identical to the container deployment.

The current supported application URL is:

```text
http://localhost/office_app/public/login
```

See `docs/native-php-deployment.md`. For PHP-FPM hosting managed through
cPanel, use `docs/cpanel-deployment.md`.

## Database support

MySQL 8 and MariaDB are the verified production engines. Oracle migrations
and repository adapters are maintained in parallel and pass structural
contract tests; live Oracle integration certification remains required
before selecting Oracle for a production tenant.
