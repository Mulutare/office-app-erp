# Application operations

## Development start

```powershell
docker compose -f compose.yaml -f compose.dev.yaml up --build -d
docker compose -f compose.yaml -f compose.dev.yaml ps
```

Application: `http://localhost:8080/office_app/public/login`

Health endpoint: `http://localhost:8080/office_app/public/health`

## Routine commands

```powershell
docker compose exec -T app php bin/migrate.php
docker compose exec -T app php bin/sync-reference-data.php
docker compose exec -T app php bin/dispatch-integration-events.php
docker compose exec -T app php bin/queue-attendance-notifications.php
```

Run integration dispatch every minute in production. Only one invocation is
normally required, but duplicate invocations are safe because module handlers
use unique business keys.

## Platform password recovery

Platform administrators can open a customer company, review its users and
select **Reset password**. The system generates a one-time password, clears
temporary locks, and requires a password change at the next login. The password
is displayed once and is never written to audit logs. Transfer it through an
approved secure channel.

Company administrators may continue to reset ordinary users from their own
tenant workspace. The vendor recovery screen is company-scoped and cannot
reset platform-administrator credentials.

## Stop and restart

```powershell
docker compose stop
docker compose restart app
```

Do not use `down -v` on a production deployment. The `-v` option removes named
database volumes.

## Deployment order

1. Confirm a current database backup and recovery test.
2. Put the application into the approved maintenance window.
3. Deploy the reviewed code and locked dependencies.
4. Run migrations once.
5. Synchronize reference data once.
6. Start or reload the application.
7. Dispatch pending integration events.
8. Check health, login, Sales, Finance and Inventory projections.
