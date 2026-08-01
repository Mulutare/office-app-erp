# Idempotency

Every POST business request requires `Idempotency-Key` containing 1-100 safe
ASCII characters. The server binds the key to the client, method, URL, and JSON
body for 24 hours. An exact retry returns the stored status and safe JSON body.
A changed request returns `409 idempotency_conflict`; a concurrent in-progress
request returns `409 request_in_progress`.

Use a new key for each logical operation and retain it until the caller has a
definitive response. Do not reuse keys across orders or payments.
