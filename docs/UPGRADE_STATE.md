# Office App ERP Upgrade State

## START HERE FOR EVERY DEVELOPMENT / UPGRADE TASK

**This is the authoritative development handoff and upgrade-state document for the entire ERP.**

- Read this file completely before changing the ERP.
- The ERP is already in production. Work is incremental upgrade only.
- Never assume the whole repository should be upgraded or refactored.
- Determine the exact requested module/functionality first.
- Inspect current code and current migration state before editing.
- Preserve all unrelated modules and production behavior.
- After every completed/deployed upgrade, update this file.
- If information here conflicts with actual production evidence, stop and verify production before making a destructive or compatibility-sensitive change.
- Always check current Git HEAD with `git rev-parse HEAD`, rather than relying on a self-referential commit number stored in this document.
- Where deployment is involved, always check the current migration baseline from both repository files and production migration records.

Application functional baseline:
`8798d7026b6b1f21daa7ad5cab4af95fa4a06f12`
(Fix Regional Central replenishment reconciliation)

The documentation/state file was introduced immediately afterward and is part of the development baseline.
Do not store a "current documentation commit" as a permanent fixed value: committing an edit to this file itself creates a newer commit.
The application functional baseline identifies the recorded application behavior; it is not a substitute for checking current HEAD.

## 1. Upgrade Policy

This ERP is maintained using INCREMENTAL PRODUCTION UPGRADES.

The current production system is the baseline.

Every requested change must be treated as a scoped upgrade, not as a
full-system redesign or cutover.

Rules:

- Modify only the requested functionality/module.
- Modify shared infrastructure only when strictly required by that
  scoped upgrade.
- Preserve all unrelated business logic and behavior.
- Do not bundle unrelated improvements, cleanup, refactors, or schema
  changes into an upgrade.
- Do not rewrite working modules merely to make architecture cleaner.
- Preserve backward compatibility unless the requested upgrade
  explicitly requires a controlled change.
- Existing production data and already-applied migrations are
  authoritative.
- Never alter an already-applied production migration.
- Add a new migration for new schema changes.
- Before choosing a migration number, inspect the existing migration
  directory and recorded migration state.
- Keep tenant/company isolation.
- Keep existing permissions and separation-of-duties rules unless the
  scoped upgrade explicitly changes them.
- Keep existing audit/history behavior.
- Do not weaken security to make a feature easier to implement.
- Do not use `git add .`.
- Stage only files belonging to the current upgrade.
- Do not include temporary files, audit dumps, backup files, patches,
  archives, screenshots, or test artifacts in commits.
- Do not commit, push, deploy, or run builds/tests unless explicitly
  requested.

## 2. Current Repository Baseline

The following records the application functional baseline, including branch, remote state, and latest commits at that baseline. Check current repository state separately; run `git rev-parse HEAD` for current HEAD.

Branch:
main

Application functional baseline commit:
8798d7026b6b1f21daa7ad5cab4af95fa4a06f12

Short commit:
8798d70

Remote state:
origin/main is aligned with 8798d70.

Latest commits:

8798d70 Fix Regional Central replenishment reconciliation
f7fc40f Add stock hierarchy replenishment and Quick Sale reporting upgrade
d65f473 Sync production stock hierarchy through migration 080
0014857 Harden Quick Sale hierarchy, routing, finance handoff and settlement controls
d340e7b Complete Quick Sale workflow and settlement integration

## 3. Current Migration Baseline

Latest migration in repository (local development, not deployed):

083_user_notifications_and_rejection_resubmit.php

Production remains recorded through 082. Migration 083 has not been run.

Recent migration sequence:

073_powerbi_history_snapshot.php
074_stock_request_routing_and_reorder_notifications.php
075_sales_quick_sale_workflow.php
076_quick_sale_post_sale_reporting.php
077_quick_sale_finance_handoff.php
078_central_stock_replenishment.php
079_manager_replenishment_and_pricing.php
080_quick_sale_staged_fulfilment.php
081_manager_peer_replenishment_and_evidence.php
082_stock_hierarchy_manager_role.php

Next migration number must NOT be assumed permanently.
Always inspect the repository before creating the next migration.

At the application functional baseline, 083 was the next candidate.
Migration 083 now exists locally for this scoped upgrade. Inspect repository
files and deployment migration records again before allocating another number.

## 4. Production Baseline

Production database:
passiontech_officeapp

