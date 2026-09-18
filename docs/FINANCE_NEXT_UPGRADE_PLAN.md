# Finance Next Upgrade Plan

**Status:** PARTIAL / LOCAL IMPLEMENTATION
**Target area:** Finance + HR integration
**Tracking date:** 2026-09-16

## Objective

Upgrade OfficeApp ERP Finance into a structured accounting workspace with familiar Sage 50 / Peachtree-style organization while preserving the existing double-entry, tenant isolation, approval, audit, and procurement/sales integrations.

The upgrade will not claim Ethiopian tax compliance merely from UI similarity. Tax-specific behavior must be verified against the company's approved accounting/tax procedures before production cutover.

## Current verified baseline

The current ERP already has meaningful Finance foundations:

- Customer invoices and customer credits.
- Posted invoice -> Accounts Receivable journal behavior.
- Customer payment allocation and residual balance handling.
- Receivable projection / aging foundation.
- Supplier bills and Accounts Payable posting from Procurement.
- Supplier partial/final payments and payable settlement.
- Balanced journal posting and accounting-period controls.
- An Expense Requests area exists, but its complete end-to-end accounting lifecycle has not yet been proven and must be audited before extending it.

## Upgrade principles

1. Reuse the current chart of accounts, journals, invoices, payments, procurement, HR employees, permissions, and audit infrastructure.
2. Do not create duplicate accounting truth. AR, AP, Expenses, Staff Loans, Cash/Bank and GL must reconcile to the same posted journals.
3. Posted accounting documents are immutable. Corrections use reversal/credit/adjustment workflows instead of destructive editing.
4. All operational documents remain company-scoped and permission-controlled.
5. Subsidiary ledgers must reconcile to their control accounts:
   - Customer AR -> Accounts Receivable control account.
   - Supplier AP -> Accounts Payable control account.
   - Staff loans -> Employee/Staff Receivable control account.
6. Build compact business workspaces rather than one oversized Finance page.

## Phase 1 — Finance capability audit

Before coding, perform a source/database audit of the existing Finance module and record each area as COMPLETE, PARTIAL, or MISSING:

- Chart of Accounts.
- Journals and journal batches.
- Accounting periods / close rules.
- Customer invoices and credits.
- Accounts Receivable.
- Customer payments and allocations.
- Supplier bills.
- Accounts Payable.
- Supplier payments and allocations.
- Expense Requests.
- Expense approval.
- Expense accounting/posting.
- Employee reimbursements.
- Cash / bank journals.
- Tax/VAT fields and tax posting.
- Attachments / receipts.
- Audit trail and reversals.
- Finance reports.
- Existing permissions.
- Procurement -> AP integration.
- Sales -> AR integration.

No new Finance schema should be designed until this audit confirms what can be reused.

## Phase 2 — Staff Loans and Advances

Add a dedicated **Staff Loans & Advances** subledger linked to `hr_employees`.

### Core records

Each loan/advance should store:

- Employee.
- Loan/advance number.
- Type.
- Request date.
- Approval date.
- Disbursement date.
- Currency.
- Principal amount.
- Interest rate / interest method when applicable; zero-interest supported.
- Installment frequency.
- Number of installments.
- First due date.
- Planned completion date.
- Installment amount.
- Amount paid.
- Outstanding principal.
- Outstanding interest if applicable.
- Next due date.
- Status.
- Purpose / notes.
- Approver.
- Supporting documents.
- Journal references.

### Lifecycle

Draft -> Submitted -> Approved -> Disbursed -> Active -> Paid

Additional controlled states:

Rejected / Cancelled / Restructured / Written Off

### Installment schedule

Generate an immutable schedule at approval/disbursement:

- Installment number.
- Due date.
- Opening balance.
- Principal due.
- Interest due if applicable.
- Total due.
- Amount paid.
- Remaining due.
- Payment date.
- Status: Upcoming / Due / Partially Paid / Paid / Overdue.

The system must calculate:

- Remaining number of installments.
- Remaining amount.
- Next installment amount.
- Next due date.
- Expected finish date.
- Overdue amount.
- Days overdue.

Allow partial payment and early payoff. Any restructuring must preserve the original schedule/history and create a controlled revised schedule.

### Accounting

At loan disbursement:

