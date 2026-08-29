# cPanel deployment runbook

The authoritative production environment and operating procedure is [PRODUCTION_PLATFORM_ERP_OPERATIONS.md](PRODUCTION_PLATFORM_ERP_OPERATIONS.md). This short entry point intentionally contains no credentials.

## Production topology

- Domain: `erp.passiontechnologiesplc.com`
- Application: `/home/passiontech/office_app`
- Separate public document root: `/home/passiontech/public_html/erp.passiontechnologiesplc.com`
- PHP: 8.1 / `ea-php81`
- Database: `passiontech_officeapp`

Do not assume the domain points to `office_app/public`. Preserve the live public bridge, `.well-known`, host PHP files, service worker and assets. Attendance requires `Permissions-Policy: geolocation=(self)`.

## Build

Run exactly one command from the repository root on a trusted workstation with Docker Desktop Linux containers running:

```powershell
powershell -ExecutionPolicy Bypass -File tools/build-cpanel-package.ps1
```

It automatically builds locked production Composer dependencies and produces:

```text
dist/officeapp-cpanel.tar.gz
dist/deployment-manifest.txt
```

The TAR.GZ is the authoritative cPanel artifact. There is no manual Composer installation, vendor copy, ZIP extraction, TAR conversion, rename or repackaging step.

## Deployment sequence

```text
build
→ verify checksum and manifest
→ back up database, application, public bridge and persistent runtime
→ upload TAR.GZ
→ extract to a versioned staging directory
→ restore protected config and preserve persistent runtime
→ validate staging
→ controlled rename cutover with previous release retained
→ run migrations
→ synchronize reference data
→ smoke test and reconcile
```

Never upload or overwrite these production-specific files from a release:

```text
config/database.php
config/app.local.php
```

Preserve `storage/uploads`, `storage/private`, `storage/logs` and any controlled backups. Cache is regenerable but its permissions should not be changed without reason.

## Upgrade commands

Use these only for an existing database upgrade:

```text
/usr/local/bin/ea-php81 /home/passiontech/office_app/bin/migrate.php
/usr/local/bin/ea-php81 /home/passiontech/office_app/bin/sync-reference-data.php
```

Never run `bin/install-database.php` for an upgrade. When Terminal is unavailable, schedule each command separately as a private one-time cPanel Cron Job, send output to protected `storage/logs`, verify the result, and delete the job immediately. Never expose a migration web endpoint.

See the authoritative operations document for GUI-only staging, bridge asset handling, backups, worker cron, smoke tests and rollback.
