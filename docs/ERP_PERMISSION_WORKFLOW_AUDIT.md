# ERP permission and workflow audit

Audit date: 2026-08-29  
Repository commit reviewed: resolve with `git rev-parse HEAD` at release time  
Method: repository-wide route, controller, service, repository, view, migration, reference-data, operations and test inspection. No production system or production data was accessed. No business remediation or schema change was made.

## 1. Executive summary

The repository contains a coherent tenant-aware ERP foundation: authentication and session controls, organization/HR/attendance, Sales, Procurement, Inventory, Finance, Fixed Assets, Analytics, Data Exchange, integration processing, audit/error registries and production runners. There are 40 controllers, 103 services, 81 repository files, 93 views and 256 declared GET/POST routes. Tenant predicates, composite company keys, transactions, idempotency keys, period controls and reconciliation services are widely used.

Production readiness is nevertheless blocked by one critical fulfilment-control defect and one critical authorization-model gap. Sales orders do not store or request a fulfilment warehouse and source location. Confirmation delegates selection to Inventory, which chooses a branch-matching/default/first warehouse and its default delivery source. No normalized user-to-warehouse/location operational scope exists. Consequently a user with broad Sales confirmation and Inventory transfer-management permissions can cause stock to be reserved and delivered from a resource they never selected and for which the server cannot prove operational assignment.

The package/build work is independent of these findings. Business remediation must follow the dependency order in `ERP_REMEDIATION_PLAN.md` and use a new forward migration after 067.

### Audit disposition

| Area | Result | Reason |
|---|---|---|
| Authentication, session and tenant membership | PARTIAL | Strong core and tests exist; resource scope stops mainly at company and selected HR relationships. |
| HR, leave and attendance | FAIL | Core HR passed, but the focused geofence suite has reproducible accepted-punch failures. |
| Sales | FAIL | Fulfilment identity is missing and silently inferred. |
| Procurement | PARTIAL | Controlled lifecycle and integration exist; operational destination scope and complete role-negative testing are incomplete. |
| Inventory | FAIL | Company-scoped integrity exists, but warehouse/location operational authorization does not. |
| Finance | PARTIAL | Balanced/idempotent posting and period controls exist; live execution/reconciliation certification was blocked. |
| Fixed Assets | PARTIAL | Lifecycle and Finance integration exist; specialist roles and full invalid-state execution are incomplete. |
| Integration/System/Data Exchange/Analytics | PARTIAL | Tenant permissions, retries, worker health and import validation exist; live worker execution was blocked. |

Finding totals: **2 CRITICAL, 7 HIGH, 7 MEDIUM, 3 LOW**.

## 2. Complete module and screen-purpose map

The following records use the required purpose vocabulary. Repeated list/detail/create/edit routes are grouped only where they perform the same business function.

### Authentication and security

| Module / submodule | Purpose / users / manager | Input → output | Preconditions / state / downstream / next step |
|---|---|---|---|
| Login, logout, password change | Establish or end an authenticated identity; all users; security administrators manage accounts | credentials/session action → tenant session or terminated session | Active account and membership; session and audit state changes; select company or open dashboard. |
| Company context | Select one authorized tenant workspace; multi-company users; company administration manages membership | company ID → refreshed permissions/modules | Server membership lookup; session tenant changes; all later queries must use this company. |
| Authenticated sessions | View and revoke a user's sessions; account/security administrators | session ID → revoked session | Tenant permission and ownership checks; affected client must reauthenticate. |
| Users, roles and permissions | Provision accounts and least-privilege grants; company administrators; platform controls software-owner identities | identity, roles, status → membership/grants/audit log | Company scope, anti-self-lockout and privilege-escalation checks; user can perform assigned work. |
| Audit log, user/employee activity, Error Registry | Investigate accountable changes, access and incidents; authorized auditors/support | filters/reference → immutable event/incident evidence | Tenant-scoped read; follow incident reference or remediation. |

### Organization, employee, HR and attendance

| Module / submodule | Purpose / users / manager | Input → output | Preconditions / state / downstream / next step |
|---|---|---|---|
| Organization setup, branches, departments, job titles, positions | Maintain company structure used by HR, branch accounting and attendance; HR/company administrators | organization master data → active hierarchy | Tenant and management permissions; assign positions/employees. |
| Employees and position assignments | Maintain employment identity and placement; HR users; HR managers | employee/person/position data → company employee record | Company-owned references; drives self-service, manager scope, attendance and leave. |
| Employee Self Service / manager workspace | Give employees their own record and managers their team context; employees/managers | current identity → scoped personal/team data | User-to-employee link; no arbitrary employee ID trust; take leave/attendance action. |
| Leave requests, approvals, policies and balances | Request, approve and account for leave; employees/managers/HR | dates/type/decision/allocation → request and balance movements | Policy/balance/approval chain; status transitions; calendar and balance effects. |
| Attendance administration | View/correct authorized attendance; HR attendance administrators | employee/date/time/source → attendance record | Company permission and valid employee; reporting downstream. |
| Attendance self-service, sessions, scans, reminders and push | Record a scheduled employee's presence and notify them; employee; HR configures policy | time/device/geolocation → active session then final record | Linked employee, schedule, branch and geofence evidence; check-in → open session → check-out. |
| Workforce calendars, holidays, schedules and work policy | Define expected work; HR attendance administrators | calendar/week/holiday/assignment → expected schedule | Company-scoped active references; attendance evaluation uses it. |

