# Container Operations

Docker is the recommended development and production packaging path. Systems
without Docker use the same application on native PHP 8.4 as documented in
`docs/native-php-deployment.md`.

## Prerequisites

- Docker Desktop with Docker Compose v2
- Host port 8080 for the application
- Host port 3307 only when direct development database access is needed

Copy `.env.example` to `.env` and replace every placeholder secret before
using shared or persistent environments. The `.env` file is ignored by Git.

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

Migrations and seeds run when MariaDB initializes an empty development
volume. Restarting a populated database does not replay initialization.

## Disposable integration test

The test stack uses PHP 8.4 and a temporary MariaDB filesystem. It applies
all migrations, seeds and test-only fixtures before running the test script:

```powershell
docker compose -f compose.test.yaml up --build --abort-on-container-exit --exit-code-from app
docker compose -f compose.test.yaml down --volumes
```

The test database is separate from development and every native installation.

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
