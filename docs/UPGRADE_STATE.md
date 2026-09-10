# Office App ERP Upgrade State

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

Branch:
main

Baseline commit:
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

Latest migration in repository:

082_stock_hierarchy_manager_role.php

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

At this baseline, if no newer migration exists, the next candidate is
083.

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
PLANNED / NOT YET IMPLEMENTED

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

Possible new migration:
083 only if repository inspection confirms 082 is still the latest
migration when implementation begins.

## 8. Upgrade Completion Procedure

For every future upgrade:

Before editing:

1. read docs/UPGRADE_STATE.md
2. run git status
3. confirm branch
4. confirm HEAD
5. inspect latest migrations
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

Implement the scoped notification + rejected edit/resubmit upgrade only.

Do not combine it with any other ERP improvement.

End of baseline state.
