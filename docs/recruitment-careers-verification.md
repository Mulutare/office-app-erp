# Careers 111 verification — 3 October 2026

Branch: `feat/recruitment-careers-portal-2026-10-03`

Base: `8abb0a6ffc3a464ceb8b93348f929fd88ce891e3`

Implementation and isolated verification are complete. Production deployment,
website installation and acceptance against the real hosting environments have
not been performed. No merge, production deployment or production data write
was performed.

## Executed results

| Suite | Passed | Failed |
|---|---:|---:|
| Recruitment module lifecycle / licensing / HR dependency | 15 | 0 |
| Careers clean 110 → 111 migration and schema | 19 | 0 |
| Existing Recruitment mailbox/application integration | 44 | 0 |
| Recruitment endpoints, authorization and screens | 43 | 0 |
| Existing Recruitment security | 18 | 0 |
| Existing mailbox retry integrity | 5 | 0 |
| Careers criteria, screening, publication, import, documents, tenant isolation | 82 | 0 |
| Public HTTP, multipart uploads, CSRF, confirmation and retries | 13 | 0 |
| Real cURL TLS push/pull/acknowledgement and closure propagation | 8 | 0 |
| Careers source security contract | 11 | 0 |
| Recruitment/Careers release contract | 10 | 0 |
| Deployment migration audit and Power BI invariants | 44 | 0 |
| Migration checksum compatibility | 18 | 0 |
| PowerShell deployment tooling | 30 | 0 |
| **Total** | **360** | **0** |

Additionally, the existing migration-110 rerun test passed. PHP 8.1 syntax checks
passed for all 32 added/modified PHP files. A SHA-256 manifest verified that every
checked PHP file in Docker exactly matched final workspace bytes. `git diff
--check` passed. The isolated environment used the existing
`compose.recruitment.test.yaml` PHP 8.1/MySQL stack and a separate `careers_test`
website database. Fixtures are synthetic; production credentials were not used.

Reproduce with `tools/test-careers-release.ps1` and
`tools/test-production-deployment.ps1`. The first script explicitly recreates only
the named disposable tmpfs Recruitment stack. The module-lifecycle suite runs
before endpoint fixtures license Recruitment; the earlier order-dependent failure
was fixed by ordering, without weakening the lifecycle assertion.

Raw evidence is in `artifacts/careers-test-run.txt`,
`artifacts/careers-deployment-tests.txt`, `artifacts/careers-lint.txt`,
`artifacts/careers-php-files.txt` and `artifacts/careers-tested-sha256.txt`.

## Security and release review

- `routes/web.php` is unchanged; applicants have no public ERP route.
- Migration 110 is unchanged. Both its working-file and base Git blob IDs are
  `f559f03b5cf189a1143aee9e45aaccd6d661447e`.
- Migration 111 preserves a real synthetic pre-111 unmatched email application
  and quarantined attachment byte-for-byte, and preserves Recruitment's 110
  module-origin metadata. Partial-object preflight fails safely.
- HMAC success, bad signature, expiry and replay are exercised. Actual cURL
  rejects an untrusted TLS certificate; the same transport succeeds with a
  trusted certificate and rejects the wrong integration key. HTTPS verification
  remains enabled. Only the **test TLS server** disables client-certificate
  requirements; production request clients always verify server certificates.
- Exact website and ERP retries are idempotent. Database uniqueness independently
  rejects duplicate source identities. An injected attachment database failure
  rolls back import; successful documents stay quarantined.
- Structured minimum failure leaves workflow status `New`; no screening code
  writes `Rejected`, generates a score, or ranks candidates. Historical question
  snapshots survive later edits. Malformed and missing answers require review.
- Tenant lookups, filters, criteria, publication, imports, documents and composite
  answer foreign keys were tested. Public host mismatch, unsigned integration,
  CSRF failure, direct document URL and rate-limit excess fail closed.
- Reviewed changed files and searched runtime source for embedded credential/key
  patterns; no live credentials were found. Runtime credentials are environment
  values; literal credentials in tests are explicitly synthetic fixture values.
- Release tooling advances **110 → 111**. Tests retain historical Recruitment
  origin at 110 and Power BI target-109 metadata invariants. Four stale deployment
  audit assertions were updated: current baseline view-health call signature,
  reference-sync target, release-health target, and selected-target ledger range.
  Power BI changes are limited to recognizing 111 as an allowed ledger target;
  HISTORY_ONLY and null live-cutover invariants remain intact.

## Visual and manual acceptance

The actual public PHP form was inspected in the in-app browser at desktop width
and a 390 × 844 mobile viewport. Personal fields, screening question, document
uploads and consent sections fit without horizontal overflow. Multipart submit,
confirmation and duplicate retry were separately exercised over HTTP using the
test-only TLS-termination adapter. The temporary preview container and browser
tab were removed after inspection.

Remaining real-environment acceptance is detailed in
`docs/recruitment-careers.md`: install the separate public package; provision its
database/storage and dedicated key; verify actual TLS/proxy routes and cron;
then perform the HR publish → applicant submit → ERP review flow and final
security/manual checklist. The standalone package is built by
`tools/package-careers.ps1`; it contains no ERP application or credential files.

## Changed files

- `app/controllers/RecruitmentController.php`
- `app/services/Recruitment/CareersContract.php`
- `app/services/Recruitment/CareersIntegrationClient.php`
- `app/services/Recruitment/CareersPublicationService.php`
- `app/services/Recruitment/CareersRequestSigner.php`
- `app/services/Recruitment/CareersSyncService.php`
- `app/services/Recruitment/ExternalSubmissionImporter.php`
- `app/services/Recruitment/RecruitmentService.php`
- `app/services/Recruitment/Repository.php`
- `app/services/Recruitment/ScreeningService.php`
- `artifacts/careers-deployment-tests.txt`
- `artifacts/careers-lint.txt`
- `artifacts/careers-php-files.txt`
- `artifacts/careers-test-run.txt`
- `artifacts/careers-tested-sha256.txt`
- `bin/sync-careers.php`
- `careers/bin/maintenance.php`
- `careers/public/.htaccess`
- `careers/public/careers.css`
- `careers/public/index.php`
- `careers/schema.sql`
- `careers/src/Portal.php`
- `careers/src/bootstrap.php`
- `database/migrations/mysql/111_recruitment_careers.php`
- `deployment/powerbi-upgrade-validation.php`
- `deployment/production-runner.php`
- `docs/recruitment-careers-verification.md`
- `docs/recruitment-careers.md`
- `resources/views/hr/recruitment/careers-screening.php`
- `resources/views/hr/recruitment/careers-vacancy.php`
- `resources/views/hr/recruitment/index.php`
- `tests/careers-http-router.php`
- `tests/careers-http.php`
- `tests/careers-integration.php`
- `tests/careers-migration.php`
- `tests/careers-preview.compose.yaml`
- `tests/careers-source-security.php`
- `tests/careers-sync.php`
- `tests/careers-tls-server.php`
- `tests/deployment-migration-audit.php`
- `tests/recruitment-endpoints.php`
- `tests/recruitment-local-bootstrap.php`
- `tests/recruitment-release-contract.php`
- `tools/deploy-production.ps1`
- `tools/deployment-release-common.ps1`
- `tools/package-careers.ps1`
- `tools/test-careers-release.ps1`
- `tools/test-production-deployment.ps1`