- DR Staff/Employee Loans Receivable
- CR Cash / Bank

At employee repayment:

- DR Cash / Bank (or Payroll Clearing in a future payroll integration)
- CR Staff/Employee Loans Receivable

Interest, if enabled by company policy, posts separately to the configured interest-income account.

Because full Payroll is not yet proven in the ERP, the first implementation should support manual/cash/bank repayment. Payroll-deduction integration can be added later without redesigning the loan subledger.

### Staff loan reports

- Staff Loan Register.
- Employee Loan Statement.
- Outstanding by Employee.
- Installments Due This Month.
- Overdue Installments.
- Loans Finishing This Month/Quarter.
- Loan Receivable Aging.
- Staff Loan GL Reconciliation.

## Phase 3 — Accounts Receivable workspace

Keep the existing Finance invoice/payment accounting as authoritative and build a clean AR workspace around it.

Required views:

- Customer list with total outstanding.
- Open invoices.
- Partially paid invoices.
- Overdue invoices.
- Credit balances / credit notes.
- Customer statement.
- Receipt/payment history.
- AR aging: Current, 1–30, 31–60, 61–90, 90+ days.
- Due-today / due-this-week.
- AR control-account reconciliation.

Support filtered reporting by customer, branch, salesperson where authoritative, date, currency, and status.

## Phase 4 — Accounts Payable workspace

Reuse Procurement supplier bills and supplier payments as the authoritative AP documents.

Required views:

- Supplier/vendor list with outstanding balance.
- Open supplier bills.
- Partially paid bills.
- Overdue bills.
- Supplier credits / reversals where supported.
- Supplier statement.
- Payment history.
- AP aging: Current, 1–30, 31–60, 61–90, 90+ days.
- Bills due today / this week / this month.
- AP control-account reconciliation.

Procurement owns purchasing/receiving. Finance owns payable settlement and accounting. Do not duplicate purchase orders or goods receipts inside Finance.

## Phase 5 — Expense Management completion

Audit the existing Expense Requests functionality first. Extend it only where gaps are proven.

The target Expense workspace should separate these cases:

### Employee reimbursable expense

Employee incurs expense -> submits claim with receipt -> manager approval -> Finance review -> reimbursement -> journal posting.

### Company-paid expense

Company pays supplier/cash expense directly -> authorized expense document -> payment -> journal posting.

### Supplier invoice expense

Where a supplier invoice exists, prefer the normal Procurement/AP bill workflow rather than bypassing AP with a direct expense.

### Petty cash

Controlled petty-cash issue/replenishment and expense settlement with supporting receipts and cash reconciliation.

### Expense fields

- Expense number.
- Expense date.
- Employee/requester.
- Vendor/payee when applicable.
- Expense category.
- GL expense account.
- Branch / department / cost center when available.
- Description/business purpose.
- Currency.
- Net amount.
- Tax/VAT amount.
- Gross amount.
- Payment/reimbursement method.
- Receipt/invoice attachment.
- Approval status.
- Payment status.
- Journal status.
- Journal reference.
- Created/approved/paid/posting audit fields.

### Expense accounting

Typical direct/reimbursable expense posting:

- DR Configured Expense Account
- DR Recoverable Tax/VAT when applicable
- CR Cash/Bank, Employee Payable, or Accounts Payable depending on settlement method

Account mapping must be configuration-driven, not hard-coded by screen.

### Expense reports

- Expense Register.
- Expense by Category.
- Expense by Department/Branch.
- Expense by Employee.
- Expense by Vendor.
- Monthly Expense Trend.
- Pending Approval.
- Approved but Unpaid.
- Employee Reimbursements Outstanding.
- Petty Cash Reconciliation.
- Expense GL Reconciliation.

## Phase 6 — Finance navigation / Sage 50–Peachtree style organization

Target Finance navigation:

1. Finance Dashboard
2. Chart of Accounts
3. General Journal
4. Accounts Receivable
5. Accounts Payable
6. Expenses
7. Staff Loans & Advances
8. Cash & Bank
9. Tax
10. Accounting Periods / Close
11. Finance Reports

The interface should feel familiar to conventional accounting users but remain consistent with OfficeApp ERP rather than copying another product.

## Phase 7 — Core Finance reports

The eventual Finance reporting package should include:

