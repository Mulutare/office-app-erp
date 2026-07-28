# OfficeApp ERP Security, Container and Migration Gap Audit

## Purpose

This document records gaps that must be resolved while preserving the current
working MariaDB application. It does not authorize code, schema or production
changes.

## Security gap register

| ID | Severity | Gap | Evidence | Required remediation | Target checkpoint |
|---|---:|---|---|---|---|
| SEC-01 | Critical | Debug exception output can disclose passwords and request data | Full throwable string is rendered in debug mode | Environment-specific error pages and sensitive-argument redaction | 2 |
| SEC-02 | High | Database exceptions are logged verbatim | Database helper logs the raw exception message | Structured redacting logger with correlation IDs | 2–3 |
| SEC-03 | High | Production session cookie policy is absent | `secure` is hard-coded false; strict mode is disabled | Environment-aware Secure, HttpOnly, SameSite and strict-mode settings | 2 |
| SEC-04 | High | No automated cross-tenant access regression suite | Tenant checks have manual validation only | Repository and HTTP tests using attacker-company IDs | 2–4 |
| SEC-05 | High | No automated privilege-escalation suite | RBAC is server-side but unautomated | Denied-route and direct-object tests for each role | 2–4 |
| SEC-06 | Medium | Security headers are absent | No centralized security-header layer | CSP, frame, content-type, referrer and permissions policies | 2 |
| SEC-07 | Medium | Login rate limiting cannot coordinate replicas | Database lockout exists; no request limiter | Rate-limit interface and optional Redis implementation | 8 |
| SEC-08 | Medium | Client-IP trust policy is undefined | Direct use of `REMOTE_ADDR` | Explicit trusted-proxy configuration | 2 |
| SEC-09 | Medium | MFA is not implemented | Password-only authentication | Preserve an MFA-ready authentication boundary | 8 |
| SEC-10 | Medium | Session storage is single-node | Native PHP session handler | Optional Redis profile for replicated production | 8 |
| SEC-11 | Medium | No secret-rotation procedure | Local credentials are ignored but undocumented | Runtime injection and rotation runbook | 2 and 8 |
| SEC-12 | Low | Diagnostic endpoints expose implementation metadata | Public health and user-model diagnostic routes | Separate liveness/readiness from privileged diagnostics | 2 |

## Existing controls to preserve

- password hashing and verification;
- forced temporary-password changes;
- account lockout and administrator unlock;
- cryptographic CSRF tokens;
- CSRF enforcement on identified POST actions;
- server-side RBAC;
- platform-administrator isolation;
- tenant-scoped memberships, roles and permissions;
- module entitlement checks;
- prepared statement values;
- internal allowlists for sort columns and directions;
- escaped output;
- authentication and privileged-action auditing;
- generic authentication failure messages.

## Container readiness

| Area | Current state | Required state |
|---|---|---|
| Images | None | PHP 8.4 development, test and production targets |
| Web server | XAMPP Apache | Documented Apache or PHP-FPM container |
| Database | Host MariaDB | Persistent MySQL/MariaDB service with health check |
| Oracle | None | Optional profile or override only |
| Configuration | PHP arrays | Validated environment configuration and safe defaults |
| Secrets | Ignored database config | Runtime secrets and safe `.env.example` |
| Runtime user | Host account | Fixed non-root production user |
| Storage | Host directories | Explicit writable mounts or volumes |
| Readiness | Manual | Readiness waits for a usable database connection |
| Debugging | Hard-coded development | Separate development and production settings |
| OPcache | Absent | Development and production OPcache policies |
| Logs | XAMPP PHP log | Redacted stdout/stderr or documented destination |
| Backups | Undefined | Database and upload backup/restore workflow |
| Sessions | Native local sessions | Native for one replica; optional Redis for scaling |

## Planned Checkpoint 2 container files

These are planned, not created during Checkpoint 1:

```text
Dockerfile
compose.yaml
compose.dev.yaml
compose.test.yaml
compose.production.yaml
docker/
  apache/
  php/
    development.ini
    production.ini
  entrypoint/
.env.example
```

The Oracle-specific override belongs to Checkpoint 5:

```text
compose.oracle.yaml
docker/oracle/
```

## Migration gaps

### Current state

- Migrations are raw numbered SQL files.
- There is no migration runner or `schema_migrations` table.
- Applied versions cannot be verified automatically.
- Reverse migrations and rollback documentation do not exist.
- Backup prerequisites are not enforced.
- Some migrations disable foreign-key checks.
- Some migrations combine backfills with constraint creation.
- Seeds are not recorded separately.

### Required controls

1. Validate driver names before selecting a migration directory.
2. Maintain separate MySQL and Oracle migrations where syntax differs.
3. Record version, checksum, driver, timestamp and duration.
4. Refuse an already-applied version unless intentionally repeatable.
5. Detect checksum drift.
6. Provide status and preflight commands.
7. Never run destructive production migrations automatically at startup.
8. Require a verified backup before destructive migrations.
9. Validate nulls, orphans and duplicates before constraints.
10. Validate indexes and foreign keys after execution.
11. Store rollback instructions even when restore is the only safe rollback.
12. Keep seeds separate and environment-aware.

## Tenant-isolation review

### Current positive design

- Company context is stored in the authenticated session.
- Active membership is checked before workspace selection.
- Membership and company-role tables use composite company/user keys.
- HR and finance records have company identifiers.
- Administration commonly uses company-scoped lookup methods.
- Company role permissions are separated per company.
- Platform-only company and licensing operations have explicit checks.

### Residual risks

- Tenant safety relies on repeated query discipline rather than repository
  contracts.
- Direct PDO use lets a future service omit company predicates.
- There are no automated cross-company read/write probes.
- Complex activity and audit queries need explicit company contract tests.
- Oracle repositories could diverge from MySQL predicates.
- Platform-user and default-company behavior require dedicated tests.

### Required regression cases

- Company A cannot view or change Company B users.
- Company A cannot access Company B HR records.
- Company A cannot access Company B finance records.
- Company A cannot assign Company B roles or permissions.
- Company A cannot enable unlicensed modules.
- Pending or inactive companies cannot authenticate.
- A platform administrator cannot accidentally inherit customer data access.
- Direct foreign IDs return 403 or 404 without revealing existence.
- Workspace switching rejects memberships not owned by the user.

## Operational gaps

- no backup/restore runbook;
- no release or rollback process;
- no image/version policy;
- no database retention policy;
- no audit-log archival policy;
- no upload malware-scanning strategy;
- no queue/worker architecture;
- no measured performance baseline;
- no alerting or health-monitoring integration.

## Checkpoint 1 acceptance

Checkpoint 1 passes when:

1. all four audit documents exist;
2. the PHP and MariaDB versions are recorded;
3. the compatibility matrix covers every discovered dialect category;
4. security, tenancy, container and migration gaps are documented;
5. no application, configuration, database or container file changed;
6. Git diff contains documentation only;
7. no commit or push is performed.
