# Enterprise module and integration audit

Audit date: 2026-08-01

Scope: current `main` release candidate, application code, MySQL/MariaDB
migrations, reference data, authorization boundaries, tenant isolation,
implemented module workflows, and registered cross-module integrations.

## Release conclusion

No release-blocking integrity failure remains in the currently implemented
modules. The application and database health checks pass, all PHP sources are
syntactically valid, migrations and authorization reference data are
repeatable, and the isolated integration suite passes on a fresh database.

This conclusion does not classify every module in the product catalogue as
implemented. Modules intentionally marked unavailable remain roadmap items and
must not be licensed or shown in tenant navigation until their own acceptance
work is complete.

## Module status

| Area | Runtime status | Integration and control evidence | Audit result |
|---|---|---|---|
| Administration | Implemented | Company lifecycle, users, roles, password reset, unlock, audit logs, platform-account protection, and privilege-escalation tests | Pass |
| Organization | Implemented platform service | Tenant-scoped branches, departments, job titles, positions, effective assignments, hierarchy/cycle checks | Pass |
| HR | Implemented | Licensed-module gate, employee isolation, leave policies, staged approval, balances, manager/self-service separation | Pass |
| Attendance | Implemented | Licensed-module gate, calendars, work policies, scan windows, overnight schedules, immutable scan evidence, reminders and Web Push | Pass |
| Finance | Implemented reporting/projection baseline | Tenant-scoped dashboard plus idempotent Sales receivable and receipt projections | Pass for current scope |
| Sales | Implemented partial ToR baseline | Licensed-module gate, professional roles, order workflow/SoD, payments, targets, serials, commissions, credit controls, durable integration events | Pass for implemented scope; remaining ToR gaps stay in the Sales compliance matrix |
| Procurement, Inventory UI, CRM, Projects, Help Desk, IT Assets, Payroll, Documents and other unavailable catalogue modules | Not implemented/available | `available = FALSE`; no tenant navigation or public business routes | Correctly fail-closed |

Inventory currently participates only through the internal idempotent Sales
commitment projection. This is not equivalent to a completed Inventory user
module.

## Cross-module flows

### Sales to Finance and Inventory

1. Sales writes the order/payment and integration event in the same database
   transaction.
2. The dispatcher atomically claims events, observes per-aggregate ordering,
   recovers expired leases, and applies bounded retries/dead-letter state.
3. Finance creates idempotent receivable/receipt projections.
4. Inventory creates idempotent Sales commitment projections.
5. Integration tests verify retry behavior, duplicate prevention, failure
   isolation, and cleanup.

### HR, Organization and Attendance

- Employees, reporting managers, branches, departments, positions, calendars,
  and attendance records are resolved inside the active company.
- Effective-dated calendars reject overlapping employee assignments.
- Attendance stores schedule and department snapshots so later organization or
  policy changes do not rewrite historical results.
- Leave and attendance self-service require a linked employee, while team
  actions are bounded to the reporting relationship and company.

## Authorization audit

- Navigation is generated only from enabled/licensed modules and effective
  permission namespaces.
- Controllers repeat module and permission enforcement; UI hiding is not used
  as the security boundary.
- Cross-company reads and mutations are denied by scoped repositories and
  service checks.
- Platform administrators remain isolated from tenant workspaces unless they
  have an explicit membership.
- Sales provides task-level permissions and seven professional job roles.
- Sales export requires `sales.reports.export`; integration replay is reserved
  for system administration; order self-approval is denied.

## Database and deployment audit

- Sixteen forward migrations are recorded and unchanged on the live local
  database.
- A fresh MariaDB database applies migrations 026-030 and baselines legacy
  definitions 015-025 correctly.
- Twenty-one reference-data files synchronize repeatedly without duplicate
  permission grants.
- Migration checksums are stable across LF and CRLF working copies.
- The standard container excludes optional Oracle libraries. Oracle remains a
  documented adapter/skeleton and is not certified as feature-equivalent for
  Sales.

## Verification evidence

- Full isolated suite: 226 checks, 0 failures.
- Sales structural contract: pass.
- PHP syntax audit: every application, controller, repository, service, route,
  view, test, command, and MySQL forward migration passes.
- Live `/health`: application healthy, database connected.
- Running application and database containers: healthy.

## Known non-blocking product gaps

- The Sales ToR remains partial beyond the implemented controlled baseline;
  quotations, invoicing, returns, full Inventory reservation/fulfilment,
  expanded analytics, and other items remain tracked in
  `docs/sales-tor-compliance.md`.
- Finance is currently a dashboard and Sales projection baseline, not a full
  general-ledger/accounting implementation.
- Unavailable catalogue modules require separate domain design, migrations,
  RBAC, integration contracts, operations documentation, and acceptance tests
  before activation.
- Oracle production equivalence and representative production-volume testing
  remain deployment certification activities.

## Release controls

Before deployment, run migrations, synchronize reference data, execute the
dispatcher under the documented scheduler, confirm backup/recovery readiness,
and repeat health and smoke checks in the target environment.
