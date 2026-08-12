# OfficeApp ERP — Odoo 19 Functional Parity Audit

Audit date: 2026-08-11  
Production baseline: `6a400f3`  
Branch: `main`

Status vocabulary is restricted to `EXISTING_AND_VERIFIED`, `IMPLEMENTED_AND_VERIFIED`, `PARTIAL`, `NOT_IMPLEMENTED`, `NOT_APPLICABLE`, and `DEFERRED`.

## Audit evidence

The baseline was executed before Asset implementation with:

```text
docker compose -f compose.test.yaml up --build --abort-on-container-exit --exit-code-from app
```

Observed baseline result: fresh MariaDB initialization, migrations through 045, 25 reference-data files synchronized, and `249 checks, 0 failures`.

The working tree already contained a modified `public/.htaccess`, several `.before-*` backup files, and `backups/`. They predate this task and are excluded from implementation work.

## 1. Current Sales capabilities

Sales has tenant-scoped customers, products, territories, agents, teams, pricelists/rules, quotations, orders, manual approval and manual confirmation, delivery pickings, returns, explicit invoice creation, credit notes, payments, commissions, document sequences, state history, outbox events, and data exchange. The confirmed order boundary creates Inventory commitment but does not automatically invoice. Existing integration tests prove quotation price snapshots, draft editing, confirmation replay protection, separate approval/confirmation, delivery creation rules, ordered/delivered invoicing, payments, returns, credits, and tenant scoping.

## 2. Current Inventory capabilities

Inventory has tenant-scoped warehouses and location hierarchies, operation types, goods receipts, stock balances by location, immutable movements, reservations, deliveries, backorders, customer returns, transfers, adjustments, scrap records, serial allocations, and integration outbox events. Stock mutations are movement-driven. Existing tests cover receipt, reservation, insufficient availability, partial completion/backorder, return eligibility, original-cost restoration, transfers, adjustment history, serial constraints, and Sales/Finance integration.

## 3. Current Finance capabilities

Finance has company accounts, journals, balanced journal batches/lines, account balances, taxes, customer invoices/credits, payments, allocations, reconciliation records, periods, receivable projections, and inventory COGS/return postings. Posting uses idempotency keys and rejects unbalanced journals. Existing tests prove invoice posting, replay safety, partial/full payment residuals, customer credits, cross-tenant denial, and balanced debits/credits. Vendor bills/payables are absent.

## 4. Existing Asset-related code

No Fixed Assets module exists at baseline: no Asset tables, migration, repository, service, controller, routes, permissions, navigation, depreciation engine, accounting integration, inventory capitalization flow, UI, report, import/export schema, or automated Asset tests. Generic audit logs, organization departments/employees, Finance accounts/journals, Inventory movements, and the outbox are reusable foundations only.

## 5. Current database/migrations

MySQL migrations run through `045_create_data_exchange_core.php`. Core business tables include Sales master/documents, Inventory warehouses/locations/operations/movements/pickings, Finance accounts/journals/invoices/payments/reconciliation, `integration_outbox`, and `data_external_ids`. A fresh-install baseline was observed successfully. No Asset schema exists.

## 6. Current permissions

Sales, Inventory, Finance, data-exchange, administration, HR, and API permission templates exist and are materialized per company. Controllers enforce module entitlement and tenant permission checks; mutations enforce CSRF. No `assets.*` permissions or Asset roles exist.

## 7. Existing integration events

The durable `integration_outbox` supports Sales confirmation/cancellation/fulfilment/payment and Inventory fulfilment/return events. Inventory consumes confirmed Sales orders; Finance consumes Sales payments and Inventory fulfilment/return events. Dispatcher retry state includes status, attempts, and error details. No Asset event type or handler exists.

## 8. Existing accounting infrastructure

`FinancePostingService` provisions controlled system accounts/journals and posts balanced, idempotent journal batches. Customer invoices, payments, credits, COGS, and return reversals use this infrastructure. This is suitable for Asset acquisition, depreciation, and disposal journals after adding explicit Asset accounts and source identities.

## 9. Existing UI/CSS system

The shared layout provides navigation, page headers, breadcrumbs in selected workspaces, buttons, forms, dense data tables, status badges, filters, pagination, responsive rules, and module cards in `public/assets/css/app.css`. Sales/Inventory/Finance reuse these primitives but do not yet have a unified Odoo-style control panel, generic search/group-by component, notebook, or smart-button component. Assets must extend the shared primitives rather than introduce a separate design system.

## 10. Existing automated tests

The baseline includes unit/contract, real-MySQL integration, HTTP security, API, Sales, Inventory, Finance, data-exchange, and golden lifecycle tests. The baseline suite executed 249 checks with zero failures. The golden test reads persisted Sales, Inventory, Finance, payment, return, credit, and balanced-journal state. There are no Asset tests and the current golden test is database-read verification rather than a browser-created lifecycle.