### Sales

| Module / submodule | Purpose / users / manager | Input → output | Preconditions / state / downstream / next step |
|---|---|---|---|
| Customers and products | Maintain commercial parties and shared product master; Sales users/managers | master data → active customer/product | Tenant and active-state checks; use in quotation/order. Product also drives Inventory and accounting. |
| Pricelists, rules, teams, agents, territories and targets | Configure pricing and commercial responsibility; Sales managers | rules/members/periods → pricing and responsibility | Active company references; create quotation/order and report performance. |
| Quotations and pro forma | Offer controlled terms and convert accepted offer; Sales users/managers | customer, lines, prices, dates → draft/sent/confirmed/cancelled quote | Active masters and permission; confirmation creates/links order; next step is order approval. |
| Sales orders | Commit a customer demand through draft/submitted/approved/confirmed/cancelled states; Sales users/managers | customer, branch, lines, commercial terms → order | Totals and state validation; **fulfilment warehouse/location missing**; confirmation emits Inventory work. |
| Deliveries, reservations and returns | Reserve exact stock, validate physical issue and reverse returned stock; Inventory operators | picking quantities/idempotency → movement, valuation and delivery status | Currently warehouse/source inferred; transaction/locks needed; completion drives COGS and invoicing. |
| Customer invoices, credit notes, payments and settlements | Bill, collect and reconcile customer funds; Finance/Sales settlement roles | delivered quantities/payment evidence/bank confirmation → AR, payment, settlement and journals | Open period, state, separation of duties and idempotency; reconcile and close. |
| Sales API, OAuth and webhooks | Permit scoped third-party sales automation; service accounts/integration admins | token/scope/idempotency/external refs → orders, payments, events | Company-bound client and permission mapping; inspect event delivery. |

### Procurement

| Module / submodule | Purpose / users / manager | Input → output | Preconditions / state / downstream / next step |
|---|---|---|---|
| Suppliers | Maintain vendor master; Procurement users/managers | supplier data/status → company supplier | Tenant and active state; use on PO. |
| Requisitions | Capture internal demand and approval; requesters/approvers | lines/reason/decision → approved demand | Maker/checker when policy applies; create PO. No separate RFQ module was found: **PURPOSE_UNCLEAR** if RFQ is expected operationally. |
| Purchase orders | Commit approved buying terms; Procurement users/managers | supplier, lines, destination, approval → confirmed PO | Active supplier/product and approval; receive goods. Destination is document-level but user operational assignment is not modeled. |
| Goods receipts and vendor returns | Validate incoming/outgoing supplier stock; receiving operators | warehouse/location, quantities, idempotency → stock/valuation movements | Exact company/warehouse/location relationships and PO balance; bill received quantities. |
| Vendor bills and payments | Recognize AP and settle vendor liability; Procurement/Finance | receipt quantities, supplier invoice, payment → AP and balanced journals | Open period, uniqueness and state checks; reconcile/close PO. |

### Inventory

| Module / submodule | Purpose / users / manager | Input → output | Preconditions / state / downstream / next step |
|---|---|---|---|
| Warehouses, locations, routes and operation types | Configure physical topology and defaults; Inventory managers | hierarchy/defaults/active state → topology | Company ownership and hierarchy checks; operators use topology. This is configuration, not ordinary operation. |
| Current stock and balances | Show on-hand, reserved and available stock; authorized operators/managers | company/product/location filters → stock position | Must be operationally scoped; currently only company/permission scope is evident. |
| Movements, transfers and adjustments | Execute traceable stock changes; Inventory operators | source/destination/product/quantity/key → movement and balances | Exact location/warehouse relationship, stock availability, transaction and idempotency; valuation/GL follows. |
| Receipts | Approve and post incoming stock; receiving/approvers | receipt lines/destination → stock and valuation | Maker/checker and PO/active topology; vendor bill follows. |
| Reservations and delivery pickings | Protect demand and perform customer issue; Inventory operators | order/picking lines/source → allocations and movements | Exact selected fulfilment required but absent for Sales; completion drives valuation/Finance. |
| Valuation reconciliation | Compare movement valuation subledger with GL; Finance/Inventory control | company/period → variance report | Posted movements/journals; investigate any non-zero variance. |

