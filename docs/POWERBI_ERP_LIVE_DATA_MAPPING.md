# Passion Sales Report: ERP live-data contract

## Scope

Migration 072 creates read-only `vw_powerbi_*` views scoped to `company_id = 2`. It does not change the Power BI file, import history, create credentials, or grant access. Every view exposes `SourceSystem = ERP_LIVE`.

The report can remain visually unchanged if Power Query preserves current field aliases and measures are rebound correctly. Quantity and Birr value are different measures and must never be interchanged.

## Authoritative sources

| Subject | Source |
|---|---|
| Shops and locations | `inventory_warehouses`, `inventory_warehouse_locations` |
| Products/categories | `sales_products` |
| Employees and DSA/DSP | `hr_employees`, `hr_departments`, `sales_agents` |
| Employee shop/location | exactly one active warehouse assignment and one matching active location assignment |
| Ordered sales | `sales_orders`, `sales_order_lines` |
| Fulfilled sales/returns | completed `inventory_stock_movements` linked through `inventory_pickings` |
| Current stock | `inventory_stock_balances` |
| Historical stock flow | completed `inventory_stock_movements`, internal endpoints only |
| Customer cash receipts | `sales_payments` |
| Bank reconciliation | `sales_settlements`, `bank_confirmations` |
| BI cluster | active `bi_warehouse_cluster_map` to active company-2 `bi_clusters` |
| BI region | active `bi_warehouse_territory_map` to active, non-deleted company-2 `sales_territories` |
| External Power BI keys | `data_external_ids` |

Both BI mapping tables have `warehouse_id` as primary key. Each warehouse therefore has at most one cluster and one territory mapping, and the hierarchy cannot multiply warehouse facts.

## View contract

| View | Grain and purpose |
|---|---|
| `vw_powerbi_warehouses` | One warehouse/shop with ERP and optional `PBI-SHOP-*` key |
| `vw_powerbi_locations` | One warehouse location |
| `vw_powerbi_products` | One product with category and optional `PBI-PRODUCT-*` key |
| `vw_powerbi_employees` | One employee; fail-closed scope plus resolved shop/location labels |
| `vw_powerbi_sales_agents` | One agent with DSA/DSP classification |
| `vw_powerbi_sales_orders` | One order: ordered commercial header value |
| `vw_powerbi_sales_order_lines` | One order line: ordered quantity and commercial value |
| `vw_powerbi_inventory_movements` | One internal completed movement endpoint leg with signed quantity/value and decomposed flows |
| `vw_powerbi_fulfilled_sales` | One fulfilled/returned movement attributable to order, shop, product, employee, cluster, and region |
| `vw_powerbi_inventory_balance_reconciliation` | One warehouse/location/product current balance, opening anchor, reconstruction, and variance |
| `vw_powerbi_inventory_daily` | One activity date/warehouse/location/product with quantity/value openings, flows, and closings |
| `vw_powerbi_receipts` | One GRV line; use `is_posted=1` for posted stock |
| `vw_powerbi_warehouse_cluster_bridge` | One actively cluster-mapped warehouse |
| `vw_powerbi_warehouse_territory_bridge` | One actively territory-mapped warehouse |
| `vw_powerbi_shop_hierarchy` | One warehouse with optional cluster and region |
| `vw_powerbi_sales_payments` | One customer payment enriched with shop, employee, cluster, and region |
| `vw_powerbi_cash_deposits` | One settlement/bank-confirmation combination for separate reconciliation reporting |

## Measure semantics

| Measure | Definition |
|---|---|
| Ordered quantity | Sum `vw_powerbi_sales_order_lines.ordered_quantity` |
| Ordered Birr | Sum `vw_powerbi_sales_order_lines.line_total` (commercial order value) |
| Fulfilled quantity | Sum `vw_powerbi_fulfilled_sales.fulfilled_quantity` |
| Returned quantity | Sum `returned_quantity` |
| Net sold quantity | Sum `net_sold_quantity` |
| Fulfilled stock-value Birr | Sum `fulfilled_inventory_cost_value` |
| Returned stock-value Birr | Sum `returned_inventory_cost_value` |
| Net sold stock-value Birr | Sum `net_sold_inventory_cost_value` |
| Shop Cash Deposit | Sum `vw_powerbi_sales_payments.amount` by shop |
| Cluster Cash Deposit | Sum the same payment amount by its authoritative cluster |
| Employee Total Cash Deposit | Sum the same payment amount by employee/agent; no-agent orders remain unattributed |
| Shop/employee rank | Rank the corresponding Cash Deposit measure |
| Bank-confirmed deposit | Distinct confirmation facts from `vw_powerbi_cash_deposits`; never substitute for Dashboard cash measures |

Movement Birr values are completed quantity times `inventory_stock_movements.unit_cost`, rounded to two decimals. This is authoritative inventory weighted cost, not selling revenue. Current inventory value comes from `inventory_stock_balances.inventory_value`. A partial fulfilment has no deterministic allocation to an order line's selling price, so fulfilled selling revenue is not fabricated.

All Daily/Weekly/Monthly/Category/Regional visuals labelled Birr must use `*_value` fields; quantity visuals must use `*_quantity`. Weekly/monthly measures aggregate the daily or atomic fact and are not separate stored facts.

## Stock flows, opening, and reconciliation

The movement view separately exposes GRV receipt, transfer-in, other-inbound, fulfilment, transfer-out, supplier-return, customer-return, adjustment-in, and adjustment-out quantity/value. It also exposes total inbound/outbound and signed net deltas. Transfers are negative at the internal source and positive at the internal destination. Customer returns are inbound and subtract from net sold. Only completed movements at internal endpoints participate.

Opening is not assumed to be zero. For each warehouse/location/product, independently for quantity and value:

`ledger opening = current inventory_stock_balances - cumulative completed internal movement deltas`

Daily beginning/closing balances anchor to this opening and add dated flows. The Power BI Date dimension must carry the latest closing across dates without activity.

Because the latest reconstruction adds back the same ledger used to derive opening, zero variance is expected by construction. This validates the view algebra and movement coverage against current balance; it does not prove pre-ledger history or approve a cutover opening. Cutover still requires independent historical-closing-to-ERP reconciliation.

## Labels and unavailable metrics

Employee `Shop Location` is the resolved shop name; `storage_location_name` is separate. Ambiguous/unresolved assignments remain null. `shop_manager_label` is the shop name for compatibility, not a person.

`NOT_AVAILABLE_IN_ERP` remains:

- incentive SIM-card Birr awards (no authoritative incentive fact);
- actual shop/district/regional manager identities (no manager hierarchy);
- fulfilled selling revenue for partial fulfilments (no authoritative fulfilment-to-order-line revenue allocation);
- independently authoritative stock history before the ledger/cutover boundary.

## Deployment gates

1. Production must be at migration 071.
2. Validate external shop/product/employee IDs and active cluster/territory mappings; unmapped shops intentionally have null hierarchy values.
3. Approve a governed cutover date and independently reconcile historical closing quantity/value to ERP by warehouse/location/product.
4. Union `POWERBI_HISTORY` before cutover with `ERP_LIVE` on/after cutover at matching grain; never mix aggregate and atomic rows in one additive measure.
5. Preserve current Power Query display aliases and rebind every visual to its ordered, fulfilled, receipt, bank, quantity, or Birr measure.
6. Grant a dedicated reporting identity `SELECT` only on required views.

Preparing this contract does not execute or deploy migration 072.