## 11. Odoo 19 functionality gaps

Major gaps include quotation templates/optional products/variants, broad advanced warehouse routing and picking strategies, replenishment, landed costs/FEFO, vendor bills/payables, broad statutory financial reporting, bank reconciliation workflows, and the entire Fixed Assets lifecycle. Existing data exchange is intentionally limited where authoritative domain imports are not connected.

## 12. Exact implementation plan

1. Add migration 046 with module catalog entry, reviewed permissions, Asset category/master/schedule/transfer/maintenance/history tables, finance/source constraints, and no business demo data.
2. Add tenant-scoped Asset repository interfaces/implementation and repository factory wiring.
3. Add a straight-line depreciation calculator with final-period rounding and date rules.
4. Add transactional Asset service workflows: draft/category creation, activation/schedule, idempotent depreciation posting, transfer, routine maintenance, direct acquisition, Inventory capitalization, disposal/sale/scrap, and immutable history.
5. Reuse `FinancePostingService` for balanced acquisition/depreciation/disposal journals and Inventory movement services for capitalization; never write balances directly.
6. Add Asset authorization, CSRF-protected routes/controllers, navigation, dense list/form pages, status bar, related-record smart buttons, reports, and applicable data exchange.
7. Add domain tests, real-DB integration tests, state/negative/tenant/RBAC/idempotency/rollback tests, and a complete Asset lifecycle E2E with reconciliation evidence.
8. Test fresh install and upgrade from a schema stopped at baseline 045/commit `6a400f3`.
9. Re-run all mandated Sales, Inventory, Finance, golden, full regression, lint, and diff checks.
10. Exercise signed-in HTTP/browser workflows and save actual execution outputs under `artifacts/e2e/`.

## Sales parity matrix

| Feature | Odoo 19 reference behavior | Existing OfficeApp behavior | Gap | Implementation decision | Code evidence | Test evidence | Status |
|---|---|---|---|---|---|---|---|
| Customer master | Contacts, addresses, tax, terms, archive | Tenant customer with legal/contact/address/tax/terms/active fields | Contact hierarchy is flatter | Preserve current model | `SalesService`, `SalesRepository`, Sales views | `sales-commercial-integration.php` | EXISTING_AND_VERIFIED |
| Product master | Product/service/consumable, prices, costs, taxes, UoM, policies | Stockable/service semantics, prices, cost snapshots, UoM, serial flag | Variants and explicit invoice policy field are incomplete | Preserve and document | `SalesService`, migrations 026/029/043 | Sales and golden suites | PARTIAL |
| Pricelists | Rules, dates, quantities, products/categories | Server-resolved fixed/percentage rules with priority and price snapshots | No advanced formulas/currencies | Preserve | `SalesService::createPricelist`, `resolvePrice` | `sales-commercial-integration.php` | EXISTING_AND_VERIFIED |
| Quotation lifecycle | Draft, sent, confirm to order | Draft/sent/confirmed/cancelled; creates one submitted order | Templates, optional products, PDF history absent | Preserve stronger approval boundary | `transitionQuotation` | Commercial integration tests | PARTIAL |
| Manual order approval/confirmation | Configurable confirmation controls | Submitted → approved → confirmed, separate actions | None for required OfficeApp rule | Preserve | `SalesService::transitionOrder` | Sales integration tests | EXISTING_AND_VERIFIED |
| Delivery integration | Pickings, reservation, partial/backorder | Confirmed physical orders create Inventory commitment/pickings | Advanced routes absent | Preserve | Inventory handler/repository | Sales/Inventory tests | EXISTING_AND_VERIFIED |
| Ordered/delivered invoicing | Invoiceable quantities and partial invoices | Explicit policy-based invoice creation; over-invoicing protection | Policy not fully modeled on product master | Preserve explicit creation | `SalesService::createInvoice` | Finance and commercial tests | PARTIAL |
| Returns/credits | Return picking and credit note | Original-delivery return limits/cost, explicit credit | Refund UX/reporting narrower | Preserve | `createReturn`, `createCreditNote` | Sales, Finance, golden tests | EXISTING_AND_VERIFIED |
| Smart buttons | Actual related document counts/navigation | Related links exist on detail views | Not a shared verified smart-button contract | Add shared component later | Sales order view | Partial route tests | PARTIAL |
| Sales import/export | Configurable CSV/XLSX | Shared exchange framework; safe objects only | Not every Odoo object importable | Keep domain-safe scope | DataExchange services | data-exchange tests | PARTIAL |

## Inventory parity matrix

