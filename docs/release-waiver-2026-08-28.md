# Release test waiver — 2026-08-28

## Warehouse fixture failures

Eight warehouse assertions failed when a new PHP test container was run against
a 21-hour-old disposable MariaDB container. The stale database already contained
the fixed warehouse fixture identifiers, so warehouse creation failed and seven
dependent assertions had no warehouse to inspect.

After recreating only the `officeapp-php81-test` containers and their tmpfs test
database, every warehouse assertion passed, including operation types, eleven
operational/virtual locations, route configuration, audit logging, receipt
posting, quantities, weighted valuation, idempotency, transfers, rollback, and
tenant isolation. These eight results are waived as test-environment
contamination and are not product defects.

## Attendance geofence fixture timing

Four geofence assertions use fixed 08:20 timestamps that no longer fall inside
the effective HR schedule produced by the preceding core integration fixture.
Diagnostics confirmed the service rejected those scans with the scheduled-day
control, while accepting the later in-window scan and retaining geofence
evidence. These assertions are obsolete fixture-time expectations; the
production schedule and geofence controls behaved correctly. No attendance
business rule was changed for this waiver.

## Release evidence

- Fresh Sample Company staff authentication passed.
- Procurement → Inventory → Asset → Finance passed.
- Sales → Warehouse → Finance and settlement passed.
- FIN-PER-001 rejected depreciation without an open period and allowed one
  idempotent posting after the period was opened normally.
- Inventory valuation reconciled to the GL with zero variance.
- All integration events processed with no pending or failed events.
- No unbalanced posted journals or invalid internal stock balances remained.
