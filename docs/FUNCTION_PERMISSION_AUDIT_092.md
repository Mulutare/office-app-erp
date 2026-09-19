# Function permission audit (local pass, migration 092)

Authorization contract: allow a request only when the active company matches, the module is enabled and entitled, an active company role grant permits the function, the record belongs to the actor's assigned hierarchy or ownership scope, the workflow state permits the action, and maker/checker rules permit the actor. Navigation is a presentation of grants, never the authorization boundary. The existing `ModuleRoleService` module-owner allowlist remains an additional restriction; it requires a separate review before arbitrary cross-module grants can become effective.

Company Administration edits `company_role_permissions` through the existing Roles and Permissions editor. The active session refreshes effective grants on each request. `role_permissions` provides new-company templates; it is not a runtime override of company grants.

## Current taskbar map

| Module | Taskbar function | Permission used | Additional scope |
| --- | --- | --- | --- |
| Dashboard | Dashboard | `dashboard.view` | Active company |
| Sales | Quick Sale create / own history | `sales.quick_sale.use` | DSA/DSP and own records |
| Sales | Quick Sale review / allocation | `sales.quick_sale.review` | Assigned manager and shop |
| Sales | DSA sales report submit / correction | `sales.report.submit` | Own allocated sale |
| Sales | DSA sales report review | `sales.report.review` | Assigned manager and shop |
| Sales | Incentives | `sales.incentive.view` | Assigned claim scope |
| Sales | Orders, Quotations, Customers, Products, Deliveries | `sales.view` | Existing Sales record scope; action grants remain separate |
| Sales | Selling Terms, Pricelists | `sales.pricing.view` | Company; management requires `sales.pricing.manage` |
| Sales | Product Variants, Teams | `sales.catalogue.manage` | Company |
| Sales | Settlements | `sales.settlements.view` | Existing settlement scope; create, submit, review remain separate |
| Procurement | Overview, Requisitions, Orders, Suppliers, Bills | `procurement.view` | Company; create, approval, posting grants remain separate |
| Procurement | Receipts | `procurement.receipts.create` | Existing receiving scope |
| Procurement | Payments | `procurement.payments.post` | Existing document scope |
| Procurement | Returns | `procurement.returns.post` | Existing document scope |
| Finance | Dashboard, Receivables, Invoices, Receipts, Payables, Expenses, Staff Loans, Cash & Bank, Accounts, Journals, Ledger, Reports | `finance.records.view` | Company; action grants remain separate |
| Finance | Settlement reconciliation | `finance.settlements.view` | Existing settlement scope |
| Finance | Bank reconciliation | `finance.bank_reconciliation.view` | Company; prepare/review/mapping grants separate |
| Finance | Accounting periods | `finance.period.view` | Company; close/reopen grants separate |
| Inventory | Current Stock, Movements, Daily History | `inventory.stock.view` | Assigned warehouse / stock scope |
| Inventory | Stock Requests | `inventory.stock_requests.view` | Existing request and authority scope |
| Inventory | Receipts | `inventory.receipts.view` | Existing receipt scope |
| Inventory | Transfers | `inventory.transfers.view` | Existing transfer scope |
| Inventory | Warehouses, Locations | `inventory.warehouses.view` | Existing operational scope |
| Assets | Register | `assets.view` | Company |
| Assets | Direct Assets, Categories | `assets.manage` | Company |
| Assets | Capitalization | `assets.inventory.capitalize` | Existing asset scope |

The primary taskbar uses `dashboard.view` and the enabled module's permission namespace. Administration uses `administration.roles.manage` for the role editor; other Administration, HR, Attendance, and Analytics pages retain their existing per-controller grants and module navigation. This pass has **not** split every broad view grant in those modules into a distinct page grant.

## Deny-by-default additions

Migration 092 adds four Sales function grants. Existing company assignments receive only the grants corresponding to established Sales Officer, Sales User, Sales Manager, Company Owner, or System Administrator access. Company Admin may revoke them independently. Quick Sale GET/POST and report GET/POST now check them; assigned-record and workflow checks still apply. Quick Sale Action Required entries and counts use these grants.

## Remaining audit work before system-wide sign-off

- Split broad `sales.view`, `finance.records.view`, `procurement.view`, and similar read grants where each page must be independently assignable.
- Audit every detail, export, API, and action route outside the four Quick Sale/report functions against a route-level function map.
- Verify privilege revoke/grant across HR, Finance, Procurement, Inventory, Assets, Analytics, Attendance, and Administration in the browser.
- Check notification recipient and dashboard-count queries for every independently assignable function.
- Verify Shop Manager grant removal against both own-shop and other-shop Quick Sale review, and verify Finance settlement handoff without changing financial records.

This document records the current implementation and open gaps; it is not a system-wide completion certificate.