Production application:
office-app-erp

Production has already been upgraded through migrations 081 and 082.

Migration 081:
manager peer replenishment and multiple Quick Sale evidence

Migration 082:
Stock Hierarchy Manager role

Regional Central replenishment reconciliation fix from commit 8798d70
has also been deployed.

Do not reapply old migrations or recreate already-completed production
configuration during future upgrades.

## 5. Important Existing Functionality To Preserve

### Stock hierarchy

Existing stock hierarchy/replenishment behavior must be preserved.

Current design includes:

DSA/DSP
-> Shop Manager
-> District Manager
-> Regional Manager
-> Central/company procurement when required

Manager replenishment represents replenishment of that manager's own
warehouse.

Do not turn this back into automatic forwarding of subordinate
requests.

Receiving manager replenishment does not automatically fulfill lower
level employee requests.

Peer transfers preserve source-owner approval.

Central replenishment and Regional reconciliation currently work and
must not be changed by unrelated upgrades.

### Quick Sale

Existing Quick Sale workflow must be preserved.

Important behavior:

- allocated stock is reported by DSA/DSP
- Sold and Returned quantities are independent
- report evidence supports multiple files
- manager can return a report for correction
- correction_required allows DSA/DSP to create a new corrected report
- previous rejected report/evidence remains immutable for audit
- confirmed report continues into existing finance/settlement workflow

Do not redesign this lifecycle during unrelated upgrades.

### Action Required

Action Required represents CURRENT WORK that still needs to be done.

It remains the source of truth for pending business actions.

Do not replace it with notifications.

## 6. Working Tree Warning

At baseline there are multiple local untracked development/audit files.

Examples include:

.tmp-quick-sale-1.js
.tmp-stock-requests-repaired-final.php
.tmp-stock-requests-repaired.php
before-bi-baseline-072.sql
central-replenishment-fix.patch
central-replenishment-full-diff.txt
employee-self-service-audit.txt
local-before-production-sync.sql
migration061-changes.patch
officeapp-074-deploy.tar.gz
officeapp-074-final.tar.gz
officeapp-074-production.sql
pre-stock-request-074.sql
quick-sale-test-receipt.png
sales-team-scope-app.tar.gz
sales-team-smart-ui.tar.gz
sales-team-ui.tar.gz
stock-hierarchy-082-audit.txt
stock-request-implementation.patch
tests/module-role-entitlements.php

These are NOT part of the baseline application upgrade.

Do not stage them automatically.

Use explicit paths with `git add -- <files>` when an upgrade is ready
to commit.

## 7. Next Planned Upgrade

Status:
IMPLEMENTED LOCALLY / NOT DEPLOYED / RUNTIME VERIFICATION PENDING

Scope:

In-app user notifications plus rejected-edit-resubmit support.

This must be implemented as ONE SCOPED UPGRADE.

Primary functionality:

1. notification bell beside Sign out
2. tenant/user-scoped persistent notifications
3. unread count
4. direct links to affected records
5. notifications for rejection/correction and selected newly-assigned
   actions
6. notifications must not replace Action Required

Rejected correction scope:

- Sales Order rejected at approval stage
  -> original creator can correct same order
  -> same order_id/order_number
  -> resubmit for approval

- Procurement Requisition rejected
  -> original requester can correct same requisition
  -> same requisition_id/requisition_number
  -> resubmit for approval

- preserve rejection reason/history/audit

Quick Sale:

DO NOT redesign its correction workflow.
Only attach notification events to the existing behavior.

Stock hierarchy:

DO NOT modify replenishment/routing logic as part of this upgrade.

Migration created after inspecting repository state:
083_user_notifications_and_rejection_resubmit.php

082 was the latest numbered MySQL migration before editing. No migration
was applied in this session; production migration records were not queried.

## 8. Upgrade Completion Procedure

For every future upgrade:

### Next-session checklist

Before editing:

1. read docs/UPGRADE_STATE.md
2. run git status
3. confirm branch
4. confirm HEAD with `git rev-parse HEAD`
5. inspect latest repository migrations and, where deployment is involved, production migration records
6. identify exact requested scope
7. inspect affected existing code before modifying it

During implementation:

1. touch only necessary files
2. preserve unrelated workflows
3. create a new migration only when schema changes require one
4. do not change previously applied migrations
5. preserve tenant isolation, authorization and audit behavior

Before commit:

Report:

- files created
- files modified
- migration added
- exact behavior changed
- behavior intentionally preserved
- git diff --stat
- git status --short

Commit only after explicit approval.

After successful production deployment:

Update this document with:

- new baseline commit
- new latest migration
- deployed upgrade
- important new behavior
- unresolved issues
- next planned upgrade

## 9. Current Next Action

Review the local scoped notification + rejected edit/resubmit upgrade.
Tests/builds, staging, committing, pushing and deployment require a separate
explicit request. Before deployment, verify production migration records,
apply the reviewed migration using the established migration procedure, and
verify the behaviors described in section 17.

Do not combine it with any other ERP improvement.

## 10. Module / Integration Status

- Stock hierarchy and manager replenishment: existing behavior is documented in section 5; Regional Central reconciliation is recorded as deployed in section 4.
- Quick Sale: reporting, multiple evidence files, correction, and existing finance/settlement integration are documented in section 5.
- Action Required: the existing source of truth for pending business actions; see section 5.
- Notifications and rejected edit/resubmit: implemented locally, runtime verification pending, NOT DEPLOYED; see sections 7 and 17.
- Other module/integration status: Not currently documented — verify before changing this area.

## 11. Production-specific Deployment Notes

The recorded production application is `office-app-erp`, using database `passiontech_officeapp`.
Migrations 081 and 082 and the Regional Central reconciliation fix are recorded as deployed in section 4.
Do not reapply old migrations or recreate already-completed production configuration.

Additional production-specific deployment details:
Not currently documented — verify before changing this area.

## 12. Known Production / Local Differences

The known local-only/untracked development and audit artifacts are listed in section 6 and must not be included automatically in upgrades.

Other production/local differences:
Not currently documented — verify before changing this area.

## 13. Completed Upgrades

Recorded completed production upgrades:

- Migration 081: manager peer replenishment and multiple Quick Sale evidence.
- Migration 082: Stock Hierarchy Manager role.
- Application commit `8798d70`: Regional Central replenishment reconciliation fix.

The application commit history at the functional baseline is retained in section 2.
This state document was introduced afterward as part of the development baseline; documentation changes do not imply an application deployment.

## 14. Current Unresolved Issues

For the local notification/correction upgrade: runtime behavior and migration
execution are unverified because tests/builds and deployment were not requested.
Production migration records and production/local compatibility must be checked
before deployment. See section 17 for deliberate correction-field limits.

Other issues: Not currently documented — verify before changing this area.

## 15. Explicit Out-of-scope Items

For the next planned upgrade in section 7:

- No full-system redesign, cutover, or repository-wide refactor.
- No unrelated ERP improvements, cleanup, or schema changes.
- No stock hierarchy replenishment or routing changes.
- No redesign of Quick Sale correction; attach notifications to existing behavior only.
- No replacement of Action Required with notifications.
- Preserve unrelated business logic, permissions, tenant isolation, and audit/history.

## 16. Post-deployment Verification / Status

Section 4 records the deployed baseline. Detailed post-deployment verification results and evidence:
Not currently documented — verify before changing this area.

After each deployment, record the deployed application baseline, migration status, completed scope, verification performed and its results, unresolved issues, and next planned upgrade.
Distinguish recorded deployment status from checks actually performed; do not infer successful verification from this document alone.
Follow the completion procedure in section 8.

## 17. Local Notification and Rejected Correction Upgrade

Implementation status: IMPLEMENTED LOCALLY; runtime verification pending.
Deployment status: NOT DEPLOYED.
Observed starting branch: main.
Observed starting HEAD: 977605ac301f4f663dade1e4a8b5ae4c05869baf.
This records the starting point only; always check current HEAD independently.

### Migration and notification functionality

Migration 083 adds user_notifications with company/user membership isolation,
per-recipient deterministic event uniqueness, read state, and newest-first/unread
indexes. It adds purchase_requisition_status_history and extends the existing
Sales Order status constraint to include rejected while retaining every old state.
Previously applied migrations are unchanged.

The shared UserNotificationService owns notification writes and read operations.
The bell immediately beside Sign out opens the latest 30 notifications, shows an
unread badge only above zero, differentiates unread entries, and displays escaped
title, shortened message and timestamp. Empty state: No notifications.
CSRF-protected POST actions derive company/user from the authenticated session.
Opening an entry marks only that user's entry read and redirects to an internal
ERP record path. Mark all as read clears only that recipient's unread entries
and returns to the dashboard. Normal destination authorization remains in force.
Reading notifications never changes domain work state.

