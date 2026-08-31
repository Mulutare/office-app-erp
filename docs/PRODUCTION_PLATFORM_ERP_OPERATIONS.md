# Production platform ERP operations

The supported one-command cPanel workflow and rollback procedure are documented in [PRODUCTION_DEPLOYMENT_RUNBOOK.md](PRODUCTION_DEPLOYMENT_RUNBOOK.md).

This is the authoritative environment/runbook record for the OfficeApp ERP production host. It contains no passwords or secrets and does not authorize a deployment.

## Platform identity

| Item | Production value |
|---|---|
| Hosting | cPanel / Linux |
| ERP domain | `erp.passiontechnologiesplc.com` |
| Application path | `/home/passiontech/office_app` |
| Public document root | `/home/passiontech/public_html/erp.passiontechnologiesplc.com` |
| PHP | 8.1 / `ea-php81` |
| Database | `passiontech_officeapp` |

The application directory and public document root are different directories. Do not point the domain at the repository root and do not assume it points directly at `office_app/public`.

## Protected configuration

These live files are environment-specific and must never be packaged, overwritten, displayed or copied into support output:

```text
/home/passiontech/office_app/config/database.php
/home/passiontech/office_app/config/app.local.php
```

The release builder excludes them. During staging/cutover, copy or bind the already protected production versions using cPanel File Manager and verify ownership/permissions without opening their contents unnecessarily.

## Persistent runtime

| Path | Policy |
|---|---|
| `storage/uploads` | Persistent business files. Back up and preserve. Never package or replace with release data. |
| `storage/private` | Persistent private business/security files. Back up, preserve and keep outside public access. |
| `storage/logs` | Persistent operational evidence according to retention policy. Preserve across release; archive/rotate deliberately. |
| `storage/cache` | Regenerable runtime state. May be cleared only through a reviewed maintenance action; preserve permissions. |
| `storage/backups` (if present) | Persistent controlled backups. Keep outside release package and public access; verify restoration. |

The package carries real empty directories for cache, logs, private and uploads, but never runtime contents. Do not recursively change permissions merely because a release was extracted. Reapply only the known production owner/group/mode to staged paths that require it, and compare with the working release first.

## Public bridge

The domain root is a production bridge, not a disposable copy of `office_app/public`. Preserve and review individually:

```text
.htaccess
.user.ini
index.php
php.ini
service-worker.js
assets/
.well-known/
```

The bridge's `index.php` is responsible for loading the application from `/home/passiontech/office_app/public`; `.htaccess` provides routing/security headers; `.user.ini`/`php.ini` supply host-specific PHP settings; `.well-known` may serve certificate/domain validation; the service worker and assets are public versioned content. Before replacing any public asset, compare the staged application public manifest/content with the live bridge and back up the live public root. Copy only reviewed changed public assets. Never replace the entire public root or `.well-known` blindly.

Attendance browser geolocation requires this public response policy:

```text
Permissions-Policy: geolocation=(self)
```

Camera and microphone may remain disabled if unused. Never deploy `geolocation=()` for the ERP domain. Verify the final HTTPS response header in browser developer tools after cutover.

## Building the release

On a trusted Windows workstation with Git, Docker Desktop Linux containers and `tar` available:

```powershell
powershell -ExecutionPolicy Bypass -File tools/build-cpanel-package.ps1
```

The command builds locked production Composer dependencies inside Docker, creates a clean stage, excludes secrets/development/runtime content, creates and validates `dist/officeapp-cpanel.tar.gz`, and writes `dist/deployment-manifest.txt`. The TAR.GZ is authoritative. No ZIP extraction, TAR conversion, vendor copy, rename or manual repackaging is part of the process.

Before upload, compare the SHA256 and byte size with the manifest and retain the manifest with the change record.

## Backup and preflight

Before every production change:

1. Put the approved maintenance/change record and exact commit/package checksum in the deployment log.
2. Export the `passiontech_officeapp` database with cPanel Backup/Backup Wizard or phpMyAdmin. Verify that the backup is non-empty and can be imported into an isolated test database.
3. Create a recoverable archive/copy of `/home/passiontech/office_app`, excluding disposable cache if policy permits but including protected configuration and persistent runtime.
4. Back up the separate ERP public document root, including hidden files and `.well-known`.
5. Confirm free space can hold live + staging + backups + extraction.
6. Verify the package SHA256 locally and again after upload if cPanel exposes checksum tooling; otherwise compare upload byte size and validate extracted manifest/files.
7. Test the exact migrations and reference sync against the restored database copy before the window.

A backup is verified only when restoration has been demonstrated. Download or copy backups to the approved protected backup location; do not leave the only copy inside a directory being replaced.

## GUI-only staging and controlled cutover

Production may not expose Terminal. Use cPanel File Manager:

