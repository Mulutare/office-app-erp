# OfficeApp database abstraction

## Current support status

OfficeApp currently supports and tests:

- MySQL 8 through PDO MySQL.
- MariaDB 10.11 through PDO MySQL.

Oracle is not currently supported or certified. A future Oracle adapter must
pass integration tests against an actual Oracle database before the product
claims Oracle compatibility.

## Checkpoint 3 boundary

Database connection creation is separated into three responsibilities:

- `DatabaseDriver` defines the connection-driver contract.
- `MySqlDriver` validates MySQL/MariaDB configuration and creates PDO
  connections.
- `ConnectionManager` selects an allowlisted driver and owns the shared
  connection.

The global `db()` helper remains as a compatibility bridge for existing models
and services. It now obtains PDO through `ConnectionManager`, so existing
business behavior is preserved while later checkpoints can inject repository
interfaces without a large, unsafe rewrite.

## Driver selection

The active driver is selected with:

```text
DB_DRIVER=mysql
```

Only explicitly implemented drivers are accepted. An unknown or unavailable
driver produces a generic configuration error. Driver names are never used as
class names, file paths, or arbitrary SQL fragments.

## Configuration validation

The MySQL adapter validates:

- host;
- port range;
- database name;
- username;
- DSN control characters;
- an allowlisted connection charset.

PDO uses exception mode, associative fetch mode, native prepared statements,
and `utf8mb4` by default. Connection failures are logged without credentials,
hostnames, database names, or exception messages. Users receive a generic
connection error.

## Next boundary

Checkpoint 4 will move MySQL-specific SQL from models and services into
MySQL repository and dialect implementations. Until that work is complete,
the connection layer is portable but application queries remain MySQL-specific.

Oracle configuration, drivers, migrations, pagination, generated IDs, date
handling, and LOB behavior are intentionally deferred to the Oracle checkpoints.