### Fixed Assets

| Module / submodule | Purpose / users / manager | Input → output | Preconditions / state / downstream / next step |
|---|---|---|---|
| Categories | Configure depreciation and account defaults; Asset managers | category/accounts/life → active category | Tenant and Finance references; create/capitalize asset. |
| Asset register/capitalization | Recognize controlled asset cost; Asset/Finance users | acquisition/cost/category/location → draft/capitalized asset | Valid category/open period/source; activate. |
| Activation and depreciation schedule | Put asset in service and systematically expense it; Asset accountant | in-service date/schedule line → active asset and posted depreciation | Sequential line and exactly one open period; next scheduled line. |
| Transfer, maintenance and tracking | Preserve custody/location/condition history; Asset users/managers | destination/activity/health → history | Active asset and company references; continue use or dispose. |
| Disposal | End asset life and post financial effect; Asset/Finance manager | disposal date/proceeds/reason → disposed asset/journal | Not already disposed, open period, unique disposal; archive/report. |

### Finance, analytics and system

| Module / submodule | Purpose / users / manager | Input → output | Preconditions / state / downstream / next step |
|---|---|---|---|
| Chart of accounts and journals | Configure tenant accounting classifications; Finance managers | account/journal setup → active posting targets | Company scope; consumed by all posting modules. |
| Journal entries | Record balanced immutable accounting effects; Finance users/managers | dated balanced lines/key → posted batch | Exactly one open period, debit=credit, tenant references and idempotency. |
| Accounting periods/fiscal years | Control when posting is permitted; Finance managers | ranges/status/reason → open/closed/locked period | Non-overlap and controlled reopen; downstream posting gate. |
| Receivables/payables/invoices/bills/payments | Present and settle customer/vendor balances; Finance/AP/AR users | source document/payment → residual and GL | Posted source, currency and open period; settlement/reconciliation. |
| Approval policies | Configure amount-based maker/checker; company/Finance administrators | action/threshold/required permission → approval rule | Tenant permission; used by PO and other controlled actions. |
| Analytics/Power BI | Present authorized performance data; managers/analysts | company metrics/config → reports | Module/analytics permission and protected secret encryption. |
| Integration events, workers and retries | Deliver cross-module/outbound effects reliably; operations admins | outbox event/retry → processed/failed state | Tenant retry permission, claim/idempotency and production runner health. |
| Data Exchange | Controlled import/export of masters/documents; module operators/managers | CSV/XLSX/schema/selection → preview/import/export | Module-specific import/export permission, file guard, tenant validation and external IDs. |
| Module/company administration and provisioning | License modules and provision tenants; platform/company administrators | company/module/config → workspace | Platform protection and entitlements; company users can access enabled modules. |

No separate Manufacturing, Payroll, CRM pipeline, RFQ/tender, budget, fleet, helpdesk or project-accounting implementation was found. These are absent, not dead routes.

## 3. Permission and role audit

### Representative role matrix

Actual permission codes are used; named role templates vary by seed/company. “Forbidden” means the role must not receive the listed permission merely to complete its job.

| Representative role | Authorized | Forbidden / separation required | Result |
|---|---|---|---|
| Company Owner | Tenant administration and all licensed-module work by current implicit model | Platform-company control outside owned company | PARTIAL: broad implicit access is defensible, but must not substitute for operator scope design. |
| Company Administrator | users/roles/organization/module configuration as delegated | Platform owner protection, ungranted Finance posting | PARTIAL |
| Sales User | `sales.view`, catalogue view, order create/submit, own drafts | approve own order; Inventory configuration | PARTIAL |
| Sales Manager | approve/confirm/cancel and commercial management | silently choose stock source; unrestricted Finance posting | FAIL |
| Inventory Operator | stock view, receive/deliver/transfer in assigned topology | warehouse/location configuration, unassigned topology | FAIL: assignment layer absent; `inventory.transfers.manage` is excessive. |
| Inventory Manager | warehouse/location configuration plus controlled operations | cross-company resources | PARTIAL |
| Procurement User | suppliers/requisitions/PO create/receipts as assigned | approve own controlled document, Finance period management | PARTIAL |
| Procurement Manager | approve/confirm/returns within policy | pay/post without separate Finance authority where policy requires | PARTIAL |
| Finance User | records view, assigned AR/AP work | period/config management and approvals not granted | PARTIAL |
| Finance Manager | post, periods, approvals, bank/settlement controls | approve own maker/checker item | PARTIAL |
| Asset User/Manager | view, maintain, activate/transfer/dispose as separately granted | depreciation/Finance posting unless granted | PARTIAL: specialist seeded templates absent. |
| HR/Attendance Administrator | company HR records, policies, schedules/corrections | unrelated company/employee and platform administration | PARTIAL |
| Employee/self-service | own attendance/leave and authorized team views | arbitrary employee, HR master or company-wide attendance | PASS by inspected controller/service design; runtime negative test blocked. |

