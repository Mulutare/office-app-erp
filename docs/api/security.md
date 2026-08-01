# API security

- Enforce HTTPS at the public proxy and never place credentials in URLs.
- Store `API_WEBHOOK_ENCRYPTION_KEY` only in the deployment secret manager.
- Grant the service user and client only the required matching permissions and
  scopes; both checks are mandatory.
- Keep approval, credit release, commissions, and integration replay internal.
- Configure narrow IP allow-lists where partner egress addresses are stable.
- Monitor 401/403/409/429/5xx API logs and webhook dead letters.
- Rotate credentials after personnel/vendor changes or suspected exposure.
- Request logs store route metadata, status, IP, duration, and correlation ID;
  they do not store authorization headers, secrets, tokens, or request bodies.
- JSON fields are explicitly consumed by Sales services; unknown fields cannot
  mass-assign database columns.

Security tests cover invalid credentials, token expiry/revocation, forbidden
scope, tenant binding, idempotency uniqueness, signature tampering and replay.
The full ERP suite retains cross-tenant, SQL parameterization, CSRF/browser,
privilege escalation, audit, credit, and payment-limit coverage.
