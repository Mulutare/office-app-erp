# Fixed Assets verification evidence

Date: 2026-08-11
Baseline: `6a400f3`

## Domain

Command: `docker compose -f compose.test.yaml run --rm app php tests/assets-domain.php`

Result: 5 checks, 0 failures. Verified 36 periods, ETB 1,500 first period, ETB 58,500 first-period NBV, ETB 6,000 salvage floor, final-period rounding, and invalid salvage rejection.

## Database integration and business lifecycle

Command: clean isolated MariaDB, migrations, reference synchronization, then `php tests/assets-integration.php`.

Result: 10 checks, 0 failures. Verified tenant category accounts, draft activation, persisted schedule, depreciation idempotency, routine maintenance, transfer history, disposal gain/loss, prevention of second disposal, future-period cancellation, balanced journals, tenant isolation, and Vendor → Input → Stock → Internal Asset Use capitalization. Five servers at ETB 120,000 were received; one was capitalized; stock ended at four; one authoritative capitalization movement and one asset at ETB 120,000 remained.

## Existing regression and schema

Command: `docker compose -f compose.test.yaml up --build --abort-on-container-exit --exit-code-from app`

Result: migration 046 applied/recognized, 26 reference files synchronized, 104 application tables and 386 foreign keys verified, 249 checks, 0 failures.

## Upgrade from production baseline

An isolated source export of commit `6a400f3` was built and executed without switching the working tree or creating a branch. A sentinel audit row was inserted into that baseline database. The current test image then applied migrations 026–046 to the same database.

Final queries: `sentinel=1`, `migration046=1`, `asset_tables=7`, `users=12`, `companies=7`. This proves migration 046 and its prerequisites apply to the named production baseline without losing the sampled existing data.

## Browser / HTTP

Temporary isolated Apache/PHP test server: `http://localhost:18080/office_app/public`.

- Unauthenticated `/assets` returned an application redirect to `/login`, not Apache's physical static-assets directory.
- Tenant A administrator authenticated and opened the licensed Assets register.
- Register displayed three persisted records, ETB 240,000 cost, ETB 3,000 accumulated depreciation, and ETB 237,000 NBV.
- Asset detail displayed SOLD state, 36 schedule periods, period 1 POSTED at ETB 1,500, remaining periods CANCELLED, one transfer, one maintenance record, and immutable lifecycle history.

Screenshots:

- `fixed-assets-register.png`
- `fixed-assets-lifecycle.png`

## Explicit limitations

- No controlled depreciation reversal/correction workflow.
- No capital-improvement modification workflow.
- No specialist Asset User/Asset Accountant role templates.
- No dedicated aggregate disposal/depreciation reports or import/export.
- No enforced serial-allocation linkage for inventory capitalization.
- Smart-button journal count/navigation is not separated from general asset history.
