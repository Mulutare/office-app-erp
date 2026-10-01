# OfficeApp ERP production deployment runbook

This workflow prepares and validates releases locally by default. It changes production only when `-Execute` is supplied. It never modifies DNS, creates databases or users, changes the document root, or pushes Git commits.

## One-time cPanel setup

1. Keep the cPanel document root at `/home/passiontech/public_html/erp.passiontechnologiesplc.com`; the existing bridge must continue loading `/home/passiontech/office_app/public`.
2. Copy `.deploy/production.env.example` to the ignored `.deploy/production.env`; set only the HTTPS runner URL, production URL, and matching deployment secret.
3. Add a random 32-or-more-character `deployment_secret` to the production-only `/home/passiontech/office_app/config/app.local.php`. Add `deployment_allowed_ips` with the deployment source IP whenever a stable address is available. Neither value belongs in Git or a release archive.
4. Upload the reviewed runner to the new unique endpoint (leave the old endpoint untouched until deployment succeeds) of `deployment/production-runner.php` at `/home/passiontech/public_html/erp.passiontechnologiesplc.com/.officeapp-deployment-v3.php` and set it to `0600`. The runner returns 404 without a configured secret, uses HMAC authentication, and accepts only narrow allowlisted deployment operations.
5. Confirm PHP 8.1 has `PharData`, `proc_open`, and zlib, `/usr/bin/mysqldump` is executable, and `/home/passiontech` has sufficient space. The runner's read-only preflight verifies these before it creates a lock or staging directory.

## Commands

```powershell
# Safe default: prepare, inspect, and report; no remote calls or mutations
powershell -ExecutionPolicy Bypass -File tools/deploy-production.ps1

# Package verification only
powershell -ExecutionPolicy Bypass -File tools/deploy-production.ps1 -VerifyOnly

# Execute after reviewing dist/releases/<sha>/
powershell -ExecutionPolicy Bypass -File tools/deploy-production.ps1 -Execute
```

Run the isolated production-backup rehearsal before sealing. VerifyOnly builds, verifies, and seals the exact artifact in ignored `.deploy/approved-release.json`; Execute never rebuilds and refuses changed package, manifest, runner source, HEAD, origin, or tracked tree. Normal deployment never needs `-ProductionMigration`. With `-Execute`, the first production operation is an authenticated, read-only runner-status identity proof for protocol 3 and build `officeapp-deployment-v3-sealed-audit-20261001`, including normalized source SHA256 and unloaded-class flags, followed by migration-status. The deployer requires the ledger to be an exact ordered prefix of the release catalog, stops on gaps, divergence, or a production version ahead of the release, and applies only the calculated forward suffix. `-ProductionMigration` is accepted only as an offline dry-run diagnostic override and is rejected with `-Execute`.

## Safety and recovery

The deployer has no cPanel API, Terminal, Bash, SSH, or unrestricted filesystem dependency. It uploads bounded ordered HTTPS chunks, verifies final size and SHA-256, rejects unsafe archive entries, stages under `/home/passiontech/deployment-staging/<sha>-<utc>/`, preserves production configuration and storage, audits the staged migration runner read-only (099 ledger, next 100, apply, exact runner hash) before creating verified database and full application backups, and performs a same-filesystem cutover. The permanent minimal runner uses timestamped HMAC requests and replay protection for every write action. It accepts neither arbitrary commands, SQL, nor paths.

If post-cutover health fails, the application directory is restored automatically. Since forward database migrations may already have run, database restore is never automatic. Inspect the report and migration ledger, then restore the matching database backup only after an explicit compatibility decision.

`tools/deploy-production.ps1` has no `-Rollback` parameter. Its supported rollback is the automatic authenticated `rollback-application` action after post-cutover health failure, restoring the exact previous application directory recorded in release metadata. A separately initiated manual rollback needs an approved operator procedure or a reviewed standalone client; do not use the formerly documented unsupported command.

For recruitment, stop its scheduled task and pause imports before reverting code. Keep migration 111, all recruitment rows, the existing encryption key and durable private documents. Application rollback never restores the database automatically. Documents configured outside the replaceable application directories remain reachable after code rollback; retain any failed-release directory until document reconciliation is complete if storage was mistakenly kept inside it. Never restore a pre-import database backup over retained applications without a separately reviewed reconciliation plan.

Rotate the API token and `deployment_secret` after operator access changes or suspected disclosure. If interrupted, verify no deployment is active before removing either lock; never blindly delete a remote lock. Review `storage/logs/deployment-actions.log` and `DEPLOYMENT_REPORT.md` for audit evidence.

## Release artifacts

Each release contains the canonical archive, SHA-256 checksum, manifest, database upgrade rationale, checklist, and report. `database-upgrade.sql` is deliberately not synthesized: these PHP migrations contain runtime preflights, checksums, and resumable ledger semantics that plain SQL would bypass.

## Production-backup rehearsal

First collect production's read-only environment metadata in phpMyAdmin with `passiontech_officeapp` selected, and save the result as a JSON object outside Git:

