# Sales access control

Sales access requires both an active company Sales-module licence and an
effective `sales.*` permission. Hiding a control in the interface is only a
convenience; every Sales endpoint repeats the module and permission checks.

## Standard roles

| Role | Intended access |
|---|---|
| Sales Manager | All normal Sales operations and reports. Integration replay remains restricted to system administration. |
| Sales Officer | View Sales, maintain the catalogue, create/submit/cancel orders, and export reports. |
| Sales Approver | View, approve/fulfil/cancel orders, manage targets, view margin, and export reports. |
| Sales Cashier | View Sales, record receipts, and export reports. |
| Sales Inventory Controller | View Sales and register serialized products. |
| Sales Commission Officer | View Sales and approve/pay commissions. |
| Sales Credit Controller | View Sales, maintain credit rules, release holds, and export reports. |
| Executive Viewer | Read-only Sales dashboard access. |
| Company Owner / System Administrator | Full Sales access; existing separation-of-duty rules still prohibit approving an order created by the same user. |

Companies may create or customize additional roles through Administration.
Avoid combining order creation and approval for ordinary users. Payment,
commission, credit-release, and integration-replay access should be assigned
only to staff responsible for those controls.

## Permission catalogue

| Permission | Purpose |
|---|---|
| `sales.view` | Open Sales dashboards and lists. |
| `sales.catalogue.manage` | Maintain customers, products, territories, and agents. |
| `sales.orders.create` | Create draft orders. |
| `sales.orders.submit` | Submit drafts for approval. |
| `sales.orders.approve` | Approve submitted orders. |
| `sales.orders.confirm` | Fulfil approved orders. |
| `sales.orders.cancel` | Cancel eligible orders with a reason. |
| `sales.payments.record` | Record customer receipts. |
| `sales.targets.manage` | Maintain Sales targets. |
| `sales.serials.manage` | Register serialized products. |
| `sales.commissions.manage` | Approve and settle commissions. |
| `sales.credit.manage` | Maintain customer credit policy. |
| `sales.credit.release` | Release credit holds. |
| `sales.reports.export` | Export Sales reports. |
| `sales.margin.view` | View cost and margin information. |
| `sales.integrations.replay` | Replay failed integration events; reserved for system administration. |
