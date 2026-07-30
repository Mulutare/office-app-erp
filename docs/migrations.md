# Database migrations

## Engine separation

OfficeApp maintains database-specific migrations where syntax differs:

```text
database/migrations/          MySQL/MariaDB reference migrations
database/migrations/oracle/   Oracle migration definitions
```

Never run migrations for one engine against another engine.

## MySQL and MariaDB

Fresh MySQL/MariaDB development and test databases load the reviewed `.sql`
schema through `docker/mysql/init/00-officeapp-init.sh`.

Forward upgrades beginning with version 015 also have checksum-protected PHP
definitions in:

```text
database/migrations/mysql/
```

Run an existing database upgrade with:

```text
php bin/migrate.php
php bin/sync-reference-data.php
```

The migration runner records a fully existing migration as a baseline, applies
a fully absent migration, and stops on a partial schema. It never guesses how
to repair partially applied DDL.

The reference-data synchronizer reapplies all additive MySQL role, permission,
company-grant and default-policy seeds in numeric order. Those seeds are
repeatable and update existing companies and role holders without creating
duplicate grants.

Development Compose runs both commands before Apache starts. Production keeps
migration execution as an explicit operator action.

## Oracle migration ledger

Oracle definitions are executed by:

```text
php bin/migrate.php
```

The runner:

- selects the configured, allowlisted database driver;
- reads only that driver's internal migration directory;
- creates a `schema_migrations` ledger;
- validates unique numeric versions;
- records a SHA-256 checksum after normalizing text line endings;
- skips unchanged applied migrations;
- treats LF and CRLF checkouts identically;
- stops if an applied migration file was substantively modified;
- can baseline a verified complete pre-ledger schema;
- stops when a migration preflight detects partial DDL;
- does not expose SQL or credentials in its user-facing failure message.

The current Oracle versions are:

```text
010  identity, access and company tenancy
020  module catalog and company entitlements
030  tenant-scoped human resources
040  tenant-scoped finance
050  login and audit history
060  stable roles, permissions and module reference data
070  modular-company commercial controls
080  company ownership and account provisioning
090  tenant-scoped organization branches
100  tenant-scoped job titles and positions
110  effective-dated employee position assignments
120  attendance records and HR leave operations
130  employee self-service reporting managers and leave access
140  attendance self-service source and permissions
150  configurable leave policy management
160  leave balance allocations and adjustments
170  configurable staged leave approval workflows
180  administrative-only company owner permission defaults
190  HR job-title management required by position planning
200  user-owned attendance reminder preferences
210  international workforce calendars, holidays, effective schedules and durable attendance notifications
220  attendance lunch, flexible-start and net work-policy snapshots
230  tenant-scoped attendance Web Push subscriptions and delivery outbox
240  auditable multi-session attendance punches
```

## Running the optional Oracle lab

After supplying approved Oracle Instant Client archives and a private Oracle
environment file:

```powershell
docker compose `
  --env-file docker/oracle/oracle.env `
  -f compose.oracle.yaml `
  --profile oracle `
  run --rm oracle-migrate
```

Run the compatibility suite separately:

```powershell
docker compose `
  --env-file docker/oracle/oracle.env `
  -f compose.oracle.yaml `
  --profile oracle `
  run --rm oracle-test
```

Migration execution is deliberately separate from application startup.

## Production safety

Before any production migration:

1. confirm the selected database driver;
2. create and verify a restorable backup;
3. test the exact migration set on a production-like copy;
4. review destructive statements and expected locks;
5. schedule a maintenance window where required;
6. run migrations as an explicit operator action;
7. verify constraints, indexes, tenant isolation and application health.

Oracle and MySQL DDL may auto-commit. The migration ledger is not a substitute
for backup and restore.

## Rollback

Checkpoint 6 creates a clean Oracle schema and does not provide destructive
down migrations. Until real Oracle validation is complete, rollback means
discarding the isolated Oracle lab schema or restoring a verified backup.

Do not delete rows from `schema_migrations` to force a rerun. Partial DDL must
be investigated and recovered deliberately.
