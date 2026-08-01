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

Sales migration `029` preserves legacy confirmed orders, adds approval and
cancellation attribution, adds commission control, and creates the serial
registry. Always synchronize reference data afterward so the new Sales
permissions are available.

## Sales integrity checks

```sql
SELECT status, COUNT(*), SUM(total_amount), SUM(paid_amount)
FROM sales_orders GROUP BY status;

SELECT status, COUNT(*), SUM(commission_amount)
FROM sales_commissions GROUP BY status;

SELECT status, COUNT(*) FROM sales_serial_numbers GROUP BY status;
```

Never change these statuses directly; use the application workflow so audit
records, attribution and integration events remain complete.

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
