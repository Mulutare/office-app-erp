# OfficeApp ERP PHP 8.4 Upgrade Risk Register

## Scope

This register covers upgrading OfficeApp ERP from PHP 8.0.30 on Windows XAMPP
to PHP 8.4 while preserving the current MariaDB implementation and local
fallback workflow.

## Compatibility observations

The application already uses PHP 8 language features:

- scalar and typed properties;
- `mixed`;
- `match`;
- `str_contains()` and `str_starts_with()`;
- constructor property typing patterns;
- the `never` return type annotation for redirects.

No uses of the following removed or legacy APIs were detected:

- `each()`;
- `create_function()`;
- magic-quotes functions;
- `FILTER_SANITIZE_STRING`;
- legacy curly-brace string offsets;
- `utf8_encode()` or `utf8_decode()`.

All 109 PHP files pass syntax validation with the existing PHP 8.0.30 runtime.

## Risk register

| ID | Risk | Likelihood | Impact | Evidence | Mitigation | Required validation |
|---|---|---:|---:|---|---|---|
| PHP-01 | PHP 8.4 warnings or deprecations expose runtime defects not covered by syntax checks | Medium | High | No automated tests | Run the application with `E_ALL`, collect deprecations, then fix without suppressing relevant errors | Full request and service test suite on PHP 8.4 |
| PHP-02 | Debug stack traces disclose passwords or personal information | High | Critical | Development exception handler renders the full throwable string | Separate development and production rendering; redact sensitive arguments and database values | Submit invalid login and database failures; confirm no secret appears |
| PHP-03 | Missing extensions break the container runtime | Medium | High | Current extensions are supplied by XAMPP; no image manifest exists | Declare only required extensions in the Dockerfile and assert them in health/test scripts | `php -m`, PDO connection, mbstring and OpenSSL checks |
| PHP-04 | OPcache settings make code changes stale in development or reduce safety in production | Medium | Medium | OPcache is currently absent | Use separate development and production INI files | Development reload test and production OPcache status test |
| PHP-05 | Linux case-sensitive paths break custom autoloading or view resolution | Medium | High | Current environment is Windows | Build and exercise all routes inside Linux containers | Autoload scan and browser route smoke test |
| PHP-06 | Hard-coded `/office_app/public` paths fail under a container document root | High | High | Router, redirects and session cookie path contain the subdirectory | Introduce a validated base-path configuration with current value as fallback | Test subdirectory and root deployments |
| PHP-07 | Storage cannot be written by a non-root production process | High | High | Current folders inherit Windows/XAMPP permissions | Create a dedicated runtime user and explicitly own only runtime directories | Upload/log/cache write tests as non-root |
| PHP-08 | Session behavior changes across PHP and container SAPIs | Medium | Critical | Active INI has strict mode disabled; app sets cookies programmatically | Centralize environment-aware session configuration | Login, logout, fixation, expiry and cookie-attribute tests |
| PHP-09 | `never` return behavior exposes an unexpected return path | Low | Medium | `redirect()` terminates with `exit` | Retain termination and add a focused redirect test | Static analysis plus redirect controller tests |
| PHP-10 | Time zone differences alter timestamps and daily dashboard totals | Medium | High | Application uses `Africa/Addis_Ababa`; database time zone is implicit | Define application and database UTC/storage policy | Boundary tests around midnight |
| PHP-11 | Manual autoloading and lack of Composer prevent reproducible platform constraints | High | Medium | No `composer.json` | Introduce a minimal Composer platform declaration when the autoload transition is planned | `composer validate` and clean-install test |
| PHP-12 | PHP-FPM or Linux Apache behavior differs from XAMPP Apache | Medium | High | No container web-server configuration | Choose and document one container web stack and front-controller routing | Assets, 404, redirect and request-body tests |
| PHP-13 | Error logs retain raw SQL or sensitive database values | High | High | Database helper logs raw exception messages | Add a redacting structured logger and correlation IDs | Forced constraint and connectivity failures |
| PHP-14 | Production image includes development tools or runs as root | Medium | High | No image exists | Use separate targets; production runs as a fixed non-root UID | Image inspection and runtime UID assertion |
| PHP-15 | XAMPP fallback is removed before container parity | Medium | High | Local workflow is currently XAMPP-only | Keep XAMPP database and configuration untouched until Checkpoint 2 passes | Side-by-side comparison |

## Required PHP 8.4 extensions

Default MySQL image:

```text
ctype
filter
json
mbstring
openssl
PDO
pdo_mysql
session
opcache
```

Extensions should only be added when a verified application path requires
them. Redis support belongs in an optional scaling target or profile. OCI8 or
PDO_OCI belongs only in the Oracle-specific target or profile.

## Upgrade acceptance gates

Checkpoint 2 cannot pass until all of the following are true:

1. PHP reports a supported 8.4 release inside development and test containers.
2. Every PHP file passes syntax validation inside the PHP 8.4 image.
3. MariaDB migrations succeed on an empty disposable test database.
4. Authentication, forced password change and lockout pass.
5. CSRF rejection passes for every state-changing route category.
6. Platform and company RBAC tests pass.
7. Cross-tenant identifiers return 403 or 404 without data leakage.
8. A browser smoke test covers login, dashboard, administration, HR and
   finance routes.
9. Health checks become healthy only after the application can reach the
   database.
10. The production image runs as non-root with debugging disabled.
11. No password, DSN credential or personal record appears in browser errors or
    logs.
12. The existing XAMPP workflow remains recoverable until explicit retirement.

## Upgrade rollback

- Record the pre-upgrade Git commit and create a database backup.
- Build PHP 8.4 as new container assets without replacing XAMPP.
- Use versioned image tags.
- If acceptance fails, stop the containers and return to the untouched XAMPP
  application and database.
- Do not apply destructive schema changes as part of the PHP runtime upgrade.
