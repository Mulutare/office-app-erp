# Recruitment handoff — 1 October 2026

Branch: `feat/recruitment-handoff-2026-10-01`. Production deployment remains pending. No merge, production migration, live mailbox connection or scheduler installation was performed.

## Complete

Recruitment provides company-scoped vacancies, applicants/applications, durable read-only email imports, retry/idempotency, private quarantined attachments, reviewer assignment, audited status/hiring/merge/archive operations, permission-protected downloads, filtered Excel export and scheduled imports with effective HR/company entitlement checks. Hiring never creates an employee automatically.

Power BI migration **110 remains unchanged**; recruitment is **111**. The draft duplicate 110 was removed. Deployment/rehearsal ledger checks recognize the reviewed additive 111 successor while preserving Power BI checks.

## Verification and pending work

**113 recruitment checks passed:** integration 44, controllers/screens 39, security 18, retry integrity 5, release ledger 7. **18 broader failures were reproduced on the pre-recruitment baseline:** `tests/run.php` 246 checks / 16 failures and module authorization 23 checks / 2 failures on matched MySQL baseline and candidate. The broad suite aborts before later checks; it is not certified green. The earlier inactive-company failure was reproduced as fixture contamination and fixed with a dedicated test tenant.

Disposable MySQL 8.4.11 with `ONLY_FULL_GROUP_BY` passed 109→110 and 110→111 preservation/repeat-run rehearsals. Exact production GLOBAL/SESSION SQL modes, trigger/binlog privileges, restored production-backup rehearsal and healthy production cutover/rollback still need verification. Native IMAP extension/provider TLS/authentication/folder/UID behavior and live scheduler checks remain untested. OAuth-only providers need a connector. No trusted malware scanning/release integration exists; ordinary users cannot download unscanned CVs.

See `docs/RECRUITMENT_VERIFICATION.txt` for every failure/classification and `docs/RECRUITMENT_DEPLOYMENT_CHECKLIST.txt` for backups, configuration, approved commands, live checks and data-preserving rollback. `docs/RECRUITMENT.md` describes permissions and operation. The verbose local evidence bundle, synthetic exported workbook and unrelated working files remain on the office PC; they are not all uploaded to Git.

## Dependencies preserved

The branch descends from **c4a8d08936b035151ea5806c7a99aa92b8092478**, the actual pre-recruitment Power BI/deployment baseline. At initial inspection, `origin/main` was verified at that commit; there were no unpushed Power BI commits to rescue. Its committed migrations 100–110 and deployment/profile validators are preserved in this branch's ancestry. Shared recruitment changes to deployment validation/rehearsal scripts are included here.

During the handoff push, `origin/main` advanced to **35840d7a637fdf63275b276d751d2421cb34d5dd** (`powerbi: keep production upgrade target at 109`). That commit removes Power BI 110 and changes earlier migration definitions and production tooling. **This recruitment branch deliberately retains the tested Power BI 110 → recruitment 111 catalog and has not merged/rebased that main update.** Neither branch is merged or deployed by this session. Do not transplant recruitment 111 alone onto the new 109 catalog or pull main into this branch without reviewing migration ordering, historical checksum compatibility and deployment targets.

Five edited files overlap with that concurrent main change: `deployment/powerbi-upgrade-validation.php`, `docs/PRODUCTION_DEPLOYMENT_RUNBOOK.md`, `tests/powerbi-mysql84-readiness-contract.php`, `tools/rehearsal-post-tests-audit.php`, and `tools/rehearse-production-upgrade.php`. The main change also modifies migrations 101/105/106/109, removes 110, and changes the production runner and additional rehearsal/deployment tests. Reconcile these side by side in an isolated checkout; do not automatically resolve the conflict by choosing either file wholesale. The 113/18 results apply to this branch's c4 baseline, not to an unverified combination with 35840d7.

Unrelated local SQL exports, archives, screenshots, recovery scripts, audit files and `work/` were deliberately not staged, deleted or uploaded. Protected runtime configuration, credentials, production data and actual applicant documents are not included. RFC822 fixtures contain only invented `example.test` messages and a minimal synthetic PDF marker. Compose passwords/keys are disposable test values, never production credentials.