- Trial Balance.
- General Ledger.
- Account Ledger.
- Profit & Loss.
- Balance Sheet.
- Cash Flow where accounting data supports it.
- AR Aging.
- AP Aging.
- Customer Statements.
- Supplier Statements.
- Expense Analysis.
- Staff Loan Receivables.
- Cash/Bank activity.
- Tax/VAT summary.
- Journal audit report.
- Subledger-to-GL reconciliation reports.

## Phase 8 — Controls and production acceptance

Before production cutover verify:

- Every posting balances debit = credit.
- No cross-company reads/writes.
- Posted records cannot be destructively edited.
- Reversal is auditable.
- Closed periods block posting.
- AR total reconciles to AR control account.
- AP total reconciles to AP control account.
- Staff loan outstanding reconciles to Staff Loan Receivable.
- Expense documents reconcile to posted journals.
- Partial payments work.
- Duplicate posting/replay is idempotent.
- Permissions separate requester, approver, Finance processor, and administrator.
- Attachments remain linked to source documents.
- Tax fields and reports are verified against the company's Ethiopian tax/accounting requirements before they are described as compliant.

## Recommended implementation order

1. Audit existing Finance/Expense implementation.
2. Fill Expense gaps without replacing working Finance code.
3. Build Staff Loans & Advances.
4. Build consolidated AR workspace/reporting.
5. Build consolidated AP workspace/reporting.
6. Add reconciliation/reporting improvements.
7. Verify Ethiopia-specific tax/accounting configuration.
8. Deploy incrementally using new migrations only; never modify production-applied migrations.

## Explicit non-goals for the first upgrade

- Do not build a full Payroll module as part of Staff Loans.
- Do not rewrite the existing invoice/payment engine.
- Do not duplicate Procurement supplier-bill logic.
- Do not duplicate Sales customer-invoice logic.
- Do not claim Sage/Peachtree file-format compatibility unless a separate import/export requirement is defined.
- Do not claim government/tax certification until verified against the applicable authority/process.

## Next development action

Complete and review the remaining Finance workflows before any production deployment.

## Local implementation and gap matrix (2026-09-17)

Current branch at the start of this upgrade: `main`. Starting HEAD: `1e18e25af67c3f65fa6b72225815549e169e5939`. Repository migrations end at 083; migration 084 is new and local. No production database inspection, migration application, runtime test, build or deployment was performed.

