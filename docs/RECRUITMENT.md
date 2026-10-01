# Recruitment operator guide

Recruitment is an additive HR workspace at `/hr/recruitment`. The database is the register; Excel is a filtered export. Employee records are not created when an application becomes Hired. Use the existing employee workflow after deliberate HR validation.

## Installation and migration

The pre-recruitment commit already contains `110_powerbi_mysql84_readiness_compatibility.php`. This module adds `database/migrations/mysql/111_recruitment.php`; the Power BI migration remains unchanged. An earlier recruitment draft also used 110 and was corrected before deployment. No recruitment-110 ledger entry was written in production.

On a **reviewed local/staging environment**, configure its database environment and run:

```sh
php bin/migrate.php
```

The existing migration runner applies the full outstanding sequence. Migration 111 requires the current ERP prerequisites, including company users, role permissions and company role permissions. It refuses to silently baseline existing recruitment tables without its ledger entry. DDL can auto-commit: back up the database and private files together before any later approved deployment. Do not use the fresh installer on an existing database.

No production migration or deployment was performed. Full-catalog local rehearsal uses disposable MySQL 8.4.11 with ONLY_FULL_GROUP_BY and synthetic ERP fixtures. Fresh installation lacks five BI source tables, and migration 107 requires fixed shop-manager identifiers; local testing supplies explicitly synthetic prerequisites. This is not a restored production-backup rehearsal. Exact global/session production SQL modes still require saved production metadata. Resolve baseline prerequisites and rehearse the reviewed backup before deploying; do not bypass production migration checks.

Recruitment 111 applies after Power BI 110, preserves all existing ERP rows and view definitions/results, and is skipped on repeat runs. Deployment view health retains the exact Power BI prefix and accepts 111 only when its normalized source checksum matches its ledger. The original-backup rehearsal now expects the complete 100–111 suffix. Its production-profile/backup execution remains unverified locally.

MySQL/MariaDB is supported by this release. The ERP's Oracle adapter is a skeleton; recruitment fails closed under Oracle.

## Runtime and private storage

Use the existing PHP 8.1+ runtime and installed PhpSpreadsheet dependency. Recruitment additionally uses `fileinfo`, `iconv`, `zip`, OpenSSL, and the PHP IMAP extension for live mailbox access. The local PHP test image has fileinfo/iconv/zip but no IMAP extension, so tests use the replaceable provider interface and RFC822 fixtures.

Configure server environment variables outside source control:

```text
RECRUITMENT_STORAGE=/srv/officeapp-private/recruitment
RECRUITMENT_APP_ORIGIN=https://erp.example.com
RECRUITMENT_IMAP_ALLOWED_HOSTS=imap.example.com
RECRUITMENT_MAX_ATTACHMENT_BYTES=10485760
RECRUITMENT_SYNC_BATCH=100
POWER_BI_ENCRYPTION_KEY=<existing securely provisioned ERP encryption key>
```

The encryption key must have at least 32 characters. Recruitment reuses `PowerBiSecretCipher` and its authenticated AES-GCM format. Preserve the existing key; changing it without re-encrypting stored credentials prevents decryption. Never paste credentials into tickets, logs or committed files. The mailbox password is entered through an authenticated administration POST with CSRF protection, encrypted in the database and never returned to the screen. Blank password updates preserve the ciphertext.

`RECRUITMENT_APP_ORIGIN` is the trusted HTTP(S) origin without a path; the ERP's configured `APP_BASE_PATH` is appended to export download URLs. Use HTTPS outside local testing. Do not derive this value from an untrusted Host header.

Storage defaults to `storage/private/recruitment`, outside `public/`. Set durable private storage for deployments that replace the application checkout. Grant the PHP/CLI account write access only to that directory. Configure restrictive directory/file permissions and Windows ACLs if applicable. Upload limits in PHP and the reverse proxy must allow the desired upload size; the application independently validates the actual bytes. Allow outbound IMAP only to the operator-configured host allowlist.

