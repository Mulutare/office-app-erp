# ERP remediation plan

This plan implements the findings in `ERP_PERMISSION_WORKFLOW_AUDIT.md` without changing business logic during the audit. Existing applied migrations 061–067 remain immutable. Any schema work starts with a new forward-only migration after 067 and must have strict preflight/recovery handling.

## Delivery principles

- Preserve current accounting and inventory valuation semantics unless a separately approved design explicitly changes them.
- Add authorization and persisted business intent before removing compatibility fallbacks.
- Enforce every scope on the server; filtered dropdowns are usability, not security.
- Make state transitions, stock movements, journals and integration events transactional and replay-safe.
- Prove each increment on disposable MariaDB/PHP 8.1, then a production-like backup copy. Do not use production data for automated tests.

## Ordered remediation backlog

| Order | Findings | Remediation | Likely files/components | Migration | Compatibility | Required tests | Deployment risk / rollback |
|---:|---|---|---|---|---|---|---|
| 1 | INV-001, AUTH-001 | Approve the branch/warehouse/location operational scope policy: direct user grants versus role grants, owner implicit-all, branch inheritance, and restricted-location semantics. | AuthorizationService, TenantContext, membership/role administration, warehouse/location repositories and admin UI | Yes, new normalized company-scoped access tables and composite FKs | Default existing users deliberately through a reviewed backfill; do not infer from activity | tenant/branch/warehouse/location allow/deny, inactive/deleted, child-location invariant, privilege escalation | High; additive deploy with feature gate. Roll back application first; keep additive tables. |
| 2 | INV-001, INV-002 | Add central `InventoryResourceAuthorizationService` and separate view/use/receive/deliver/transfer/validate permissions from warehouse/location manage/configure. | Inventory/Sales/Procurement controllers/services, ActionRequiredCountService, navigation, permission reference synchronization | Usually yes for permission/reference data; access schema from step 1 | Map existing roles explicitly and report changed effective grants before activation | role matrix, UI/backend parity, tampered IDs, owner/admin/operator boundaries | High; incorrect mapping can block operations or overgrant. Feature flag and grant report are rollback controls. |
| 3 | SAL-001 | Persist Sales fulfilment warehouse and source location; add explicit authorized selectors to order create/edit/approval flow. | sales order migration/repository/service/controller/views/API schema/export/import | Yes: new nullable fields initially, composite company/warehouse/location FKs and indexes | Existing draft orders require selection; existing confirmed/history remains readable with legacy marker; no silent backfill from defaults | required-field, active/company/assignment/hierarchy, API contract, legacy orders | High; deploy additive/read-compatible before enforcing non-null by state. |
| 4 | SAL-001, INV-003 | Confirm against the exact selected company/warehouse/location/product and reserve under row locks; express shortage details; forbid implicit split/fallback. | InventoryRepository, InventorySalesIntegrationHandler, SalesService, reservation tables | Possibly constraints/indexes only in a new migration | Maintain current quantity and negative-stock semantics; change only selection/control | sufficient/insufficient, negative policy on/off, concurrent confirmations, duplicate confirm, stale reservation, rollback/no partial allocation | Critical; shadow-compare on production-like data. Roll back code while retaining additive schema. |
| 5 | SAL-001 | Make delivery inherit the reservation/order fulfilment identity; remove independent warehouse/source fallback. | picking creation/reservation/delivery services and repositories | Possibly add immutable source identity/constraints if current picking fields are insufficient | Existing legacy pickings remain unchanged; new documents require identity | reservation-to-picking equality, no allocation path fails closed, returns inherit correct topology, replay | Critical; release with metrics for failed legacy preparation and no automatic alternate source. |
| 6 | PRO-001 | Apply destination warehouse/location access to PO and receipt, and verify exact destination on stock posting. | ProcurementService/controller/views, Inventory receipt service/repository | Reuse scope tables; perhaps PO destination constraints | Existing open PO destinations audited and grandfathered only through explicit migration report | unauthorized/inactive/wrong warehouse location, receipt replay/concurrency, valuation/GRNI | High; deploy after access assignments are populated. |
| 7 | INV-002, INV-003, DATA-002 | Apply scope and canonical eligibility to transfers, adjustments, stock views, receipts, deliveries, returns and scrap. | Inventory controllers/services/repositories/views, Data Exchange | Usually no new schema beyond steps 1–2; constraints if gaps found | Preserve valuation/negative-stock policy | every operation authorized/forbidden/cross-company, inactive/archived, double post, concurrent stock | High; operation-by-operation rollout and reconciliation. |
| 8 | FIN-001, FIN-002, FIN-003 | Split Finance create/post/pay/reverse/configure permissions; certify opening valuation; explicitly scope or implement FX. | Finance controllers/services/views/reference permissions, valuation reconciliation | Permission reference change; FX requires separate later schema/design | Do not change journal semantics or functional currency silently | maker/checker, closed/locked period, unbalanced/replay, AR/AP/cash/subledger reconciliation | High; backup and signed zero-variance cutover; database restore is financial rollback. |
| 9 | AST-001 | Add Asset user/accountant/manager templates and negative-state coverage. | Asset permissions/seeds/reference sync/controllers | No domain schema expected | Existing grants preserved until reviewed mapping | activation/depreciation/transfer/maintenance/disposal allow/deny, double depreciation/disposal, closed period | Medium; additive templates. |
| 10 | SAL-002 | Rebind ready-delivery Action Required items/counts to the new operational permission and assignment scope. | ActionRequiredCountService, module navigation, Sales delivery views | No | Counts may decrease to assigned work only; document this intended behavior | item/count parity, assigned user sees badge/target, state transition removes it | Medium; stale cache/request only, simple code rollback. |
| 11 | AUTH-002 | Decide and implement document owner/team/company visibility per module. | repositories, query services, exports, Action Required | Maybe team/scope relationships if not reusable | Avoid unexpectedly hiding historical records from managers | list/detail/export/tampered-ID parity for creator/team/manager/company roles | Medium/high; staged module rollout. |
| 12 | PRO-002 | Product decision: formally exclude RFQ or design vendor solicitation/comparison/award workflow. | New Procurement domain only if approved | Yes if implemented | PO/requisition remain stable | lifecycle, supplier confidentiality, approval and conversion | Separate project; not a production hotfix. |
| 13 | HR-001 | Diagnose accepted Attendance punch failures, correct product logic or fixture according to the proven schedule/geofence contract, then restore the green release gate. | Attendance self-service service/repository, focused test, CI/compose documentation | Not expected; use a new migration only if persistence integrity is proven deficient | Preserve fail-closed geofence security and historical evidence | focused geofence alone and after core suite; PHP 8.1 MariaDB full gate | Medium; no Attendance release without green evidence. |
| 14 | SYS-002 | Declare MySQL/MariaDB as production scope or fund Oracle parity. | architecture/database docs or Oracle repositories/migrations | Large if parity chosen | Never silently select an incomplete driver | real Oracle schema, permissions and every module E2E | Separate program. |
| 15 | DATA-001 | Add prospective test-data provenance and implement a report-only dependency explorer; execute cleanup only under separate approval. | test fixtures, optional metadata/report CLI | Optional additive tag fields/table | Existing name-only records remain candidates, never assumed test data | clone-only dependency report, reconciliation before/after, rollback | High if cleanup is ever authorized; current deliverable is plan only. |
| 16 | SYS-003, SYS-004, SYS-005, DATA-002 | Mark stale audits, modularize routes, format dense code and document eligibility vocabulary. | docs, route registrars, formatted classes | No | Exact route and behavior preservation | route inventory snapshot, full regression | Low; defer until critical controls are stable. |