### Operational use versus management

| Resource | Operational permissions present | Management permission | Finding |
|---|---|---|---|
| Warehouse/location selection | No explicit `inventory.warehouses.use` or assignment-backed equivalent | `inventory.warehouses.manage` | CRITICAL gap. |
| Delivery/internal transfer | `inventory.transfers.manage` | Same permission performs operational validation | HIGH conflation. |
| Receipt | create/approve/post are separated | warehouse topology remains `manage` | Good action separation; scope absent. |
| Sales order | create/submit/approve/confirm/cancel separated | catalogue/targets separately managed | Good action separation; fulfilment resource decision absent. |
| Procurement | requisition/order/receipt/bill/payment actions separated | supplier management separate | Good baseline; destination resource assignment absent. |
| Finance | view/manage, period, bank and settlement permissions | several posting operations still share broad `finance.records.manage` | MEDIUM breadth. |
| Assets | view/manage/activate/depreciation/dispose separated | category/master shares `assets.manage` | Acceptable baseline, specialist roles needed. |
| Attendance/leave | self/team/company and policy/balance permissions separated | HR record management separate | Strongest least-privilege model inspected. |

### Scope matrix

| Scope | Enforced evidence | Gap |
|---|---|---|
| Company | TenantContext, company-bound membership/permissions, pervasive `company_id`, composite unique/FK keys | Maintain negative tests for every write. |
| Branch | Branch belongs to company; Sales/Finance carry branch; attendance assignment resolves branch | No general user-to-branch operational assignment for Sales/Inventory/Finance. |
| Warehouse | Company-owned and active/deleted checks in repositories | No user assignment; silent selection bypasses intent. |
| Location | Composite warehouse/location relations and company predicates | No user assignment; authorization cannot be narrower than warehouse. |
| Employee/user | Self-service resolves authenticated link; team approvals use reporting relationship | Administrative HR permissions remain broad company scope by design. |
| Customer/vendor/product/accounting record | Company predicates and composite relationships | Continue tampered-ID tests; active/archived enforcement varies by transition. |

## 4. Workflow and state-transition audit

### Sales workflow

`Customer → Product → Quotation(draft → sent → confirmed) → Sales Order(draft → submitted → approved → confirmed) → [missing explicit warehouse/source decision] → reservation → Delivery(draft/waiting_stock/ready → partially_done/done) → Invoice(draft → posted) → Payment(posted) → Settlement(draft → submitted → supervisor_reviewed → finance_reconciled → approved/closed) → GL`

| Current state | Action | Permission | Required data/validation/transaction | New state / side effect / next |
|---|---|---|---|---|
| Quote draft/sent | confirm | `sales.orders.submit` | active masters, totals/date | confirmed; linked order; submit order. |
| Order draft | submit | `sales.orders.submit` | creator/lines/state | submitted; approve. |
| submitted | approve | `sales.orders.approve` | policy and maker/checker | approved; choose fulfilment. |
| approved | confirm | `sales.orders.confirm` | **warehouse/source missing**; integration transaction | confirmed; automatic reservation/delivery; validate delivery. |
| ready delivery | complete | `inventory.transfers.manage` | quantities, lock, key, stock | done/partial; stock/valuation/COGS; invoice. |
| delivered | invoice/post/pay | Finance permissions | delivered-uninvoiced quantity, open period, balanced journal, key | AR then payment/residual; settlement. |

### Procurement workflow

`Supplier → Requisition(draft → submitted → approved/rejected) → PO(draft → submitted → approved → confirmed → partially_received/received → partially_billed/billed → closed) → Receipt(draft/submitted → approved → posted) → stock + valuation/GRNI → Vendor Bill(draft → posted) → Payment → AP/GL`

The source implements transactions, amount approval policy, receipt quantities, supplier-invoice uniqueness and idempotency tables. User assignment to destination warehouse/location is not established.

### Internal transfer

`Source warehouse/location → destination warehouse/location → availability → transfer draft → posted movement(s) → source balance down / destination balance up → valuation reconciliation.` Company/hierarchy and movement identities exist. Operator resource scope does not.

### Asset

`Category/acquisition → draft asset → capitalization → activation → sequential depreciation in open period → transfer/maintenance/tracking → disposal → GL.` Unique disposal and depreciation schedule constraints reduce replay risk; runtime invalid-state and concurrent posting tests were not executed in this environment.

### Attendance