Files use generated identifiers, checksums, and company-specific directories. Imported executable, malformed-type and oversized parts are preserved in quarantine and explicitly classified. Manual invalid/oversized uploads are rejected. No files are labelled clean by this release. No malware scanner was found in the repository, so regular viewers cannot download unscanned documents; a separate quarantine permission plus explicit acknowledgement is required. A trusted scanning integration remains an operator/security prerequisite for ordinary CV downloads. Raw failed messages require both mailbox and quarantine permissions.

The MIME reader conservatively handles multipart messages, encoded bodies, common filename parameters and text/HTML conversion. Unsupported nested message attachments or malformed MIME remain in the failed queue with the original RFC822 bytes preserved. HR can download the quarantined original, create/review a manual application, and link messages to an existing application. No AI parser or ranking is required.

## Permissions and HR workflow

All routes require an authenticated tenant, an enabled/licensed HR module and its effective module gate. Migration 111 grants defaults to company owners and HR administrators using existing global/company role templates. Configure individual functions through the existing access-control UI:

| Permission | Capability |
|---|---|
| `hr.recruitment.view` | Dashboard, applications, vacancies, safe email text and protected document lookup |
| `hr.recruitment.edit` | Vacancy/application changes, reviewer assignment, upload and deliberate email linking |
| `hr.recruitment.export` | Excel export; viewing permission is also required |
| `hr.recruitment.hire` | Transition into or out of Hired; editing permission, matched vacancy and verified identity are required |
| `hr.recruitment.mailboxes` | Configure, test, preview and sync mailboxes; does not imply applicant viewing |
| `hr.recruitment.merge` | Confirmed applicant merges |
| `hr.recruitment.delete` | Archive applications while preserving documents/history |
| `hr.recruitment.quarantine` | Explicitly acknowledged quarantine downloads |

1. Create vacancies with unique company references. Unmatched applications are valid.
2. Create a manual application or configure/preview an email import.
3. Review identity and contact fields, select a vacancy if justified, and assign an active company HR reviewer.
4. Move through New, Under Review, Shortlisted, Interview, Offered, Hired, Rejected or Withdrawn. Status history records the actor and previous/new states.
5. Inspect duplicate suggestions based on email, phone or document checksums. Type MERGE and supply a reason to consolidate confirmed applicant identities. Names alone never merge records. Applications remain separate after an applicant merge.
6. Use filters and Export filtered Excel. One row represents one application, with all 15 requested columns, headers, frozen headings, filters, company-timezone dates and authenticated ERP URLs. All applicant-supplied cells are explicitly written as text by the existing spreadsheet codec, preventing formula execution.

The initial sender identity is only a candidate. Forwarded/agency messages leave email identity unassigned for HR review. Imported candidates stay in the review queue. Matching is company-scoped. Local-part case, dots and plus suffixes are preserved; domain case is normalized. A new message in the same mailbox epoch remains visible as a separate application, which HR can deliberately link to an existing application.

## Mailbox configuration and live connection

No actual provider was identified from configuration/documentation, and no live mailbox was accessed. The connector uses standard IMAP and can be replaced via `MailProvider` for a future provider API.

Required operator information: actual provider, IMAP hostname, username/password or supported app password, port 993 (implicit TLS) or 143 (mandatory STARTTLS), recruitment folder, initial import date and preferred interval. Provider-specific OAuth-only access requires a future API/OAuth connector; it is not represented as a connected mailbox.

