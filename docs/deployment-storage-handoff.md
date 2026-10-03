# Deployment runtime-storage handoff (2026-10-03)

Branch: `fix/deployment-storage-handoff-2026-10-03`. Base: `a48a1573d4b195b37372184c02808e588f855fec`.

## Root cause and scope

The supplied inspection shows verified/extracted application files and protected config, but only part of live storage copied and metadata still `uploading`. This locates the second bottleneck in finalize's recursive runtime-storage duplication. The prior PHP timeout guard cannot prevent an external LiteSpeed termination. Application backup also recursively copied that same growing runtime tree and would encounter the same scaling problem.

This change removes runtime-file copies from finalization and application backup. It preserves the existing `office_app/storage` layout, introduces no public link or storage-path changes, and does not modify business code. The database dump/compression/verification action remains unchanged. Protocol 3 and the existing build ID remain compatible; executed source SHA is still mandatory.

## Finalize and backup contracts

Finalization still verifies package size/SHA, archive root/path/link constraints, protected-config exclusions, Composer and application presence. Packaged storage must contain exactly the four empty directories `cache`, `logs`, `private`, and `uploads`. Any packaged runtime file or unexpected directory is rejected. Phar may omit empty TAR directories during extraction: the runner recreates only these four directories after proving their presence/type in the verified archive. Staged storage remains empty; protected config is copied server-side only after package verification. No live storage entry is enumerated or copied by finalization. Only then is state set to `staged`.

Application backup still requires `database-backed-up`. It copies every live application root entry except `storage`, including app, bin, config (both protected server files), database/migrations, deployment, docs, public, resources, routes, vendor and root runtime files. Copies are hash-verified, file permissions are retained, and app/vendor/config presence is checked. Links/special files in the copied code are rejected. The backup contains **no storage directory or runtime evidence**; metadata and response explicitly record `code-config-without-runtime-storage`. Partial failed backups are not marked verified and are retained privately for inspection.

This is an independent code/config recovery artifact, not a runtime-data backup. Runtime storage remains the original directory, preserved by handoff, and still needs the site's independent disaster-recovery backup policy. The verified DB dump under `storage/backups` survives with that directory. Application rollback uses the atomic previous-code root, never the copied code/config backup.

## Handoff state machine

Pre-cutover while live is untouched:

1. Require state `ready`; acquire an exclusive nonblocking handoff lock and reread metadata.
2. Require canonical, non-linked directories under fixed deployment roots. Validate the staged and live application, absent collision destinations, matching filesystem devices, and live storage's writable root and required backups/cache/logs/private/uploads directories.
3. Scan runtime **metadata only**, rejecting nested symlinks, special files and mount/device changes. No runtime file content is read, hashed or copied. This scan scales with entry count, not stored bytes, and completes before touching live. The existing verified unlimited PHP guard also covers these cutover/rollback audits; external timeouts during prechecks leave live untouched.
4. Validate and remove only the four empty staged runtime directories using `rmdir`, never recursive deletion.
5. Atomically save `cutover-pending`, recording fixed source/target/park paths and storage device/inode identity before the first live move.

Cutover uses three same-filesystem renames:

1. `office_app` -> `office_app_failed_<UTC>_<release-id>` (previous code).
2. Previous code's `storage` -> staged application's `storage`.
3. Staged application -> `office_app`.

Post-check the storage directory's identity, writability and required directories without rescanning its contents; atomically commit state `cutover` with `cutover_previous`. Result: new live code plus the original runtime directory; previous code remains without a duplicate storage tree.

Rollback requires `cutover` and a previous-root name matching this exact release ID. It validates the same filesystem/storage constraints and an absent failed-health destination, saves `rollback-pending`, then renames:

1. Current live -> `office_app_failed_health_<UTC>_<release-id>`.
2. Failed-health storage -> previous code's empty storage location.
3. Previous code -> live.

Verify the same storage identity and save `rolled-back` plus `rollback_failed`. Files written after cutover travel back with the original storage. Failed new code remains isolated without runtime duplication. Database migrations are not reversed.

## Compensation and interruption

