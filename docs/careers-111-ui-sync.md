# Careers 111 frontend sync verification

Scope: follow-up to `ab37caf6d64564372bcba17accbc3c140033f383` on `feat/recruitment-careers-portal-2026-10-03`.

## Files changed

- `careers/public/index.php`: text brand, navy hero, intentional empty state, company links, vacancy cards/details, numbered application sections, accessible inline errors, private-upload presentation, and opaque-reference confirmation. Empty results no longer show pagination.
- `careers/public/careers.css`: dependency-free responsive styling, restrained red accent, readable spacing, keyboard focus, native upload controls, and narrow-screen layouts.
- `tests/careers-http.php`: eleven additional frontend/security assertions (24 total HTTP assertions).
- `docs/careers-111-ui-sync.md`: this verification and operator reference.

## Verification

- `tools/test-careers-release.ps1`: **341 passed, 0 failed**, in isolated synthetic Docker databases. Breakdown: lifecycle 15, migration 19, recruitment integration 44, endpoints/screens 43, recruitment security 18, retry integrity 5, Careers integration 82, Careers HTTP 24, TLS sync 8, Careers source security 11, release contract 10, deployment audit 44, checksum 18.
- `php -l careers/public/index.php` and `php -l tests/careers-http.php`: **2 passed, 0 failed**.
- `git diff --check`: passed.
- Browser inspection: desktop empty state, mobile vacancy cards/details, and desktop/mobile form controls; 390px and 320px viewport checks showed no horizontal overflow. Corrected mobile hero sentence spacing after the full test run; syntax checked afterward.
- Real multipart submission, redirect/confirmation reference, retry idempotency and inline validation passed in HTTP tests. Browser preview used only disposable synthetic data.
- Request-processing prefix and security headers remain identical to the base. CSP still restricts styles and form actions to self; no scripts, CDN, external fonts, document URLs, public ERP URL, credentials, or private keys introduced.
- Secret-pattern inspection found no credential literals in the frontend. Existing environment-variable lookups remain unchanged.
- CSRF, rate limiting, HMAC, replay defenses, config loading, private/quarantined uploads, and safe error handling are unchanged and their existing tests passed.
- Minimum-requirement failure still does not automatically reject an application; human review remains required.
- `routes/web.php`, migration files, integration/backend services, config and storage handling are unchanged.

## Sync artifact

Local directory: `dist/careers-111-ui-sync/`

Archive: `dist/careers-111-ui-sync.tar.gz`

SHA256: `9BB6F1DA2D9C20F5A642BB40F7FC23AC56358247B172B06BD7B03F6227C24ABC`

The archive contains only `public/index.php`, `public/careers.css`, and `SYNC-INSTRUCTIONS.txt`. Generated artifacts remain local and are not committed.

Copy **both** frontend files:

| Local package file | Exact remote destination |
| --- | --- |
| `public/index.php` | `/home/passiontech/careers_app/public/index.php` |
| `public/careers.css` | `/home/passiontech/public_html/careers/careers.css` |

Extract into private staging, then copy files individually. Preserve `/home/passiontech/public_html/careers/index.php`, the existing public wrapper. Back up both destination files outside public_html first; restore both for rollback. Preserve hardened ownership/permissions. Hard-refresh the page after copying CSS.

**No migration, database or config change is required.** No production deployment/data changes were performed. ERP 111 deployment remains untouched. Main was not merged. The pre-existing root `careers-111-final.tar.gz` was left untouched.