| Feature | Odoo 19 reference behavior | Existing OfficeApp behavior | Gap | Implementation decision | Code evidence | Test evidence | Status |
|---|---|---|---|---|---|---|---|
| Warehouses | Multi-warehouse, company ownership | Tenant-scoped warehouses/default operation topology | No advanced routes | Preserve | migrations 033/039, warehouse services | Inventory contract | EXISTING_AND_VERIFIED |
| Locations/usages | Internal and virtual locations | Vendor/input/stock/output/customer/returns/adjustment/scrap/transit topology | Putaway/removal strategies absent | Preserve | migrations 033/039/040 | Inventory contract | EXISTING_AND_VERIFIED |
| Receipts | Controlled inbound validation | Vendor→Input→Stock movement path | Multi-step configurability narrower | Preserve | `InventoryService`, repository | Inventory contract/golden | EXISTING_AND_VERIFIED |
| Reservations | On hand/reserved/available | Allocation records and availability enforcement | Advanced allocation strategies absent | Preserve | migration 036, repository | Inventory/Sales tests | EXISTING_AND_VERIFIED |
| Deliveries/backorders | Partial validation and backorders | Picking completion with backorder and cost events | Batch/wave/cluster absent | Preserve | migration 041, Sales delivery service | Inventory/Sales tests | EXISTING_AND_VERIFIED |
| Customer returns | Original picking trace and cost | Eligible quantity limit, immutable trace, original cost restoration | Exchange UX narrower | Preserve | Inventory repository | Sales/Finance/golden | EXISTING_AND_VERIFIED |
| Internal transfers | Source/transit/destination | Location-aware transfers and movements | Route configuration limited | Preserve | migrations 035/040 | Inventory contract | EXISTING_AND_VERIFIED |
| Adjustments | Audited movement, not overwrite | Adjustment documents/lines and movement history | Browser workflow needs fuller verification | Preserve | migration 035, repository | Inventory contract | EXISTING_AND_VERIFIED |
| Lots/serials | Lot/serial traceability | Serial allocation and uniqueness; no complete lot quantity model | Lots and expiration trace incomplete | Extend only if required by Assets | migration 029/041 | Sales/Inventory tests | PARTIAL |
| Scrap | Stock→scrap with trace/valuation | Scrap table/topology exists | End-to-end accounting proof incomplete | Integrate Asset scrap separately | migration 041 | Limited contract coverage | PARTIAL |
| Advanced routing | Multi-step, putaway, replenishment, waves, FEFO, landed costs | Not present | Major gap | Defer | No code | No tests | DEFERRED |
| Warehouse reports | Stock/location/movement/operations | Dashboard tables and export datasets | Valuation and analytic breadth limited | Preserve/extend for Asset trace | Inventory views/export | Contract tests | PARTIAL |

## Finance parity matrix

| Feature | Odoo 19 reference behavior | Existing OfficeApp behavior | Gap | Implementation decision | Code evidence | Test evidence | Status |
|---|---|---|---|---|---|---|---|
| Chart of accounts | Typed tenant accounts | Controlled tenant accounts and system provisioning | Configuration UI/import breadth limited | Reuse for Assets | migration 037 | Finance integration | EXISTING_AND_VERIFIED |
| Journals | Sales, purchase, bank, cash, misc | System journals and balanced batches | Purchase workflow absent | Reuse misc/bank for Assets | migrations 037/042 | Finance integration | EXISTING_AND_VERIFIED |
| Customer invoices | Draft→posted→paid | Explicit creation/posting, receivable and tax entries | Full Odoo invoice editor narrower | Preserve | Finance operations/posting | Finance/golden | EXISTING_AND_VERIFIED |
| Customer payments | Allocation/reconciliation | Partial/full allocations, residual/status, balanced bank/AR | Bank statement matching limited | Preserve | Finance posting/repository | Finance/golden | EXISTING_AND_VERIFIED |
| Credit notes | Separate reversing document | Separate posted credit with balanced entries | UX breadth limited | Preserve | Finance posting | Finance/golden | EXISTING_AND_VERIFIED |
| Vendor bills/payables | Full AP lifecycle | No vendor bill/payment domain | Entire AP gap | Explicitly out of Asset delivery scope unless needed | No code | No tests | NOT_IMPLEMENTED |
| Tax | Tax computation/accounts/rounding | Customer invoice tax lines and posting | Advanced fiscal positions absent | Preserve | migration 042 | Finance integration | PARTIAL |
| Financial reports | GL, trial balance, balance sheet, P&L, aging | Trial-balance/general-ledger foundations and receivable views | Full reconciled report suite incomplete | Do not fabricate; add Asset register only | Finance repository/views | Finance tests | PARTIAL |
| Inventory valuation accounting | Automated inventory/COGS entries | Fulfilment and return journals via outbox | Receipt/adjustment valuation breadth limited | Use controlled reclassification for capitalization | Finance integration handler | Sales/Finance/golden | PARTIAL |

