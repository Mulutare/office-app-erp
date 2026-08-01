# API operations

After code deployment run `php bin/migrate.php` and
`php bin/sync-reference-data.php`. Set a high-entropy
`API_WEBHOOK_ENCRYPTION_KEY` identically on every worker before creating
subscriptions. Do not change this key without a planned re-encryption process.

Run every minute, in this order:

```text
php bin/dispatch-integration-events.php
php bin/dispatch-api-webhooks.php
```

Monitor `/health`, API 5xx/429 counts, token/client revocations,
`api_webhook_deliveries` failed/dead-letter counts, integration outbox backlog,
and database growth. Retain API request logs per company policy and purge only
expired idempotency rows after the 24-hour replay window.

Rollback: stop API/webhook schedulers, route traffic away from `/api/v1`, and
revert application code. Migration 031 is additive and should remain in place;
dropping API tables or `external_reference` risks audit/data loss and requires a
separately approved backup-tested data migration.
