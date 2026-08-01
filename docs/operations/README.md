# OfficeApp ERP operations manual

This directory is the deployment handover set. Update it whenever a release
changes startup, migrations, scheduled work, backup, recovery, health checks,
permissions, or module integration.

## Manuals

- `application-operations.md`: deploy, start, stop, health and scheduled work.
- `database-operations.md`: migration, reference data, backup and recovery.
- `maintenance.md`: release checklist, monitoring and recurring maintenance.
- `troubleshooting.md`: symptom-led diagnosis and safe recovery.

## Current cross-module flow

```text
Sales transaction
  -> integration_outbox (same database transaction)
  -> dispatch-integration-events.php
       -> Finance receivable and receipt projections
       -> Inventory sales commitments
```

Handlers are idempotent. Re-running the dispatcher must not duplicate Finance
receipts or Inventory commitments. A module must never write another module's
tables directly outside its registered integration handler.
