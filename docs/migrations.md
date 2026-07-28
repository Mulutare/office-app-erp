# Database migrations

## Engine separation

OfficeApp maintains database-specific migrations where syntax differs:

```text
database/migrations/          MySQL/MariaDB reference migrations
database/migrations/oracle/   Oracle migration definitions
```

Never run migrations for one engine against another engine.

## MySQL and MariaDB

The existing MySQL/MariaDB development and test environments continue loading
the reviewed `.sql` files through `docker/mysql/init/00-officeapp-init.sh` when
a fresh database volume is created. Existing persistent databases are not
modified automatically.

This behavior is retained to avoid disrupting the validated local workflow.

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
- records a SHA-256 checksum;
- skips unchanged applied migrations;
- stops if an applied migration file was modified;
- does not expose SQL or credentials in its user-facing failure message.

The current Oracle versions are:

```text
010  identity, access and company tenancy
020  module catalog and company entitlements
030  tenant-scoped human resources
040  tenant-scoped finance
050  login and audit history
060  stable roles, permissions and module reference data
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
