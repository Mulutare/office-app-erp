# Troubleshooting manual

## Application does not start

1. Check container/process status.
2. Read the application startup log.
3. If migration failed, identify the exact migration and statement.
4. Inspect the schema; do not repeatedly restart against a partial migration.
5. Restore the pre-deployment backup or follow the reviewed migration recovery
   procedure.

## Workforce calendar shows missing scan-window warnings

Calendar creation and weekly schedule saving must always provide
`scan_open_before_minutes` and `scan_close_after_minutes`. Current builds seed
working days with 120/240 minutes and non-working days with zero. If an older
deployment shows an undefined-array-key warning, confirm migration 025 is
recorded, deploy the current service/repository code, and rerun the isolated
regression suite. Do not suppress PHP warnings or edit calendar rows manually;
warnings can send output before a redirect and cause a secondary header error.

## Module unavailable

Confirm all three controls:

- `erp_modules.available = 1`
- company module entitlement is enabled and active
- the user has at least one permission in the module namespace

Navigation visibility is not authorization. The protected route performs the
same module and permission checks on the server.

## Sales order exists but Finance or Inventory is missing

1. Find its `sales.order.confirmed` event in `integration_outbox`.
2. If pending, run `php bin/dispatch-integration-events.php`.
3. If failed, read `last_error`, correct the root cause, and retry.
4. Confirm the Finance receivable and Inventory commitments share the same
   `company_id` and `order_id`.
5. Do not manually insert projection rows unless an approved recovery script
   has been reviewed and tested.

## Payment is in Sales but not Finance

Locate the `sales.payment.recorded` event. The Finance handler uses the Sales
payment ID as an idempotency key. Re-dispatching is safe and must not duplicate
the receipt.

## Sales order cannot be approved

Confirm the order is `submitted` and the user has `sales.orders.approve`.
Credit-limit validation happens during submission. Use the audit record to
identify later state changes; do not edit the status directly.

## Serial number cannot be registered

Confirm the product has serial tracking enabled, the user has
`sales.serials.manage`, and the value is not already registered for that
company. Use one serial per line and preserve manufacturer formatting.

## Commission is missing

Commission is created only after approval when the order has a DSA/DSP and its
products have a positive commission rate. Check the agent, approval timestamp
and catalogue rates. Never recreate paid or cancelled commissions manually.

## User cannot sign in after an administrator reset

Confirm that the user is entering the newly generated temporary password and
the correct username. The previous password stops working immediately. Clear
no database flags manually: a successful reset already clears failed-login
counters and temporary locks. After authentication, the user must complete the
forced password-change screen.

## Migration reports partial schema

Stop. Record existing tables and columns, capture the database backup status,
and compare the migration preflight with the actual schema. Never broaden a
drop command or remove unrelated tables. Production recovery requires an
approved backup/restore or a targeted forward repair.

## Escalation information

Include timestamp and timezone, environment, release commit, company code,
route or command, event ID/order number, sanitized error, migration ledger,
and the last relevant log lines. Never include passwords, tokens, cookies or
private push endpoints.