`Employee ↔ user → current position/branch → calendar/schedule → geofence evidence → check-in → active attendance session → check-out → final attendance record.` Missing branch/geofence information fails closed with a corrective message. Self/team/company permissions are distinct.

## 5. Action Required and UI/server consistency

| State | Badge target / role / disappearance | Result |
|---|---|---|
| Draft/sent quotation | Sales → Quotations; submitter; disappears on confirm/cancel/expiry | PASS by code inspection. |
| Draft/submitted/approved order | Sales → Orders; creator/approver/confirmer | PASS structurally. |
| Confirmed order without picking | Sales → Orders; confirmer; disappears when picking exists | PARTIAL: preparation should not occur before explicit fulfilment. |
| Draft/ready delivery | Sales → Deliveries; `inventory.transfers.manage`; disappears on done/cancel | HIGH: state is counted, but operational user needs a management-named permission. This explains the observed missing guidance for a role lacking it. |
| Procurement requisition/PO/receipt/bill/payment | Relevant Procurement/Inventory tabs; action permission | PASS structurally. |
| Draft/postable customer invoice | Finance → Invoices; broad records manager | PARTIAL: permission is coarse. |
| Asset draft/next depreciation | Assets → Register, with Finance period link when blocked | PASS structurally. |
| Failed integration event | Administration → Integration Events; retry role | PASS structurally. |
| Leave approval | HR → Leave; assigned approver | PASS structurally. |

UI and server both use permission checks in the inspected high-risk controllers. However, UI filtering is not treated as authorization. The central gap is that neither layer has a resource-assignment model to enforce.

## 6. Dangerous defaults and fallbacks

| Fallback | Classification | Reason |
|---|---|---|
| Sales reservation warehouse: branch match, otherwise default, otherwise first eligible | **DANGEROUS BUSINESS DECISION** | Determines custody, availability and accounting without user intent. |
| Delivery warehouse `ORDER BY is_default ... LIMIT 1` | **DANGEROUS BUSINESS DECISION** | Can diverge from reservation and bypass authorization. |
| Delivery operation type default source/destination | Dangerous when order has no explicit fulfilment; valid convenience only as a prefilled, validated choice | Defaults may propose, not decide. |
| Head-office lookup `LIMIT 1` under unique head-office rule | VALID CONVENIENCE | Resolves a constrained configuration identity. |
| Active accounting-period selection | VALID only because posting requires exactly one open matching period and otherwise fails closed | Not a user choice when uniqueness is enforced. |
| Approval-policy highest matching threshold | VALID POLICY RESOLUTION | Ordered configuration selection, not an operational resource. |
| Default bank in settlement form | VALID PREFILL if user can review/change and server validates company/currency | Does not silently post by itself. |

## 7. Security, invalid scenarios and transaction safety

Repository inspection shows company predicates on principal Sales, Procurement, Inventory, Finance, Asset and HR reads/writes; CSRF checks on browser mutations; API client scope-to-permission mapping; external IDs and request idempotency; unique delivery completion, movement, journal, valuation, depreciation/disposal and integration identities. Financial posting uses balanced batches and accounting-period gates. Inventory completion and reservation code contains transactions/locking, but exact concurrency certification requires executable tests.

Invalid-scenario status:

| Scenario family | Result |
|---|---|
| Cross-company customer/vendor/product/account/employee IDs | PARTIAL: protected by inspected predicates/composite keys; complete runtime matrix blocked. |
| Wrong-warehouse/inactive/unauthorized location | FAIL for unauthorized user scope; company/hierarchy validation exists. |
| Insufficient stock/negative stock | PARTIAL: reservation semantics exist; selection is wrong and exact selected-location behavior cannot exist yet. |
| Duplicate confirmation/delivery/receipt/invoice/journal/payment/depreciation/disposal | PARTIAL: state checks/idempotency/unique constraints exist; full replay suite blocked. |
| Closed period/unbalanced journal | PASS by implementation and existing focused test design; execution blocked. |
| Partial failure | PARTIAL: business services generally transact and roll back; integration outbox is durable; all paths not runtime-proven. |

## 8. Cross-module reconciliation

| Effect | Implementation evidence | Audit result |
|---|---|---|
| Sales delivery → stock decrease → valuation → COGS/inventory GL | picking/movement/valuation layers, Inventory and Finance integration handlers, reconciliation service | PARTIAL; source selection FAIL, numeric execution blocked. |
| Purchase receipt → stock increase → valuation/GRNI | Procurement/Inventory services and migration 057 | PARTIAL; focused test exists, execution blocked. |
| Vendor bill → AP and GRNI/expense/asset | Procurement and Finance posting services | PARTIAL. |
| Customer invoice → AR/revenue/tax; payment → cash/AR | Finance operations/posting and allocations | PARTIAL. |
| Depreciation → expense/accumulated depreciation | Asset service/schedule and Finance posting | PARTIAL. |
| Settlements → bank evidence without duplicate GL payment | settlement service links posted payments and enforces unique payment line | PARTIAL. |

