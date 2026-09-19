# Sales module

The Sales module implements the first order-to-cash increment from the ERP
terms of reference. It is tenant-scoped and uses the same controller, service,
repository and audit boundaries as the rest of OfficeApp.

## Current workflow

1. A user with `sales.catalogue.manage` creates territories, DSA/DSP records,
   customers and telecom products.
2. A user with `sales.orders.create` saves or confirms a multi-line order.
3. Confirmed orders create commission accruals when a DSA/DSP and a product
   commission rate are present.
4. A user with `sales.payments.record` records receipts. The repository locks
   the order, rejects overpayments and updates receivable status atomically.
5. The dashboard reports total sales, open and overdue receivables, and accrued
   commissions.
6. Managers set territory and DSA/DSP targets and monitor achieved sales.
7. Authorized users export the current order and receivables report to CSV.
8. Confirmed orders and payments publish transactional integration events.
   Finance and Inventory consume those events through idempotent handlers.

## Maintenance boundaries

- HTTP parsing and redirects: `SalesController`
- validation and calculations: `SalesService`
- SQL and transactions: `SalesRepository`
- schema evolution: migration `026`
- RBAC and module availability: seed `018`

Never calculate order totals in a controller or view. New order-line entry UIs
should submit a line array to `SalesService`; the schema and repository already
support multiple lines. Inventory allocation and invoice journal posting should
be added as separate services so that sales does not directly mutate inventory
or finance tables.

## Local migration 088 work in progress (not deployable)

The unstaged 088 draft introduces independently approved, effective SKU prices
and exact discounts per unit, immutable rejected-order revisions, structured
Mobile/MiFi brand/model/SKU classification, manager-issued cash float and
Safaricom incentive claims/settlements. DSA/DSP price and discount calculation
is intended to remain server-authoritative, with existing sale lines retaining
their historical snapshots. Operational float and incentive records do not post
Finance GL entries; accounting policy is deferred.

The selling resolver accepts only saved, effective SKU pricing with percentage
discount and tax; an unpriced SKU is blocked. A privileged user updates these
terms directly with `sales.pricing.manage`, without a second approval. The
product catalogue and DSA/DSP preview read the same effective record. Legacy
pricelist rules remain for history but do not control Quick Sale pricing.
Reservation events are captured before the locked baseline, with an event-ID
boundary used to exclude pre-baseline changes. Existing-company permissions are
limited to active assigned company-owner/system-administrator, Sales Manager,
Sales Approver and Sales Officer roles; DSA/DSP identity and manager hierarchy
remain service-enforced. The core Sales, Finance and DSA/DSP workflows were
exercised in the local acceptance pass. See `docs/UPGRADE_STATE.md`
section 25 for the cumulative 086–091 production package status.

Daily stock history reads completed authoritative movement legs: receipt is
Received; internal source/destination legs are Transfers Out/In; return_in is
Returns In; issue/fulfilment is Sold/Issued; return_out is Returns Out; and
opening/adjustment_in/adjustment_out are Adjustments. Unexpected movement legs
remain visibly Unclassified and still affect Ending On-Hand. Reservation and
Available history before the explicit 088 cutover is unavailable, not zero.
