# OfficeApp ERP MySQL-to-Oracle Compatibility Matrix

## Status

- MySQL/MariaDB is the active, tested reference implementation.
- Oracle is not currently supported.
- Oracle compatibility must not be claimed until integration tests pass
  against a real Oracle Database environment.

## Compatibility matrix

| File | Query or migration | MySQL behavior | Oracle equivalent | Portability risk | Recommended abstraction | Test required |
|---|---|---|---|---|---|---|
| `app/helpers/database.php` | MySQL PDO DSN and attributes | Connects with a `mysql:` DSN and native prepares | OCI8 or PDO_OCI service-name connection with explicit charset | Critical | `ConnectionManager`, validated `DatabaseDriver`, engine factories | Connection, Unicode, invalid credentials and error handling |
| `app/database/MySqlDriver.php` | `SELECT DATABASE()` and `SELECT 1` | Returns schema metadata and checks connection health | Adapter metadata or `SYS_CONTEXT('USERENV','CURRENT_SCHEMA')` | High | `DatabaseDriver` metadata and health methods | Health response for both drivers |
| `app/repositories/MySql/DashboardStatisticsRepository.php` | `CURDATE()` and `+ INTERVAL 1 DAY` through `MySqlDialect` | Uses server-local date boundaries | `TRUNC(CURRENT_DATE)` and Oracle date arithmetic | High | Dialect date expressions or bound UTC ranges | Midnight and time-zone boundary tests |
| `app/repositories/MySql/CompanyRepository.php` | `NOW()`, pagination and `lastInsertId()` | Server timestamps, `LIMIT/OFFSET` and generated IDs | `SYSTIMESTAMP`, `OFFSET/FETCH`, identity/sequence with returning | Critical | Company repository plus dialect pagination/generated-ID APIs | Create, approve, search and pagination |
| `app/repositories/MySql/CompanyMembershipRepository.php` | `NOW()`, `LIMIT 1`, `ON DUPLICATE KEY UPDATE` | Expiry checks, row limiting and MySQL upsert | Oracle timestamp, `FETCH FIRST`, `MERGE` | Critical | Membership repository with explicit upsert | Concurrent assignment, default membership and expiry |
| `app/repositories/MySql/CompanyModuleRepository.php` | `NOW()`, `LIMIT 1` | Current entitlement filtering | Oracle timestamp and row limiting | High | Module-entitlement repository | Active, trial, expired and disabled modules |
| `app/repositories/MySql/UserRepository.php` | `NOW()`, pagination, allowlisted ordering and `lastInsertId()` | Authentication, paging, updates and IDs | Oracle expressions, `OFFSET/FETCH`, identity/sequence return | Critical | User repository contract and dialect APIs | Authentication, lockout, create, paging and sort |
| `app/repositories/MySql/RoleRepository.php` | `LIMIT`, `FOR UPDATE`, `ON DUPLICATE KEY UPDATE` | Row limiting, locking and permission upsert | `FETCH FIRST`, Oracle `FOR UPDATE`, `MERGE` | Critical | Role repository with lock and replace-permission methods | Concurrent role updates and rollback |
| `app/repositories/MySql/UserActivityRepository.php` | `CONCAT`, `CAST(... AS CHAR)`, MySQL collations | Builds a unified textual timeline | `||`, `TO_CHAR`, Oracle collation strategy | Critical | Activity-query repository per engine | Unicode, identifiers, search and ordering |
| `app/repositories/MySql/DepartmentRepository.php` | `LIMIT 1`, `lastInsertId()` | Single-row lookup and generated ID | `FETCH FIRST`, identity/sequence return | High | Department repository | Duplicate, create, lookup and IDs |
| `app/repositories/MySql/EmployeeRepository.php` | Pagination, fixed `LIMIT 250`, `lastInsertId()` | Employee search and generated IDs | `OFFSET/FETCH`, identity/sequence return | Critical | Employee repository and pagination object | Large filters, create and tenant scoping |
| `app/repositories/MySql/ExpenseRequestRepository.php` | `LIMIT/OFFSET` | Paged finance requests | `OFFSET/FETCH` | High | Expense request repository | Stable ordering, filters and tenant scoping |
| `app/repositories/MySql/AuditLogRepository.php` | `LIMIT`, JSON encoded into long text | Recent logs and text snapshots | `FETCH FIRST`, CLOB or supported Oracle JSON type | Critical | Audit repository and long-text binding abstraction | 100k rows, CLOB, Unicode and redaction |
| `app/repositories/MySql/AuditLogQueryRepository.php` | Pagination and dynamic conditions | Paged audit search | `OFFSET/FETCH` | High | Audit query repository and portable filters | Search, stable paging and execution plan |
| `app/repositories/MySql/LoginAttemptRepository.php` | `LIMIT` | Recent authentication attempts | `FETCH FIRST` | Medium | Login-attempt repository | Lockout history and high volume |
| `app/repositories/MySql/EmployeeActivityRepository.php` | `LIMIT/OFFSET` | Paged activity timeline | `OFFSET/FETCH` | Medium | Employee activity repository | Stable chronological pagination |
| `database/migrations/001_create_authentication_core.sql` | `AUTO_INCREMENT`, `UNSIGNED`, `BOOLEAN`, collation, timestamp updates and `FOREIGN_KEY_CHECKS` | Creates the MySQL authentication schema | Identity/sequence, `NUMBER`, boolean mapping, Oracle collation and explicit updates | Critical | Separate Oracle migration | Clean schema, constraints, indexes and Unicode |
| `database/migrations/002_create_hr_core.sql` | MySQL DDL and HR relationships | Creates the HR schema | Oracle numeric, identity, timestamp and constraint syntax | Critical | Separate Oracle HR migration | Manager hierarchy and tenant keys |
| `database/migrations/003_create_finance_core.sql` | MySQL DDL, decimal and date columns | Creates the finance schema | Oracle `NUMBER(p,s)`, date policy and identity | Critical | Separate Oracle finance migration | Precision, dates, FKs and uniqueness |
| `database/migrations/004_create_modular_product_core.sql` | MySQL DDL, booleans and timestamps | Creates company/module catalog | Oracle identity, numeric boolean and timestamps | Critical | Separate Oracle modular migration | Catalog, entitlements and indexes |
| `database/migrations/005_add_company_provisioning.sql` | `ALTER TABLE ... ADD ... AFTER` | Controls MySQL column position | Oracle `ADD` without `AFTER` | High | Engine-specific migration | Upgrade with data preserved |
| `database/migrations/006_create_company_membership_core.sql` | MySQL DDL and upserts | Creates memberships and idempotent assignments | Oracle DDL and `MERGE` | Critical | Separate migration and seed implementation | Repeat seeds, uniqueness and concurrency |
| `database/migrations/007_add_tenant_data_isolation.sql` | `FOREIGN_KEY_CHECKS`, `LIMIT 1`, MySQL index changes | Backfills company IDs before constraints | Staged Oracle updates and constraints | Critical | Engine-specific forward migration with preflight | Existing-data upgrade, nulls and orphans |
| `database/migrations/008_add_vendor_company_ownership.sql` | MySQL column placement, booleans and collations | Adds platform ownership | Oracle-specific columns and indexes | Critical | Engine-specific migration | Existing users, owners and approval |
| `database/migrations/009_repair_user_foreign_keys.sql` | MariaDB foreign-key metadata repair | Repairs stale references after a table rebuild | Not applicable as written | Critical | MySQL/MariaDB-only repair | Affected MariaDB reproduction and Oracle exclusion |
| `database/seeds/004_assign_module_management_permission.sql` | `ON DUPLICATE KEY UPDATE` | Idempotent permission seed | Oracle `MERGE` | High | Engine-specific seed runner | Repeated execution |
| `database/seeds/005_assign_company_management_permission.sql` | `ON DUPLICATE KEY UPDATE` | Idempotent permission seed | Oracle `MERGE` | High | Engine-specific seed runner | Repeated execution |