No live or production reconciliation was performed. This report must not be represented as certification of production balances.

## 9. Findings register

### SAL-001 — CRITICAL — Sales fulfilment identity is silently selected

- **Module:** Sales / Inventory
- **Problem:** Sales order UI/schema omit fulfilment warehouse and source location.
- **Expected behavior:** User explicitly selects an authorized warehouse and its authorized internal source; confirmation checks exact available stock and persists that identity.
- **Current behavior:** confirmation prepares Inventory work; reservation prefers branch/default/first warehouse and delivery can independently fall back; source comes from default operation type.
- **Business impact:** wrong site fulfils an order; inaccurate availability promises and operational confusion.
- **Security/financial impact:** unauthorized custody use; valuation/branch COGS can be attributed to an unintended source.
- **Root cause:** fulfilment responsibility is absent from `sales_orders`; integration treats configuration defaults as decisions.
- **Files/components:** `SalesController`, sales order views, `SalesService::transitionOrder/prepareDelivery`, `InventoryRepository`, `InventorySalesIntegrationHandler`.
- **Database objects:** `sales_orders`, `inventory_sales_reservation_allocations`, `inventory_pickings`, `inventory_operation_types`, `inventory_stock_balances`.
- **Recommended fix:** after scope model, add forward-only fulfilment fields, constrained selectors, exact-location lock/check/reservation, and delivery inheritance; remove independent fallback.
- **Required test:** authorized/unauthorized/tampered selection, insufficient stock message, no cross-warehouse split, replay/concurrency, delivery identity inheritance.
- **Dependencies:** INV-001, AUTH-001, new migration after 067, compatibility strategy for existing confirmed orders.

### INV-001 — CRITICAL — No warehouse/location operational assignment model

- **Module:** Inventory / authorization
- **Problem:** Permissions are company/action scoped; no normalized user/role warehouse and location access was found.
- **Expected behavior:** administrators assign operational access; server and selectors enforce it; location access cannot exceed warehouse access; owner implicit-all may be explicit policy.
- **Current behavior:** company users with transaction permission can operate company topology; operational access is conflated with broad permissions.
- **Business impact:** weak custody and segregation across sites.
- **Security/financial impact:** unauthorized stock access/movement and unreliable responsibility evidence.
- **Root cause:** schema/reference permissions model action grants only.
- **Files/components:** authorization services, warehouse/location controllers/services/repositories, Inventory/Sales/Procurement services and views.
- **Database objects:** `company_user_roles`, `company_role_permissions`, `inventory_warehouses`, `inventory_locations`; assignment tables absent.
- **Recommended fix:** company-scoped user warehouse access plus optional location restriction (or normalized role assignment), with composite FKs and server authorization service.
- **Required test:** owner implicit policy, assigned/unassigned warehouse, child location, cross-company tampering, inactive/deleted records.
- **Dependencies:** design decision on user vs role grants; migration after 067.

### AUTH-001 — HIGH — Branch operational scope is not generalized

- **Module:** Authorization / multi-site operations
- **Problem:** branch ownership exists, but user branch assignment is not a universal transaction gate.
- **Expected/current/impact:** users should operate assigned branches; currently permissions are tenant-wide outside HR reporting/geofence constructs, permitting broader commercial/financial reach.
- **Root cause/components/objects:** `AuthorizationService`, `TenantContext`, transaction services; `organization_branches`, membership/role tables.
- **Recommended fix/test/dependencies:** define branch scope and inheritance into warehouses; test tampered branch and cross-branch document actions; coordinate with INV-001.

### INV-002 — HIGH — Delivery operation uses a management permission

- **Module:** Sales delivery / Inventory
- **Problem:** validation/returns and Action Required use `inventory.transfers.manage`.
- **Expected/current/impact:** operator permission distinct from topology management; current roles either cannot work/see badge or gain excess authority.
- **Root cause/files:** permission catalogue, `SalesController`, ActionRequiredCountService, navigation/views.
- **Recommended fix/test/dependencies:** introduce/reuse operate/validate permissions and migrate templates; permission contract and UI/backend parity tests; depends on INV-001.

### PRO-001 — HIGH — Procurement destination authorization is tenant-only

- **Module:** Procurement / Inventory
- **Problem:** receipt destination can be company-valid without proving operator assignment.
- **Expected/current/impact:** PO/receipt must use authorized destination; same custody risk as Sales.
- **Root cause/files/objects:** ProcurementService/controllers/views; warehouse/location assignment absent.
- **Recommended fix/test/dependencies:** scope selectors and writes; wrong-warehouse/location and inactive cases; depends on INV-001/AUTH-001.

