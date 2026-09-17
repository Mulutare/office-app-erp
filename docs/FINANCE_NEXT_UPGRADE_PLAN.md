# Finance Next Upgrade Plan

**Status:** PLANNED / NOT IMPLEMENTED
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

**Audit only. No schema or UI implementation yet.**

The next development session should inspect the current repository and database-facing Finance code, especially Expense Requests, AR/AP, journal/account mappings, and permissions. It should then update this plan with a precise gap matrix and propose the smallest safe migration number after the current production migration baseline.
