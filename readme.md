# OfficeApp ERP

OfficeApp ERP is a modular, multi-company enterprise application.

## Runtime requirement

PHP 8.4 or newer is required. PHP 8.0 is not supported.

Run:

```text
php bin/check-runtime.php
```

## Docker deployment

Start the development application:

```text
docker compose up --build -d
```

Open:

```text
http://localhost:8080/office_app/public/login
```

See `docs/container-operations.md`.

## Deployment without Docker

Install PHP 8.4, the required extensions, Apache/Nginx/IIS and
MySQL/MariaDB directly on the host. The application source and business
behavior are identical to the container deployment.

The current supported application URL is:

```text
http://localhost/office_app/public/login
```

See `docs/native-php-deployment.md`.

## Database support

MySQL 8 and MariaDB are the currently supported engines. Oracle remains a
planned optional adapter and must not be represented as supported until real
Oracle integration tests pass.