## Proposed minimum access model

The smallest normalized design that meets the audit requirement is:

```text
company_user_warehouse_access
  company_id, user_id, warehouse_id, access_mode, active, granted_by, timestamps
  UNIQUE(company_id, user_id, warehouse_id)
  composite FKs proving company membership and warehouse ownership

company_user_location_access (optional restrictions)
  company_id, user_id, warehouse_id, location_id, active, granted_by, timestamps
  UNIQUE(company_id, user_id, location_id)
  composite FK(company_id, warehouse_id, location_id)
  FK to the user's warehouse access
```

`access_mode` should be avoided if it duplicates action permissions. Prefer action permission + resource assignment: permission answers “what,” assignment answers “where.” If location rows are absent, the approved policy must say whether warehouse access means every active internal location or none; ambiguity is unsafe.

Company Owner implicit access may remain, but it must be an explicit authorization rule tested in one service, not duplicated controller exceptions. Platform administrators should not automatically gain tenant operational custody merely because they manage the software.

## Sales fulfilment compatibility sequence

1. Add nullable fulfilment columns and read support.
2. Populate access assignments through an administrator workflow and report coverage.
3. Require explicit fields for newly submitted/approved orders; allow old closed history to remain null.
4. For existing open confirmed orders, require a controlled “assign fulfilment” action before reservation/delivery. Do not backfill from the old fallback without human confirmation.
5. Deploy exact-location reservation behind a release switch, run production-like concurrent tests, then activate.
6. Remove fallback only after metrics show no legacy path; retain clear fail-closed diagnostics.

## Mandatory release evidence

- Full PHP 8.1/MariaDB test output with assertion counts and zero unexplained failures.
- Role/permission matrix for authorized and forbidden actions.
- Cross-company, branch, warehouse, location and employee tampering results.
- Concurrent same-product reservation proof showing no over-reservation.
- Delivery/receipt/transfer/adjustment replay proof.
- Inventory valuation-to-GL, AR, AP, cash and asset schedule reconciliation with zero unexplained variance.
- Backup/restore rehearsal and migration preflight output on a production-like copy.
- UI selectors/buttons and server behavior parity evidence.
- Action Required count/item/target parity.

## Rollback policy

New migrations are forward-only and should be additive through the compatibility window. Application rollback restores the previous code while additive nullable columns/tables remain. Any data transformation, permission cutover, inventory opening or financial posting requires a verified pre-change database backup; MySQL DDL can auto-commit, so application rollback is not a database rollback. Never edit the migration ledger or existing migrations 061–067.
# Migration 070 — controlled internal stock transfers

Implemented locally: exact authorized source/destination selection, source availability confirmation, maker/checker approval, dispatch to transit, receipt into the selected destination, action-required routing, atomic permissions, permanent actor/timestamp quantities, and linked movement history. Production work remains backup, migration/reference sync, explicit role grants, resource-scope review, staging tests, and controlled cutover.