1. Upload `officeapp-cpanel.tar.gz` outside the public document root.
2. Extract to a new versioned staging directory, for example `/home/passiontech/releases/office_app-<commit>/`. Extraction must produce one `office_app/` directory.
3. Confirm `app`, `bin`, `config`, `database`, `deployment`, `docs`, `public`, `resources`, `routes`, `storage`, `vendor/autoload.php` and the four empty runtime directories exist.
4. Confirm `config/database.php` and `config/app.local.php` are absent from the extracted release.
5. Copy the protected live configuration into the staged `office_app/config` through File Manager. Preserve persistent uploads/private/logs and any approved backups; do not replace them with empty release directories. A reviewed symlink or controlled copy strategy may be used only if supported and already tested on this host.
6. Validate staged paths, ownership and PHP runtime. If there is no safe way to execute `bin/check-runtime.php` privately, inspect requirements in cPanel MultiPHP Manager and validate through the post-cutover health route; do not create a public command endpoint.
7. Keep `/home/passiontech/office_app` intact until staging is complete. Cut over using a recoverable rename: rename the live directory to a timestamped rollback name, then rename staged `office_app` to `/home/passiontech/office_app`. File Manager renames within the same filesystem are preferred to delete/copy.
8. Update only reviewed public bridge/assets, preserving host files and geolocation policy.
9. Run database upgrades as the separately controlled step below, then smoke test.
10. Retain the rollback release and backups according to policy; do not delete them during the deployment window.

## GUI-compatible database upgrade

Existing production upgrade commands are:

```text
/usr/local/bin/ea-php81 /home/passiontech/office_app/bin/migrate.php
/usr/local/bin/ea-php81 /home/passiontech/office_app/bin/sync-reference-data.php
```

The cPanel PHP binary is `ea-php81`; verify its displayed path in MultiPHP Manager/cPanel documentation if the host maps it differently. Never use `bin/install-database.php` for an upgrade.

When Terminal is unavailable, use cPanel **Cron Jobs** as a private one-time command runner:

1. Create the migration cron for a near-future minute with the absolute PHP and application paths. Redirect stdout/stderr to a protected file under `/home/passiontech/office_app/storage/logs/`, not the public root.
2. Wait for the scheduled run and verify the protected log and `schema_migrations` through the application's operations view or phpMyAdmin read-only query.
3. Delete the migration cron immediately after the one successful execution, before scheduling reference sync.
4. Create the reference-data sync cron for one near-future minute, verify its protected log/result, and delete it immediately.
5. Record operator, time, command, migration versions and result in the change record.

Example command bodies (the cPanel schedule fields supply the minute/date):

```text
/usr/local/bin/ea-php81 /home/passiontech/office_app/bin/migrate.php >> /home/passiontech/office_app/storage/logs/deploy-migrate.log 2>&1
/usr/local/bin/ea-php81 /home/passiontech/office_app/bin/sync-reference-data.php >> /home/passiontech/office_app/storage/logs/deploy-reference-sync.log 2>&1
```

Do not combine both commands into one cron: verification must gate the second. Do not make the cron repeat indefinitely. Do not expose migration or sync through a web URL, temporary controller, query parameter or public script. If cPanel cannot schedule a one-time minute precisely, create a temporary low-frequency job, observe its first run, and delete it immediately; migration checksum/preflight protects against blind reruns but is not permission to leave the job active.

## Recurring background processing

Production operations require integration, webhook and attendance processing through `bin/run-production-task.php` (or the current documented attendance wrapper if retained). cPanel Cron Jobs should use absolute `ea-php81` and application paths and redirect routine output away from public paths. The runner has non-overlap/database locking; still monitor task-run success/failure and Error Registry. Do not create duplicate legacy and consolidated attendance schedules.

Review the active Cron Jobs list during every release. One-time migration/sync jobs must be absent after deployment; recurring workers must be present exactly once per intended cadence.

## Smoke tests

Perform with dedicated approved non-production-like accounts/data inside the live tenant only when the change record authorizes it; otherwise use existing read-only records.

- Health/runtime: HTTPS, no debug output, expected PHP extensions, protected config not downloadable.
- Authentication: login/logout, company switch, ordinary employee has no administration access.
- Attendance: employee self-service loads; browser requests geolocation; policy header is `geolocation=(self)`; safe check-in/out only if approved.
- Sales: customer/product/quotation/order pages load; verify fulfilment controls appropriate to the released version; no silent alternate warehouse should be accepted after remediation.
- Inventory: stock, warehouse/location views and assigned operations; no cross-company/resource access.
- Procurement: supplier/PO/receipt/bill views and next-action badges.
- Assets: register/detail, next depreciation guidance and period link.
- Finance: periods, invoices, AR/AP, journals and reconciliation views; debit/credit and subledger variances remain correct.
- Integration: worker health, pending/failed events, controlled retry permission.
- Database: expected new migration versions recorded once; reference sync completed.
- Public assets: CSS/JS/service worker load from the separate bridge; `.well-known` preserved.
- Background work: next scheduled runs complete and are recorded.

Stop and roll back on login failure, 500 errors, missing protected configuration, unexpected migration state, non-zero unexplained reconciliation variance, broken public assets/geolocation, or unauthorized access.

## Rollback

1. Stop user traffic through the approved maintenance mechanism if possible.
2. Preserve error logs and deployment evidence.
3. Rename the failed `/home/passiontech/office_app` aside and rename the retained previous application directory back; restore the backed-up public bridge/assets if changed.
4. If migrations or business writes occurred, use the pre-deployment database backup according to the approved rollback decision. Do not edit `schema_migrations`, drop new columns manually or run the installer.
5. Restore persistent runtime only if it was damaged; do not overwrite newer valid uploads casually.
6. Re-run health/login and reconciliation checks and document the rollback.

MySQL DDL may auto-commit. Application rollback alone does not reverse schema or data effects; this is why the tested database backup is mandatory.