### FIN-001 — HIGH — Broad Finance records management spans distinct duties

- **Module:** Finance
- **Problem:** invoice creation/posting/payment UI paths commonly depend on `finance.records.manage`.
- **Expected/current/impact:** create/post/pay/reverse/configure should be independently grantable; broad grants weaken separation of duties.
- **Root cause/files/objects:** `FinanceController`, `SalesController`, Finance services and permission seeds.
- **Recommended fix/test/dependencies:** permission split and template migration without silently changing posting semantics; role-negative and UI parity tests.

### HR-001 — HIGH — Attendance geofence accepted-punch paths fail focused tests

- **Module:** Attendance / test gate
- **Problem:** exact-radius Sign In, inside-radius Sign Out and disabled-geofence attendance paths fail; downstream idempotency/evidence assertions also fail.
- **Expected/current/impact:** valid in-policy punches create/update one attendance cycle and immutable attempt evidence; currently focused execution cannot certify successful attendance recording.
- **Root cause/files:** not yet isolated below the service/repository boundary; `AttendanceSelfServiceService`, MySQL Attendance repository, `tests/attendance-geofence-authorization.php`. The failure reproduces on a fresh disposable schema, so it is not merely contamination from `tests/run.php`.
- **Recommended fix/test/dependencies:** diagnose date/schedule, boundary rounding and accepted-scan persistence without weakening geofence enforcement; rerun focused test both alone and after core suite. Do not change production logic solely to fit an invalid fixture.

### SYS-002 — HIGH — Oracle business modules are not parity-complete

- **Module:** Database portability
- **Problem:** Oracle migration/repository catalogue covers older core/HR breadth while modern Sales/Inventory/Finance/Assets/Procurement rely on MySQL.
- **Expected/current/impact:** either certify MySQL-only production support or complete Oracle adapters; selecting Oracle cannot provide this ERP.
- **Root cause/files:** Oracle repository skeleton and migrations versus MySQL 026-067.
- **Recommended fix/test/dependencies:** explicit support boundary; real Oracle clean-schema/integration suite before claiming parity.

### FIN-002 — HIGH — Production opening inventory valuation requires controlled cutover

- **Module:** Inventory / Finance
- **Problem:** repository status documentation notes existing production stock needs opening valuation reconciliation.
- **Expected/current/impact:** subledger and GL agree from an approved opening position; otherwise historical/current value can diverge.
- **Root cause/files/objects:** migration 057 adoption boundary, InventoryValuationReconciliationService.
- **Recommended fix/test/dependencies:** backup-tested cutover workbook and signed variance zero; no semantic change in this audit.

### SAL-002 — MEDIUM — Ready delivery guidance is tied to excessive permission

- **Module:** Action Required
- **Problem:** ready/draft delivery is counted correctly, but only for `inventory.transfers.manage`.
- **Expected/current/impact:** assigned delivery operators see actionable count and target; missing badge causes workflow delay.
- **Files/objects/fix/test:** ActionRequiredCountService, module navigation, `inventory_pickings`; change with INV-002 and assert count/item parity.

### AST-001 — MEDIUM — Specialist Asset roles are not seeded

- **Module:** Fixed Assets
- **Problem:** action permissions exist but dedicated Asset user/accountant/manager templates are incomplete.
- **Impact/fix/test:** manual broad grants increase risk; define least-privilege templates and test activation/depreciation/disposal forbidden paths.

### FIN-003 — MEDIUM — Currency breadth is document-level, not a complete FX subledger

- **Module:** Finance/Sales/Procurement
- **Problem:** ISO currency fields exist, but exchange-rate source, functional currency translation and realized/unrealized FX workflows were not found.
- **Impact/fix/test:** multi-currency financial statements may be incomplete; explicitly restrict supported currency or design FX accounting before use.

### INV-003 — MEDIUM — Negative-stock policy and error guidance need exact-location certification

- **Module:** Inventory
- **Problem:** availability/reservation behavior exists, but cannot express the required selected-location shortage contract until SAL-001 is fixed.
- **Fix/test:** produce Product/Warehouse/Location/Requested/Available/Shortage error and concurrency test under negative-stock on/off.

### PRO-002 — MEDIUM — RFQ workflow is absent

- **Module:** Procurement
- **Problem:** requisition and PO exist; no RFQ/vendor-comparison state was found.
- **Impact/fix/test:** competitive procurement may occur outside the ERP; mark as an intentional scope exclusion or design a controlled RFQ module.

### SYS-003 — MEDIUM — Some purpose/status documents are stale

- **Module:** Documentation
- **Problem:** older audit/parity documents describe modules/migration ceilings that later files supersede.
- **Impact/fix/test:** operators may infer wrong capabilities; timestamp and mark superseded audits, use this report and remediation status as current references.

