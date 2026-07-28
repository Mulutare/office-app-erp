# OfficeApp ERP Checkpoint 1 Current-State Audit

## Document control

- Audit date: 2026-07-27
- Repository: `C:\xampp\htdocs\office_app`
- Branch: `main`
- Baseline commit: `427a2b9 Repair user foreign key constraints`
- Audit scope: repository, PHP runtime, database, SQL, migrations, security,
  tenancy, containers, tests, configuration and operations
- Change policy: this checkpoint changes documentation only

## Executive summary

OfficeApp ERP is a functional, custom PHP MVC application with authentication,
RBAC, audit logging, modular company subscriptions and tenant-scoped HR,
finance and administration features. The current MySQL/MariaDB implementation
is the only proven database implementation and must remain the reference
implementation during modernization.

The application is not yet containerized and has no automated test suite,
dependency manifest, migration ledger or operational documentation. Database
access is coupled to a global MySQL PDO connection. SQL is concentrated mainly
in model classes, but transaction handling and several raw queries also exist
in services and one controller.

The safest modernization sequence is:

1. preserve the existing XAMPP environment as a fallback;
2. establish PHP 8.4 MySQL containers and automated regression coverage;
3. introduce database and repository interfaces without changing behavior;
4. move MySQL-specific SQL behind MySQL repositories and a MySQL dialect;
5. add an optional Oracle boundary and validate it only against a real Oracle
   test environment.

Oracle compatibility has not been proven and must not be claimed.

## Repository baseline

| Item | Observed state |
|---|---|
| Git branch | `main`, synchronized with `origin/main` |
| Working tree | Clean before Checkpoint 1 documentation |
| Tracked files | 133 |
| PHP files | 109 |
| SQL files | 15 |
| Composer | No `composer.json` or lock file |
| Automated tests | No test directory or test runner |
| Containers | No Dockerfile or Compose files |
| Documentation | No existing `docs/` directory |
| Environment template | No `.env.example` |
| Runtime directories | `storage/logs`, `storage/cache`, `storage/uploads` |

## Runtime baseline

| Component | Version or state |
|---|---|
| PHP | 8.0.30, XAMPP Windows ZTS build |
| MariaDB | 10.4.32 |
| PDO | Enabled |
| PDO MySQL | Enabled |
| mbstring | Enabled |
| OpenSSL | Enabled |
| Session | Enabled |
| OPcache | Not enabled |
| Redis extension | Not installed |
| OCI8 | Not installed |
| PDO_OCI | Not installed |

The `php` command is not globally available; current validation uses
`C:\xampp\php\php.exe`.

## Application architecture

The current structure is a small custom MVC implementation:

```text
app/
  controllers/
  helpers/
  models/
  services/
config/
database/
  migrations/
  seeds/
public/
resources/
  views/
routes/
storage/
```

Key characteristics:

- `app/helpers/autoload.php` provides custom autoloading.
- `app/helpers/router.php` provides the application router.
- Controllers instantiate concrete services directly.
- Services instantiate concrete model classes directly.
- Models contain most prepared SQL statements.
- `app/helpers/database.php` exposes one shared global `db(): PDO` connection.
- The DSN is fixed to `mysql:`.
- Services call global PDO transaction methods directly.
- `HomeController` directly executes `SELECT DATABASE()`.
- There is no dependency-injection container or composition root.
- There are no repository contracts or database dialect classes.

## Implemented business capabilities

The repository currently includes:

- authentication and logout;
- forced password change;
- account lockout, reset, unlock and activation management;
- platform administrator and company-owner separation;
- company approval and ownership;
- company memberships and workspace switching;
- tenant-scoped roles and effective permissions;
- configurable module licensing and navigation;
- users, roles, permissions and audit administration;
- HR employee and department management;
- finance dashboard and expense request reading;
- login and privileged-action audit activity.

## Database baseline

The active database is `office_app_dev` on MariaDB 10.4.32.

- Tables: 17
- Foreign keys: 42
- Index entries reported by `information_schema.STATISTICS`: 162
- `schema_migrations` table: absent
- Table integrity checks: passed
- Orphaned company-user memberships: 0
- Orphaned company-role assignments: 0

Current tables:

```text
audit_logs
companies
company_modules
company_role_permissions
company_user_roles
company_users
erp_modules
finance_expense_categories
finance_expense_requests
hr_departments
hr_employees
login_attempts
permissions
role_permissions
roles
user_roles
users
```

## Migration baseline

Migrations are manually executed SQL files numbered `001` through `009`.

Risks:

- applied migrations are not recorded;
- duplicate execution is not centrally prevented;
- several migrations are deliberately non-repeatable;
- rollback scripts do not exist;
- backup prerequisites are not enforced;
- MariaDB/MySQL DDL auto-commit behavior prevents reliable transactional
  rollback;
- migrations use engine-specific `FOREIGN_KEY_CHECKS`, collations, unsigned
  types and generated-ID syntax;
- migration 009 demonstrates that MariaDB table rebuilds can invalidate older
  inbound foreign-key metadata.

Seeds are partially idempotent but are not tracked in a seed ledger.

## Security baseline

### Existing protections

- strict types are enabled throughout PHP source files;
- credentials are ignored through `config/database.php`;
- values are normally bound with prepared statements;
- dynamic sort columns and directions use internal allowlists;
- passwords use `password_hash()` and `password_verify()`;
- temporary passwords are generated with cryptographic randomness;
- CSRF tokens use `random_bytes()` and `hash_equals()`;
- all identified state-changing controllers perform CSRF verification;
- session IDs are regenerated after authentication and security changes;
- server-side RBAC protects administration and module operations;
- platform-only company/module operations have explicit checks;
- tenant-bound record retrieval includes company identifiers;
- output helpers escape rendered values;
- login and privileged actions are audited.

### Existing gaps

- `config/app.php` hard-codes development mode and debug output;
- debug exception output can expose submitted credentials through stack
  arguments;
- raw database exception messages are written to the PHP error log;
- development session cookies are not `Secure`;
- `session.use_strict_mode` is disabled in the active PHP configuration;
- application-level security headers are absent;
- no trusted-proxy policy exists for request IP handling;
- no rate limiter exists for authentication;
- no MFA implementation exists;
- no Redis/session-replication option exists;
- no automated privilege-escalation or cross-tenant regression tests exist.

## Container and deployment baseline

No container assets currently exist.

The current workflow assumes:

- Windows XAMPP;
- Apache serving the application under `/office_app/public`;
- a local root MariaDB account with no password;
- writable local storage directories;
- hard-coded application and database configuration files.

Containerization must account for:

- Linux case-sensitive filesystems;
- configurable application base paths;
- Apache or PHP-FPM document-root routing;
- non-root runtime ownership of `storage/`;
- persistent database and upload volumes;
- database readiness rather than start order alone;
- separate development and production error settings;
- secrets injected at runtime rather than copied into images.

## Test baseline

No automated test harness exists. The current baseline validation produced:

| Validation | Result |
|---|---|
| PHP syntax, 109 files | Passed |
| MariaDB table checks | Passed |
| Company-user orphan check | Passed |
| Company-role orphan check | Passed |
| Git worktree before documentation | Clean |

Authentication, CSRF, RBAC and tenant behavior have been manually exercised
during previous milestones, but these checks are not repeatable automation.

## Checkpoint conclusion

The current application should not be rewritten for Oracle or upgraded through
an uncontrolled dependency batch. PHP 8.4 container parity with MariaDB and an
automated security regression baseline are prerequisites for database
abstraction. The MySQL implementation remains authoritative until a real Oracle
integration environment proves equivalent behavior.
