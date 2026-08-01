# Sales professional gap matrix

| Area | Verified current state | Status | Completion requirement |
|---|---|---|---|
| A. Master data | Basic customer/product/territory/agent records | Partial | Complete attributes, effective history, branch scope and duplicate controls |
| B. Document lifecycle | Draft, submitted, approved, fulfilled, paid, cancelled | Partial | Quotations, holds, rejection/correction, invoice, return and close with history |
| C. Numbering/control | Timestamp/random order number | Unsafe | Locked tenant/branch sequences and immutable document/version references |
| D. Lines/calculation | Server calculations; view limited to three | Partial | Dynamic lines, inclusive/exclusive tax, order discounts, charges, FX, cost/margin |
| E. Price/discount | One product price; free line discount | Missing | Effective price lists, override/threshold permissions and margin floor |
| F. Credit | Fixed positive limit checked at submission | Incorrect | none/unlimited/fixed modes, exposure/overdue holds and checkpoint rechecks |
| G. Approval | Single approval; creator may self-approve | Unsafe | Configurable levels, SoD, comments, rejection/correction, delegation/history |
| H. Inventory/fulfilment | Approval creates commitments directly | Partial | Request/result/release events, warehouse, partial/backorder, dispatch/delivery |
| I. Finance/receivables | Receivable and receipt projections | Partial | Invoice event, allocation, reversal/refund/credit/write-off/aging/reconciliation |
| J. Commission | Product percentage accrued at approval | Partial | Effective plans, policy, tiers/caps/accelerators, reversal and statements |
| K. Targets/performance | Territory/agent amount/quantity | Partial | Dimensions, revisions/approval, variance/trend/rank/drill-down |
| L. Returns/cancellation | Pre-payment cancellation; unused returned state | Missing | Authorized return lifecycle and compensating Inventory/Finance/commission events |
| M. Dashboard/reporting | Five KPIs, lists, CSV | Partial | Filters, aging, margin, dimensions, action queues, PDF/Excel-ready exports |
| N. RBAC/SoD | Eight permissions, three roles | Unsafe | Task permissions, professional roles and explicit 403 tests |
| O. Tenant/branch | Company filtering; branch unused | Partial | Composite ownership validation and branch visibility/numbering |
| P. Audit/compliance | Service-level action logs | Partial | Immutable histories plus before/after, branch, IP/request and reason |
| Q. Notifications/tasks | No Sales notifications | Missing | Durable in-app tasks for approvals, holds, overdue and dispatcher failures |
| R. Integration reliability | Atomic outbox, retry and idempotent consumers | Unsafe | Atomic claims, leases, predecessor blocking, backoff, dead-letter and replay |
| S. Data quality | Basic validation and unique customer/SKU/receipt | Partial | Complete duplicate, inactive, cross-tenant and threshold matrix |
| T. Nonfunctional | PHP 8.1+, MariaDB, CSRF, escaping, parameterized SQL | Partial | MySQL certification, pagination/volume checks, responsive contract and package audit |
