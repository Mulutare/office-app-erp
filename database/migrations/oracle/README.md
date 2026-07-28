# Oracle migrations

These PHP migration definitions build the same final 17-table logical schema
used by the MySQL/MariaDB reference implementation.

They are intentionally separate because Oracle uses:

- identity columns instead of `AUTO_INCREMENT`;
- `NUMBER(1)` with check constraints instead of MySQL booleans;
- `VARCHAR2` and `CLOB`;
- `TIMESTAMP` with `SYSTIMESTAMP`;
- Oracle pagination and index syntax;
- Oracle foreign-key behavior.

Run them only through:

```text
php bin/migrate.php
```

The runner creates `schema_migrations`, records a SHA-256 checksum and refuses
to execute an already-applied migration again. It also rejects a changed file
whose version has already been recorded.

Oracle DDL auto-commits. If a statement fails after earlier DDL in the same
migration succeeded, restore the clean test schema or recover from a verified
backup. Never attempt production recovery by editing the migration ledger.

The files are executable test assets, but they remain unverified until
Checkpoint 7 runs them against an approved Oracle environment.