| Area | Status | Source and remaining work |
| --- | --- | --- |
| Chart of Accounts | PARTIAL | Existing `finance_accounts` exposed read-only with code, type, normal balance, currency and system key. Account maintenance, hierarchy presentation and reconciliation configuration remain. |
| General Ledger / journals | PARTIAL | Posted `finance_journal_batches` and `finance_journal_entries` exposed with date, reference, source and debit/credit. Register is capped at 500 rows and needs pagination, drill-down, actor filters and controlled reversal UI. |
| Accounting periods | IMPLEMENTED | Existing period lifecycle and posting-date validation reused. No period rules were changed. |
| Customer invoices / payments | IMPLEMENTED | Existing `finance_invoices`, `finance_payments`, allocations and posting retained. |
| AR workspace / aging | PARTIAL | Read-only posted customer invoice residual register with aging bucket. Customer statement and AR control reconciliation are now available from posted records. Summary queues, inline credit visibility and pagination remain. Existing Sales receivable projection remains available on the Finance dashboard. |
| Supplier bills / payments | IMPLEMENTED | Procurement remains authoritative for bill creation, posting, payment, return and reversal. |
| AP workspace / aging | PARTIAL | Read-only posted vendor bill residual register with supplier and PO references. Supplier statement and AP control reconciliation now use posted bills, reversals and allocated payments. Due queues and pagination remain. |
| Expense workflow | PARTIAL | Existing expense table extended by local migration 084. Draft create/edit/cancel, submit, independent approval/rejection, and paid posting are implemented. Approved employee reimbursement can first recognize DR expense / recoverable tax and CR Employee Payable, then settle DR Employee Payable / CR Cash or Bank. Company-paid and petty cash expenses post directly. Paid expense reversal requires `finance.requests.approve` by someone other than the payment processor, creates opposite posted journal entries, links both reversed batches where applicable, stores actor/date/reason and blocks a second reversal. Existing balanced posting, open-period and idempotency controls apply. Receipt upload, separate payment approval, branch/department allocation and expense analysis remain. Evidence is a reference field only. Existing approved requests lacking an expense account need a controlled remediation path. |
| Staff loans & advances | IMPLEMENTED LOCALLY | Migration 085 creates company-scoped loans, installments, repayments, allocations and history linked to `hr_employees`. Draft, submitted, approved, rejected, disbursed, active, paid and cancelled states are controlled. Approval creates a fixed weekly or monthly schedule with zero interest or simple annual interest on declining principal. Payments allocate oldest installment first, interest then principal, and support partial, multi-installment and early full-schedule payoff. Disbursement posts DR Staff Loans Receivable / CR Cash or Bank; repayment posts DR Cash or Bank / CR Staff Loans Receivable and Interest Income when applicable. Register, detail, schedule, repayment and overdue views are present. Payroll deductions, restructuring, write-off and early-payoff interest rebate are deferred. |
| Cash & Bank | PARTIAL | Posted cash control-account movements exposed read-only; existing cash/bank journals and settlement reconciliation retained. Bank account-specific running balances and additional cash accounts need verified mapping. |
| Trial Balance | IMPLEMENTED LOCALLY | Posted journal-line debit, credit and net by account/currency with date filters and debit/credit totals. Opening balance and comparison periods remain optional enhancements. |
| Profit & Loss | IMPLEMENTED LOCALLY | Posted revenue and expense account activity and per-currency revenue, expense and profit/loss totals for the selected dates. |
| Balance Sheet | IMPLEMENTED LOCALLY | Posted asset, liability and equity balances through the as-of date, plus unclosed current earnings and a displayed balancing difference, separated by currency. |
| Cash Flow | NOT IMPLEMENTED | Current ledger lacks reliable operating/investing/financing classification. A derived cash-flow statement would be misleading. |
| Tax/VAT | PARTIAL | Expense posting can debit a selected asset tax account. Existing invoice and Procurement tax behavior is unchanged. Sales and purchase tax accounts need a verified configuration and tax-period report; no tax compliance claim is made. |
| Statements and control reconciliations | IMPLEMENTED LOCALLY | Customer and supplier statements use posted invoices, credits, bill reversals and allocated payments with opening/running balance by currency. Read-only AR, AP and Staff Loan subledger totals are compared with mapped posted GL control-account lines and show MATCHED or DIFFERENCE. No automatic adjustment is made. |
| Expense/loan report expansion | PARTIAL | Loan register, schedule, repayment history and overdue totals exist. Expanded expense analysis, period trend and loan aging exports remain. |

Migration 084 remains expense-only. Migration 085 contains only Staff Loan schema. No duplicate AR/AP or GL table is created. No Finance permission code was added: `finance.records.view`, `finance.records.manage` and `finance.requests.approve` are reused. All new reads and writes derive `company_id` from `TenantContext`; loan and expense financial mutations are transactional.

## Local runtime verification (2026-09-17)

The Docker development database recorded 083 as its latest applied migration, with 084 and 085 as its only pending files. Both migrations applied locally; 69 prior migration records were unchanged. No production migration was applied.

Company 2 local service checks completed a company-paid expense through reversal, an employee reimbursement through recognition and payment, and a zero-interest staff loan through three installments and full payoff. Posting retries did not create duplicate journals. Maker/checker and expired first-due-date attempts were rejected. Local Trial Balance debit and credit totals matched. AR and AP have no posted local documents to verify their full reporting and reconciliation paths.

Browser verification used a newly authorized local-only `finance.verify.local` account with Finance Officer and Finance Approver roles in company 2. The Finance dashboard, all ten Finance navigation destinations, customer/supplier statements, reconciliation, and staff-loan detail rendered. The original local `admin` (user 2) is a platform administrator; existing login rules restrict that account to Default Company, where Finance is unavailable. This role boundary was not changed. Cash Flow classification, receipt/file upload, expanded expense analysis, payroll deductions, loan restructuring, loan write-off, and early-payoff interest rebate remain PARTIAL / DEFERRED.

## Finance work-center audit and local presentation pass (2026-09-18)