## Cross-cutting Oracle risks

### Empty strings

Oracle treats empty strings as `NULL`. Username, email, codes, optional text,
uniqueness and validation behavior require explicit tests.

### Case sensitivity and collation

MySQL searches inherit `utf8mb4_unicode_ci`. Oracle search is not automatically
equivalent and needs an explicit normalization and indexing strategy.

### Generated identifiers

`PDO::lastInsertId()` is not a portable contract. Oracle repositories must use
identity columns or sequences with safe returning semantics. `SELECT MAX(id)`
is prohibited.

### Bind behavior

Oracle bind semantics, LOB handling and numeric/string conversions differ from
PDO MySQL. Repository contracts cannot assume identical statement behavior.

### DDL and migrations

Both engines have important DDL auto-commit behavior, but syntax and recovery
differ. Each engine needs separate migrations, preflight checks and recovery
instructions.

### Audit data

Audit snapshots are JSON-encoded application data stored in MySQL long text.
Oracle needs deliberate CLOB or supported JSON-column binding and size tests.

### Tenant isolation

Every Oracle repository must contain the same company predicate as the MySQL
reference. Contract tests must attempt cross-company IDs for every operation.

## Oracle support gate

Oracle remains unverified until these pass against a real Oracle database:

- clean migrations and seeds;
- authentication and password changes;
- commit and rollback;
- generated IDs;
- Unicode and empty strings;
- CLOB audit snapshots;
- pagination and stable ordering;
- tenant isolation;
- unique-constraint handling;
- concurrent membership and role writes;
- audit and login-attempt logging.
