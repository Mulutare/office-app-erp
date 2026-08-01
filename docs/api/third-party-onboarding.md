# Third-party onboarding

1. Create a dedicated active user in the target company and assign the minimum
   Sales role/permissions.
2. Confirm the company and Sales licence are active.
3. Run `php bin/manage-api-client.php create COMPANY_ID USER_ID NAME ADMIN_ID scopes ip-addresses`.
4. Transfer the one-time secret through an approved secret channel.
5. Partner obtains a token and performs read-only smoke tests before writes.
6. Test duplicate idempotency keys, failure recovery, and tenant-negative IDs.
7. If using webhooks, set the encryption key and create a subscription with
   `php bin/manage-api-webhook.php create COMPANY_ID API_CLIENT_DB_ID URL events ADMIN_ID`.
8. Partner verifies timestamp, signature, and delivery deduplication.
9. Record owner, purpose, expiry review, scopes, IPs, and rotation date.

Rotate with `manage-api-client.php rotate CLIENT_ID`; this revokes tokens. Revoke
with `manage-api-client.php revoke CLIENT_ID`. Webhook secrets use
`manage-api-webhook.php rotate SUBSCRIPTION_ID`.
