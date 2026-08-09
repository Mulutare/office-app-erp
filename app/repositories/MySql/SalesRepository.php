<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\SalesRepository as SalesRepositoryContract;
use PDO;
use RuntimeException;
use Throwable;

final class SalesRepository extends MySqlRepository implements SalesRepositoryContract
{
    public function dashboard(int $companyId): array
    {
        $statement = $this->connection()->prepare(
            "SELECT
                COUNT(*) AS order_count,
                COALESCE(SUM(CASE WHEN status IN ('approved','confirmed','fulfilled','partially_paid','paid') THEN total_amount ELSE 0 END), 0) AS sales_total,
                COALESCE(SUM(CASE WHEN status IN ('approved','confirmed','fulfilled','partially_paid') THEN total_amount - paid_amount ELSE 0 END), 0) AS receivable_total,
                COALESCE(SUM(CASE WHEN due_date < CURRENT_DATE AND status IN ('approved','confirmed','fulfilled','partially_paid') THEN total_amount - paid_amount ELSE 0 END), 0) AS overdue_total
             FROM sales_orders
             WHERE company_id = :company_id AND deleted_at IS NULL"
        );
        $statement->execute(['company_id' => $companyId]);
        $summary = $statement->fetch(PDO::FETCH_ASSOC);

        $commission = $this->connection()->prepare(
            "SELECT COALESCE(SUM(commission_amount), 0)
             FROM sales_commissions
             WHERE company_id = :company_id AND status IN ('accrued','approved')"
        );
        $commission->execute(['company_id' => $companyId]);

        return [
            'orderCount' => (int) ($summary['order_count'] ?? 0),
            'salesTotal' => (float) ($summary['sales_total'] ?? 0),
            'receivableTotal' => (float) ($summary['receivable_total'] ?? 0),
            'overdueTotal' => (float) ($summary['overdue_total'] ?? 0),
            'commissionTotal' => (float) $commission->fetchColumn(),
        ];
    }

