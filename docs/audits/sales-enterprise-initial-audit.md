# Sales enterprise initial audit

Audit date: 2026-08-01
Scope: local `main` workspace and local development database only
Authoritative source: company ERP ToR, Sales requirements 103-112 and cross-cutting requirements 178-212

This is the mandatory pre-implementation audit. Status reflects verified behavior, not the presence of a screen or table.

| Requirement | ToR source | Current implementation | Evidence | Status | Required correction | Test required |
|---|---|---|---|---|---|---|
| Sales order processing | ToR 104 | Draft/submitted/approved/fulfilled/cancelled plus payment states; no quotations, invoices, rejection, holds, returns or closure | Routes, `SalesService`, migrations 026/029 | Partial | Add complete controlled lifecycle and immutable transition history | Allowed/forbidden transitions, retry safety, immutability |
| Telecom product tracking | ToR 105 | Product catalogue and Sales-owned serial registry; allocation table unused | migrations 026/029, Sales UI | Partial | Move operational serial allocation/reservation semantics to Inventory integration; add batch/fulfilment references | Serial/batch reservation, cross-tenant denial, cancellation release |
| Commission automation | ToR 106 | Product percentage accrued at approval; manual approve/pay | repository `accrueCommission()` | Partial | Add explicit earning policy, effective plans, reversal and statements | Accrual, approval, pay, cancellation/return reversal |
| Sales target setting | ToR 107 | Amount/quantity by territory or agent; achievement query | `sales_targets`, repository `targets()` | Partial | Add branch/product/collection/margin targets, revision approval and drill-down | Exclusions, revisions, multiple periods, tenant isolation |
| Territory management | ToR 108 | Flat territory with nullable branch; UI cannot select branch | `sales_territories`, controller/service | Partial | Add hierarchy, region, branch enforcement and assignment history | Hierarchy, reassignment, branch denial |
| DSA/DSP tracking | ToR 109 | DSA/DSP catalogue with optional territory; no effective assignment history | `sales_agents` | Partial | Add channel/distributor model and effective-dated assignments | Inactive agent, assignment dates, cross-tenant denial |
| Customer database | ToR 110 | Number, type, one address, tax, basic contact, credit limit/terms | `sales_customers` | Partial | Add billing/delivery, contacts, class/group/currency, credit mode/status/risk, branch/agent and duplicate tax controls | Duplicate number/tax, credit modes, inactive/cross-tenant cases |
| Sales dashboard | ToR 111 | Five totals, order list, targets; no filters, margin, aging buckets or branch/product/customer analysis | repository `dashboard()`, view | Partial | Add indexed, filtered KPI/report queries, drill-down and authorized export | Filter isolation, totals, pagination, CSV/PDF authorization |
| Receivables tracking | ToR 112 | Order-level balance, partial/full payments, basic overdue total and Finance projection | payments and Finance handler | Partial | Add allocations, reversals, refunds, credit notes, write-off, statements and aging | Overpayment, duplicate, reversal, reconciliation, aging |
| Multi-branch operation | ToR 29, 181 | Branch columns exist on territory/order but service always writes `branch_id = null`; no branch scoping | service create methods and local DB (zero branches) | Incorrect | Require valid tenant branch when configured and enforce branch visibility | Foreign branch selection and visibility denial |
| RBAC / segregation | ToR 183 | Task-level permissions, seven professional Sales roles, licensed-module enforcement, protected export, and creator/approver segregation | seeds 018-021, controller/view | Implemented baseline | Add HTTP-level denial coverage for every role/action combination | 403 matrix, UI visibility, and self-approval denial |
| Audit trail | ToR 184 | Creation/transitions/payments audited after repository commit; before-state and branch/request metadata absent | `SalesService` audit calls | Partial | Persist immutable business history in transaction and enrich audit context | Before/after, actor, tenant, branch, reason and failure cases |
| Order numbering | Professional control | Timestamp plus random 4 digits; unique constraint catches collision | `SalesService::createOrder()` | Unsafe | Add locked tenant/branch document sequences | Concurrency, retry and tenant collision tests |
| Calculations | Professional control | Server recalculates product price, line discount/tax and totals; UI only renders 3 lines; controller accepts 20 | service and view | Partial | Remove UI cap; centralize reusable calculator; add order discount, inclusive tax, charges, FX, cost/margin | Unlimited lines, rounding, tax, discounts and totals |
| Price/discount control | Professional control | Catalogue unit price and line discount only | product/order service | Missing | Add effective price lists, override/discount permissions, floors and audit history | Effective price, threshold, override and negative margin denial |
| Credit management | Professional control | Submission check only when credit limit > 0; zero means unlimited implicitly | service `createOrder()` | Incorrect | Explicit none/unlimited/fixed modes, exposure/overdue holds and checkpoint rechecks | Hold/release, zero semantics, overdue and concurrency |
| Approval workflow | Professional control | One approval action; same creator may approve; no history/comments/rules | migration 029 and repository transition | Unsafe | Configurable multi-level rules, inbox, rejection/correction and immutable history | SoD, levels, rules, delegation and retry safety |
| Inventory integration | ToR 94 and professional control | Approval publishes `sales.order.confirmed`; handler directly inserts simplistic commitments without stock check | outbox and Inventory handler | Partial | Add reservation request/result/release, warehouse, partial/backorder and fulfilment events | Failure/retry/order/release/idempotency |
| Finance integration | ToR 34, 112 | Approval opens receivable; payments post idempotent receipt and update projection | Finance handler | Partial | Define invoice trigger, reversal/credit-note/refund/write-off and reconciliation | Duplicate, ordering, reversal and negative-balance prevention |
| Returns/cancellations | Professional control | Pre-payment cancellation with reason; returned status exists but no route/rules/events | repository transition, migration 029 | Missing | Add return authorization, quantities/serials and Inventory/Finance/commission reversal events | Full/partial return and no-hard-delete tests |
| Notifications/tasks | Professional control | None for Sales; Attendance notification infrastructure is separate | service/routes search | Missing | Add durable in-app Sales tasks and integration failure notifications | Recipient scope, deduplication and read state |
| Outbox atomicity | Professional control | Business events inserted inside order/payment transactions | repository `enqueue()` | Complete | Preserve and version event contracts | Rollback proves no orphan event |
| Outbox ordering/locking | Professional control | Global auto-increment ordering; query does not lock/claim, block later aggregate events or prevent concurrent dispatch | dispatcher/repository | Unsafe | Atomic claim with lease, per-aggregate blocking, exponential retry, dead-letter/replay permission | Concurrent dispatch, failed predecessor, dead-letter/replay |
| Validation/data quality | Professional control | Several positive validations and DB uniqueness; missing duplicate tax, branch, inactive negative-path and role thresholds | service/schema/tests | Partial | Add complete validation and human-safe conflict handling | Full negative matrix |
| Reporting/export | ToR 171, 176-177 | Order CSV only; no PDF/Excel-ready detailed report or filters | controller export/view | Partial | Add filters, paginated reports, sensitive export permission and print/PDF view | Authorization, escaping and totals |
| Tests | ToR 193-198 | One structural contract and one happy-path integration test; full suite has 10 unrelated Attendance failures | `tests/sales-*`, isolated suite | Partial | Add focused domain, RBAC, tenancy, integration reliability, HTTP and migration-upgrade tests | Required 30-area matrix |
| Documentation/training | ToR 203, 205-207 | Short module, compliance and generic operations notes | `docs/sales-*`, operations docs | Partial | Produce all fourteen requested Sales/operations artifacts | Documentation contract and link checks |
| Runtime compatibility | ToR 178-188 | PHP 8.1 floor, MariaDB container, parameterized SQL and CSRF; Oracle Sales adapter absent; MySQL 8 live certification incomplete | composer/runtime checks, repository factory | Partial | Maintain PHP 8.1/MySQL 8/MariaDB SQL; document Oracle limitation; add volume/performance checks | PHP 8.1, MySQL/MariaDB migration and representative volume |

## Local-state findings

- Two companies and no organization branches exist locally.
- Sales sample data: one territory, one customer, one product, two agents; no orders, payments, commissions, targets or serials.
- The integration outbox is empty.
- Sixteen Sales permissions and seven least-privilege Sales roles exist. Company owner and system administrator retain full access; executive viewer remains read-only.
- Local Git `main` is clean but one commit ahead of `origin/main` at audit start.

## Phase 1 conclusion

The nine ToR headlines are represented, but none is enterprise-complete end to end. Order processing, branch isolation, approval segregation, credit semantics, integration concurrency, returns, reporting, negative tests and operational documentation require material correction. Existing migrations 026-029 must remain immutable; all schema corrections must start at migration 030.
