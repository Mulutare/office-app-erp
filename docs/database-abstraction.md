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

## Checkpoint 4 repository and dialect boundary

MySQL-specific application queries now live under:

```text
app/repositories/MySql/
```

The classes in `app/models/` remain as small compatibility facades so the
existing service API and verified ERP behavior do not change during the
refactor. They contain no SQL.

`SqlDialect` defines the cross-engine SQL-fragment contract.
`MySqlDialect` currently supplies:

- current timestamp expressions;
- current-day range predicates;
- single-row limiting;
- limit/offset pagination.

Dynamic identifier input is rejected unless it is a valid internal qualified
column identifier. User-controlled values remain prepared-statement
parameters.

`DashboardService` now depends on `DashboardStatisticsRepository`, selected by
the allowlisted `RepositoryFactory`. `HomeController` delegates connection
health and database metadata queries to `DatabaseDriver`, so controllers and
services do not contain database-specific SQL.

## Compatibility facades

The model facades are temporary compatibility boundaries, not domain entities.
New business services should depend on repository interfaces. Existing
services can be migrated incrementally without changing controllers, routes or
views.

## Checkpoint 5 Oracle skeleton

The allowlisted connection manager recognizes `oracle` and selects
`OracleDriver`. The skeleton deliberately uses PDO_OCI to preserve the current
PDO connection contract. `OracleDialect` defines Oracle timestamp, daily range
and pagination expressions.

Oracle repositories remain fail-closed placeholders. MySQL remains the default
and only tested business-data implementation.

## Next boundary

Checkpoint 6 must add separate Oracle migrations and executable integration
tests. Generated IDs, CLOB binding, Oracle empty-string behavior, transaction
semantics, tenant isolation and full repository implementations remain
unavailable until those tests are built and run.
