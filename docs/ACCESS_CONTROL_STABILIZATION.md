# Access-control stabilization (093–096)

Company module availability is the outer boundary. Active company-role grants supply defaults; a company-user Allow or Deny changes only that user's capabilities. A Deny wins over role grants. The effective module gate is required for its child capabilities. Tenant, record hierarchy, workflow state and maker/checker checks remain separate.

Users → View → Edit → **Edit this user's function access** opens a separate override form. Saving it writes only `company_user_permission_overrides` and audit entries, never any role-assignment or role-permission table. The form shows role default, override, effective capability, and landing-page access. Role management continues to own defaults.

## Verification scope and remaining legacy work

Access Control upgrade complete for converted workspaces; remaining legacy broad-route splits are documented.

`FUNCTION_PERMISSION_AUDIT_094.md` inventories 351 registered routes: 260 explicit-function classifications, 75 broad legacy classifications, 8 authenticated/internal and 8 public/authentication. These are static classifications, not proof of exhaustive workflow security. No claim of system-wide privilege hardening is made.

Two legacy Sales controls remain shared: Selling Terms and Pricelists use `sales.pricing.view`; Variants and Teams use `sales.catalogue.manage`. The user editor labels all affected pages rather than implying independent switches. Several landing grants still require the existing broad read grant; the editor reports the actual saved landing result. Further permission splits require a separately reviewed additive migration; stabilization did not add one.

Settlement reads, payment selection, evidence/PDF endpoints and Sales transitions now use the existing reporting hierarchy. Finance settlement review retains its separately authorized company scope. Sales Allow does not enable Finance. Procurement policy task queries and Finance notification recipients now resolve current effective permissions, including user overrides.

## Tests

`tests/effective-permission-policy.php` is a pure policy test.

The HTTP/integration regression scripts target a deliberately isolated local snapshot database, `office_app_test`, served by `officeapp-access-test`. They are fixture-specific, not an empty-database CI seed. Do not run against development or production data. Test accounts are synthetic local verification accounts: administrator 121, manager A 122, DSA 123, and `access.manager.b.local` sharing A's test role. The snapshot includes Quick Sale 11, report 1, Settlement 1, and district authority 115. HTTP tests use the disposable fixture password in their source and the internal test-container web port 8080. Manager B must be active before running the HTTP suite.

Run inside the isolated app container:

```
php tests/effective-permission-policy.php
php tests/user-function-overrides.php
php tests/access-control-security.php
php tests/access-control-stabilization.php
php tests/workspace-access-http.php
php tests/workspace-access-http.php individual
php tests/sales-hierarchy-scope.php
php tests/audit-route-permissions.php
```

Do not run DB-mutating regression scripts concurrently. They restore role/default, module and override changes in `finally` blocks. Audit entries deliberately remain. The landing matrix writes JSON into the system temporary directory. The route audit refreshes its intentional documentation file.

The older hierarchy suite had two stale assertions expecting recursive legacy manager links without a stock-authority assignment. The production hierarchy code was unchanged; the assertions now verify the existing direct-report fallback and deny implicit recursive authority. Separate populated-authority checks verify district access and isolation.

Migrations 093–096 were not edited during stabilization. The migration runner normalizes CRLF/LF before hashing; 095 and 096 have different raw Windows file hashes but matching canonical recorded hashes. No schema migration was added or applied during this pass.
