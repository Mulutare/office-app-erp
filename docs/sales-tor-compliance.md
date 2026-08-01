# Sales ToR compliance matrix

This document distinguishes the company ToR from professional controls added beyond it. “Implemented” never means complete unless database, tenant/branch scope, RBAC, validation, audit, UI, service rules, integrations, tests and user documentation are all evidenced.

| Explicit company ToR requirement | Existing implementation | Gap identified | Professional control required beyond ToR | Evidence | Automated test | Status |
|---|---|---|---|---|---|---|
| Sales order processing | Tenant order/header/lines, server totals, draft/submission/approval/fulfilment/cancellation/payment | No quotation, invoice, holds, rejection, return, close, immutable history or branch enforcement; UI has three lines | Full document lifecycle, locked numbering, SoD approval, transition history, retry safety | migrations 026/029; Sales layers/routes | Happy path only | Partial |
| Telecom product tracking | Product catalogue, serial-required flag, serial registry | No batch, effective price, branch/warehouse ownership or actual serial allocation/fulfilment | Inventory-owned reservation/serial/batch fulfilment and effective pricing | products/serial tables; Inventory handler | Registration only | Partial |
| Commission automation | Product-rate accrual on approval, manual approval/payment | No plan/effective date/tier/cap/policy/reversal/statement | Configured earning policy, plans and reversal lifecycle | repository commission methods | Accrual/approve only | Partial |
| Sales target setting | Territory/agent amount and quantity period targets with actual | No branch/product/collection/customer/margin, revision approval, ranking or drill-down | Versioned approved targets and multi-dimensional KPI reporting | target schema/query/UI | Happy path only | Partial |
| Territory management | Flat tenant territory, nullable branch | No hierarchy/region, branch UI, reassignment history or effective dates | Hierarchy and effective channel assignments | territory schema/service | Creation only | Partial |
| DSA/DSP tracking | DSA/DSP type, code, name, optional territory | No distributor/channel, history, effective assignment or branch controls | Effective assignment ledger and branch/channel scope | agent schema/service | Creation only | Partial |
| Customer database | Number/type/name/tax/contact/address/credit limit/terms/territory | Missing billing/delivery separation, contacts, class/group/currency/credit mode/status/risk/branch/agent and duplicate-tax rule | Complete master, explicit credit semantics and change audit | customer schema/service | Creation only | Partial |
| Sales performance dashboard | Orders, sales, receivable, overdue, commission and target table | No filters, branch/product/customer analysis, margin, aging, pending actions, drill-down, PDF or sensitive-export permission | Indexed filtered KPIs, aging, pagination and authorized exports | dashboard query/view/export | Structural only | Partial |
| Receivables tracking | Order balance, due date, partial/full payment, Finance projection | No allocation, reversal/refund/credit note/write-off/statement/aging buckets/reconciliation | Finance-owned accounting lifecycle with idempotent events | payment repository and Finance handler | Partial payment only | Partial |

## Cross-cutting verification

| Control | Result | Status |
|---|---|---|
| Database model | Core tables exist; enterprise lifecycle/master/history structures do not | Partial |
| Tenant isolation | Most reads/writes filter `company_id`; composite ownership is not enforced on every foreign key | Partial |
| Branch/location isolation | Nullable columns exist but service writes null and visibility is not scoped | Incorrect |
| RBAC | Eight coarse permissions; no professional roles/operation separation | Unsafe |
| Validation | Positive validations exist; major negative/duplicate/threshold cases missing | Partial |
| Audit | High-level actions logged after commit; before-state and branch/request context missing | Partial |
| UI | Single large screen; limited lines, no inbox or lifecycle documents | Partial |
| Service rules | Basic totals/credit/transition logic; no configurable policies | Partial |
| Integration | Atomic outbox and idempotent projections; concurrency and predecessor blocking unsafe | Partial |
| Automated tests | One structural and one happy-path integration script | Partial |
| User documentation | Short module/operations notes; required training/admin/UAT set missing | Partial |

## Final status before enterprise corrections

The nine company Sales requirements are **Partial**, not Complete. Detailed corrections and acceptance evidence are tracked in `docs/audits/sales-enterprise-initial-audit.md` and `docs/audits/sales-professional-gap-matrix.md`.