Starting committed HEAD `fc673f8` on `main`; repository migrations end at 085. The user reports that 084/085 and the Finance application are deployed. This pass did not inspect production migration records or touch production. The working tree had only pre-existing untracked audit artifacts before editing.

Current-state assessment from routes, controllers, services, repositories, views, permissions and migrations:

| Area | Assessment | Decision |
| --- | --- | --- |
| Sales invoices/credits, receipts, Quick Sale handoff, Procurement vendor bills/payments, settlement | Existing source documents and posting routes are authoritative. Finance receives read models and posted journals. | Preserve services and old URLs. No duplicate source documents or settlement implementation. |
| Chart, journals, GL, periods, posting | Balanced/idempotent posting, open-period validation and immutable posted entries exist. GL list was visually weak and hid poster/reversal metadata. | Expose existing batch ID, poster, reversal reference and timestamps in read-only register. No posting changes. |
| Expense and staff-loan workflows | Transactional, company-scoped lifecycle, maker/checker, schedule and reversal controls are present. Registers/forms were difficult to scan; create was hidden above records. | Register first, obvious create action, grouped forms, visible filters/status/actions. No accounting state transition changes. |
| AR/AP and reconciliation | Posted invoice residual aging, statements and current control comparisons exist. Read-only account workspaces had plain links and dense tables. | Keep document sources; improve contextual links, filter toolbar and explicit difference/drill-down presentation. |
| Dashboard and reports | Finance home primarily showed legacy receivables; reports rendered three large statements at once. | Add posted/company-scoped, currency-separated work-center indicators and choose-one-report rendering. Cash control indicator is labelled as mapped cash, not all bank accounts. |
| Evidence and categories | 084 has text `evidence_reference`; `PrivateUploadService` stores protected settlement/Quick Sale files, but expense metadata and retrieval authorization are absent. Expense category has no GL or tax defaults; required employee FK applies to company-paid expenses too. | A cohesive future schema/workflow pass is needed for secure attachments, category defaults and optional beneficiary. No UI claim of uploaded evidence. |
| Banking and cash flow | Settlement confirmation and `bank_transactions` exist, but no bank-statement reconciliation session/match history. No verified operating/investing/financing account mapping. | Do not equate settlement reconciliation with bank reconciliation or synthesize cash flow. Unmapped classification remains a disclosed gap. |

Local scope: six Finance work centers with contextual secondary links; the original Finance URLs remain routed. A grouped report center renders only the selected Trial Balance, Profit & Loss or Balance Sheet query and links to existing aging, statements, GL, cash, loans and control reconciliation. Finance home shows current Action Required counts, posted mapped cash, subledger/GL balances and differences, overdue posted invoices, open expense counts, loan amounts, unreconciled settlement count and current accounting periods where data exists. No mixed-currency totals are created. Expense search/status and loan Due filters use company-scoped server queries. Ledger and reconciliation remain read-only. Reusable styles live in `app.css`.

Migration 086: **not created**. This pass changes presentation/read models only. Future persistence should be designed as separate cohesive, reviewed upgrades: private expense file metadata and access, company-scoped category GL/tax defaults and optional non-reimbursement employee, bank statement sessions/transaction matches, and explicit cash-flow mapping. Other deferred scope remains payroll deductions, loan restructuring/write-off, budget/forecasting and tax filing. Accountants must confirm cash-flow classifications, category defaults, bank matching policy and expense beneficiary rules before schema and posting changes.

Local validation: PHP lint and `git diff --check` passed; browser navigation/rendering was checked with the company 2 `finance.verify.local` account for home, reports, Trial Balance, expenses/create form, staff-loan register and GL. This is not a production acceptance test or a full financial transaction replay.

## Migration 086 — expense completion (local, not deployed)

Migration `086_finance_expense_evidence_and_defaults.php` is the next additive, expense-only schema step. It creates company-scoped `finance_expense_evidence` with a 1–10 sequence, original filename, private path, detected MIME, size, SHA-256, uploader and timestamp. The evidence row belongs to an expense through a composite company/expense FK. `finance_expense_categories` gains nullable company-safe default expense and recoverable-tax account references. `finance_expense_requests.requested_by_employee_id` becomes nullable, with a database CHECK requiring an employee for reimbursements. Existing rows and the external `evidence_reference` are retained; migrations 084 and 085 remain immutable.

