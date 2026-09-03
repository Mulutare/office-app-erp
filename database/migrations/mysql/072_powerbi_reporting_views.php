<?php

declare(strict_types=1);

return [
    'version'=>'072',
    'description'=>'Create company 2 Power BI live reporting views',
    'preflight'=>static function(\PDO $connection):string{
        $required=['data_external_ids','inventory_goods_receipts','inventory_goods_receipt_lines','inventory_pickings','inventory_stock_balances','inventory_stock_movements','inventory_user_location_access','inventory_user_warehouse_access','inventory_warehouse_locations','inventory_warehouses','hr_departments','hr_employees','sales_agents','sales_customers','sales_order_lines','sales_orders','sales_payments','sales_products','sales_settlements','bank_confirmations','sales_territories','bi_clusters','bi_warehouse_cluster_map','bi_warehouse_territory_map'];
        $quoted=implode(',',array_map(static fn(string $name):string=>$connection->quote($name),$required));
        $tables=(int)$connection->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN($quoted)")->fetchColumn();
        if($tables!==count($required))throw new \RuntimeException('Migration 072 requires the complete Sales, Inventory, HR, settlement and external-ID schema.');
        $names=['vw_powerbi_warehouses','vw_powerbi_locations','vw_powerbi_products','vw_powerbi_employees','vw_powerbi_sales_agents','vw_powerbi_sales_orders','vw_powerbi_sales_order_lines','vw_powerbi_inventory_movements','vw_powerbi_fulfilled_sales','vw_powerbi_inventory_balance_reconciliation','vw_powerbi_inventory_daily','vw_powerbi_receipts','vw_powerbi_warehouse_cluster_bridge','vw_powerbi_warehouse_territory_bridge','vw_powerbi_shop_hierarchy','vw_powerbi_sales_payments','vw_powerbi_cash_deposits'];
        $viewQuoted=implode(',',array_map(static fn(string $name):string=>$connection->quote($name),$names));
        $views=(int)$connection->query("SELECT COUNT(*) FROM information_schema.views WHERE table_schema=DATABASE() AND table_name IN($viewQuoted)")->fetchColumn();
        if($views===0)return 'apply';
        if($views===count($names))return 'baseline';
        throw new \RuntimeException('Migration 072 found a partial Power BI reporting-view layer.');
    },
    'statements'=>[
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_warehouses AS
SELECT w.company_id,w.warehouse_id,x.external_id AS pbi_shop_id,
       w.code AS shop_code,w.name AS shop_name,w.warehouse_type,
       w.branch_id,w.address,w.phone,w.email,w.manager_user_id,
       w.active,w.created_at,w.updated_at,
       'ERP_LIVE' AS SourceSystem
FROM inventory_warehouses w
LEFT JOIN data_external_ids x
  ON x.company_id=w.company_id AND x.entity_type='warehouses' AND x.entity_id=w.warehouse_id
WHERE w.company_id=2 AND w.deleted_at IS NULL
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_locations AS
SELECT l.company_id,l.location_id,l.warehouse_id,w.pbi_shop_id,
       l.code AS location_code,l.name AS location_name,l.location_type,
       l.location_usage,l.parent_location_id,l.receiving_allowed,
       l.picking_allowed,l.is_virtual,l.active,l.created_at,l.updated_at,
       'ERP_LIVE' AS SourceSystem
FROM inventory_warehouse_locations l
INNER JOIN vw_powerbi_warehouses w ON w.warehouse_id=l.warehouse_id
WHERE l.company_id=2 AND l.deleted_at IS NULL
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_products AS
SELECT p.company_id,p.product_id,x.external_id AS pbi_product_id,
       p.sku,p.name AS product_name,p.category,p.product_type,
       p.unit_of_measure,p.unit_price,p.serial_tracking,p.active,
       p.created_at,p.updated_at,'ERP_LIVE' AS SourceSystem
FROM sales_products p
LEFT JOIN data_external_ids x
  ON x.company_id=p.company_id AND x.entity_type='products' AND x.entity_id=p.product_id
WHERE p.company_id=2 AND p.deleted_at IS NULL
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_employees AS
SELECT e.company_id,e.employee_id,x.external_id AS pbi_employee_id,e.user_id,
       e.employee_number,
       CONCAT_WS(' ',e.first_name,NULLIF(e.middle_name,''),e.last_name) AS employee_name,
       e.preferred_name,e.work_email,e.work_phone,e.department_id,d.code AS department_code,
       d.name AS department_name,e.job_title,e.employment_type,e.employment_status,
       CASE WHEN a.agent_count=1 THEN a.agent_id END AS agent_id,
       CASE WHEN a.agent_count=1 THEN a.agent_code END AS agent_code,
       CASE WHEN a.agent_count=1 THEN a.agent_type END AS dsa_dsp_role,
       CASE WHEN COALESCE(a.agent_count,0)=1 THEN 'RESOLVED' ELSE 'UNRESOLVED' END AS agent_classification_status,
       CASE WHEN s.warehouse_count=1 AND s.location_count=1 THEN s.warehouse_id END AS warehouse_id,
       CASE WHEN s.warehouse_count=1 AND s.location_count=1 THEN s.location_id END AS location_id,
       CASE WHEN s.warehouse_count=1 AND s.location_count=1 THEN w.pbi_shop_id END AS pbi_shop_id,
       CASE WHEN s.warehouse_count=1 AND s.location_count=1 THEN w.shop_name END AS shop_location_label,
       CASE WHEN s.warehouse_count=1 AND s.location_count=1 THEN l.location_name END AS storage_location_name,
       CASE WHEN s.warehouse_count=1 AND s.location_count=1 THEN 'RESOLVED' ELSE 'UNRESOLVED' END AS warehouse_scope_status,
       e.hire_date,e.termination_date,e.created_at,e.updated_at,
       'ERP_LIVE' AS SourceSystem
FROM hr_employees e
LEFT JOIN hr_departments d ON d.company_id=e.company_id AND d.department_id=e.department_id
LEFT JOIN (
    SELECT company_id,employee_id,COUNT(*) AS agent_count,MIN(agent_id) AS agent_id,
           MIN(agent_code) AS agent_code,MIN(agent_type) AS agent_type
    FROM sales_agents
    WHERE company_id=2 AND employee_id IS NOT NULL AND deleted_at IS NULL AND active=TRUE
    GROUP BY company_id,employee_id
) a ON a.company_id=e.company_id AND a.employee_id=e.employee_id
LEFT JOIN data_external_ids x
  ON x.company_id=e.company_id AND x.entity_type='employees' AND x.entity_id=e.employee_id
LEFT JOIN (
    SELECT wa.company_id,wa.user_id,COUNT(DISTINCT wa.warehouse_id) AS warehouse_count,
           COUNT(DISTINCT il.location_id) AS location_count,
           MIN(wa.warehouse_id) AS warehouse_id,MIN(il.location_id) AS location_id
    FROM inventory_user_warehouse_access wa
    LEFT JOIN inventory_user_location_access la
      ON la.company_id=wa.company_id AND la.user_id=wa.user_id
     AND la.warehouse_id=wa.warehouse_id AND la.active=TRUE
    LEFT JOIN inventory_warehouse_locations il
      ON il.company_id=la.company_id AND il.warehouse_id=la.warehouse_id
     AND il.location_id=la.location_id AND il.active=TRUE AND il.deleted_at IS NULL
    INNER JOIN inventory_warehouses w
      ON w.company_id=wa.company_id AND w.warehouse_id=wa.warehouse_id
     AND w.active=TRUE AND w.deleted_at IS NULL
    WHERE wa.company_id=2 AND wa.active=TRUE
    GROUP BY wa.company_id,wa.user_id
) s ON s.company_id=e.company_id AND s.user_id=e.user_id
LEFT JOIN vw_powerbi_warehouses w ON w.warehouse_id=s.warehouse_id
LEFT JOIN vw_powerbi_locations l ON l.location_id=s.location_id
WHERE e.company_id=2 AND e.deleted_at IS NULL
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_sales_agents AS
SELECT a.company_id,a.agent_id,x.external_id AS pbi_agent_id,a.employee_id,
       a.agent_code,a.name AS agent_name,a.agent_type AS dsa_dsp_role,
       a.territory_id,t.code AS territory_code,t.name AS territory_name,
       a.phone,a.active,a.created_at,a.updated_at,'ERP_LIVE' AS SourceSystem
FROM sales_agents a
LEFT JOIN sales_territories t ON t.company_id=a.company_id AND t.territory_id=a.territory_id
LEFT JOIN data_external_ids x
  ON x.company_id=a.company_id AND x.entity_type='sales-agents' AND x.entity_id=a.agent_id
WHERE a.company_id=2 AND a.deleted_at IS NULL
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_sales_orders AS
SELECT o.company_id,o.order_id,x.external_id AS pbi_order_id,o.order_number,
       o.external_reference,o.warehouse_id,w.pbi_shop_id,o.source_location_id,
       o.customer_id,c.customer_number,c.name AS customer_name,o.territory_id,
       t.code AS territory_code,t.name AS territory_name,o.agent_id,a.agent_code,
       a.name AS agent_name,a.agent_type AS dsa_dsp_role,o.order_date,o.due_date,
       o.status,o.currency,o.subtotal,o.discount_amount,o.tax_amount,o.total_amount,
       o.paid_amount,(o.total_amount-o.paid_amount) AS balance_due,
       CASE WHEN o.status IN('approved','confirmed','partially_fulfilled','partially_paid','paid','fulfilled','returned') THEN 1 ELSE 0 END AS is_reportable_sale,
       o.confirmed_at,o.submitted_at,o.approved_at,o.cancelled_at,
       o.created_at,o.updated_at,'ERP_LIVE' AS SourceSystem
FROM sales_orders o
INNER JOIN vw_powerbi_warehouses w ON w.warehouse_id=o.warehouse_id
INNER JOIN sales_customers c ON c.company_id=o.company_id AND c.customer_id=o.customer_id
LEFT JOIN sales_territories t ON t.company_id=o.company_id AND t.territory_id=o.territory_id
LEFT JOIN sales_agents a ON a.company_id=o.company_id AND a.agent_id=o.agent_id
LEFT JOIN data_external_ids x
  ON x.company_id=o.company_id AND x.entity_type='sales-orders' AND x.entity_id=o.order_id
WHERE o.company_id=2 AND o.deleted_at IS NULL
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_sales_order_lines AS
SELECT l.company_id,l.order_line_id,l.order_id,o.order_number,o.warehouse_id,
       o.pbi_shop_id,o.source_location_id,o.order_date,o.status AS order_status,
       o.agent_id,o.agent_code,o.agent_name,o.dsa_dsp_role,l.product_id,
       p.pbi_product_id,p.sku,p.product_name,p.category,l.description,
       l.quantity AS ordered_quantity,l.unit_price,l.discount_amount,l.tax_rate,
       l.line_total,l.commission_rate,o.currency,l.order_line_id AS incremental_key,
       o.created_at AS order_created_at,o.updated_at AS order_updated_at,
       'ERP_LIVE' AS SourceSystem
FROM sales_order_lines l
INNER JOIN vw_powerbi_sales_orders o ON o.order_id=l.order_id
INNER JOIN vw_powerbi_products p ON p.product_id=l.product_id
WHERE l.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_inventory_movements AS
SELECT CONCAT(m.movement_id,'-S') AS movement_leg_key,m.company_id,m.movement_id,
       'SOURCE' AS movement_leg,m.source_warehouse_id AS warehouse_id,w.pbi_shop_id,
       m.source_location_id AS location_id,m.product_id,p.pbi_product_id,
       m.movement_type,m.reference_type,m.reference_id,m.reference_number,
       -m.completed_quantity AS quantity_delta,m.completed_quantity AS absolute_quantity,
       m.unit_cost,-ROUND(m.completed_quantity*m.unit_cost,2) AS value_delta,m.currency,
       CASE m.movement_type
         WHEN 'fulfilment' THEN 'FULFILMENT_SOLD_OUT'
         WHEN 'transfer_out' THEN 'TRANSFER_OUT'
         WHEN 'return_out' THEN 'SUPPLIER_RETURN_OUT'
         WHEN 'adjustment_out' THEN 'ADJUSTMENT_OUT'
         ELSE 'OTHER_OUT' END AS flow_type,
       0 AS grv_receipt_quantity,0 AS grv_receipt_value,
       0 AS transfer_in_quantity,0 AS transfer_in_value,
       0 AS other_inbound_quantity,0 AS other_inbound_value,
       CASE WHEN m.movement_type='fulfilment' THEN m.completed_quantity ELSE 0 END AS fulfilled_sold_quantity,
       CASE WHEN m.movement_type='fulfilment' THEN ROUND(m.completed_quantity*m.unit_cost,2) ELSE 0 END AS fulfilled_sold_value,
       CASE WHEN m.movement_type='transfer_out' THEN m.completed_quantity ELSE 0 END AS transfer_out_quantity,
       CASE WHEN m.movement_type='transfer_out' THEN ROUND(m.completed_quantity*m.unit_cost,2) ELSE 0 END AS transfer_out_value,
       CASE WHEN m.movement_type='return_out' THEN m.completed_quantity ELSE 0 END AS supplier_return_quantity,
       CASE WHEN m.movement_type='return_out' THEN ROUND(m.completed_quantity*m.unit_cost,2) ELSE 0 END AS supplier_return_value,
       0 AS sales_return_quantity,0 AS sales_return_value,
       0 AS adjustment_in_quantity,0 AS adjustment_in_value,
       CASE WHEN m.movement_type='adjustment_out' THEN m.completed_quantity ELSE 0 END AS adjustment_out_quantity,
       CASE WHEN m.movement_type='adjustment_out' THEN ROUND(m.completed_quantity*m.unit_cost,2) ELSE 0 END AS adjustment_out_value,
       0 AS total_inbound_quantity,0 AS total_inbound_value,
       m.completed_quantity AS total_outbound_quantity,ROUND(m.completed_quantity*m.unit_cost,2) AS total_outbound_value,
       DATE(m.occurred_at) AS transaction_date,m.occurred_at,m.completed_at,m.created_at,
       m.recorded_by,m.completed_by,'ERP_LIVE' AS SourceSystem
FROM inventory_stock_movements m
INNER JOIN inventory_warehouse_locations l
  ON l.company_id=m.company_id AND l.location_id=m.source_location_id
 AND l.warehouse_id=m.source_warehouse_id AND l.location_usage='internal'
INNER JOIN vw_powerbi_warehouses w ON w.warehouse_id=m.source_warehouse_id
INNER JOIN vw_powerbi_products p ON p.product_id=m.product_id
WHERE m.company_id=2 AND m.status='completed' AND m.source_location_id IS NOT NULL
UNION ALL
SELECT CONCAT(m.movement_id,'-D'),m.company_id,m.movement_id,'DESTINATION',
       m.destination_warehouse_id,w.pbi_shop_id,m.destination_location_id,
       m.product_id,p.pbi_product_id,m.movement_type,m.reference_type,m.reference_id,
       m.reference_number,m.completed_quantity,m.completed_quantity,m.unit_cost,
       ROUND(m.completed_quantity*m.unit_cost,2),m.currency,
       CASE m.movement_type
         WHEN 'receipt' THEN 'GRV_RECEIPT_IN'
         WHEN 'transfer_in' THEN 'TRANSFER_IN'
         WHEN 'return_in' THEN 'SALES_RETURN_IN'
         WHEN 'adjustment_in' THEN 'ADJUSTMENT_IN'
         ELSE 'OTHER_IN' END,
       CASE WHEN m.movement_type='receipt' THEN m.completed_quantity ELSE 0 END,
       CASE WHEN m.movement_type='receipt' THEN ROUND(m.completed_quantity*m.unit_cost,2) ELSE 0 END,
       CASE WHEN m.movement_type='transfer_in' THEN m.completed_quantity ELSE 0 END,
       CASE WHEN m.movement_type='transfer_in' THEN ROUND(m.completed_quantity*m.unit_cost,2) ELSE 0 END,
       CASE WHEN m.movement_type NOT IN('receipt','transfer_in','return_in','adjustment_in') THEN m.completed_quantity ELSE 0 END,
       CASE WHEN m.movement_type NOT IN('receipt','transfer_in','return_in','adjustment_in') THEN ROUND(m.completed_quantity*m.unit_cost,2) ELSE 0 END,
       0,0,0,0,0,0,
       CASE WHEN m.movement_type='return_in' THEN m.completed_quantity ELSE 0 END,
       CASE WHEN m.movement_type='return_in' THEN ROUND(m.completed_quantity*m.unit_cost,2) ELSE 0 END,
       CASE WHEN m.movement_type='adjustment_in' THEN m.completed_quantity ELSE 0 END,
       CASE WHEN m.movement_type='adjustment_in' THEN ROUND(m.completed_quantity*m.unit_cost,2) ELSE 0 END,
       0,0,m.completed_quantity,ROUND(m.completed_quantity*m.unit_cost,2),0,0,
       DATE(m.occurred_at),m.occurred_at,m.completed_at,m.created_at,
       m.recorded_by,m.completed_by,'ERP_LIVE'
FROM inventory_stock_movements m
INNER JOIN inventory_warehouse_locations l
  ON l.company_id=m.company_id AND l.location_id=m.destination_location_id
 AND l.warehouse_id=m.destination_warehouse_id AND l.location_usage='internal'
INNER JOIN vw_powerbi_warehouses w ON w.warehouse_id=m.destination_warehouse_id
INNER JOIN vw_powerbi_products p ON p.product_id=m.product_id
WHERE m.company_id=2 AND m.status='completed' AND m.destination_location_id IS NOT NULL
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_fulfilled_sales AS
SELECT im.company_id,im.movement_leg_key,im.movement_id,im.flow_type,
       im.transaction_date,im.occurred_at,im.created_at,
       o.order_id,o.order_number,o.order_date,o.order_status,o.currency AS order_currency,
       o.warehouse_id,o.pbi_shop_id,w.shop_name,w.shop_name AS shop_manager_label,
       bc.cluster_id,bc.code AS cluster_code,bc.name AS cluster_name,
       t.territory_id,t.code AS territory_code,t.name AS region_name,
       o.agent_id,o.agent_code,o.agent_name,o.dsa_dsp_role,a.employee_id,
       im.location_id,im.product_id,im.pbi_product_id,p.sku,p.product_name,p.category,
       CASE WHEN im.flow_type='FULFILMENT_SOLD_OUT' THEN im.absolute_quantity ELSE 0 END AS fulfilled_quantity,
       CASE WHEN im.flow_type='FULFILMENT_SOLD_OUT' THEN im.fulfilled_sold_value ELSE 0 END AS fulfilled_inventory_cost_value,
       CASE WHEN im.flow_type='SALES_RETURN_IN' THEN im.absolute_quantity ELSE 0 END AS returned_quantity,
       CASE WHEN im.flow_type='SALES_RETURN_IN' THEN im.sales_return_value ELSE 0 END AS returned_inventory_cost_value,
       CASE WHEN im.flow_type='FULFILMENT_SOLD_OUT' THEN im.absolute_quantity ELSE -im.absolute_quantity END AS net_sold_quantity,
       CASE WHEN im.flow_type='FULFILMENT_SOLD_OUT' THEN im.fulfilled_sold_value ELSE -im.sales_return_value END AS net_sold_inventory_cost_value,
       im.unit_cost AS inventory_unit_cost,'INVENTORY_WEIGHTED_COST_NOT_SALES_REVENUE' AS value_basis,
       'ERP_LIVE' AS SourceSystem
FROM vw_powerbi_inventory_movements im
INNER JOIN inventory_pickings ip
  ON ip.company_id=im.company_id AND ip.picking_id=im.reference_id
 AND im.reference_type='inventory_picking'
INNER JOIN vw_powerbi_sales_orders o ON o.order_id=ip.sales_order_id
INNER JOIN vw_powerbi_warehouses w ON w.warehouse_id=o.warehouse_id
LEFT JOIN bi_warehouse_cluster_map bcm
  ON bcm.warehouse_id=o.warehouse_id AND bcm.active=1
LEFT JOIN bi_clusters bc
  ON bc.cluster_id=bcm.cluster_id AND bc.company_id=im.company_id AND bc.active=1
LEFT JOIN bi_warehouse_territory_map btm
  ON btm.warehouse_id=o.warehouse_id AND btm.active=1
LEFT JOIN sales_territories t
  ON t.territory_id=btm.territory_id AND t.company_id=im.company_id
 AND t.active=TRUE AND t.deleted_at IS NULL
INNER JOIN vw_powerbi_products p ON p.product_id=im.product_id
LEFT JOIN sales_agents a ON a.company_id=im.company_id AND a.agent_id=o.agent_id
WHERE im.flow_type IN('FULFILMENT_SOLD_OUT','SALES_RETURN_IN')
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_inventory_balance_reconciliation AS
SELECT k.company_id,k.warehouse_id,w.pbi_shop_id,k.location_id,k.product_id,p.pbi_product_id,
       COALESCE(b.quantity_on_hand,0) AS current_quantity_on_hand,
       COALESCE(b.average_unit_cost,0) AS current_average_unit_cost,
       COALESCE(b.inventory_value,0) AS current_inventory_value,
       COALESCE(f.cumulative_quantity_delta,0) AS cumulative_ledger_quantity,
       COALESCE(f.cumulative_value_delta,0) AS cumulative_ledger_value,
       COALESCE(b.quantity_on_hand,0)-COALESCE(f.cumulative_quantity_delta,0) AS ledger_opening_quantity,
       COALESCE(b.inventory_value,0)-COALESCE(f.cumulative_value_delta,0) AS ledger_opening_value,
       (COALESCE(b.quantity_on_hand,0)-COALESCE(f.cumulative_quantity_delta,0))+COALESCE(f.cumulative_quantity_delta,0) AS reconstructed_current_quantity,
       (COALESCE(b.inventory_value,0)-COALESCE(f.cumulative_value_delta,0))+COALESCE(f.cumulative_value_delta,0) AS reconstructed_current_value,
       COALESCE(b.quantity_on_hand,0)-((COALESCE(b.quantity_on_hand,0)-COALESCE(f.cumulative_quantity_delta,0))+COALESCE(f.cumulative_quantity_delta,0)) AS quantity_reconciliation_variance,
       COALESCE(b.inventory_value,0)-((COALESCE(b.inventory_value,0)-COALESCE(f.cumulative_value_delta,0))+COALESCE(f.cumulative_value_delta,0)) AS value_reconciliation_variance,
       b.last_movement_at,b.updated_at AS balance_updated_at,f.last_occurred_at,f.last_created_at,
       'CURRENT_BALANCE_MINUS_COMPLETE_LEDGER' AS opening_basis,'ERP_LIVE' AS SourceSystem
FROM (
    SELECT company_id,warehouse_id,location_id,product_id FROM inventory_stock_balances WHERE company_id=2
    UNION
    SELECT company_id,warehouse_id,location_id,product_id FROM vw_powerbi_inventory_movements
) k
INNER JOIN vw_powerbi_warehouses w ON w.warehouse_id=k.warehouse_id
INNER JOIN vw_powerbi_products p ON p.product_id=k.product_id
LEFT JOIN inventory_stock_balances b
  ON b.company_id=k.company_id AND b.warehouse_id=k.warehouse_id
 AND b.location_id=k.location_id AND b.product_id=k.product_id
LEFT JOIN (
    SELECT company_id,warehouse_id,location_id,product_id,
           SUM(quantity_delta) AS cumulative_quantity_delta,
           SUM(value_delta) AS cumulative_value_delta,
           MAX(occurred_at) AS last_occurred_at,MAX(created_at) AS last_created_at
    FROM vw_powerbi_inventory_movements
    GROUP BY company_id,warehouse_id,location_id,product_id
) f ON f.company_id=k.company_id AND f.warehouse_id=k.warehouse_id
   AND f.location_id=k.location_id AND f.product_id=k.product_id
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_inventory_daily AS
SELECT d.company_id,d.reporting_date,d.warehouse_id,d.pbi_shop_id,d.location_id,
       d.product_id,d.pbi_product_id,
       r.ledger_opening_quantity+COALESCE(SUM(d.net_movement_quantity) OVER(PARTITION BY d.warehouse_id,d.location_id,d.product_id ORDER BY d.reporting_date ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING),0) AS beginning_stock_quantity,
       r.ledger_opening_value+COALESCE(SUM(d.net_movement_value) OVER(PARTITION BY d.warehouse_id,d.location_id,d.product_id ORDER BY d.reporting_date ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING),0) AS beginning_stock_value,
       d.grv_receipt_quantity,d.grv_receipt_value,d.transfer_in_quantity,d.transfer_in_value,
       d.other_inbound_quantity,d.other_inbound_value,d.sales_return_quantity,d.sales_return_value,
       d.adjustment_in_quantity,d.adjustment_in_value,d.total_inbound_quantity,d.total_inbound_value,
       r.ledger_opening_quantity+COALESCE(SUM(d.net_movement_quantity) OVER(PARTITION BY d.warehouse_id,d.location_id,d.product_id ORDER BY d.reporting_date ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING),0)+d.total_inbound_quantity AS available_for_sale_quantity,
       r.ledger_opening_value+COALESCE(SUM(d.net_movement_value) OVER(PARTITION BY d.warehouse_id,d.location_id,d.product_id ORDER BY d.reporting_date ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING),0)+d.total_inbound_value AS available_for_sale_value,
       d.fulfilled_sold_quantity,d.fulfilled_sold_value,d.transfer_out_quantity,d.transfer_out_value,
       d.supplier_return_quantity,d.supplier_return_value,d.adjustment_out_quantity,d.adjustment_out_value,
       d.other_outbound_quantity,d.other_outbound_value,d.total_outbound_quantity,d.total_outbound_value,
       d.fulfilled_sold_quantity-d.sales_return_quantity AS net_sold_quantity,
       d.fulfilled_sold_value-d.sales_return_value AS net_sold_value,
       d.net_movement_quantity,d.net_movement_value,
       r.ledger_opening_quantity+SUM(d.net_movement_quantity) OVER(PARTITION BY d.warehouse_id,d.location_id,d.product_id ORDER BY d.reporting_date ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS closing_stock_quantity,
       r.ledger_opening_value+SUM(d.net_movement_value) OVER(PARTITION BY d.warehouse_id,d.location_id,d.product_id ORDER BY d.reporting_date ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS closing_stock_value,
       r.current_quantity_on_hand,r.current_inventory_value,r.quantity_reconciliation_variance,r.value_reconciliation_variance,
       d.last_occurred_at,d.last_created_at,'ERP_LIVE' AS SourceSystem
FROM (
    SELECT company_id,transaction_date AS reporting_date,warehouse_id,pbi_shop_id,
           location_id,product_id,pbi_product_id,
           SUM(grv_receipt_quantity) AS grv_receipt_quantity,SUM(grv_receipt_value) AS grv_receipt_value,
           SUM(transfer_in_quantity) AS transfer_in_quantity,SUM(transfer_in_value) AS transfer_in_value,
           SUM(other_inbound_quantity) AS other_inbound_quantity,SUM(other_inbound_value) AS other_inbound_value,
           SUM(sales_return_quantity) AS sales_return_quantity,SUM(sales_return_value) AS sales_return_value,
           SUM(adjustment_in_quantity) AS adjustment_in_quantity,SUM(adjustment_in_value) AS adjustment_in_value,
           SUM(total_inbound_quantity) AS total_inbound_quantity,SUM(total_inbound_value) AS total_inbound_value,
           SUM(fulfilled_sold_quantity) AS fulfilled_sold_quantity,SUM(fulfilled_sold_value) AS fulfilled_sold_value,
           SUM(transfer_out_quantity) AS transfer_out_quantity,SUM(transfer_out_value) AS transfer_out_value,
           SUM(supplier_return_quantity) AS supplier_return_quantity,SUM(supplier_return_value) AS supplier_return_value,
           SUM(adjustment_out_quantity) AS adjustment_out_quantity,SUM(adjustment_out_value) AS adjustment_out_value,
           SUM(CASE WHEN flow_type='OTHER_OUT' THEN total_outbound_quantity ELSE 0 END) AS other_outbound_quantity,
           SUM(CASE WHEN flow_type='OTHER_OUT' THEN total_outbound_value ELSE 0 END) AS other_outbound_value,
           SUM(total_outbound_quantity) AS total_outbound_quantity,SUM(total_outbound_value) AS total_outbound_value,
           SUM(quantity_delta) AS net_movement_quantity,SUM(value_delta) AS net_movement_value,
           MAX(occurred_at) AS last_occurred_at,
           MAX(created_at) AS last_created_at
    FROM vw_powerbi_inventory_movements
    GROUP BY company_id,transaction_date,warehouse_id,pbi_shop_id,location_id,product_id,pbi_product_id
) d
INNER JOIN vw_powerbi_inventory_balance_reconciliation r
  ON r.company_id=d.company_id AND r.warehouse_id=d.warehouse_id
 AND r.location_id=d.location_id AND r.product_id=d.product_id
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_receipts AS
SELECT r.company_id,r.goods_receipt_id,r.receipt_number,r.purchase_order_id,
       r.warehouse_id,w.pbi_shop_id,r.destination_location_id,l.goods_receipt_line_id,
       l.purchase_order_line_id,l.product_id,p.pbi_product_id,p.sku,p.product_name,
       l.quantity,l.unit_cost,l.line_value,r.supplier_name,r.supplier_reference,
       r.receipt_date,r.currency,r.status,CASE WHEN r.status='posted' THEN 1 ELSE 0 END AS is_posted,
       r.posted_at,r.created_at,r.updated_at,'ERP_LIVE' AS SourceSystem
FROM inventory_goods_receipts r
INNER JOIN inventory_goods_receipt_lines l
  ON l.company_id=r.company_id AND l.goods_receipt_id=r.goods_receipt_id
INNER JOIN vw_powerbi_warehouses w ON w.warehouse_id=r.warehouse_id
INNER JOIN vw_powerbi_products p ON p.product_id=l.product_id
WHERE r.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_warehouse_cluster_bridge AS
SELECT w.company_id,w.warehouse_id,w.pbi_shop_id,m.cluster_id,
       c.code AS cluster_code,c.name AS cluster_name,
       GREATEST(m.updated_at,c.updated_at) AS updated_at,'ERP_LIVE' AS SourceSystem
FROM vw_powerbi_warehouses w
INNER JOIN bi_warehouse_cluster_map m
  ON m.warehouse_id=w.warehouse_id AND m.active=1
INNER JOIN bi_clusters c
  ON c.cluster_id=m.cluster_id AND c.company_id=w.company_id AND c.active=1
WHERE w.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_warehouse_territory_bridge AS
SELECT w.company_id,w.warehouse_id,w.pbi_shop_id,m.territory_id,
       t.code AS territory_code,t.name AS territory_name,
       GREATEST(m.updated_at,t.updated_at) AS updated_at,'ERP_LIVE' AS SourceSystem
FROM vw_powerbi_warehouses w
INNER JOIN bi_warehouse_territory_map m
  ON m.warehouse_id=w.warehouse_id AND m.active=1
INNER JOIN sales_territories t
  ON t.territory_id=m.territory_id AND t.company_id=w.company_id
 AND t.active=TRUE AND t.deleted_at IS NULL
WHERE w.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_shop_hierarchy AS
SELECT w.company_id,w.warehouse_id,w.pbi_shop_id,w.shop_code,w.shop_name,
       w.shop_name AS shop_manager_label,c.cluster_id,c.cluster_code,c.cluster_name,
       t.territory_id,t.territory_code,t.territory_name AS region_name,
       w.active AS shop_active,
       GREATEST(w.updated_at,COALESCE(c.updated_at,'1970-01-01'),COALESCE(t.updated_at,'1970-01-01')) AS updated_at,
       'ERP_LIVE' AS SourceSystem
FROM vw_powerbi_warehouses w
LEFT JOIN vw_powerbi_warehouse_cluster_bridge c ON c.warehouse_id=w.warehouse_id
LEFT JOIN vw_powerbi_warehouse_territory_bridge t ON t.warehouse_id=w.warehouse_id
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_sales_payments AS
SELECT p.company_id,p.payment_id,p.order_id,o.order_number,o.warehouse_id,
       o.pbi_shop_id,h.shop_name,h.shop_manager_label,h.cluster_id,h.cluster_code,h.cluster_name,
       h.territory_id,h.territory_code,h.region_name,
       o.agent_id,o.agent_code,o.agent_name,o.dsa_dsp_role,a.employee_id,
       p.receipt_number,p.payment_date,p.amount,p.payment_method,p.reference_number,
       p.recorded_by,p.created_at,'ERP_LIVE' AS SourceSystem
FROM sales_payments p
INNER JOIN vw_powerbi_sales_orders o ON o.order_id=p.order_id
INNER JOIN vw_powerbi_shop_hierarchy h ON h.warehouse_id=o.warehouse_id
LEFT JOIN sales_agents a ON a.company_id=p.company_id AND a.agent_id=o.agent_id
WHERE p.company_id=2
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_cash_deposits AS
SELECT s.company_id,s.settlement_id,s.settlement_number,s.bank_account_id,
       s.currency,s.expected_amount,s.confirmed_amount,s.variance_amount,
       s.remaining_amount,s.reconciliation_status,s.workflow_status,
       bc.confirmation_id,bc.bank_reference,bc.transaction_date,
       bc.confirmed_amount AS bank_confirmed_amount,bc.source AS confirmation_source,
       s.created_at,s.updated_at,bc.created_at AS confirmation_created_at,
       'ERP_LIVE' AS SourceSystem
FROM sales_settlements s
LEFT JOIN bank_confirmations bc
  ON bc.company_id=s.company_id AND bc.settlement_id=s.settlement_id
WHERE s.company_id=2
SQL,
    ],
];
