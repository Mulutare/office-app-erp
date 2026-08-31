# OfficeApp ERP production deployment runbook

This workflow prepares and validates releases locally by default. It changes production only when `-Execute` is supplied. It never modifies DNS, creates databases or users, changes the document root, or pushes Git commits.

## One-time cPanel setup

1. Keep the cPanel document root at `/home/passiontech/public_html/erp.passiontechnologiesplc.com`; the existing bridge must continue loading `/home/passiontech/office_app/public`.
2. Create a least-privilege cPanel API token and copy `.deploy/production.env.example` to the ignored `.deploy/production.env`.
3. Add a random 32-or-more-character `deployment_secret` to the production-only `/home/passiontech/office_app/config/app.local.php`. Add `deployment_allowed_ips` with the deployment source IP whenever a stable address is available. Neither value belongs in Git or a release archive.
4. Once, upload `deployment/production-runner.php` as `/home/passiontech/public_html/erp.passiontechnologiesplc.com/.officeapp-deployment.php`, set it to `0600`, and set `DEPLOYMENT_RUNNER_URL` to its HTTPS URL. The runner returns 404 without the production secret, accepts only signed fixed actions, and its migration-status operation is read-only. It remains installed because every future deployment must securely discover the ledger before making any production mutation.
5. Confirm cPanel permits UAPI Fileman upload/list/metadata and the legacy cPanel API 2 Fileman operations used for mkdir, copy, move, rename, chmod, extraction, and trash. The command stops if these capabilities are unavailable.
6. Confirm `/usr/bin/mysqldump`, `/usr/bin/gzip`, PHP `proc_open`, and sufficient backup space are available. A verified database backup is mandatory before migration.

## Commands

```powershell
# Safe default: prepare, inspect, and report; no remote calls or mutations
powershell -ExecutionPolicy Bypass -File tools/deploy-production.ps1

# Package verification only
powershell -ExecutionPolicy Bypass -File tools/deploy-production.ps1 -VerifyOnly

# Execute after reviewing dist/releases/<sha>/
powershell -ExecutionPolicy Bypass -File tools/deploy-production.ps1 -Execute
```

Normal deployment never needs `-ProductionMigration`. With `-Execute`, the first production operation is an authenticated, read-only migration-status request. The deployer requires the ledger to be an exact ordered prefix of the release catalog, stops on gaps, divergence, or a production version ahead of the release, and applies only the calculated forward suffix. `-ProductionMigration` is accepted only as an offline dry-run diagnostic override and is rejected with `-Execute`.

## Safety and recovery

The deployer uses local and remote locks, stages under `/home/passiontech/deployment-staging/<sha>-<utc>/`, preserves production-only configuration and storage, creates compressed database and full application backups, validates staging, and performs a same-filesystem directory cutover. The permanent minimal runner is HMAC authenticated with timestamp validation, uses replay protection for mutating actions, accepts only fixed deployment actions, and cannot execute caller-supplied commands or SQL.

If post-cutover health fails, the application directory is restored automatically. Since forward database migrations may already have run, database restore is never automatic. Inspect the report and migration ledger, then restore the matching database backup only after an explicit compatibility decision.

```powershell
# Application-only restore of an exact recorded backup
powershell -ExecutionPolicy Bypass -File tools/deploy-production.ps1 -Execute -Rollback office_app_before_YYYYMMDDhhmmss_abcdef0
```

Rotate the API token and `deployment_secret` after operator access changes or suspected disclosure. If interrupted, verify no deployment is active before removing either lock; never blindly delete a remote lock. Review `storage/logs/deployment-actions.log` and `DEPLOYMENT_REPORT.md` for audit evidence.

## Release artifacts

Each release contains the canonical archive, SHA-256 checksum, manifest, database upgrade rationale, checklist, and report. `database-upgrade.sql` is deliberately not synthesized: these PHP migrations contain runtime preflights, checksums, and resumable ledger semantics that plain SQL would bypass.
