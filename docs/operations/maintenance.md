# Maintenance manual

## Every release

1. Review `git status` and the exact release diff.
2. Run PHP syntax checks and focused module tests.
3. Run the isolated integration suite.
4. Confirm migration and seed repeatability.
5. Verify tenant isolation and permission changes.
6. Back up and test restoration.
7. Deploy, migrate, synchronize, dispatch and smoke-test.
8. Record release version, operator, migration versions and test result.

## Daily

- Check application and database health.
- Check failed integration events and retry counts.
- Check backup completion and available storage.
- Review authentication lockouts and high-priority audit events.

## Weekly

- Review overdue receivables and unfulfilled Inventory commitments.
- Review users, roles and inactive accounts.
- Review error logs for recurring signatures.
- Confirm scheduled dispatch and notification jobs are running.

## Monthly

- Restore a backup into an isolated environment.
- Apply current dependency and base-image security updates in staging.
- Review database growth, slow queries and indexes.
- Reconcile Sales totals with Finance projections and Inventory commitments.
- Update these manuals for operational changes.

## Module development contract

- Controllers parse HTTP only.
- Services own validation and business rules.
- Repositories own SQL and transactions.
- Modules integrate using versioned business events and idempotent handlers.
- Every tenant-owned read and write includes `company_id`.
- Every irreversible business action creates an audit event.
- New phases include migration, permissions, tests, manual updates and a
  rollback/recovery note.
