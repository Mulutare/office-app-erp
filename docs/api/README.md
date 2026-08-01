# OfficeApp ERP third-party API v1

Base path: `/office_app/public/api/v1` for the standard subdirectory deployment.

This API is a separate tenant-bound integration boundary. It does not expose
database access or reuse browser controllers. Every request requires an active
client, company, Sales licence, scope, and corresponding permission on the
linked service user.

## Initial audit

Before this release the repository contained no external API routes, OAuth
client authentication, service accounts, bearer tokens, API scopes, API rate
limiter, API request audit log, webhook subscriptions, API documentation, or
API security tests. Internal transactional outbox processing and Sales to
Finance/Inventory handlers existed and are reused as the durable event source.

See `authentication.md`, `sales-api.md`, `idempotency.md`, `errors.md`,
`webhooks.md`, `security.md`, `operations.md`, `third-party-onboarding.md`, and
`openapi.yaml`.

API client and webhook management is intentionally command-line controlled in
v1; no public management endpoint can create credentials.
