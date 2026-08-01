# Sales workflow

## Order creation

1. The service validates tenant-owned customer, products, territory and agent.
2. The server recalculates every line, discount, tax, commission and total.
3. Credit policy is evaluated as `no_credit`, `unlimited` or `fixed`.
4. The repository reserves the next number from the locked tenant/branch sequence.
5. Order header, lines and initial status history are committed atomically.

Order numbers use `SO-00000001` format. A sequence is independent for each
company, branch scope and document type. Gaps are permitted after a failed
business transaction; duplicates and number reuse are not.

## Status control

| Action | From | To | Permission |
|---|---|---|---|
| Submit | Draft | Submitted | `sales.orders.submit` |
| Approve | Submitted | Approved | `sales.orders.approve` |
| Fulfil | Approved or legacy Confirmed | Fulfilled | `sales.orders.confirm` |
| Cancel | Draft, Submitted, Approved or legacy Confirmed | Cancelled | `sales.orders.cancel` |

The creator cannot approve the same order. Cancellation requires at least ten
characters of reason and is blocked after payment. Each accepted action writes
one immutable status-history row in the same transaction. The browser supplies
an idempotency key so resubmitting the same request returns success without
repeating integration, commission or audit side effects.

## Integration

Approval accrues commission and publishes `sales.order.confirmed` within the
order transaction. Payments publish `sales.payment.recorded` within the payment
transaction. The dispatcher atomically claims eligible rows, observes strict
per-aggregate predecessor ordering and uses idempotent Finance and Inventory
consumers.
