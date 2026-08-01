# Authentication

Use OAuth 2.0 client credentials over HTTPS. Send client credentials with HTTP
Basic authentication to `POST /api/v1/oauth/token` and form field
`grant_type=client_credentials`. Tokens expire between 5 minutes and 24 hours
and are stored only as SHA-256 digests. Client secrets use PHP password hashes.

```http
Authorization: Basic BASE64(client_id:client_secret)
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials
```

Use `Authorization: Bearer TOKEN` on business requests. Rotation immediately
revokes existing tokens. A revoked/inactive client, user, company, licence, or
token fails closed. IP allow-lists contain exact source addresses; configure
the trusted reverse proxy so `REMOTE_ADDR` represents the controlled source.

Scope-to-permission mappings are defined in `ApiSecurityService`. External
approval, credit release, commission management, and integration replay scopes
are not available.
