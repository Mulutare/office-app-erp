# Webhooks

Subscriptions are company- and client-bound and select from the supported
Sales events. Production endpoints must be HTTPS. Secrets are shown only at
creation/rotation and encrypted at rest with `API_WEBHOOK_ENCRYPTION_KEY`.

Headers:

- `X-OfficeApp-Delivery`: stable delivery UUID
- `X-OfficeApp-Timestamp`: Unix seconds
- `X-OfficeApp-Signature`: `v1=` plus HMAC-SHA256 of `timestamp.payload`

Receivers must reject timestamps more than 300 seconds from local time, verify
the HMAC using constant-time comparison, and deduplicate delivery IDs. Return a
2xx response only after durable acceptance. Delivery retries use exponential
backoff, stop after ten attempts, and enter dead-letter state for controlled
manual replay. Rotation affects new attempts and invalidates the old secret.

Events: order submitted/approved/confirmed/cancelled, payment recorded, and
credit hold created/released. Credit-hold events become active when the domain
publishes those transitions.