    public function orders(int $companyId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));
        $statement = $this->connection()->prepare(
            "SELECT orders.*, customers.name AS customer_name,
                    agents.name AS agent_name,
                    (orders.total_amount - orders.paid_amount) AS balance_due,
                    (SELECT MIN(ip.picking_id) FROM inventory_pickings ip
                     WHERE ip.company_id=orders.company_id AND ip.sales_order_id=orders.order_id
                       AND ip.picking_type='delivery' AND ip.status<>'cancelled') AS delivery_picking_id
             FROM sales_orders orders
             INNER JOIN sales_customers customers ON customers.customer_id = orders.customer_id
             LEFT JOIN sales_agents agents ON agents.agent_id = orders.agent_id
             WHERE orders.company_id = :company_id AND orders.deleted_at IS NULL
             ORDER BY orders.order_date DESC, orders.order_id DESC
             LIMIT {$limit}"
        );
        $statement->execute(['company_id' => $companyId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function orderDetail(int $companyId, int $orderId): ?array
    {
        $header = $this->connection()->prepare(
            'SELECT o.*, c.name customer_name, c.address customer_address,
                    a.name agent_name, q.quotation_id, q.quotation_number, q.billing_address,
                    q.delivery_address, q.payment_terms_days, q.pricelist_id,
                    q.team_id, t.name team_name, p.name pricelist_name
             FROM sales_orders o
             INNER JOIN sales_customers c ON c.customer_id=o.customer_id
             LEFT JOIN sales_agents a ON a.agent_id=o.agent_id
             LEFT JOIN sales_quotations q ON q.company_id=o.company_id AND q.sales_order_id=o.order_id
             LEFT JOIN sales_teams t ON t.company_id=q.company_id AND t.team_id=q.team_id
             LEFT JOIN sales_pricelists p ON p.company_id=q.company_id AND p.pricelist_id=q.pricelist_id
             WHERE o.company_id=:company_id AND o.order_id=:order_id AND o.deleted_at IS NULL'
        );
        $header->execute(['company_id'=>$companyId,'order_id'=>$orderId]);
        $order=$header->fetch(PDO::FETCH_ASSOC);
        if(!is_array($order))return null;
        $lines=$this->connection()->prepare(
            "SELECT l.*, p.sku, p.unit_of_measure, p.product_type,
                    COALESCE(d.reserved,0) reserved_quantity,
                    COALESCE(d.delivered,0) delivered_quantity,
                    COALESCE(d.returned,0) returned_quantity,
                    COALESCE(i.invoiced,0) invoiced_quantity
             FROM sales_order_lines l
             INNER JOIN sales_products p ON p.product_id=l.product_id
             LEFT JOIN (
                SELECT pl.company_id,ip.sales_order_id,pl.product_id,
                       SUM(CASE WHEN ip.status IN('ready','waiting','confirmed','assigned') THEN pl.reserved_quantity ELSE 0 END) reserved,
                       SUM(CASE WHEN ip.status IN('partially_done','done') THEN pl.completed_quantity ELSE 0 END) delivered,
                       SUM(CASE WHEN ip.status IN('partially_done','done') THEN pl.returned_quantity ELSE 0 END) returned
                FROM inventory_picking_lines pl INNER JOIN inventory_pickings ip ON ip.company_id=pl.company_id AND ip.picking_id=pl.picking_id
                WHERE ip.picking_type='delivery' GROUP BY pl.company_id,ip.sales_order_id,pl.product_id
             ) d ON d.company_id=l.company_id AND d.sales_order_id=l.order_id AND d.product_id=l.product_id
             LEFT JOIN (
                SELECT il.company_id,il.sales_order_line_id,SUM(il.quantity) invoiced
                FROM finance_invoice_lines il INNER JOIN finance_invoices fi ON fi.company_id=il.company_id AND fi.invoice_id=il.invoice_id
                WHERE fi.document_type='customer_invoice' AND fi.status IN('draft','posted') GROUP BY il.company_id,il.sales_order_line_id
             ) i ON i.company_id=l.company_id AND i.sales_order_line_id=l.order_line_id
             WHERE l.company_id=:company_id AND l.order_id=:order_id ORDER BY l.order_line_id"
        );
        $lines->execute(['company_id'=>$companyId,'order_id'=>$orderId]);
        $order['lines']=$lines->fetchAll(PDO::FETCH_ASSOC);
        $invoices=$this->connection()->prepare("SELECT invoice_id,invoice_number,document_type,status,payment_status,total_amount,residual_amount FROM finance_invoices WHERE company_id=:company_id AND sales_order_id=:order_id AND document_type IN('customer_invoice','customer_credit') AND status<>'cancelled' ORDER BY invoice_id");
        $invoices->execute(['company_id'=>$companyId,'order_id'=>$orderId]);$order['invoices']=$invoices->fetchAll(PDO::FETCH_ASSOC);
        $pickings=$this->connection()->prepare("SELECT picking_id,picking_number,picking_type,status FROM inventory_pickings WHERE company_id=:company_id AND sales_order_id=:order_id ORDER BY picking_id");
        $pickings->execute(['company_id'=>$companyId,'order_id'=>$orderId]);$order['pickings']=$pickings->fetchAll(PDO::FETCH_ASSOC);
        $ordered=$delivered=$returned=$invoiced=0.0;
        foreach($order['lines'] as &$line){$ordered+=(float)$line['quantity'];$delivered+=(float)$line['delivered_quantity'];$returned+=(float)$line['returned_quantity'];$invoiced+=(float)$line['invoiced_quantity'];$net=max(0,(float)$line['delivered_quantity']-(float)$line['returned_quantity']);$line['net_delivered_quantity']=$net;$line['remaining_quantity']=max(0,(float)$line['quantity']-(float)$line['delivered_quantity']);$line['invoiceable_quantity']=max(0,$net-(float)$line['invoiced_quantity']);$line['remaining_to_invoice']=max(0,(float)$line['quantity']-(float)$line['invoiced_quantity']);}unset($line);
        $creditedReturns=$this->connection()->prepare("SELECT COALESCE(SUM(fil.quantity),0) FROM finance_invoice_lines fil INNER JOIN finance_invoices fi ON fi.company_id=fil.company_id AND fi.invoice_id=fil.invoice_id WHERE fi.company_id=:company_id AND fi.sales_order_id=:order_id AND fi.document_type='customer_credit' AND fi.status='posted'");
        $creditedReturns->execute(['company_id'=>$companyId,'order_id'=>$orderId]);
        $order['credit_note_eligible_quantity']=max(0.0,$returned-(float)$creditedReturns->fetchColumn());
        $net=max(0,$delivered-$returned);$order['delivery_state']=$delivered<=0?'not_delivered':($delivered<$ordered?'partially_delivered':'delivered');$order['invoice_state']=$invoiced<=0?($net>0?'to_invoice':'nothing_to_invoice'):($invoiced<$net?'partially_invoiced':'invoiced');$salesInvoices=array_values(array_filter($order['invoices'],static fn(array $i):bool=>($i['document_type']??'customer_invoice')==='customer_invoice'));$residual=array_sum(array_map(static fn(array $i)=>(float)$i['residual_amount'],$salesInvoices));$invoiceTotal=array_sum(array_map(static fn(array $i)=>(float)$i['total_amount'],$salesInvoices));$order['payment_state']=$invoiceTotal<=0||$residual===$invoiceTotal?'unpaid':($residual>0?'partially_paid':'paid');
        return $order;
    }

    public function customers(int $companyId): array
    {
        return $this->catalogue(
            'SELECT c.*,a.name agent_name,t.name team_name,p.name pricelist_name
             FROM sales_customers c LEFT JOIN sales_agents a ON a.agent_id=c.agent_id
             LEFT JOIN sales_teams t ON t.company_id=c.company_id AND t.team_id=c.team_id
             LEFT JOIN sales_pricelists p ON p.company_id=c.company_id AND p.pricelist_id=c.pricelist_id
             WHERE c.company_id = :company_id AND c.deleted_at IS NULL ORDER BY c.active DESC,c.name',
            $companyId
        );
    }

    public function customer(int $companyId,int $customerId): ?array
    {$s=$this->connection()->prepare('SELECT c.*,a.name agent_name,t.name team_name,p.name pricelist_name FROM sales_customers c LEFT JOIN sales_agents a ON a.agent_id=c.agent_id LEFT JOIN sales_teams t ON t.company_id=c.company_id AND t.team_id=c.team_id LEFT JOIN sales_pricelists p ON p.company_id=c.company_id AND p.pricelist_id=c.pricelist_id WHERE c.company_id=:company_id AND c.customer_id=:id AND c.deleted_at IS NULL');$s->execute(['company_id'=>$companyId,'id'=>$customerId]);$row=$s->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;}

    public function products(int $companyId): array
    {
        return $this->catalogue(
            'SELECT product_id, sku, name, category, product_type, unit_of_measure, unit_price, commission_rate, serial_tracking,active
             FROM sales_products WHERE company_id = :company_id AND deleted_at IS NULL ORDER BY active DESC,name',
            $companyId
        );
    }

    public function product(int $companyId,int $productId): ?array
    {$s=$this->connection()->prepare('SELECT p.*,COALESCE(SUM(b.quantity_on_hand*b.average_unit_cost)/NULLIF(SUM(b.quantity_on_hand),0),0) weighted_average_cost,COALESCE(SUM(b.quantity_on_hand),0) quantity_on_hand FROM sales_products p LEFT JOIN inventory_stock_balances b ON b.company_id=p.company_id AND b.product_id=p.product_id LEFT JOIN inventory_warehouse_locations l ON l.company_id=b.company_id AND l.location_id=b.location_id AND l.location_usage=\'internal\' WHERE p.company_id=:company_id AND p.product_id=:id AND p.deleted_at IS NULL AND (b.stock_balance_id IS NULL OR l.location_id IS NOT NULL) GROUP BY p.product_id');$s->execute(['company_id'=>$companyId,'id'=>$productId]);$row=$s->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;}

    public function pricelists(int $companyId): array
    {
        return $this->catalogue("SELECT p.*,(SELECT COUNT(*) FROM sales_pricelist_rules r WHERE r.company_id=p.company_id AND r.pricelist_id=p.pricelist_id AND r.active=TRUE) rule_count FROM sales_pricelists p WHERE p.company_id=:company_id ORDER BY p.active DESC,p.name",$companyId);
    }

    public function teams(int $companyId): array
    {
        return $this->catalogue("SELECT t.*,a.name leader_name,(SELECT COUNT(*) FROM sales_team_members m WHERE m.company_id=t.company_id AND m.team_id=t.team_id) member_count FROM sales_teams t LEFT JOIN sales_agents a ON a.agent_id=t.leader_agent_id WHERE t.company_id=:company_id ORDER BY t.active DESC,t.name",$companyId);
    }

    public function pricelist(int $companyId,int $pricelistId): ?array
    {
        $s=$this->connection()->prepare('SELECT * FROM sales_pricelists WHERE company_id=:company_id AND pricelist_id=:id');$s->execute(['company_id'=>$companyId,'id'=>$pricelistId]);$p=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($p))return null;$r=$this->connection()->prepare('SELECT r.*,p.sku,p.name product_name FROM sales_pricelist_rules r LEFT JOIN sales_products p ON p.company_id=r.company_id AND p.product_id=r.product_id WHERE r.company_id=:company_id AND r.pricelist_id=:id ORDER BY r.active DESC,r.priority,r.rule_id');$r->execute(['company_id'=>$companyId,'id'=>$pricelistId]);$p['rules']=$r->fetchAll(PDO::FETCH_ASSOC);return $p;
    }

    public function team(int $companyId,int $teamId): ?array
    {
        $s=$this->connection()->prepare('SELECT t.*,a.name leader_name,tr.name territory_name FROM sales_teams t LEFT JOIN sales_agents a ON a.agent_id=t.leader_agent_id LEFT JOIN sales_territories tr ON tr.territory_id=t.territory_id WHERE t.company_id=:company_id AND t.team_id=:id');$s->execute(['company_id'=>$companyId,'id'=>$teamId]);$t=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($t))return null;$m=$this->connection()->prepare('SELECT a.agent_id,a.agent_code,a.name FROM sales_team_members tm INNER JOIN sales_agents a ON a.company_id=tm.company_id AND a.agent_id=tm.agent_id WHERE tm.company_id=:company_id AND tm.team_id=:id ORDER BY a.name');$m->execute(['company_id'=>$companyId,'id'=>$teamId]);$t['members']=$m->fetchAll(PDO::FETCH_ASSOC);return $t;
    }

    public function quotations(int $companyId): array
    {
        return $this->catalogue("SELECT q.*,c.name customer_name,a.name agent_name,t.name team_name,p.name pricelist_name,o.order_number FROM sales_quotations q INNER JOIN sales_customers c ON c.customer_id=q.customer_id LEFT JOIN sales_agents a ON a.agent_id=q.agent_id LEFT JOIN sales_teams t ON t.company_id=q.company_id AND t.team_id=q.team_id LEFT JOIN sales_pricelists p ON p.company_id=q.company_id AND p.pricelist_id=q.pricelist_id LEFT JOIN sales_orders o ON o.order_id=q.sales_order_id WHERE q.company_id=:company_id ORDER BY q.quotation_date DESC,q.quotation_id DESC",$companyId);
    }

    public function quotation(int $companyId, int $quotationId): ?array
    {
        $statement = $this->connection()->prepare(
            'SELECT q.*, c.name customer_name, a.name agent_name,
                    t.name team_name, p.name pricelist_name, o.order_number,
                    o.status order_status
             FROM sales_quotations q
             INNER JOIN sales_customers c ON c.customer_id = q.customer_id
             LEFT JOIN sales_agents a ON a.agent_id = q.agent_id
             LEFT JOIN sales_teams t ON t.company_id = q.company_id AND t.team_id = q.team_id
             LEFT JOIN sales_pricelists p ON p.company_id = q.company_id AND p.pricelist_id = q.pricelist_id
             LEFT JOIN sales_orders o ON o.order_id = q.sales_order_id
             WHERE q.company_id = :company_id AND q.quotation_id = :quotation_id'
        );
        $statement->execute(['company_id' => $companyId, 'quotation_id' => $quotationId]);
        $quotation = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($quotation)) {
            return null;
        }
        $lines = $this->connection()->prepare(
            'SELECT l.*, p.sku, p.name product_name
             FROM sales_quotation_lines l
             INNER JOIN sales_products p ON p.product_id = l.product_id
             WHERE l.company_id = :company_id AND l.quotation_id = :quotation_id
             ORDER BY l.sequence'
        );
        $lines->execute(['company_id' => $companyId, 'quotation_id' => $quotationId]);
        $quotation['lines'] = $lines->fetchAll(PDO::FETCH_ASSOC);
        $quotation['effective_status'] = $quotation['status'] === 'draft'
            && $quotation['expiration_date'] !== null
            && $quotation['expiration_date'] < date('Y-m-d')
                ? 'expired' : $quotation['status'];
        return $quotation;
    }

    public function createPricelist(int $companyId,array $values,int $actorId): int
    {
        $c=$this->connection();$c->beginTransaction();try{$s=$c->prepare('INSERT INTO sales_pricelists(company_id,name,currency,valid_from,valid_to,created_by) VALUES(:company_id,:name,:currency,:valid_from,:valid_to,:actor)');$s->execute(['company_id'=>$companyId,'name'=>$values['name'],'currency'=>$values['currency'],'valid_from'=>$values['valid_from'],'valid_to'=>$values['valid_to'],'actor'=>$actorId]);$id=(int)$c->lastInsertId();$r=$c->prepare('INSERT INTO sales_pricelist_rules(company_id,pricelist_id,product_id,category,minimum_quantity,calculation,fixed_price,percentage_adjustment,valid_from,valid_to,priority) VALUES(:company_id,:id,:product_id,:category,:minimum_quantity,:calculation,:fixed_price,:percentage_adjustment,:rule_from,:rule_to,:priority)');$r->execute(['company_id'=>$companyId,'id'=>$id,'product_id'=>$values['product_id'],'category'=>$values['category'],'minimum_quantity'=>$values['minimum_quantity'],'calculation'=>$values['calculation'],'fixed_price'=>$values['fixed_price'],'percentage_adjustment'=>$values['percentage_adjustment'],'rule_from'=>$values['rule_from'],'rule_to'=>$values['rule_to'],'priority'=>$values['priority']]);$c->commit();return $id;}catch(Throwable $e){if($c->inTransaction())$c->rollBack();throw $e;}
    }

    public function updatePricelist(int $companyId,int $pricelistId,array $values): void
    {$s=$this->connection()->prepare('UPDATE sales_pricelists SET name=:name,currency=:currency,valid_from=:valid_from,valid_to=:valid_to WHERE company_id=:company_id AND pricelist_id=:id');$s->execute(['name'=>$values['name'],'currency'=>$values['currency'],'valid_from'=>$values['valid_from'],'valid_to'=>$values['valid_to'],'company_id'=>$companyId,'id'=>$pricelistId]);if($s->rowCount()===0&&$this->pricelist($companyId,$pricelistId)===null)throw new RuntimeException('Pricelist was not found.');}

    public function createPricelistRule(int $companyId,int $pricelistId,array $v): int
    {$s=$this->connection()->prepare('INSERT INTO sales_pricelist_rules(company_id,pricelist_id,product_id,category,minimum_quantity,calculation,fixed_price,percentage_adjustment,valid_from,valid_to,priority) SELECT :company_id,pricelist_id,:product_id,:category,:minimum_quantity,:calculation,:fixed_price,:percentage_adjustment,:valid_from,:valid_to,:priority FROM sales_pricelists WHERE company_id=:owner AND pricelist_id=:id');$s->execute(['company_id'=>$companyId,'product_id'=>$v['product_id'],'category'=>$v['category'],'minimum_quantity'=>$v['minimum_quantity'],'calculation'=>$v['calculation'],'fixed_price'=>$v['fixed_price'],'percentage_adjustment'=>$v['percentage_adjustment'],'valid_from'=>$v['rule_from'],'valid_to'=>$v['rule_to'],'priority'=>$v['priority'],'owner'=>$companyId,'id'=>$pricelistId]);if($s->rowCount()!==1)throw new RuntimeException('Pricelist was not found.');return(int)$this->connection()->lastInsertId();}

    public function setPricelistActive(int $companyId,int $pricelistId,bool $active): void
    {$s=$this->connection()->prepare('UPDATE sales_pricelists SET active=:active WHERE company_id=:company_id AND pricelist_id=:id');$s->execute(['active'=>$active?1:0,'company_id'=>$companyId,'id'=>$pricelistId]);if($s->rowCount()===0&&$this->pricelist($companyId,$pricelistId)===null)throw new RuntimeException('Pricelist was not found.');}

    public function resolvePrice(int $companyId, ?int $pricelistId, int $productId, float $quantity, string $date, float $basePrice): float
    {
        if ($pricelistId === null) {
            return round($basePrice, 2);
        }

        $statement = $this->connection()->prepare(
            'SELECT r.calculation, r.fixed_price, r.percentage_adjustment
             FROM sales_pricelist_rules r
             INNER JOIN sales_pricelists p
                ON p.company_id = r.company_id AND p.pricelist_id = r.pricelist_id
             INNER JOIN sales_products product
                ON product.company_id = r.company_id AND product.product_id = :product_id
             WHERE r.company_id = :company_id
               AND r.pricelist_id = :pricelist_id
               AND p.active = TRUE AND r.active = TRUE
               AND r.minimum_quantity <= :quantity
               AND (r.product_id = :exact_product_id OR (r.product_id IS NULL AND (r.category IS NULL OR r.category = product.category)))
               AND (p.valid_from IS NULL OR p.valid_from <= :price_date)
               AND (p.valid_to IS NULL OR p.valid_to >= :price_date_to)
               AND (r.valid_from IS NULL OR r.valid_from <= :rule_date)
               AND (r.valid_to IS NULL OR r.valid_to >= :rule_date_to)
             ORDER BY (r.product_id IS NOT NULL) DESC,
                      (r.category IS NOT NULL) DESC,
                      r.minimum_quantity DESC,
                      r.priority ASC,
                      r.rule_id ASC
             LIMIT 1'
        );
        $statement->execute([
            'product_id' => $productId,
            'company_id' => $companyId,
            'pricelist_id' => $pricelistId,
            'quantity' => $quantity,
            'exact_product_id' => $productId,
            'price_date' => $date,
            'price_date_to' => $date,
            'rule_date' => $date,
            'rule_date_to' => $date,
        ]);
        $rule = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($rule)) {
            return round($basePrice, 2);
        }

        $resolved = $rule['calculation'] === 'fixed'
            ? (float) $rule['fixed_price']
            : $basePrice * (1 + ((float) $rule['percentage_adjustment'] / 100));

        return round(max(0, $resolved), 2);
    }

    public function updatePricelistRule(int $companyId,int $pricelistId,int $ruleId,array $v): void
    {$s=$this->connection()->prepare('UPDATE sales_pricelist_rules SET product_id=:product_id,category=:category,minimum_quantity=:minimum_quantity,calculation=:calculation,fixed_price=:fixed_price,percentage_adjustment=:percentage_adjustment,valid_from=:valid_from,valid_to=:valid_to,priority=:priority WHERE company_id=:company_id AND pricelist_id=:pricelist_id AND rule_id=:rule_id');$s->execute(['product_id'=>$v['product_id'],'category'=>$v['category'],'minimum_quantity'=>$v['minimum_quantity'],'calculation'=>$v['calculation'],'fixed_price'=>$v['fixed_price'],'percentage_adjustment'=>$v['percentage_adjustment'],'valid_from'=>$v['rule_from'],'valid_to'=>$v['rule_to'],'priority'=>$v['priority'],'company_id'=>$companyId,'pricelist_id'=>$pricelistId,'rule_id'=>$ruleId]);if($s->rowCount()===0){$check=$this->connection()->prepare('SELECT COUNT(*) FROM sales_pricelist_rules WHERE company_id=:company_id AND pricelist_id=:pricelist_id AND rule_id=:rule_id');$check->execute(['company_id'=>$companyId,'pricelist_id'=>$pricelistId,'rule_id'=>$ruleId]);if((int)$check->fetchColumn()!==1)throw new RuntimeException('Pricelist rule was not found.');}}

    public function setPricelistRuleActive(int $companyId,int $pricelistId,int $ruleId,bool $active): void
    {$s=$this->connection()->prepare('UPDATE sales_pricelist_rules SET active=:active WHERE company_id=:company_id AND pricelist_id=:pricelist_id AND rule_id=:rule_id');$s->execute(['active'=>$active?1:0,'company_id'=>$companyId,'pricelist_id'=>$pricelistId,'rule_id'=>$ruleId]);if($s->rowCount()===0){$check=$this->connection()->prepare('SELECT COUNT(*) FROM sales_pricelist_rules WHERE company_id=:company_id AND pricelist_id=:pricelist_id AND rule_id=:rule_id');$check->execute(['company_id'=>$companyId,'pricelist_id'=>$pricelistId,'rule_id'=>$ruleId]);if((int)$check->fetchColumn()!==1)throw new RuntimeException('Pricelist rule was not found.');}}

    public function createTeam(int $companyId,array $values,array $memberIds,int $actorId): int
    {
        $c=$this->connection();$c->beginTransaction();try{$s=$c->prepare('INSERT INTO sales_teams(company_id,name,leader_agent_id,territory_id,created_by) VALUES(:company_id,:name,:leader,:territory,:actor)');$s->execute(['company_id'=>$companyId,'name'=>$values['name'],'leader'=>$values['leader_agent_id'],'territory'=>$values['territory_id'],'actor'=>$actorId]);$id=(int)$c->lastInsertId();$m=$c->prepare('INSERT INTO sales_team_members(company_id,team_id,agent_id,joined_at) SELECT :company_id,:team_id,agent_id,NOW() FROM sales_agents WHERE company_id=:agent_company AND agent_id=:agent_id');foreach($memberIds as $agentId)$m->execute(['company_id'=>$companyId,'team_id'=>$id,'agent_company'=>$companyId,'agent_id'=>$agentId]);$c->commit();return $id;}catch(Throwable $e){if($c->inTransaction())$c->rollBack();throw $e;}
    }

    public function updateTeam(int $companyId,int $teamId,array $values,array $memberIds): void
    {$c=$this->connection();$c->beginTransaction();try{$s=$c->prepare('UPDATE sales_teams SET name=:name,leader_agent_id=:leader,territory_id=:territory WHERE company_id=:company_id AND team_id=:id');$s->execute(['name'=>$values['name'],'leader'=>$values['leader_agent_id'],'territory'=>$values['territory_id'],'company_id'=>$companyId,'id'=>$teamId]);if($s->rowCount()===0&&$this->team($companyId,$teamId)===null)throw new RuntimeException('Sales team was not found.');$d=$c->prepare('DELETE FROM sales_team_members WHERE company_id=:company_id AND team_id=:id');$d->execute(['company_id'=>$companyId,'id'=>$teamId]);$m=$c->prepare('INSERT INTO sales_team_members(company_id,team_id,agent_id,joined_at) SELECT :company_id,:team_id,agent_id,NOW() FROM sales_agents WHERE company_id=:owner AND agent_id=:agent_id');foreach(array_unique($memberIds) as $agentId)$m->execute(['company_id'=>$companyId,'team_id'=>$teamId,'owner'=>$companyId,'agent_id'=>$agentId]);$c->commit();}catch(Throwable $e){if($c->inTransaction())$c->rollBack();throw $e;}}

    public function setTeamActive(int $companyId,int $teamId,bool $active): void
    {$s=$this->connection()->prepare('UPDATE sales_teams SET active=:active WHERE company_id=:company_id AND team_id=:id');$s->execute(['active'=>$active?1:0,'company_id'=>$companyId,'id'=>$teamId]);if($s->rowCount()===0&&$this->team($companyId,$teamId)===null)throw new RuntimeException('Sales team was not found.');}

    public function createQuotation(int $companyId,array $q,array $lines,int $actorId): int
    {
        $c=$this->connection();$c->beginTransaction();try{$s=$c->prepare('INSERT INTO sales_quotations(company_id,quotation_number,customer_id,agent_id,team_id,pricelist_id,quotation_date,expiration_date,payment_terms_days,currency,billing_address,delivery_address,notes,status,untaxed_amount,tax_amount,total_amount,created_by,updated_by) VALUES(:company_id,:quotation_number,:customer_id,:agent_id,:team_id,:pricelist_id,:quotation_date,:expiration_date,:payment_terms_days,:currency,:billing_address,:delivery_address,:notes,\'draft\',:untaxed_amount,:tax_amount,:total_amount,:actor,:actor2)');$s->execute($q+['company_id'=>$companyId,'actor'=>$actorId,'actor2'=>$actorId]);$id=(int)$c->lastInsertId();$ls=$c->prepare('INSERT INTO sales_quotation_lines(company_id,quotation_id,sequence,product_id,description,quantity,unit_of_measure,unit_price,discount_amount,tax_rate,untaxed_amount,tax_amount,line_total) VALUES(:company_id,:quotation_id,:sequence,:product_id,:description,:quantity,:unit_of_measure,:unit_price,:discount_amount,:tax_rate,:untaxed_amount,:tax_amount,:line_total)');foreach($lines as $line)$ls->execute($line+['company_id'=>$companyId,'quotation_id'=>$id]);$c->commit();return $id;}catch(Throwable $e){if($c->inTransaction())$c->rollBack();throw $e;}
    }

    public function updateQuotation(int $companyId, int $quotationId, array $q, array $lines, int $actorId): void
    {
        $connection = $this->connection();
        $ownsTransaction = !$connection->inTransaction();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }
        try {
            $lock = $connection->prepare(
                'SELECT status FROM sales_quotations
                 WHERE company_id = :company_id AND quotation_id = :quotation_id FOR UPDATE'
            );
            $lock->execute(['company_id' => $companyId, 'quotation_id' => $quotationId]);
            $status = $lock->fetchColumn();
            if ($status === false) {
                throw new RuntimeException('Quotation was not found.');
            }
            if ($status !== 'draft') {
                throw new RuntimeException('Only draft quotations may be edited.');
            }
            $update = $connection->prepare(
                'UPDATE sales_quotations SET customer_id=:customer_id, agent_id=:agent_id,
                    team_id=:team_id, pricelist_id=:pricelist_id, quotation_date=:quotation_date,
                    expiration_date=:expiration_date, payment_terms_days=:payment_terms_days,
                    currency=:currency, billing_address=:billing_address,
                    delivery_address=:delivery_address, notes=:notes,
                    untaxed_amount=:untaxed_amount, tax_amount=:tax_amount,
                    total_amount=:total_amount, updated_by=:actor
                 WHERE company_id=:company_id AND quotation_id=:quotation_id'
            );
            $update->execute($q + [
                'actor' => $actorId,
                'company_id' => $companyId,
                'quotation_id' => $quotationId,
            ]);
            $delete = $connection->prepare(
                'DELETE FROM sales_quotation_lines WHERE company_id=:company_id AND quotation_id=:quotation_id'
            );
            $delete->execute(['company_id' => $companyId, 'quotation_id' => $quotationId]);
            $insert = $connection->prepare(
                'INSERT INTO sales_quotation_lines(company_id,quotation_id,sequence,product_id,description,quantity,unit_of_measure,unit_price,discount_amount,tax_rate,untaxed_amount,tax_amount,line_total)
                 VALUES(:company_id,:quotation_id,:sequence,:product_id,:description,:quantity,:unit_of_measure,:unit_price,:discount_amount,:tax_rate,:untaxed_amount,:tax_amount,:line_total)'
            );
            foreach ($lines as $line) {
                $insert->execute($line + ['company_id' => $companyId, 'quotation_id' => $quotationId]);
            }
            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    public function transitionQuotation(int $companyId,int $quotationId,string $action,int $actorId): array
    {
        $c=$this->connection();$c->beginTransaction();try{$s=$c->prepare('SELECT * FROM sales_quotations WHERE company_id=:company_id AND quotation_id=:id FOR UPDATE');$s->execute(['company_id'=>$companyId,'id'=>$quotationId]);$q=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($q))throw new RuntimeException('Quotation was not found.');if($action==='confirm'&&$q['status']==='confirmed'){$c->commit();return ['orderId'=>(int)$q['sales_order_id'],'replayed'=>true];}$allowed=['send'=>['draft'],'confirm'=>['draft','sent'],'cancel'=>['draft','sent']];if(!in_array($q['status'],$allowed[$action]??[],true))throw new RuntimeException('Quotation transition is not allowed from '.$q['status'].'.');if($action==='confirm'&&$q['expiration_date']!==null&&$q['expiration_date']<date('Y-m-d'))throw new RuntimeException('Expired quotation cannot be confirmed.');$status=$action==='send'?'sent':($action==='cancel'?'cancelled':'confirmed');$orderId=null;if($action==='confirm'){$lines=$c->prepare('SELECT * FROM sales_quotation_lines WHERE company_id=:company_id AND quotation_id=:id ORDER BY sequence');$lines->execute(['company_id'=>$companyId,'id'=>$quotationId]);$order=['branch_id'=>null,'customer_id'=>$q['customer_id'],'territory_id'=>null,'agent_id'=>$q['agent_id'],'order_number'=>$this->reserveDocumentNumber($companyId,null,'order'),'external_reference'=>$q['quotation_number'],'order_date'=>date('Y-m-d'),'due_date'=>date('Y-m-d',strtotime('+'.(int)$q['payment_terms_days'].' days')),'status'=>'submitted','currency'=>$q['currency'],'subtotal'=>$q['untaxed_amount'],'discount_amount'=>0,'tax_amount'=>$q['tax_amount'],'total_amount'=>$q['total_amount'],'notes'=>$q['notes'],'confirmed_at'=>null,'commission_amount'=>0];$orderLines=[];foreach($lines->fetchAll(PDO::FETCH_ASSOC) as $l)$orderLines[]=['product_id'=>$l['product_id'],'description'=>$l['description'],'quantity'=>$l['quantity'],'unit_price'=>$l['unit_price'],'discount_amount'=>$l['discount_amount'],'tax_rate'=>$l['tax_rate'],'line_total'=>$l['line_total'],'commission_rate'=>0];$orderId=$this->createOrder($companyId,$order,$orderLines,$actorId);}$u=$c->prepare('UPDATE sales_quotations SET status=:status,sales_order_id=:order_id,sent_at=CASE WHEN :sent=1 THEN NOW() ELSE sent_at END,confirmed_at=CASE WHEN :confirmed=1 THEN NOW() ELSE confirmed_at END,cancelled_at=CASE WHEN :cancelled=1 THEN NOW() ELSE cancelled_at END,updated_by=:actor WHERE company_id=:company_id AND quotation_id=:id');$u->execute(['status'=>$status,'order_id'=>$orderId,'sent'=>$action==='send'?1:0,'confirmed'=>$action==='confirm'?1:0,'cancelled'=>$action==='cancel'?1:0,'actor'=>$actorId,'company_id'=>$companyId,'id'=>$quotationId]);$c->commit();return ['orderId'=>$orderId,'replayed'=>false];}catch(Throwable $e){if($c->inTransaction())$c->rollBack();throw $e;}
    }

    public function activateOrderFromConfirmedQuotation(
        int $companyId, int $quotationId, int $orderId, int $actorId
    ): void {
        $statement = $this->connection()->prepare(
            "UPDATE sales_orders o
             INNER JOIN sales_quotations q
                ON q.company_id=o.company_id AND q.sales_order_id=o.order_id
             SET o.status='approved', o.confirmed_at=COALESCE(o.confirmed_at,NOW()),
                 o.approved_by=:actor, o.updated_at=NOW()
             WHERE o.company_id=:company_id AND o.order_id=:order_id
               AND q.quotation_id=:quotation_id AND q.status='confirmed'
               AND o.status='submitted'"
        );
        $statement->execute([
            'actor' => $actorId, 'company_id' => $companyId,
            'order_id' => $orderId, 'quotation_id' => $quotationId,
        ]);
    }

    public function agents(int $companyId): array
    {
        return $this->catalogue(
            'SELECT agent_id, agent_code, name, agent_type FROM sales_agents
             WHERE company_id = :company_id AND active = TRUE AND deleted_at IS NULL ORDER BY name',
            $companyId
        );
    }

    public function territories(int $companyId): array
    {
        return $this->catalogue(
            'SELECT territory_id, code, name FROM sales_territories
             WHERE company_id = :company_id AND active = TRUE AND deleted_at IS NULL ORDER BY name',
            $companyId
        );
    }

    public function targets(int $companyId): array
    {
        return $this->catalogue(
            "SELECT targets.*, territories.name AS territory_name,
                    agents.name AS agent_name,
                    COALESCE(SUM(CASE
                        WHEN orders.status IN ('approved','confirmed','fulfilled','partially_paid','paid')
                         AND orders.order_date BETWEEN targets.period_start AND targets.period_end
                        THEN orders.total_amount ELSE 0 END), 0) AS achieved_amount
             FROM sales_targets targets
             LEFT JOIN sales_territories territories ON territories.territory_id = targets.territory_id
             LEFT JOIN sales_agents agents ON agents.agent_id = targets.agent_id
             LEFT JOIN sales_orders orders
                ON orders.company_id = targets.company_id
               AND (targets.territory_id IS NULL OR orders.territory_id = targets.territory_id)
               AND (targets.agent_id IS NULL OR orders.agent_id = targets.agent_id)
               AND orders.deleted_at IS NULL
             WHERE targets.company_id = :company_id
             GROUP BY targets.target_id, targets.company_id, targets.territory_id,
                      targets.agent_id, targets.period_start, targets.period_end,
                      targets.target_amount, targets.target_quantity,
                      targets.created_by, targets.created_at,
                      territories.name, agents.name
             ORDER BY targets.period_start DESC, targets.target_id DESC",
            $companyId
        );
    }

    public function commissions(int $companyId): array
    {
        return $this->catalogue(
            "SELECT commissions.*, orders.order_number,
                    agents.agent_code, agents.name AS agent_name
             FROM sales_commissions commissions
             INNER JOIN sales_orders orders ON orders.order_id = commissions.order_id
             INNER JOIN sales_agents agents ON agents.agent_id = commissions.agent_id
             WHERE commissions.company_id = :company_id
             ORDER BY commissions.accrued_at DESC, commissions.commission_id DESC",
            $companyId
        );
    }

    public function serialNumbers(int $companyId): array
    {
        return $this->catalogue(
            "SELECT serials.*, products.sku, products.name AS product_name
             FROM sales_serial_numbers serials
             INNER JOIN sales_products products ON products.product_id = serials.product_id
             WHERE serials.company_id = :company_id
             ORDER BY serials.registered_at DESC, serials.serial_id DESC
             LIMIT 200",
            $companyId
        );
    }

    public function customerOutstanding(int $companyId, int $customerId): float
    {
        $statement = $this->connection()->prepare(
            "SELECT COALESCE(SUM(total_amount - paid_amount), 0)
             FROM sales_orders
             WHERE company_id = :company_id
               AND customer_id = :customer_id
               AND status IN ('confirmed', 'approved', 'partially_paid', 'fulfilled')
               AND deleted_at IS NULL"
        );
        $statement->execute([
            'company_id' => $companyId,
            'customer_id' => $customerId,
        ]);
        return (float) $statement->fetchColumn();
    }

    public function reserveDocumentNumber(
        int $companyId,
        ?int $branchId,
        string $documentType
    ): string {
        $prefixes = ['order' => 'SO', 'quotation' => 'QT', 'invoice' => 'INV', 'receipt' => 'RCPT', 'credit_note' => 'CN', 'return' => 'RTN'];
        if (!isset($prefixes[$documentType])) {
            throw new RuntimeException('Unsupported Sales document type.');
        }
        $connection = $this->connection();
        $ownsTransaction = !$connection->inTransaction();
        if ($ownsTransaction) { $connection->beginTransaction(); }
        try {
            $branchScope = $branchId ?? 0;
            $insert = $connection->prepare(
                'INSERT IGNORE INTO sales_document_sequences
                    (company_id, branch_id, branch_scope, document_type, prefix, next_number)
                 VALUES (:company_id, :branch_id, :branch_scope, :document_type, :prefix, 1)'
            );
            $insert->execute([
                'company_id' => $companyId, 'branch_id' => $branchId,
                'branch_scope' => $branchScope, 'document_type' => $documentType,
                'prefix' => $prefixes[$documentType],
            ]);
            $select = $connection->prepare(
                'SELECT prefix, next_number FROM sales_document_sequences
                 WHERE company_id = :company_id AND branch_scope = :branch_scope
                   AND document_type = :document_type FOR UPDATE'
            );
            $select->execute([
                'company_id' => $companyId, 'branch_scope' => $branchScope,
                'document_type' => $documentType,
            ]);
            $sequence = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($sequence)) {
                throw new RuntimeException('Sales document sequence could not be reserved.');
            }
            $number = (int) $sequence['next_number'];
            $update = $connection->prepare(
                'UPDATE sales_document_sequences SET next_number = :next_number
                 WHERE company_id = :company_id AND branch_scope = :branch_scope
                   AND document_type = :document_type'
            );
            $update->execute([
                'next_number' => $number + 1, 'company_id' => $companyId,
                'branch_scope' => $branchScope, 'document_type' => $documentType,
            ]);
            if ($ownsTransaction) { $connection->commit(); }
            return sprintf('%s-%08d', (string) $sequence['prefix'], $number);
        } catch (Throwable $exception) {
            if ($ownsTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    public function createCustomer(int $companyId, array $values, int $actorId): int
    {
        $statement = $this->connection()->prepare(
            'INSERT INTO sales_customers
                (company_id, territory_id, agent_id,pricelist_id,team_id,customer_number,name,legal_name,customer_type,tax_number,email,phone,mobile,address,street,street2,city,state_region,postal_code,country,preferred_currency,credit_mode,credit_limit,credit_status,payment_terms_days,created_by)
             VALUES
                (:company_id,:territory_id,:agent_id,:pricelist_id,:team_id,:customer_number,:name,:legal_name,:customer_type,:tax_number,:email,:phone,:mobile,:address,:street,:street2,:city,:state_region,:postal_code,:country,:preferred_currency,:credit_mode,:credit_limit,:credit_status,:payment_terms_days,:created_by)'
        );
        $statement->execute($values + ['company_id' => $companyId, 'created_by' => $actorId]);

        return (int) $this->connection()->lastInsertId();
    }

    public function updateCustomer(int $companyId,int $customerId,array $v,int $actorId): void
    {$s=$this->connection()->prepare('UPDATE sales_customers SET territory_id=:territory_id,agent_id=:agent_id,pricelist_id=:pricelist_id,team_id=:team_id,customer_number=:customer_number,name=:name,legal_name=:legal_name,customer_type=:customer_type,tax_number=:tax_number,email=:email,phone=:phone,mobile=:mobile,address=:address,street=:street,street2=:street2,city=:city,state_region=:state_region,postal_code=:postal_code,country=:country,preferred_currency=:preferred_currency,credit_mode=:credit_mode,credit_limit=:credit_limit,credit_status=:credit_status,payment_terms_days=:payment_terms_days WHERE company_id=:company_id AND customer_id=:id AND deleted_at IS NULL');$s->execute($v+['company_id'=>$companyId,'id'=>$customerId]);if($s->rowCount()===0&&$this->customer($companyId,$customerId)===null)throw new RuntimeException('Customer was not found.');}
    public function setCustomerActive(int $companyId,int $customerId,bool $active,int $actorId): void
    {$s=$this->connection()->prepare('UPDATE sales_customers SET active=:active WHERE company_id=:company_id AND customer_id=:id AND deleted_at IS NULL');$s->execute(['active'=>$active?1:0,'company_id'=>$companyId,'id'=>$customerId]);if($s->rowCount()===0&&$this->customer($companyId,$customerId)===null)throw new RuntimeException('Customer was not found.');}

    public function createProduct(int $companyId, array $values, int $actorId): int
    {
        $statement = $this->connection()->prepare(
            'INSERT INTO sales_products
                (company_id, sku, name, category, product_type, unit_of_measure, unit_price, commission_rate, serial_tracking, created_by)
             VALUES
                (:company_id, :sku, :name, :category, :product_type, :unit_of_measure, :unit_price, :commission_rate, :serial_tracking, :created_by)'
        );
        $statement->execute($values + ['company_id' => $companyId, 'created_by' => $actorId]);

        return (int) $this->connection()->lastInsertId();
    }

    public function updateProduct(int $companyId,int $productId,array $v,int $actorId): void
    {$s=$this->connection()->prepare('UPDATE sales_products SET sku=:sku,name=:name,category=:category,product_type=:product_type,unit_of_measure=:unit_of_measure,unit_price=:unit_price,commission_rate=:commission_rate,serial_tracking=:serial_tracking WHERE company_id=:company_id AND product_id=:id AND deleted_at IS NULL');$s->execute($v+['company_id'=>$companyId,'id'=>$productId]);if($s->rowCount()===0&&$this->product($companyId,$productId)===null)throw new RuntimeException('Product was not found.');}
    public function setProductActive(int $companyId,int $productId,bool $active,int $actorId): void
    {$s=$this->connection()->prepare('UPDATE sales_products SET active=:active WHERE company_id=:company_id AND product_id=:id AND deleted_at IS NULL');$s->execute(['active'=>$active?1:0,'company_id'=>$companyId,'id'=>$productId]);if($s->rowCount()===0&&$this->product($companyId,$productId)===null)throw new RuntimeException('Product was not found.');}

    public function createTerritory(int $companyId, array $values, int $actorId): int
    {
        return $this->insertCatalogue(
            'INSERT INTO sales_territories
                (company_id, branch_id, code, name, created_by)
             VALUES (:company_id, :branch_id, :code, :name, :created_by)',
            $companyId,
            $values,
            $actorId
        );
    }

    public function createAgent(int $companyId, array $values, int $actorId): int
    {
        return $this->insertCatalogue(
            'INSERT INTO sales_agents
                (company_id, territory_id, employee_id, agent_code, name, agent_type, phone, created_by)
             VALUES
                (:company_id, :territory_id, :employee_id, :agent_code, :name, :agent_type, :phone, :created_by)',
            $companyId,
            $values,
            $actorId
        );
    }

    public function createTarget(int $companyId, array $values, int $actorId): int
    {
        return $this->insertCatalogue(
            'INSERT INTO sales_targets
                (company_id, territory_id, agent_id, period_start, period_end,
                 target_amount, target_quantity, created_by)
             VALUES
                (:company_id, :territory_id, :agent_id, :period_start, :period_end,
                 :target_amount, :target_quantity, :created_by)',
            $companyId,
            $values,
            $actorId
        );
    }

    public function registerSerialNumbers(
        int $companyId,
        int $productId,
        array $serialNumbers,
        int $actorId
    ): int {
        $statement = $this->connection()->prepare(
            "INSERT INTO sales_serial_numbers
                (company_id, product_id, serial_number, status,
                 registered_by, registered_at)
             VALUES
                (:company_id, :product_id, :serial_number, 'available',
                 :registered_by, NOW())"
        );
        $created = 0;
        foreach ($serialNumbers as $serialNumber) {
            $statement->execute([
                'company_id' => $companyId,
                'product_id' => $productId,
                'serial_number' => $serialNumber,
                'registered_by' => $actorId,
            ]);
            $created++;
        }
        return $created;
    }

    public function transitionOrder(
        int $companyId,
        int $orderId,
        string $action,
        ?string $reason,
        int $actorId,
        string $idempotencyKey
    ): array {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $statement = $connection->prepare(
                'SELECT * FROM sales_orders
                 WHERE company_id = :company_id AND order_id = :order_id
                   AND deleted_at IS NULL FOR UPDATE'
            );
            $statement->execute([
                'company_id' => $companyId,
                'order_id' => $orderId,
            ]);
            $order = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order)) {
                throw new RuntimeException('Sales order was not found.');
            }
            $current = (string) $order['status'];
            $existing = $connection->prepare(
                'SELECT from_status, to_status FROM sales_order_status_history
                 WHERE company_id = :company_id AND idempotency_key = :idempotency_key'
            );
            $existing->execute(['company_id' => $companyId, 'idempotency_key' => $idempotencyKey]);
            $prior = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($prior)) {
                $connection->commit();
                return ['oldStatus' => $prior['from_status'], 'newStatus' => $prior['to_status'], 'replayed' => true];
            }
            $allowed = [
                'submit' => ['draft'],
                'approve' => ['submitted'],
                'cancel' => ['draft', 'submitted', 'approved', 'confirmed'],
                'fulfill' => ['approved', 'confirmed'],
            ];
            if (!in_array($current, $allowed[$action] ?? [], true)) {
                throw new RuntimeException(
                    'The requested order transition is not allowed from ' . $current . '.'
                );
            }
            if ($action === 'cancel' && (float) $order['paid_amount'] > 0) {
                throw new RuntimeException('A paid order requires a return or credit-note workflow.');
            }
            if ($action === 'cancel' && ($reason === null || mb_strlen($reason) < 10)) {
                throw new RuntimeException('Provide a cancellation reason of at least 10 characters.');
            }
            if ($action === 'approve' && (int) ($order['created_by'] ?? 0) === $actorId) {
                throw new RuntimeException('The order creator cannot approve the same order.');
            }
            $newStatus = match ($action) {
                'submit' => 'submitted',
                'approve' => 'approved',
                'cancel' => 'cancelled',
                'fulfill' => $current,
            };
            $update = $connection->prepare(
                "UPDATE sales_orders SET
                    status = :status,
                    submitted_at = CASE WHEN :is_submit = 1 THEN NOW() ELSE submitted_at END,
                    approved_by = CASE WHEN :is_approve = 1 THEN :actor_approve ELSE approved_by END,
                    approved_at = CASE WHEN :is_approve_time = 1 THEN NOW() ELSE approved_at END,
                    cancelled_by = CASE WHEN :is_cancel = 1 THEN :actor_cancel ELSE cancelled_by END,
                    cancelled_at = CASE WHEN :is_cancel_time = 1 THEN NOW() ELSE cancelled_at END,
                    cancellation_reason = CASE WHEN :is_cancel_reason = 1 THEN :reason ELSE cancellation_reason END,
                    updated_by = :updated_by
                 WHERE company_id = :company_id AND order_id = :order_id"
            );
            $update->execute([
                'status' => $newStatus,
                'is_submit' => $action === 'submit' ? 1 : 0,
                'is_approve' => $action === 'approve' ? 1 : 0,
                'actor_approve' => $actorId,
                'is_approve_time' => $action === 'approve' ? 1 : 0,
                'is_cancel' => $action === 'cancel' ? 1 : 0,
                'actor_cancel' => $actorId,
                'is_cancel_time' => $action === 'cancel' ? 1 : 0,
                'is_cancel_reason' => $action === 'cancel' ? 1 : 0,
                'reason' => $reason,
                'updated_by' => $actorId,
                'company_id' => $companyId,
                'order_id' => $orderId,
            ]);
            if ($action === 'approve') {
                $this->accrueCommission($connection, $companyId, $orderId, $order);
                $this->enqueueOrderConfirmed($connection, $companyId, $orderId, $order);
            }
            $webhookEvent = match ($action) {
                'submit' => 'sales.order.submitted',
                'approve' => 'sales.order.approved',
                'cancel' => 'sales.order.cancelled',
                'fulfill' => 'sales.order.fulfilled',
            };
            if (!($action === 'approve' && $webhookEvent === 'sales.order.confirmed')) {
                $this->enqueue(
                    $connection,
                    $companyId,
                    $webhookEvent,
                    'sales_order',
                    (string) $orderId,
                    [
                        'order_id' => $orderId,
                        'order_number' => $order['order_number'],
                        'branch_id' => isset($order['branch_id'])
                            ? (int) $order['branch_id']
                            : null,
                        'status' => $newStatus,
                        'reason' => $reason,
                        'actor_id' => $actorId,
                    ]
                );
            }
            $history = $connection->prepare(
                'INSERT INTO sales_order_status_history
                    (company_id, order_id, from_status, to_status, action, reason,
                     actor_id, occurred_at, idempotency_key)
                 VALUES
                    (:company_id, :order_id, :from_status, :to_status, :action, :reason,
                     :actor_id, NOW(), :idempotency_key)'
            );
            $history->execute([
                'company_id' => $companyId, 'order_id' => $orderId,
                'from_status' => $current, 'to_status' => $newStatus,
                'action' => $action, 'reason' => $reason, 'actor_id' => $actorId,
                'idempotency_key' => $idempotencyKey,
            ]);
            $connection->commit();
            return ['oldStatus' => $current, 'newStatus' => $newStatus];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    public function transitionCommission(
        int $companyId,
        int $commissionId,
        string $action,
        int $actorId
    ): array {
        $expected = $action === 'approve' ? 'accrued' : 'approved';
        $next = $action === 'approve' ? 'approved' : 'paid';
        if (!in_array($action, ['approve', 'pay'], true)) {
            throw new RuntimeException('Unsupported commission transition.');
        }
        $statement = $this->connection()->prepare(
            "UPDATE sales_commissions SET
                status = :next_status,
                approved_by = CASE WHEN :is_approve = 1 THEN :approver ELSE approved_by END,
                approved_at = CASE WHEN :is_approve_time = 1 THEN NOW() ELSE approved_at END,
                paid_by = CASE WHEN :is_pay = 1 THEN :payer ELSE paid_by END,
                paid_at = CASE WHEN :is_pay_time = 1 THEN NOW() ELSE paid_at END
             WHERE company_id = :company_id
               AND commission_id = :commission_id
               AND status = :expected_status"
        );
        $statement->execute([
            'next_status' => $next,
            'is_approve' => $action === 'approve' ? 1 : 0,
            'approver' => $actorId,
            'is_approve_time' => $action === 'approve' ? 1 : 0,
            'is_pay' => $action === 'pay' ? 1 : 0,
            'payer' => $actorId,
            'is_pay_time' => $action === 'pay' ? 1 : 0,
            'company_id' => $companyId,
            'commission_id' => $commissionId,
            'expected_status' => $expected,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The commission status changed or the transition is not allowed.');
        }
        return ['oldStatus' => $expected, 'newStatus' => $next];
    }

    public function createOrder(int $companyId, array $order, array $lines, int $actorId): int
    {
        $connection = $this->connection();
        $ownsTransaction = !$connection->inTransaction();
        if ($ownsTransaction) { $connection->beginTransaction(); }

        try {
            $statement = $connection->prepare(
                'INSERT INTO sales_orders
                    (company_id, branch_id, customer_id, territory_id, agent_id, order_number, external_reference, order_date, due_date, status, currency, subtotal, discount_amount, tax_amount, total_amount, notes, confirmed_at, created_by, updated_by)
                 VALUES
                    (:company_id, :branch_id, :customer_id, :territory_id, :agent_id, :order_number, :external_reference, :order_date, :due_date, :status, :currency, :subtotal, :discount_amount, :tax_amount, :total_amount, :notes, :confirmed_at, :created_by, :updated_by)'
            );
            $orderValues = $order;
            unset($orderValues['commission_amount']);
            $statement->execute($orderValues + [
                'company_id' => $companyId,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            $orderId = (int) $connection->lastInsertId();
            $history = $connection->prepare(
                'INSERT INTO sales_order_status_history
                    (company_id, order_id, from_status, to_status, action, reason,
                     actor_id, occurred_at, idempotency_key)
                 VALUES
                    (:company_id, :order_id, NULL, :to_status, :action, NULL,
                     :actor_id, NOW(), :idempotency_key)'
            );
            $history->execute([
                'company_id' => $companyId, 'order_id' => $orderId,
                'to_status' => $order['status'], 'action' => 'create',
                'actor_id' => $actorId,
                'idempotency_key' => 'order-create-' . $orderId,
            ]);
            $lineStatement = $connection->prepare(
                'INSERT INTO sales_order_lines
                    (company_id, order_id, product_id, description, quantity, unit_price, discount_amount, tax_rate, line_total, commission_rate)
                 VALUES
                    (:company_id, :order_id, :product_id, :description, :quantity, :unit_price, :discount_amount, :tax_rate, :line_total, :commission_rate)'
            );
            foreach ($lines as $line) {
                $lineStatement->execute($line + ['company_id' => $companyId, 'order_id' => $orderId]);
            }

            if ($order['agent_id'] !== null && (float) $order['commission_amount'] > 0) {
                $commission = $connection->prepare(
                    "INSERT INTO sales_commissions
                        (company_id, order_id, agent_id, commission_amount, status, accrued_at)
                     VALUES (:company_id, :order_id, :agent_id, :amount, 'accrued', NOW())"
                );
                $commission->execute([
                    'company_id' => $companyId, 'order_id' => $orderId,
                    'agent_id' => $order['agent_id'], 'amount' => $order['commission_amount'],
                ]);
            }

            if ($order['status'] === 'confirmed') {
                $this->enqueue(
                    $connection,
                    $companyId,
                    'sales.order.confirmed',
                    'sales_order',
                    (string) $orderId,
                    [
                        'order_id' => $orderId,
                        'order_number' => $order['order_number'],
                        'branch_id' => isset($order['branch_id'])
                            ? (int) $order['branch_id']
                            : null,
                        'customer_id' => $order['customer_id'],
                        'currency' => $order['currency'],
                        'total_amount' => $order['total_amount'],
                        'due_date' => $order['due_date'],
                        'lines' => array_map(
                            static fn (array $line): array => [
                                'product_id' => $line['product_id'],
                                'quantity' => $line['quantity'],
                            ],
                            $lines
                        ),
                    ]
                );
            }

            if ($ownsTransaction) { $connection->commit(); }
            return $orderId;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    public function recordPayment(int $companyId, int $orderId, array $payment, int $actorId): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $order = $connection->prepare(
                "SELECT total_amount, paid_amount, status FROM sales_orders
                 WHERE company_id = :company_id AND order_id = :order_id AND deleted_at IS NULL FOR UPDATE"
            );
            $order->execute(['company_id' => $companyId, 'order_id' => $orderId]);
            $row = $order->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || in_array($row['status'], ['draft', 'submitted', 'cancelled'], true)) {
                throw new RuntimeException('The order cannot receive a payment.');
            }
            $balance = (float) $row['total_amount'] - (float) $row['paid_amount'];
            if ((float) $payment['amount'] > $balance + 0.0001) {
                throw new RuntimeException('Payment exceeds the order balance.');
            }
            $insert = $connection->prepare(
                'INSERT INTO sales_payments
                    (company_id, order_id, receipt_number, payment_date, amount, payment_method, reference_number, notes, recorded_by)
                 VALUES
                    (:company_id, :order_id, :receipt_number, :payment_date, :amount, :payment_method, :reference_number, :notes, :recorded_by)'
            );
            $insert->execute($payment + [
                'company_id' => $companyId, 'order_id' => $orderId, 'recorded_by' => $actorId,
            ]);
            $paymentId = (int) $connection->lastInsertId();
            $newPaid = (float) $row['paid_amount'] + (float) $payment['amount'];
            $status = $newPaid + 0.0001 >= (float) $row['total_amount'] ? 'paid' : 'partially_paid';
            $update = $connection->prepare(
                'UPDATE sales_orders SET paid_amount = :paid_amount, status = :status, updated_by = :updated_by
                 WHERE company_id = :company_id AND order_id = :order_id'
            );
            $update->execute([
                'paid_amount' => $newPaid, 'status' => $status, 'updated_by' => $actorId,
                'company_id' => $companyId, 'order_id' => $orderId,
            ]);
            $this->enqueue(
                $connection,
                $companyId,
                'sales.payment.recorded',
                'sales_payment',
                (string) $paymentId,
                [
                    'payment_id' => $paymentId,
                    'order_id' => $orderId,
                    'receipt_number' => $payment['receipt_number'],
                    'payment_date' => $payment['payment_date'],
                    'amount' => $payment['amount'],
                    'payment_method' => $payment['payment_method'],
                    'reference_number' => $payment['reference_number'],
                ]
            );
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    private function catalogue(string $sql, int $companyId): array
    {
        $statement = $this->connection()->prepare($sql);
        $statement->execute(['company_id' => $companyId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $values */
    private function insertCatalogue(
        string $sql,
        int $companyId,
        array $values,
        int $actorId
    ): int {
        $statement = $this->connection()->prepare($sql);
        $statement->execute($values + [
            'company_id' => $companyId,
            'created_by' => $actorId,
        ]);

        return (int) $this->connection()->lastInsertId();
    }

    /** @param array<string, mixed> $order */
    private function accrueCommission(
        PDO $connection,
        int $companyId,
        int $orderId,
        array $order
    ): void {
        $agentId = (int) ($order['agent_id'] ?? 0);
        if ($agentId < 1) {
            return;
        }
        $amount = $connection->prepare(
            'SELECT COALESCE(SUM(
                (quantity * unit_price - discount_amount)
                * commission_rate / 100
             ), 0)
             FROM sales_order_lines
             WHERE company_id = :company_id AND order_id = :order_id'
        );
        $amount->execute([
            'company_id' => $companyId,
            'order_id' => $orderId,
        ]);
        $commissionAmount = round((float) $amount->fetchColumn(), 2);
        if ($commissionAmount <= 0) {
            return;
        }
        $statement = $connection->prepare(
            "INSERT INTO sales_commissions
                (company_id, order_id, agent_id, commission_amount,
                 status, accrued_at)
             VALUES
                (:company_id, :order_id, :agent_id, :amount,
                 'accrued', NOW())
             ON DUPLICATE KEY UPDATE
                commission_amount = VALUES(commission_amount)"
        );
        $statement->execute([
            'company_id' => $companyId,
            'order_id' => $orderId,
            'agent_id' => $agentId,
            'amount' => $commissionAmount,
        ]);
    }

    /** @param array<string, mixed> $order */
    private function enqueueOrderConfirmed(
        PDO $connection,
        int $companyId,
        int $orderId,
        array $order
    ): void {
        $lineStatement = $connection->prepare(
            'SELECT product_id, quantity
             FROM sales_order_lines
             WHERE company_id = :company_id AND order_id = :order_id
             ORDER BY order_line_id'
        );
        $lineStatement->execute([
            'company_id' => $companyId,
            'order_id' => $orderId,
        ]);
        $lines = $lineStatement->fetchAll(PDO::FETCH_ASSOC);
        $this->enqueue(
            $connection,
            $companyId,
            'sales.order.confirmed',
            'sales_order',
            (string) $orderId,
            [
                'order_id' => $orderId,
                'order_number' => $order['order_number'],
                'branch_id' => isset($order['branch_id'])
                    ? (int) $order['branch_id']
                    : null,
                'customer_id' => $order['customer_id'],
                'currency' => $order['currency'],
                'total_amount' => $order['total_amount'],
                'due_date' => $order['due_date'],
                'lines' => array_map(
                    static fn (array $line): array => [
                        'product_id' => (int) $line['product_id'],
                        'quantity' => (float) $line['quantity'],
                    ],
                    $lines
                ),
            ]
        );
    }

    /** @param array<string, mixed> $payload */
    private function enqueue(
        PDO $connection,
        int $companyId,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload
    ): void {
        $eventId = sprintf(
            '%s-%s-4%s-%s%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            dechex(random_int(8, 11)),
            bin2hex(random_bytes(1)),
            bin2hex(random_bytes(6))
        );
        $statement = $connection->prepare(
            "INSERT INTO integration_outbox
                (event_id, company_id, event_type, aggregate_type,
                 aggregate_id, payload_json, status, available_at)
             VALUES
                (:event_id, :company_id, :event_type, :aggregate_type,
                 :aggregate_id, :payload_json, 'pending', NOW())"
        );
        $statement->execute([
            'event_id' => $eventId,
            'company_id' => $companyId,
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload_json' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            ),
        ]);
    }
}