1. Install/enable the PHP IMAP extension in the approved hosting runtime and provision the environment above.
2. Open HR → Recruitment mailboxes and create a paused mailbox record.
3. Test its connection. The connector verifies TLS certificates and requests a read-only mailbox. Body fetches use PEEK. It never deletes, moves, expunges, marks read or sends messages. [PHP's IMAP documentation](https://www.php.net/manual/en/function.imap-open.php) defines the read-only and certificate flags used.
4. Preview the selected folder/date range. The preview shows the message count and creates a single-use confirmation token expiring after 15 minutes, scoped to the UIDVALIDITY and maximum UID observed.
5. Confirm historical import to enable bounded batches and ongoing sync. Records are not enabled merely by saving configuration. Changes to the initial date invalidate the preview and pause sync. After imports exist, changing the account/folder requires a new mailbox record so historical provenance stays stable.
6. Inspect import runs and the failed/pending queue. Fix configuration/storage errors and use Sync / retry now. Sanitized messages and counters avoid credentials and applicant content.

Each identity incorporates mailbox record, folder, UIDVALIDITY and UID. Message-ID is stored but is not the deduplication key. UIDVALIDITY changes restart inventory evaluation. Exact-byte messages from a previous epoch can reuse their existing application while retaining the new provider identity. A new same-epoch message remains a distinct application. A MySQL connection-scoped advisory lock prevents overlapping workers and concurrent configuration/preview writes. Fetch/connect transient failures have three attempts with bounded backoff. Incomplete raw messages and attachment failures are retried from durable records, including after interruption. Original mail is committed before extraction; required records/files commit before processing becomes complete. Failed filesystem/DB transactions may leave retained orphan files; do not delete these automatically.

Large mailboxes are searched within the selected date range on each run; completed identities are skipped. Batch size bounds new-message work, and at most 100 preserved failed messages are retried per run. Very large RFC822 messages are read into PHP memory to preserve complete content; provision suitable PHP memory and disk capacity. If capacity is exhausted, the import remains failed and the source mailbox is untouched. No claim of arbitrary-size streaming support is made.

## Scheduling

The existing production task runner now recognizes `recruitment` and records sanitized counts in its existing operations run register. Use the approved server environment/secret loader used for other ERP scheduled jobs:

```sh
php /path/to/office-app-erp/bin/run-production-task.php recruitment
```

Example cron entry (default five-minute schedule):

```cron
*/5 * * * * cd /path/to/office-app-erp && /path/to/php bin/run-production-task.php recruitment
```

Per-mailbox intervals default to five minutes and are checked against persisted last-sync time. Scheduled imports also require the company's effective HR entitlement; inactive, suspended, expired or HR-disabled companies are skipped before provider access. If shorter intervals are needed, invoke cron each minute; the worker still respects each configured interval. Do not log environment values or credentials. The standalone `php bin/sync-recruitment.php` uses the same scheduler and worker when the existing production runner is unavailable. No scheduled job was installed during implementation.

## Retention, audit and rollback

Mailbox retention days are configurable advisory settings. Blank disables the retention policy; automatic physical deletion is deliberately not implemented or enabled. HR can archive applications through the permission-protected action. Application views, downloads, status updates, exports, links, merges, mailbox changes and archives emit company-scoped recruitment events and identifiers into the existing ERP audit stream without full CV/email bodies.

For rollback after an approved deployment: pause all recruitment mailboxes, stop the recruitment scheduled task, revert the recruitment code/navigation and leave migration 111's tables, ledger entry, ciphertext and private documents intact. Do not drop the tables, wipe storage or remove encryption keys. A later restored code release can resume from durable identities without deleting source emails. Back up the database and private storage together, verify checksums during restore, and reconcile retained orphan files manually. There is no destructive down-migration.

## Local verification

Use a disposable synthetic `office_app_test` database with `APP_ENV=testing`, never a production dump. The added tests fail closed unless both guards match:

```sh
php tests/recruitment-migration.php
php tests/recruitment-integration.php
php tests/recruitment-endpoints.php
php tests/recruitment-security.php
php tests/recruitment-retry-integrity.php
php tests/recruitment-release-contract.php
```

The fixture migration test isolates migration 111 plus existing authorization migrations 093/096 through the normal runner and verifies ledger rerun behavior. Fixture setup helper `tests/recruitment-fixture-setup.php` loads existing synthetic seeds/accounts only into that test database; it is not a deployment command. The integration test resets only synthetic recruitment records for the default fixture company and retains physical files. Fixtures cover normal CVs, missing CV/vacancy, two vacancies, repeated messages, forwarding, raw/attachment interruptions, UIDVALIDITY, overlapping locks, invalid/oversized files, tenant boundaries, status/merge/archive audit, export filters and spreadsheet injection. Endpoint tests call the actual controllers and register real routes using synthetic users and render the HR screens.

Detailed results and any remaining baseline failures are provided with the implementation report. Live TLS/authentication and provider behavior require the actual mailbox setup above; local fixtures do not establish a live connection.