## Resume on the home PC

Prerequisites: Git, Docker Desktop with Linux containers and access to the existing GitHub remote. Run these in a **new clean directory**, keeping any existing checkout's work untouched:

```powershell
git clone --branch feat/recruitment-handoff-2026-10-01 --single-branch https://github.com/Mulutare/office-app-erp.git office-app-erp-recruitment
cd office-app-erp-recruitment
git status --short
git log -1 --oneline
git log -1 --oneline c4a8d08936b035151ea5806c7a99aa92b8092478
git diff --check
docker compose -f compose.recruitment.test.yaml up -d --build
docker compose -f compose.recruitment.test.yaml exec -T app php tests/recruitment-local-bootstrap.php
docker compose -f compose.recruitment.test.yaml exec -T app php tests/recruitment-migration.php
docker compose -f compose.recruitment.test.yaml exec -T app php tests/recruitment-integration.php
docker compose -f compose.recruitment.test.yaml exec -T app php tests/recruitment-endpoints.php
docker compose -f compose.recruitment.test.yaml exec -T app php tests/recruitment-security.php
docker compose -f compose.recruitment.test.yaml exec -T app php tests/recruitment-retry-integrity.php
docker compose -f compose.recruitment.test.yaml exec -T app php tests/recruitment-release-contract.php
```

Run each command only after its predecessor passes. `tests/recruitment-local-bootstrap.php` refuses a non-empty database; it does not erase data. It creates explicitly synthetic missing BI source tables/manager records needed by the existing 072/102/107 prerequisites, then uses the real catalog through 111. This helper is not a production installer or a restored-backup rehearsal. The test Compose publishes no ports and uses a disposable tmpfs database; destroying/recreating it loses only that project's synthetic data.

For read-only comparison with current main after a single-branch clone:

```powershell
git fetch origin main:refs/remotes/origin/main
git log --oneline --left-right origin/main...HEAD
git diff origin/main HEAD -- database/migrations/mysql deployment docs/PRODUCTION_DEPLOYMENT_RUNBOOK.md tests/powerbi-mysql84-readiness-contract.php tools/rehearse-production-upgrade.php tools/rehearsal-post-tests-audit.php
```

These commands inspect divergence; they do not merge or deploy it.

To examine broader regressions, recreate **only this test project**, initialize fresh fixtures and run each suite separately so an expected failure does not hide later results:

```powershell
docker compose -f compose.recruitment.test.yaml down --volumes
docker compose -f compose.recruitment.test.yaml up -d
docker compose -f compose.recruitment.test.yaml exec -T app php tests/recruitment-local-bootstrap.php
docker compose -f compose.recruitment.test.yaml exec -T app php tests/run.php
docker compose -f compose.recruitment.test.yaml exec -T app php tests/module-authorization-contract.php
docker compose -f compose.recruitment.test.yaml exec -T app php tests/effective-permission-policy.php
docker compose -f compose.recruitment.test.yaml exec -T app php tests/data-exchange.php
docker compose -f compose.recruitment.test.yaml exec -T app php tests/module-release-integration.php
docker compose -f compose.recruitment.test.yaml exec -T app php tests/deployment-migration-audit.php
docker compose -f compose.recruitment.test.yaml down --volumes
```

The portable harness uses the repository PHP 8.1 Dockerfile and Composer lock. Build only from the clean cloned branch so unrelated office-PC archives/configuration do not enter the Docker context. It has no IMAP extension/live credentials. Supply production secrets later through approved hosting configuration; do not copy them into this branch or test Compose.

## Rollback and deployment boundary

Stop recruitment cron, pause mailboxes and retain all 111 tables/ledger, imports/history, ciphertext, existing encryption key and stable private document storage outside replaceable application directories. Code-only rollback preserves the database; restoring a pre-import database backup would lose later applications. The deployer has automatic post-cutover rollback but no `-Rollback` CLI parameter. An old release-health helper may reject retained 111; certify a forward-compatible fallback artifact before deployment. Follow the checklist; **do not run production `-Execute` or production migrations merely to resume this work**.
