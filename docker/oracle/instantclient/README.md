# Oracle Instant Client build inputs

The optional Oracle application image requires Oracle Instant Client Basic
Light and SDK archives accepted and downloaded by an authorized operator.

Rename the matching Linux x86-64 archives to:

```text
basiclite.zip
sdk.zip
```

Place them in this directory before building the `oracle-development` or
`oracle-production` Docker target.

The archives are ignored by Git and must never be committed or redistributed
from this repository. Confirm Oracle licensing and architecture requirements
before use.