For either direction, a caught error reverses only completed moves in reverse order. A failed storage move restores the parked live root. A failed activation first returns storage to the parked root, then restores it to live. A failed post-activation metadata write reverses all three moves. Successful compensation restores the original metadata and, for cutover, the empty staged skeleton.

If compensation itself fails, no runtime data is deleted; pending intent stays on disk. Atomic metadata replacement prevents a partial JSON write from destroying the prior state. Cleanup acquires the same handoff lock and refuses to remove the deployment lock for `cutover-pending`/`rollback-pending` or an active handoff. Pending states cannot be automatically retried, migrated or cut over.

Each rename is atomic; the three-operation sequence is **not** a single filesystem transaction. A process kill, machine failure or external termination between moves cannot execute PHP compensation. The persisted intent and single preserved storage directory support controlled operator recovery. Do not bypass pending-state/lock gates or start another release in that condition. Inspect the exact private journal paths and locate storage before restoring; never recursively delete or copy an uncertain runtime tree. Use a controlled cutover window with application/background writers quiesced to avoid requests observing the brief path transition. This runner does not add an application-wide maintenance mechanism.

## Staging, security and release consequences

Neither failed staging ID may be reused:

- `a011e6e-20261003091905`
- `a48a157-20261003151957`

Existing-directory rejection remains. Ordinary cleanup still retains private staging evidence; unresolved handoffs additionally retain the deployment lock. No production staging, locks, backups or data were inspected or deleted in this task.

HMAC, replay checks, TLS requirements, package SHA/size verification, protected config exclusions, release identity, migration/backup gates and safe JSON errors remain. All storage paths remain private. Runtime rename preserves ownership/modes. No storage data or config contents are returned or logged by the new helpers.

New normalized runner SHA256:

`3e671fccbf80a17e76c46b136834c533a68134adcc52812ad2e27255dbb14d39`

The a48a157 sealed artifact/approval is now **obsolete for deployment**. After review and an authorized merge to main, release **111 must be rebuilt and resealed**, the corrected runner installed as `.officeapp-deployment-v3.php`, and live `runner-status` SHA proven equal to the new seal before another Execute attempt. Neither rebuilding/sealing nor installation/deployment was performed here. Target remains 111; expected production prefix remains 110 based on the supplied evidence, without contacting production.

## Verification and changed files

Changed files: `deployment/production-runner.php`, `tests/deployment-runner-runtime.php`, `tests/deployment-migration-audit.php`, and this report. Existing full regression driver already runs the expanded runner tests. Build/package behavior is unchanged; the runner now enforces its empty-storage contract.

Temporary-filesystem tests exercise actual runner blocks and real directory renames. They cover a sparse 128 MiB live evidence file absent from finalized staging; archive failures; independent code/config backup; exact runtime hashes, inode and permissions; DB backup preservation; post-cutover evidence retention; failures at every cutover/rollback rename and final metadata update; failed compensation; pending locks; root/nested/target links; collisions including dangling links; invalid/cross-release paths; concurrent handoff/cleanup exclusion; fatal/timeout/memory JSON behavior; and original migration gates.

Migration 110 and migration 111, all Recruitment/Careers functionality, application storage resolution, ERP permissions and Power BI code are unchanged against the base. Production was not contacted. No merge or deployment was performed.

Final results:

- `tools/test-careers-release.ps1`: **415 passed, 0 failed**, including **74 runner runtime**, **44 deployment migration-audit**, and **10 release-contract** assertions.
- `tools/test-production-deployment.ps1`: **30 passed, 0 failed**.
- Combined assertions: **445 passed, 0 failed**.
- PHP 8.1 syntax checks: **3 passed, 0 failed** (`production-runner.php`, `deployment-runner-runtime.php`, `deployment-migration-audit.php`).
- `git diff --check`: passed.
- Base comparison: no app/Careers/database/routes/config/resources changes; database-backup action body identical to a48a157.
- Raw local evidence (not committed/shipped): `dist/deployment-storage-regression-tests.txt`, `dist/deployment-storage-powershell-tests.txt`.
