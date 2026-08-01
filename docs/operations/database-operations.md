# Database operations

## Supported engine

MySQL 8 and MariaDB 10.11 are supported. Oracle assets are experimental and
must not be used for production certification.

## Migration rules

```text
php bin/migrate.php
php bin/sync-reference-data.php
```

- Never edit a migration that appears in `schema_migrations`.
- Add the next numeric migration and include a strict preflight check.
- Migration failure must stop deployment. Do not manually mark it complete.
- Reference-data files must be idempotent.

## Backup minimum

Back up before every deployment and at the agreed daily interval. A backup is
not accepted until it has been restored into an isolated database and basic
authentication, tenant isolation and financial totals have been checked.

Example logical backup:

```text
mariadb-dump --single-transaction --routines --triggers DATABASE > backup.sql
```

Keep credentials outside shell history and scripts. Use the platform secret
manager or a protected client option file.

## Integration monitoring

```sql
SELECT status, COUNT(*)
FROM integration_outbox
GROUP BY status;

SELECT event_id, event_type, attempts, available_at, last_error
FROM integration_outbox
WHERE status = 'failed'
ORDER BY created_at;
```

Investigate repeated failures before retrying. Never delete an outbox event to
hide a failure. Correct the handler or data, then make the event available and
run the dispatcher.
