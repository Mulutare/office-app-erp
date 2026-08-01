# Sales integration contracts

## Reliability rules

- Events are inserted in the same database transaction as the business change.
- `company_id`, aggregate type/id and monotonic `outbox_sequence` are mandatory.
- A later event for one aggregate cannot be claimed until every predecessor is processed.
- Claims use database row locks and a worker lease; stale claims are recoverable after ten minutes.
- Only the claiming worker may mark an event processed or failed.
- Retry delay grows exponentially from five minutes and is capped at sixty minutes.
- The tenth unsuccessful attempt records `dead_lettered_at` and requires an authorized manual replay workflow.
- Consumer tables use tenant-scoped unique business keys to reject duplicates.

## `sales.order.confirmed`

Published after independent approval. Payload fields:

- `order_id`, `order_number`, `customer_id`
- ISO `currency`, `total_amount`, `due_date`
- `lines[]`: `product_id`, `quantity`

Finance creates or refreshes one receivable keyed by company/order. Inventory
creates one reservation commitment per company/order/product. The current
contract does not yet represent warehouse choice, partial reservation or
reservation failure; these remain Partial in the professional gap matrix.

## `sales.payment.recorded`

Published after a payment and order balance update commit. Payload fields:

- `payment_id`, `order_id`, `receipt_number`
- `amount`, `payment_date`, `payment_method`, `reference_number`

Finance inserts one receipt keyed by company/payment and updates the receivable
only when that insert is new.
