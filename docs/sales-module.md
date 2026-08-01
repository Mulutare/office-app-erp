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