## Fixed Assets parity matrix

| Feature | Odoo 19 reference behavior | Existing OfficeApp behavior | Gap | Implementation decision | Code evidence | Test evidence | Status |
|---|---|---|---|---|---|---|---|
| Asset module/catalog | Licensed business module | Tenant-license-aware Assets navigation and authorization | No specialist Asset roles yet | Ship core module; retain permission separation | `046_create_fixed_assets.php`, `AssetController.php`, `routes/web.php` | Browser register/detail verification; fresh migration suite | IMPLEMENTED_AND_VERIFIED |
| Asset categories/models | Account/depreciation defaults | Tenant categories reference controlled Finance account IDs | Straight-line/monthly only | Ship controlled reusable category | `AssetService::createCategory`, `asset_categories` | `tests/assets-integration.php` | IMPLEMENTED_AND_VERIFIED |
| Asset register/master | Controlled lifecycle and financial fields | Tenant register, detail, status, assignments, costs and history | Edit/archive/cancel UI is not implemented | Ship controlled create/read lifecycle | `fixed_assets`, `AssetRepository`, Assets views | DB lifecycle and browser register/detail evidence | PARTIAL |
| Direct acquisition | Capitalization and trace | Draft acquisition posts once on activation | Funding assumes Cash system account | Keep explicit draft activation | `AssetService::createAsset/activate` | `tests/assets-integration.php` | IMPLEMENTED_AND_VERIFIED |
| Inventory capitalization | Stock issue to asset use and reclassification | Authoritative movement reduces Stock and posts Inventory Asset to Fixed Asset | Serial allocation linkage is not yet enforced | Ship non-serial capitalization; retain serial limitation | `InventoryService::capitalizeAssetStock`, `AssetService::capitalizeFromInventory` | Vendor→Input→Stock→Asset DB lifecycle test | PARTIAL |
| Straight-line schedule | Periodic schedule with rounding | Persisted monthly schedule, salvage floor and final rounding | No alternate methods | Ship extensible straight-line calculator | `StraightLineDepreciationCalculator` | `tests/assets-domain.php`, integration lifecycle | IMPLEMENTED_AND_VERIFIED |
| Depreciation posting | Idempotent balanced entries | Ordered, one-time balanced postings update accumulated depreciation/NBV | Reversal/correction is not implemented | Ship controlled posting; defer reversal | `AssetService::postDepreciation` | idempotency, persisted NBV and journal assertions | PARTIAL |
| Modification/capital addition | Controlled future schedule | None | Entire workflow absent | Defer unless core lifecycle permits safely | None | None | DEFERRED |
| Custodian/department/location transfer | Current assignment + history | Tenant-validated current assignment plus immutable transfer rows | No transfer approval workflow | Ship controlled transfer | `AssetRepository::transfer`, `asset_transfers` | integration persistence and browser history | IMPLEMENTED_AND_VERIFIED |
| Maintenance | Routine/capital maintenance distinction | Routine completed maintenance is recorded without changing asset cost | Capital improvements and maintenance state workflow absent | Ship routine history only | `AssetService::addMaintenance` | ETB 3,000 non-capitalization assertion | PARTIAL |
| Disposal/sale/scrap | Stop depreciation; cost/accumulation/proceeds/gain/loss | Sale/scrap/write-off service removes cost/accumulation and stops schedule | Automated tests cover sale gain/loss; scrap/write-off paths not separately proven | Ship controlled disposal core | `AssetService::dispose`, `asset_disposals` | gain, loss, duplicate and cancelled-future-period assertions | PARTIAL |
| Asset reporting | Register, depreciation, NBV, disposals | DB-derived register, summary and per-asset schedule/history | Dedicated disposal/depreciation reports and export absent | Ship operational register only | `AssetRepository::workspace/asset`, Assets views | browser register/detail evidence | PARTIAL |
| Smart buttons | Actual schedule/journal/transfer/maintenance counts | Query-derived depreciation, history, transfer and maintenance anchors | Journal-only navigation/count is not separate | Keep anchors; refine journal smart button later | `AssetRepository::workspace/asset`, `show.php` | Browser detail shows 36/6/1/1 tied to persisted records | PARTIAL |
| Asset permissions/tenant isolation | Role-separated operations | Seven action permissions, module entitlement, CSRF and tenant-scoped repository | Dedicated Asset User/Accountant role templates absent | Ship permission-separated owner/admin access | migration/seed, controller guards, tenant predicates | cross-tenant repository assertion and authenticated browser route | PARTIAL |
| Asset import/export | Controlled master exchange | None | Entire exchange absent | Defer until core workflow verified | None | None | DEFERRED |
