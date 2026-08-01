# Errors

Errors are JSON and include a stable code, safe message, and correlation ID.

```json
{"error":{"code":"insufficient_scope","message":"The token does not grant the required scope."},"correlation_id":"..."}
```

Common statuses: 400 invalid JSON/idempotency key, 401 invalid client/token,
403 scope/permission/licence/IP denial, 404 tenant-scoped resource absent, 409
idempotency conflict, 413 payload too large, 422 domain validation, 429 rate
limit, and 500 unexpected incident. Database details and secrets are never
returned. Supply `X-Correlation-ID` when contacting support.