```sql
SELECT VERSION() AS version, @@version_comment AS version_comment,
 @@character_set_server AS character_set_server, @@collation_server AS collation_server,
 @@character_set_database AS character_set_database, @@collation_database AS collation_database,
 @@character_set_client AS character_set_client, @@character_set_connection AS character_set_connection,
 @@character_set_results AS character_set_results, @@collation_connection AS collation_connection,
 @@SESSION.sql_mode AS sql_mode, @@GLOBAL.sql_mode AS global_sql_mode;
```

Run `powershell -ExecutionPolicy Bypass -File tools/rehearse-production-upgrade.ps1 -BackupPath <downloaded.sql.gz> -ProductionEnvironmentPath <metadata.json>`. Without the required version/vendor/charset/collation evidence, an upgrade rehearsal fails closed. The profile must include `"required_sql_modes": ["ONLY_FULL_GROUP_BY"]`; both actual global and session modes are recorded and must contain this proven production mode. An exact `sql_mode` string is optional and is reproduced/checked only when fully supplied; no other production modes are inferred. `-DiagnoseBaselineOnly` restores and audits read-only using explicitly unproven image defaults; it never runs migrations or reports an upgrade PASS. The source server header, independently of the dump-client version, selects the pinned compatible image. Production server/database settings initialize only the empty disposable server/schema; the dump's session directives, schema name, definitions and original definers are preserved. Disposable definer accounts have generated passwords and schema-only privileges.

For the verified MySQL 8.4.11 production profile, server and database defaults remain `utf8mb4_0900_ai_ci`. Restore and validation connections initialize with `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci` and explicit `SET collation_connection = 'utf8mb4_unicode_ci'`. The original dump's later session directives take precedence during restore. Before migrations, require the restored `vw_powerbi_fulfilled_sales` creation metadata to remain `utf8mb4` / `utf8mb4_unicode_ci` and record its successful `COUNT(*)` result; never alter a view to manufacture parity.

Before migration 100, the permanent read-only gate records actual image/version/vendor and server/database/session character sets and collations, inventories table/string-column/view metadata and dependencies, and queries every existing view. It preserves the exact failing view/query/error and SHOW CREATE metadata. Any required-view failure or environment mismatch prohibits migration. The read-only first-unapplied/preflight audit and upgrade follow only after this gate passes. Session settings reported by phpMyAdmin may differ from application PDO sessions; a mismatch requires evidence, never a collation cast or view/table rewrite to force a pass.

The environment uses an internal Docker network with no published ports or production credentials. Reports under ignored `dist/rehearsal/` contain schema metadata and counts, never dump rows or passwords. Plaintext SQL and generated credential files are deleted in `finally`, including when `-KeepFailedEnvironment` preserves containers for debugging. No historical import or cutover is performed. Run `powershell -ExecutionPolicy Bypass -File tools/test-rehearsal-baseline-guard.ps1` for the independent empty-server fixture proving that error 1270 prevents migration 100 and leaves the 099 ledger unchanged.

The current original-backup rehearsal requires the exact 099 to 111 sequence, reference synchronization, an idempotent second migration run and zero step residue. Migration 110 repairs the grouped Quick Sale sales-price projection, replaces all six final blocker branches with derived counts, and restores explicit history text normalization while retaining all final migration-106 readiness fields. Its preflight accepts only captured canonical final-109 definitions or exact completed-110 definitions; partial or unexpected views fail closed. The audit view's `sql_mode_contains_only_full_group_by` field is creation-time proof of the enforced preflight requirement. Current session mode is checked separately by preflight and runtime audits because MySQL prohibits system variables inside views. Recruitment uses the additive 111 migration; release health accepts its exact checksum and complete preceding ledger while retaining all Power BI view, profile and sentinel checks. See `docs/RECRUITMENT.md` for durable document storage and data-preserving rollback.

UTF-8 application PDO connections explicitly establish `utf8mb4_unicode_ci` without changing SQL modes or server/database/table defaults. The staged audit and migration/reference requests use the staged release's shared session validator, including when the live PDO adapter is still the old application version. The runner's staged audit queries all 23 required baseline views before backups. Release health uses the same read-only validator on the exact completed 110 ledger and every current view before cutover and after activation; a failed view or environment mismatch aborts deployment. Rehearsal and the focused 110 test invoke these same validators and check the cold application connection before any rehearsal session override.

The verified backup contains zero imported historical rows. The focused 110 test creates 99 synthetic unresolved Mussie rows solely inside an isolated rollback transaction, proves their blocker/readiness counts and null roles, then proves the original data and ledgers unchanged. These fixture rows are never imported by migrations or retained in the backup clone. A final read-only audit repeats view health, checksums, migration ledger, reporting/shop invariants and protected native/history row hashes after the focused tests.

Upload source: `deployment/production-runner.php` from the clean release clone. Intended public filename: `.officeapp-deployment-v3.php`. Upload is a separate manual operator action, never performed by these scripts. After upload, the deployer proves the executing endpoint identity before any write. The former endpoint remains untouched.
