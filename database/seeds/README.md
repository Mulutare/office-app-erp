# OfficeApp authorization seeds

Run authorization seeds in numeric order after the authentication-core
migration:

1. `001_seed_roles_and_permissions.sql`
2. `002_assign_system_administrator_permissions.sql`
3. `003_assign_standard_role_permissions.sql`
4. Continue through the remaining numbered, additive module seeds.
5. Always run the final owner-access cleanup seed so populated installations
   receive the current administrative-only company owner baseline.

For a configured MySQL/MariaDB application, run the full repeatable catalog
with:

```text
php bin/sync-reference-data.php
```

The standard-role seed is additive and safe to rerun. It establishes the
least-privilege baseline while preserving any additional grants configured
for a particular organization.

Role and permission `code` values are the stable integration contract.
Future modules should:

1. add new permission records with a unique module-scoped code;
2. add explicit baseline mappings in a new numbered seed;
3. avoid relying on environment-specific numeric IDs;
4. keep management and approval permissions separate from view permissions;
5. validate that each active standard role resolves to its intended effective
   permissions.

## Standard baseline

| Role | Baseline permissions |
|---|---|
| `system_administrator` | Every active permission |
| `company_owner` | Company administration, audit, licensed-module management and team oversight; no employee self-service by default |
| `executive_viewer` | Dashboard and read-only access to IT, Finance, and Business records; HR administration requires a separate HR-authorized role |
| `hr_administrator` | Dashboard plus HR view and management |
| `it_administrator` | Dashboard plus IT view and management |
| `finance_officer` | Dashboard plus Finance view and management |
| `finance_approver` | Dashboard plus Finance view and approval |
| `business_development_officer` | Dashboard plus Business view and management |
| `auditor` | Dashboard, audit logs, and read-only access to IT, Finance, and Business records; HR administration requires a separate HR-authorized role |

Sales roles and their least-privilege permission matrix are documented in
`docs/sales-access-control.md` and provisioned by seed `021`.