### DATA-001 — MEDIUM — Production test-data ownership is not formally tagged

- **Module:** Data hygiene
- **Problem:** names such as E2E/TEST/sample can identify candidates but are not reliable ownership proof.
- **Expected/current/impact:** explicit test-run/company tags and dependency inventory; name-only cleanup risks deleting real records or leaving financial/stock orphans.
- **Objects:** companies/users/employees/products/customers/suppliers/quotes/orders/reservations/movements/pickings/invoices/payments/assets/outbox/audit/errors.
- **Recommended fix/test:** add a reviewed tagging policy prospectively; cleanup only from a dependency report and transaction on backup-tested clone.

### AUTH-002 — MEDIUM — Document-owner scope is inconsistent by workflow

- **Module:** Sales/Procurement/HR
- **Problem:** some Action Required queries restrict creator/maker while broad view permissions expose all company documents.
- **Impact/fix/test:** decide role-based company visibility versus owner/team visibility per document and enforce repositories, not just lists.

### SYS-004 — LOW — Route file is a large composition root

- **Module:** Routing
- **Problem:** 256 routes and controller construction are centralized in one file.
- **Impact/fix/test:** auditability and accidental omissions suffer; split module route registrars without changing URLs and add route/permission contract tests.

### SYS-005 — LOW — Several core business classes are highly compressed

- **Module:** Maintainability
- **Problem:** dense one-line controllers/services obscure transaction and authorization review.
- **Impact/fix/test:** higher review risk; format-only refactor with exact regression suite after critical controls.

### DATA-002 — LOW — Soft-delete/active-state vocabulary varies

- **Module:** Master data
- **Problem:** modules use active flags, deleted timestamps and statuses in different combinations.
- **Impact/fix/test:** selectors may diverge; document canonical eligibility per entity and add inactive/archived contract tests.

## 10. Test execution and environment blockers

Executed release-gate command:

```text
docker compose -f compose.php81.test.yaml up --build --abort-on-container-exit --exit-code-from app
```

Docker Desktop was started from its installed per-user location and the disposable PHP 8.1/MariaDB gate executed. Results before fail-fast termination:

- authenticated-session termination: **21/21 PASS**;
- core `tests/run.php`: **249/249 PASS**;
- attendance geofence authorization: **14 PASS, 4 FAIL** in sequence;
- total before termination: **284 PASS, 4 FAIL (288 checks)**.

An isolated fresh-schema rerun of the geofence script produced **12 PASS, 6 FAIL**, confirming a reproducible Attendance/product-or-fixture defect requiring diagnosis rather than an order-only collision. The failing accepted-punch assertions were exact-radius Sign In, inside-radius Sign Out, request-key idempotency, accepted/rejected attempt retention, accepted evidence snapshots, and disabled-geofence attendance.

Because the command is fail-fast, migration checksum, permission-template upgrade, company permission preservation, inventory valuation, periods, Sales, Procurement, Action Required, settlements, workflow trace, authorization, analytics and approval/operations scripts after geofence did not execute in that run. Additional repository scripts not in the compose command should be added to the release gate: API contract/security, Assets domain/integration/routes, Data Exchange, Finance accounting, Inventory/Sales module contracts, module licensing/release, settlement, and golden E2E.

Counts for this audit classification (module areas, not individual assertions): **PASS 1, PARTIAL 5, FAIL 3, NOT TESTABLE 1**. Static inspection is evidence but not a substitute for executable PASS.

## 11. Test-data hygiene plan (no deletion performed)

1. Work only on a restored production backup in an isolated database.
2. Produce candidate companies/users/employees/masters from explicit tags first; treat name patterns (`E2E`, `TEST`, `PRE-E2E`, `sample`) as leads only.
3. For every candidate company, enumerate all dependent commercial documents, reservations, movements, pickings, valuations, journals, invoices, payments, assets, integration events, audit logs and error incidents.
4. Reconcile stock, AR/AP, cash, asset and GL effects before considering removal. Posted financial/stock history should normally be retained or reversed through approved business documents, not deleted.
5. Separate removable unposted drafts from immutable posted evidence. Obtain business-owner and Finance approval.
6. Test a dependency-ordered cleanup transaction on the clone, check FK failures and reconciliation, roll it back, then repeat and commit only under a separately approved production change.
7. Never use a broad name-based `DELETE`; never remove a company before dependent proof is complete.

## 12. Release decision

Do not deploy Sales fulfilment changes until INV-001/AUTH-001 scope design, forward migration, legacy-order policy, exact-location locking and full role/concurrency/reconciliation tests are approved. The deployment package tooling may be released independently after its build and archive validation pass in a Docker-enabled environment.
