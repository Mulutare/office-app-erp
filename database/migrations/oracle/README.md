# Oracle migrations

Oracle migrations are intentionally not implemented in Checkpoint 5.

Checkpoint 6 must create reviewed Oracle-specific migrations with the same
logical versions, constraints, indexes and tenant-isolation behavior as the
MySQL reference schema.

Do not run MySQL migration files against Oracle. Do not start OfficeApp with
`DB_DRIVER=oracle` until the Oracle migrations and integration tests exist.
