# Deployment finalize runtime hotfix (2026-10-03)

## Diagnosis and scope

Base: `a011e6efd89272d3cf1cfbc3fe93e0b724b603d4`. Branch: `fix/deployment-finalize-timeout-2026-10-03`.

The reported release-111 attempt stopped at `finalize-release` while production was at 110, before backups, migration, reference sync or cutover. The code left finalize under the host's 30-second PHP limit despite hashing, Phar archive inspection/extraction and recursive live-storage copying. That is a confirmed runtime-protection gap and a plausible cause of the incident, **not proof of the particular termination cause**. No production logs or endpoint were accessed for this investigation. Memory exhaustion and external LiteSpeed/proxy termination remain possible explanations for empty/non-JSON HTTP 500.

The existing shutdown handler ran only after authentication and action validation. It had no memory reserve, did not suppress displayed diagnostics, and supplied release context only for migration requests. PHP may still fail before registration (including startup/parse errors), run out of memory while encoding a response, or have its output replaced/discarded by the webserver. External process kills, LiteSpeed request/connection deadlines, and proxy deadlines cannot be caught or disabled by PHP's `set_time_limit(0)`.

## Code changes

- Add `finalize-release`, `database-backup`, `application-backup`, and `staged-migration-audit` to the existing long-action guard. Verify `ini_get('max_execution_time') === '0'` after attempting `set_time_limit(0)`; otherwise retain structured `deployment_runtime_timeout_limit` HTTP 503 before DB bootstrap/action work. Existing authenticated write nonce consumption remains unchanged.
- Keep a 256 KiB response reserve and release it on fatal shutdown, suppress displayed PHP diagnostics, and attach only a strictly validated release ID to failures. Keep generic fatal messages, classified error codes, and the existing one-response guard.
- Replace raw caught-exception response text with a fixed safe message. This avoids exposing PDO credentials, Phar paths/archive-controlled diagnostics or configuration contents. Detailed errors remain in the existing private server log; no public stack trace is added.
- Keep protocol version 3 and build ID `officeapp-deployment-v3-lite-runtime-20261002`. The source hash is the compatibility-preserving identity change.

| Action | Execution-time assessment |
| --- | --- |
| finalize-release | Archive hash/inspection/extraction plus recursive storage copy; newly protected |
| database-backup | Streams database dump and performs gzip compression/hash; newly protected |
| application-backup | Recursive copy of the full live tree including storage/backups; newly protected |
| staged-migration-audit | Catalog hashing, first preflight and complete view reads; newly protected |
| preflight, migrate-next, sync-reference-data, release-health | Retain their existing unlimited-window guard |
| cutover, rollback-application | Bounded directory renames and metadata updates, no recursive copying; unchanged |
| begin-release, upload-chunk, cleanup | Small metadata/bounded chunk/lock operations; unchanged |

Authentication, TLS, replay checks, release identity, archive SHA/size and root/path validation, protected-config exclusions, server-only config copy order, staging isolation, backup/migration gates and rollback operations are unchanged. No change to the deployment client or its state machine is needed.

## Failed staging and retry policy

`cleanup` removes only `/home/passiontech/.officeapp-deployment.lock` when its contents match the validated release ID. It does **not** remove uploads, extracted files, release metadata, application backups or database backups. The client's finally block attempts that cleanup and removes its local lock; absence of the local lock alone does not prove remote cleanup succeeded.

The failed staging path `/home/passiontech/deployment-staging/a011e6e-20261003091905` may therefore remain, possibly including partially copied protected config/storage. Actual remote presence and process/lock state were not inspected. Treat it as sensitive private forensic material, not a deployable release. Keep it outside public_html with existing restrictive directory permissions. No automatic deletion was added or performed.

A later attempt generates a fresh commit-prefix/UTC-timestamp release ID. `begin-release` rejects any existing directory (including same-second timestamp collisions), so stale bytes cannot be silently reused. It also continues to reject an existing deployment lock. Do not retry the failed finalize request against its partially extracted directory or bypass lock/identity gates. Before an authorized retry, the operator should establish that no earlier worker is still running and use a newly sealed release/new staging ID. Retained forensic data can consume disk space; any eventual deletion requires a separate controlled review of the exact stale path, never live data or backups.

## Release identity and operational consequence

Old runner SHA: `de6313b349f5fbd6c5637cdf46ab955b774e74b225d143e6c594235b642a6312`.

New normalized-LF runner SHA: `f36b106d69b69e2e8804b2346f26a86b3eb434c1fa95a42d068ddec723e27060`.

**Release target remains 111 and the expected production prefix remains 110. Release 111 must be rebuilt and resealed after the hotfix enters the approved release workflow.** The previous a011e6e sealed artifact/approval, package SHA `8187d0b17ba6a818af4347692bb59b8740818e52fee2f03b2c3e18cb2ba41554`, is obsolete for this corrected deployment and must not be reused. Preserve it only as incident evidence. Existing seal checks reject changed HEAD, runner bytes and package hashes. Runner installation and executed-SHA proof must match the new seal during a future authorized deployment. No seal, runner installation or production deployment was performed here.

## Verification

The new `tests/deployment-runner-runtime.php` executes extracted real runner blocks in subprocesses with only temporary filesystem constants. It does not load production authentication/config or connect to a DB. Coverage includes per-action limits, disabled/unretained extensions, real PHP fatal/timeout/memory failures, response redaction, actual Phar finalize success and rejected size/SHA/root/config payloads, server-side config/storage copy, migration gates, cleanup retention and existing-release rejection. It is included in the existing release regression driver.

PHP 8.1.34 syntax checks passed for all three changed PHP files. `git diff --check` passed. Comparison against the base confirms migration 110, migration 111, all Careers code, Recruitment services and ERP routes are unchanged. Production was not contacted or modified; main was not merged.

Results: `tools/test-careers-release.ps1` **372 passed / 0 failed**, including deployment migration audit **44/0**, new runner runtime regressions **31/0**, and release contract **10/0**. `tools/test-production-deployment.ps1` **30 passed / 0 failed**. Combined assertions: **402 passed / 0 failed**. PHP syntax: **3 passed / 0 failed**. Local raw output is retained in `dist/deployment-finalize-regression-tests.txt` and `dist/deployment-finalize-powershell-tests.txt` (not shipped or committed).

Changed files: `deployment/production-runner.php`, `tests/deployment-migration-audit.php`, `tests/deployment-runner-runtime.php`, `tools/test-careers-release.ps1`, and this document. The new test driver entry changes only which regressions run, not Careers business behavior.
