# Container Operations

Docker is the recommended development and production packaging path. Systems
without Docker use the same application on native PHP 8.1+ as documented in
`docs/native-php-deployment.md`.

## Prerequisites

- Docker Desktop with Docker Compose v2
- Host port 8080 for the application
- Host port 3307 only when direct development database access is needed

Copy `.env.example` to `.env` and replace every placeholder secret before
using shared or persistent environments. The `.env` file is ignored by Git.

For background desktop/mobile attendance notifications, generate a stable
VAPID key pair after the first build:

```powershell
docker compose run --rm app php bin/generate-web-push-keys.php
```

Copy the four `WEB_PUSH_*` values into the ignored `.env`, use a real contact
email, then rebuild the application. Keep the private key secret and stable.

Development startup creates the first platform administrator from the
`DEV_ADMIN_*` values only when that username does not exist. The password is
hashed and must be changed at first login. This bootstrap refuses to run
outside the development environment.

## Development

Start the application and persistent development database:

```powershell
docker compose up --build -d
```

Open:

```text
http://localhost:8080/office_app/public/login
```

To expose MariaDB to a local database client on port 3307:

```powershell
docker compose -f compose.yaml -f compose.dev.yaml up --build -d
```

Inspect or stop the services:

```powershell
docker compose ps
docker compose logs --tail 100 app
docker compose logs --tail 100 db
docker compose down
```

The database image initializes an empty volume from the reviewed SQL catalog.
Before Apache starts, the application also runs checksum-protected forward
migrations and synchronizes repeatable reference data. This upgrades populated
development volumes without deleting company or user data.

Create one complete, approved sample tenant after startup:

```powershell
docker compose exec app php bin/provision-development-sample.php
```

The command prints one-time temporary passwords for `sample.owner` and
`sample.employee`. The owner is an administrative identity for company users,
security and licensed modules; it is not created as an HR employee. The
employee is linked to the owner through the company reporting line and receives
an HR employee profile, Employee Self Service role, HR module and Attendance
module. The command is development-only and refuses to overwrite an existing
sample company.

## Attendance notification dispatcher

Personal attendance alerts are stored in the database so they remain available
after the browser is closed. Run the dispatcher every minute from one trusted
application worker:

```powershell
docker compose exec -T app php bin/queue-attendance-notifications.php
```

For a native installation, schedule the equivalent command with the deployed
PHP executable:

```powershell
php bin/queue-attendance-notifications.php
```

Use Windows Task Scheduler, cron, or the production scheduler to run exactly
one dispatcher instance each minute. The command is safe to repeat because
each user, reminder type and work date has a unique deduplication key.

The dispatcher:

- uses each employee's IANA timezone and personal reminder lead time;
- honors the effective company calendar, ISO workweek and full-day holidays;
- carries overnight check-out reminders into the next local day;
- ignores inactive companies, users, memberships and employee profiles;
- writes private, company-scoped in-application notifications;
- delivers VAPID-authenticated browser push when the employee registered that
  device and the server has Web Push enabled;
- retries temporary push-service failures and disables expired subscriptions;
- never requires an open browser session.

The in-application inbox remains the durable source of truth when device
permission is denied or a push service is temporarily unavailable.

## Disposable integration test

The test stack uses PHP 8.4 and a temporary MariaDB filesystem. It initializes
the schema and fixtures, verifies the migration ledger, synchronizes reference
data a second time to prove repeatability, and then runs the test script:

```powershell
docker compose -f compose.test.yaml up --build --abort-on-container-exit --exit-code-from app
docker compose -f compose.test.yaml down --volumes
```

The test database is separate from development and every native installation.

## Optional Oracle compatibility lab

The standard application image does not contain Oracle libraries. The optional
Oracle profile requires operator-supplied Instant Client archives and an
explicitly approved Oracle Free-compatible database image.

See `docs/oracle-deployment.md`. Oracle remains unverified and must not be used
for production or customer data.

## Production preparation

Production uses a separate Compose definition and requires explicit secrets:

```powershell
docker compose --env-file .env.production -f compose.production.yaml config
docker compose --env-file .env.production -f compose.production.yaml up --build -d
```

Production requirements:

- Place the application behind a TLS reverse proxy.
- Keep `SESSION_COOKIE_SECURE=true`.
- Never expose MariaDB publicly.
- Back up and restore-test the database before schema changes.
- Run reviewed migrations as a controlled deployment step.
- Store and rotate credentials outside Git.

Production does not automatically execute repository migrations. Apache
runs as `www-data` on internal port 8080, and application write access is
limited to the storage directory.

## Troubleshooting

Use bounded status and log checks:

```powershell
docker compose ps
docker compose logs --tail 200 app
docker compose logs --tail 200 db
```

Confirm database health before diagnosing PHP. The application and database
services must use the same database name, username and password.

Do not run `docker compose down --volumes` against a database containing
data that must be retained.
