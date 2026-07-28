# OfficeApp ERP architecture

## Runtime

OfficeApp requires PHP 8.4 or newer. It supports:

- a containerized PHP 8.4 and MariaDB runtime;
- a native PHP 8.4 web-server and MySQL/MariaDB runtime.

Both deployment modes execute the same application source.

## Request flow

```text
public/index.php
  -> bootstrap and runtime validation
  -> router
  -> controller
  -> application service
  -> repository contract
  -> configured database repository
  -> database driver and SQL dialect
```

Controllers handle HTTP input and responses. Application services enforce
business rules, authorization and tenant context. Repositories own database
queries. Views render escaped presentation data and contain no SQL.

## Database boundaries

`ConnectionManager` selects only allowlisted `DatabaseDriver`
implementations. The active MySQL/MariaDB implementation owns the PDO
connection, database health query and schema metadata query.

`SqlDialect` isolates database-specific SQL expressions. Repository
implementations are grouped by engine:

```text
app/repositories/
  DashboardStatisticsRepository.php
  RepositoryFactory.php
  MySql/
    MySqlRepository.php
    DashboardStatisticsRepository.php
    UserRepository.php
    CompanyRepository.php
    ...
```

The `app/models/` classes are backward-compatible facades for services written
before the repository boundary. They contain no SQL and may be retired
incrementally as services adopt repository contracts.

## Multi-company isolation

The authenticated session contains the active company context. Services obtain
the current company through `TenantContext`, and repositories include
`company_id` in tenant-owned reads and writes. Platform administrators remain
isolated to the vendor workspace unless an explicit membership permits another
company.

## Module entitlements

Modules are enabled per approved company. Navigation and controllers enforce
both the company module entitlement and RBAC permission. Hiding a navigation
entry is not a security control; every protected route must enforce access on
the server.

## Oracle status

Oracle is not supported or certified. An experimental PDO_OCI driver, Oracle
dialect and fail-closed repository skeleton exist only for compatibility
development. Product documentation must not claim Oracle compatibility until
actual Oracle migrations and integration tests pass.