Draft creators may attach or remove up to ten private PDF/PNG/JPEG receipts (10 MB each). Evidence is read only after submission and remains attached through payment and reversal. Downloads require Finance read permission and a company-scoped expense/evidence lookup; private paths are never rendered. Category defaults are optional suggestions for new/editable drafts, applied only when their account currency fits the selected expense currency. They do not calculate tax or mutate existing expense or posted journal values. Reimbursement still requires a same-company employee; company-paid and petty-cash may have no employee, while any supplied employee remains validated.

Migration 086 is not a bank-reconciliation or Cash Flow upgrade. Bank statement sessions/matching, explicit Cash Flow classification, payroll deductions, and loan restructuring/write-off remain deferred. This local implementation is not a production deployment or end-to-end production acceptance claim.

Production-readiness source follow-up: the complete selected file batch is validated before create/edit draft persistence where practical; the locked evidence write still rechecks the ten-file limit. If a create upload fails after draft insertion, stored files are cleaned and only that newly created, still-unmodified draft is compensated; if safe compensation is impossible, the user is told the draft remains. If an edit upload fails after the fields save, the user is explicitly told that draft changes were saved but evidence was not. Draft evidence removal commits metadata deletion first, so access is revoked; a failed post-commit unlink is logged by safe company/expense/evidence IDs for orphan cleanup, without restoring access or rolling back business state. Migration 086 remains **implemented locally / NOT DEPLOYED / DB and runtime verification pending**. Its MigrationRunner-normalized checksum is `769bc3ec24bbf3e97246a1e7bd9c6dccaa851639b166d670100eb6d0143e57da`.

## Migration 087 — true bank reconciliation (source-reviewed local implementation)

Starting HEAD `1319332`. Migration 086 is committed but not runtime-verified or deployed. Production order is 086 first, then 087 only after its own separate runtime acceptance. Migration 087 remains local and uncommitted; source review does not authorize deployment.

The source has no company-bank-account-to-GL mapping. Default Bank and Cash journals share the `cash` system account, and customer payments post there directly. Sales Settlement Reconciliation confirms customer settlement/payment evidence; it is not a whole-bank statement reconciliation. `bank_transactions` is preserved as-is and is not used as the authoritative statement-line ledger. Existing Cash & Bank and AR/AP/loan reconciliation remain read-only posted-GL/control views.

The local 087 schema introduces durable company-scoped bank/GL ownership, so one Finance GL account cannot be assigned to different physical banks even after mapping supersession; the same bank may retain that GL through multiple mapping revisions. It adds explicit approved mapping history and opening cutover against the actual posted dedicated GL balance; statement headers and manual CREDIT/inflow or DEBIT/outflow lines; session snapshots; amount-limited, company-scoped matches to posted GL entries; and append-only reconciliation events. No mapping, statement, clearing or shared-cash history is backfilled. The worksheet calculates posted GL closing balance, uncleared debit deposits in transit, uncleared credit outstanding payments, adjusted bank balance = statement ending + deposits in transit − outstanding payments, and difference = adjusted bank − GL closing. Unexplained statement lines and nonzero difference block completion. An independent actor reviews; completed records cannot be edited. Uncleared book entries carry forward from authoritative GL rows, not duplicate records.

The narrow bank-posting guard is now part of the existing balanced-journal transaction. It blocks only a same-company journal line on a historically or currently mapped physical-bank GL account with a date protected by a completed reconciliation, including superseded mapping history. Reversals are postings and face the same rule; corrections belong in a later open period. Unrelated Finance accounts and draft/in-review sessions are not frozen. Statement evidence upload/download remains deferred with no file endpoint; unused nullable metadata is retained only as a future placeholder. CSV/provider import, automatic fee/interest adjustment journals, FX reconciliation, versioned reopening and Cash Flow are separate future work. The 087 source is local and uncommitted, not deployed or production-ready; DB/runtime verification remains pending. Migration 086 is committed but runtime-unverified and not deployed, and must be accepted first. Before any 087 deployment, apply and inspect the migration on an 086-accepted local database, verify MariaDB FKs/CHECKs/permissions, exercise tenant/maker-checker/match and posting/completion concurrency and financial math, and verify Sales Settlement/customer-payment paths unchanged.
