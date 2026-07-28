# Oracle deployment status

## Support status

Oracle is not supported or certified in this release.

Checkpoint 5 provides only:

- an allowlisted Oracle `DatabaseDriver` skeleton;
- a PDO_OCI connection configuration boundary;
- `OracleDialect` SQL fragments;
- fail-closed Oracle repository placeholders;
- an isolated Oracle Compose profile;
- an Oracle migration directory reserved for Checkpoint 6.

Do not use this profile for production or customer data.

## Deliberate driver choice

The adapter uses PDO_OCI because the current database connection contract
returns PDO. OCI8 would require a different low-level connection and statement
contract. This decision may be revisited only through a reviewed interface
change.

## Instant Client

Download matching Linux x86-64 Oracle Instant Client Basic Light and SDK
archives after accepting Oracle licensing terms. Rename them:

```text
basiclite.zip
sdk.zip
```

Place them in:

```text
docker/oracle/instantclient/
```

These proprietary archives are ignored by Git and must not be redistributed
from the OfficeApp repository.

## Oracle test image

Choose an approved Oracle Free-compatible image and set it in a private
environment file based on:

```text
docker/oracle/oracle.env.example
```

The selected image must support the configured `ORACLE_PASSWORD`, `APP_USER`,
`APP_USER_PASSWORD` and health-check behavior. Pin an immutable image version
for repeatable testing.

`DB_DRIVER=oracle` must be set in the process environment. The runtime checks
this value before loading the application database configuration so it can
require `pdo_oci` instead of `pdo_mysql`.

Validate the profile without starting it:

```powershell
docker compose `
  --env-file docker/oracle/oracle.env `
  -f compose.oracle.yaml `
  --profile oracle `
  config
```

Build and start only after Instant Client inputs and an approved database image
are available:

```powershell
docker compose `
  --env-file docker/oracle/oracle.env `
  -f compose.oracle.yaml `
  --profile oracle `
  up --build
```

## Required support gates

Oracle must remain labelled unverified until all of these pass against an
actual Oracle environment:

- clean Oracle migrations and seeds;
- authentication and forced password change;
- role and permission enforcement;
- tenant isolation and cross-tenant denial;
- commit and rollback;
- generated identifiers;
- pagination and stable sorting;
- Unicode and Oracle empty-string semantics;
- timestamps and time zones;
- CLOB audit snapshots;
- unique-constraint handling;
- concurrent membership and permission updates;
- browser and health-check smoke tests.

Checkpoint 6 owns migrations and executable Oracle integration tests.
Checkpoint 7 owns actual compatibility validation.
