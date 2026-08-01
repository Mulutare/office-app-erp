# Sales API

Read endpoints return tenant-filtered active catalogue records and up to 200
recent orders. Write endpoints call `SalesService`, so server catalogue prices,
discount/tax validation, totals, commissions, credit controls, payment balance
limits, transition rules, audit logs, and creator/approver separation remain in
force.

Order creation accepts an unlimited `lines` array at the service boundary. Each
line supports `product_id`, `quantity`, `discount_amount`, and `tax_rate`.
`external_reference` is optional and unique per company. API creation always
creates a draft; submission is a separate scoped request.

Routes and required scopes:

| Route | Scope |
|---|---|
| GET products / product | `sales.products.read` |
| GET customers / customer | `sales.customers.read` |
| POST customers | `sales.customers.write` |
| GET orders / order | `sales.orders.read` |
| POST orders | `sales.orders.write` |
| POST order submit | `sales.orders.submit` |
| POST order cancel | `sales.orders.cancel` |
| POST order payments | `sales.payments.write` |
| GET receivables / receivable | `sales.receivables.read` |
| GET reports/summary | `sales.reports.read` |

Example order body:

```json
{"customer_id":10,"order_date":"2026-08-01","due_date":"2026-08-31","currency":"ETB","external_reference":"PARTNER-1001","lines":[{"product_id":20,"quantity":2,"discount_amount":0,"tax_rate":15}]}
```