Events are written inside the associated domain transaction:

- Sales Order rejection -> original creator, keyed by status-history ID.
- Requisition rejection -> original requester, keyed by new status-history ID.
- Quick Sale report submission, including corrected reports -> assigned manager,
  keyed by report ID.
- Quick Sale correction required -> original report submitter, keyed by report ID.
- Stock request creation and transfer receipt/cancellation handback -> recorded
  current handler when pending review, keyed by request/user and creation or
  transfer event identity.
- Peer proposal -> exact source owner, keyed by proposal ID.
- Dispatched peer transfer -> exact destination owner, keyed by proposal ID.

No company-wide recipient broadcast, rendering-triggered notification creation,
email, push, queues, WebSockets or external notification service was added.
Optional Quick Sale confirmation notifications were not added. General transfers
without an existing exact responsible recipient do not generate notifications.

### Same-document correction

Sales Orders: submitted -> rejected, restricted to sales.orders.approve,
with mandatory reason and creator/rejector separation. Quick Sale-linked orders
and orders with execution/payment records cannot use this rejection path.
The original creator with existing create/submit permissions and record scope
can use Edit and Resubmit: rejected -> submitted on the same ID/number.
Creation validation/calculation is reused, including active products/customers,
source authorization, dates, discounts, tax and credit controls. Header and lines,
commission accrual recalculation, status history and audit are transactional.
Approval is required again; old rejection history remains.

Correction fields are deliberately limited: dates, notes, and quantities,
discounts and tax on existing direct-order products. Customer, owner, currency,
product identity and existing source remain fixed. A missing legacy source
must be selected and authorized. Quotation-linked commercial quantities,
discounts, tax, prices and commission rates remain fixed; their dates/notes
can be corrected. Downstream records or approved commissions block correction.

Requisitions: submitted -> rejected remains; the original requester with the
existing create permission can edit and resubmit rejected -> submitted on the
same ID/number with requester unchanged. Existing product/department/warehouse
validation and line validation are reused. Justification, required-by date,
descriptions, quantities and positive estimated prices are editable.
Product, department, warehouse and line IDs remain fixed; linked replenishment
quantities remain fixed. Converted/approved records cannot use this path.
History/audit retain previous rejection reasons. Approval is required again.

Action Required adds owner tasks: Correct and resubmit order and Correct and
resubmit requisition. Rejected records do not become approver work until
resubmitted. Notification read state is not used to determine pending work.

### Preserved behavior and verification status

Quick Sale still creates a NEW corrected report under the SAME Quick Sale;
old report/evidence remain immutable and Correct Sales Report remains.
Stock hierarchy, manager routing, Central replenishment, peer source approval,
finance integrations and unrelated modules remain unchanged. Stock/peer changes
are notification calls only.

No tests, builds, migration execution or deployment were run. Static source/diff
review was performed. Before a separately authorized deployment, verify migration
083 against the actual production ledger and verify tenant/user read isolation,
CSRF, notification deduplication and rollback, owner-only correction, repeated
rejection/resubmission history, source/credit validation, exact record links,
unchanged Quick Sale correction/evidence, and unchanged stock/peer behavior.

### Files created

- `app/controllers/NotificationController.php`
- `app/services/UserNotificationService.php`
- `database/migrations/mysql/083_user_notifications_and_rejection_resubmit.php`
- `resources/views/components/rejected-order.php`
- `resources/views/components/user-notifications.php`
- `resources/views/procurement/requisition.php`

### Files modified

- `app/controllers/ProcurementController.php`
- `app/controllers/SalesController.php`
- `app/repositories/MySql/SalesRepository.php`
- `app/repositories/SalesRepository.php`
- `app/services/ActionRequiredCountService.php`
- `app/services/ProcurementService.php`
- `app/services/SalesQuickSaleService.php`
- `app/services/SalesService.php`
- `app/services/StockRequestPeerWorkflow.php`
- `app/services/StockRequestService.php`
- `resources/views/layouts/app.php`
- `resources/views/procurement/index.php`
- `resources/views/sales/order.php`
- `routes/web.php`
- `docs/UPGRADE_STATE.md`

After actual deployment, update the production baseline and verification evidence.
Do not interpret this local implementation record as production upgrade evidence.

End of baseline state.
