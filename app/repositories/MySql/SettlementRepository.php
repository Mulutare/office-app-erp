<?php
declare(strict_types=1);
namespace App\Repositories\MySql;

use PDO;
use RuntimeException;

final class SettlementRepository extends MySqlRepository
{
    public function bankAccounts(int $companyId): array{$s=$this->connection()->prepare('SELECT * FROM company_bank_accounts WHERE company_id=:c AND active=TRUE ORDER BY is_default DESC,bank_name,account_number');$s->execute(['c'=>$companyId]);return $s->fetchAll(PDO::FETCH_ASSOC);}
    public function saveBankAccount(int $companyId,array $v,int $actorId): int
    {
        $c=$this->connection();$c->beginTransaction();try{if(!empty($v['is_default'])){$c->prepare('UPDATE company_bank_accounts SET is_default=FALSE,updated_by=:a WHERE company_id=:c')->execute(['a'=>$actorId,'c'=>$companyId]);}
        $s=$c->prepare('INSERT INTO company_bank_accounts(company_id,bank_name,account_name,account_number,branch,currency,swift_bic,provider_code,is_default,created_by,updated_by) VALUES(:c,:bank,:name,:number,:branch,:currency,:swift,:provider,:default,:actor,:actor2)');
        $s->execute(['c'=>$companyId,'bank'=>$v['bank_name'],'name'=>$v['account_name'],'number'=>$v['account_number'],'branch'=>$v['branch']?:null,'currency'=>$v['currency'],'swift'=>$v['swift_bic']?:null,'provider'=>$v['provider_code']?:null,'default'=>!empty($v['is_default'])?1:0,'actor'=>$actorId,'actor2'=>$actorId]);$id=(int)$c->lastInsertId();$c->commit();return $id;}catch(\Throwable $e){if($c->inTransaction())$c->rollBack();throw $e;}
    }
    public function eligiblePayments(int $companyId): array
    {
        $s=$this->connection()->prepare("SELECT p.payment_id,p.payment_number,p.payment_date,p.currency,p.amount,p.reference_number,i.sales_order_id,o.order_number,c.name customer_name FROM finance_payments p JOIN finance_payment_allocations a ON a.company_id=p.company_id AND a.payment_id=p.payment_id JOIN finance_invoices i ON i.company_id=a.company_id AND i.invoice_id=a.invoice_id JOIN sales_orders o ON o.company_id=i.company_id AND o.order_id=i.sales_order_id JOIN sales_customers c ON c.company_id=o.company_id AND c.customer_id=o.customer_id LEFT JOIN sales_settlement_lines sl ON sl.company_id=p.company_id AND sl.finance_payment_id=p.payment_id WHERE p.company_id=:c AND p.direction='inbound' AND p.status='posted' AND i.document_type='customer_invoice' AND i.status='posted' AND o.status NOT IN('draft','cancelled') AND sl.settlement_line_id IS NULL GROUP BY p.payment_id,p.payment_number,p.payment_date,p.currency,p.amount,p.reference_number,i.sales_order_id,o.order_number,c.name ORDER BY p.payment_date,p.payment_id");$s->execute(['c'=>$companyId]);return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function create(int $companyId,int $bankAccountId,array $paymentIds,string $notes,int $actorId): int
    {
        $c=$this->connection();$c->beginTransaction();try{$placeholders=implode(',',array_fill(0,count($paymentIds),'?'));$s=$c->prepare("SELECT p.payment_id,p.currency,p.amount,i.sales_order_id FROM finance_payments p JOIN finance_payment_allocations a ON a.company_id=p.company_id AND a.payment_id=p.payment_id JOIN finance_invoices i ON i.company_id=a.company_id AND i.invoice_id=a.invoice_id LEFT JOIN sales_settlement_lines sl ON sl.company_id=p.company_id AND sl.finance_payment_id=p.payment_id WHERE p.company_id=? AND p.payment_id IN($placeholders) AND p.direction='inbound' AND p.status='posted' AND i.document_type='customer_invoice' AND i.status='posted' AND sl.settlement_line_id IS NULL GROUP BY p.payment_id,p.currency,p.amount,i.sales_order_id FOR UPDATE");$s->execute(array_merge([$companyId],$paymentIds));$rows=$s->fetchAll(PDO::FETCH_ASSOC);if(count($rows)!==count($paymentIds))throw new RuntimeException('One or more payments are ineligible or already settled.');$currencies=array_unique(array_column($rows,'currency'));if(count($currencies)!==1)throw new RuntimeException('A settlement may contain only one currency.');$bank=$c->prepare('SELECT currency FROM company_bank_accounts WHERE company_id=:c AND bank_account_id=:b AND active=TRUE');$bank->execute(['c'=>$companyId,'b'=>$bankAccountId]);$bankCurrency=$bank->fetchColumn();if($bankCurrency===false||$bankCurrency!==$currencies[0])throw new RuntimeException('Select an active company bank account in the settlement currency.');$total=round(array_sum(array_map(fn($r)=>(float)$r['amount'],$rows)),2);$number='SET-'.date('Y').'-'.str_pad((string)(((int)$c->query('SELECT COALESCE(MAX(settlement_id),0)+1 FROM sales_settlements')->fetchColumn())),6,'0',STR_PAD_LEFT);$h=$c->prepare("INSERT INTO sales_settlements(company_id,settlement_number,bank_account_id,currency,expected_amount,variance_amount,remaining_amount,notes,created_by) VALUES(:c,:n,:b,:currency,:amount,:variance,:remaining,:notes,:actor)");$h->execute(['c'=>$companyId,'n'=>$number,'b'=>$bankAccountId,'currency'=>$currencies[0],'amount'=>$total,'variance'=>-$total,'remaining'=>$total,'notes'=>$notes?:null,'actor'=>$actorId]);$id=(int)$c->lastInsertId();$line=$c->prepare('INSERT INTO sales_settlement_lines(company_id,settlement_id,sales_order_id,finance_payment_id,amount) VALUES(:c,:s,:o,:p,:a)');foreach($rows as $r)$line->execute(['c'=>$companyId,'s'=>$id,'o'=>$r['sales_order_id'],'p'=>$r['payment_id'],'a'=>$r['amount']]);$this->event($c,$companyId,$id,'created',null,'draft',null,$actorId);$c->commit();return $id;}catch(\Throwable $e){if($c->inTransaction())$c->rollBack();throw $e;}
    }
    public function list(int $companyId): array{$s=$this->connection()->prepare('SELECT s.*,b.bank_name,b.account_number,u.display_name creator_name FROM sales_settlements s JOIN company_bank_accounts b ON b.company_id=s.company_id AND b.bank_account_id=s.bank_account_id JOIN users u ON u.user_id=s.created_by WHERE s.company_id=:c ORDER BY s.settlement_id DESC');$s->execute(['c'=>$companyId]);return $s->fetchAll(PDO::FETCH_ASSOC);}
    public function find(int $companyId,int $id): ?array
    {
        $s=$this->connection()->prepare('SELECT s.*,b.bank_name,b.account_name,b.account_number,b.branch,b.swift_bic,c.name company_name,c.legal_name,c.contact_email,c.contact_phone FROM sales_settlements s JOIN company_bank_accounts b ON b.company_id=s.company_id AND b.bank_account_id=s.bank_account_id JOIN companies c ON c.company_id=s.company_id WHERE s.company_id=:c AND s.settlement_id=:id');$s->execute(['c'=>$companyId,'id'=>$id]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($row))return null;
        $q=$this->connection()->prepare("SELECT
    sl.*,
    p.payment_number,
    p.payment_date,
    p.reference_number,
    o.order_number,
    sc.name customer_name,
    qs.quick_sale_id,
    qsr.report_id AS quick_sale_report_id,
    qsr.invoice_reference AS quick_sale_receipt_reference,
    CASE
        WHEN qsr.evidence_path IS NOT NULL
         AND qsr.evidence_path <> ''
        THEN 1
        ELSE 0
    END AS quick_sale_has_receipt
FROM sales_settlement_lines sl
JOIN finance_payments p
  ON p.company_id=sl.company_id
 AND p.payment_id=sl.finance_payment_id
JOIN sales_orders o
  ON o.company_id=sl.company_id
 AND o.order_id=sl.sales_order_id
JOIN sales_customers sc
  ON sc.company_id=o.company_id
 AND sc.customer_id=o.customer_id
LEFT JOIN sales_quotations q
  ON q.company_id=sl.company_id
 AND q.sales_order_id=sl.sales_order_id
LEFT JOIN sales_quick_sales qs
  ON qs.company_id=q.company_id
 AND qs.quotation_id=q.quotation_id
 AND qs.status='closed'
LEFT JOIN sales_quick_sale_reports qsr
  ON qsr.company_id=qs.company_id
 AND qsr.quick_sale_id=qs.quick_sale_id
 AND qsr.status='confirmed'
 AND qsr.report_id=(
     SELECT MAX(qsr2.report_id)
     FROM sales_quick_sale_reports qsr2
     WHERE qsr2.company_id=qs.company_id
       AND qsr2.quick_sale_id=qs.quick_sale_id
       AND qsr2.status='confirmed'
 )
WHERE sl.company_id=:c
  AND sl.settlement_id=:id
ORDER BY sl.settlement_line_id");$q->execute(['c'=>$companyId,'id'=>$id]);$row['lines']=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$this->connection()->prepare('SELECT bc.*,u.display_name creator_name FROM bank_confirmations bc JOIN users u ON u.user_id=bc.created_by WHERE bc.company_id=:c AND bc.settlement_id=:id ORDER BY bc.confirmation_id');$q->execute(['c'=>$companyId,'id'=>$id]);$row['confirmations']=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$this->connection()->prepare('SELECT e.*,u.display_name actor_name FROM sales_settlement_events e LEFT JOIN users u ON u.user_id=e.actor_id WHERE e.company_id=:c AND e.settlement_id=:id ORDER BY e.event_id');$q->execute(['c'=>$companyId,'id'=>$id]);$row['events']=$q->fetchAll(PDO::FETCH_ASSOC);return $row;
    }
    public function transition(int $companyId,int $id,string $action,string $reason,int $actorId): void
    {
        $map=['submit'=>['draft','submitted'],'review'=>['submitted','supervisor_reviewed'],'reconcile'=>['supervisor_reviewed','finance_reconciled'],'approve'=>['finance_reconciled','approved']];if(!isset($map[$action]))throw new RuntimeException('Unsupported settlement action.');[$from,$to]=$map[$action];$c=$this->connection();$c->beginTransaction();try{$s=$c->prepare('SELECT * FROM sales_settlements WHERE company_id=:c AND settlement_id=:id FOR UPDATE');$s->execute(['c'=>$companyId,'id'=>$id]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($row))throw new RuntimeException('Settlement was not found.');if($row['workflow_status']!==$from)throw new RuntimeException('Settlement action is not allowed from its current status.');if($action==='review'&&((int)$row['created_by']===$actorId||(int)($row['submitted_by']??0)===$actorId))throw new RuntimeException('The settlement creator or submitter cannot review their own settlement.');if($action==='approve'&&(int)$row['created_by']===$actorId)throw new RuntimeException('The settlement creator cannot final-approve their own settlement.');if($action==='reconcile'&&!in_array($row['reconciliation_status'],['matched','partial','mismatch','review_required'],true))throw new RuntimeException('Bank confirmation is required before reconciliation.');if($action==='approve'&&$row['reconciliation_status']!=='matched')throw new RuntimeException('A mismatch or partial deposit cannot be approved or closed.');$columns=['submit'=>'submitted','review'=>'supervisor_reviewed','reconcile'=>'finance_reconciled','approve'=>'approved'];$prefix=$columns[$action];$closed=$action==='approve'?',workflow_status=\'closed\',closed_at=NOW()':'';$u=$c->prepare("UPDATE sales_settlements SET workflow_status=:to,{$prefix}_by=:actor,{$prefix}_at=NOW()$closed WHERE company_id=:c AND settlement_id=:id");$u->execute(['to'=>$to,'actor'=>$actorId,'c'=>$companyId,'id'=>$id]);$this->event($c,$companyId,$id,$action,$from,$action==='approve'?'closed':$to,$reason?:null,$actorId);$c->commit();}catch(\Throwable $e){if($c->inTransaction())$c->rollBack();throw $e;}
    }
    public function addConfirmation(int $companyId,int $id,array $v,int $actorId): int
    {
        $c=$this->connection();$c->beginTransaction();try{$s=$c->prepare("SELECT expected_amount,currency,workflow_status,reconciliation_status,remaining_amount FROM sales_settlements WHERE company_id=:c AND settlement_id=:id FOR UPDATE");$s->execute(['c'=>$companyId,'id'=>$id]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($row)||!in_array($row['workflow_status'],['submitted','supervisor_reviewed'],true))throw new RuntimeException('Confirmation requires a submitted settlement.');if((string)$row['reconciliation_status']==='matched'||(float)$row['remaining_amount']<=0.005)throw new RuntimeException('This settlement already has a complete bank confirmation.');$dup=$c->prepare('SELECT confirmation_id FROM bank_confirmations WHERE company_id=:dup_company AND settlement_id=:dup_settlement AND evidence_sha256=:dup_sha LIMIT 1');$dup->execute(['dup_company'=>$companyId,'dup_settlement'=>$id,'dup_sha'=>$v['evidence_sha256']]);if($dup->fetchColumn()!==false)throw new RuntimeException('This bank confirmation evidence has already been added to the settlement.');$i=$c->prepare('INSERT INTO bank_confirmations(company_id,settlement_id,bank_reference,transaction_date,confirmed_amount,currency,evidence_path,evidence_original_name,evidence_mime,evidence_size,evidence_sha256,created_by) VALUES(:c,:s,:ref,:date,:amount,:currency,:path,:name,:mime,:size,:sha,:actor)');$i->execute(['c'=>$companyId,'s'=>$id,'ref'=>$v['bank_reference'],'date'=>$v['transaction_date'],'amount'=>$v['confirmed_amount'],'currency'=>$row['currency'],'path'=>$v['evidence_path'],'name'=>$v['evidence_original_name'],'mime'=>$v['evidence_mime'],'size'=>$v['evidence_size'],'sha'=>$v['evidence_sha256'],'actor'=>$actorId]);$confirmationId=(int)$c->lastInsertId();$sum=$c->prepare('SELECT COALESCE(SUM(confirmed_amount),0) FROM bank_confirmations WHERE company_id=:c AND settlement_id=:s');$sum->execute(['c'=>$companyId,'s'=>$id]);$confirmed=round((float)$sum->fetchColumn(),2);$expected=round((float)$row['expected_amount'],2);$variance=round($confirmed-$expected,2);$remaining=max(0,round($expected-$confirmed,2));$status=abs($variance)<0.005?'matched':($confirmed<$expected?'partial':'mismatch');$c->prepare('UPDATE sales_settlements SET confirmed_amount=:confirmed,variance_amount=:variance,remaining_amount=:remaining,reconciliation_status=:status WHERE company_id=:c AND settlement_id=:s')->execute(['confirmed'=>$confirmed,'variance'=>$variance,'remaining'=>$remaining,'status'=>$status,'c'=>$companyId,'s'=>$id]);$this->event($c,$companyId,$id,'bank_confirmation_added',$row['workflow_status'],$row['workflow_status'],'Reference '.$v['bank_reference'].'; reconciliation '.$status,$actorId);$c->commit();return $confirmationId;}catch(\Throwable $e){if($c->inTransaction())$c->rollBack();throw $e;}
    }
    public function confirmation(int $companyId,int $settlementId,int $confirmationId): ?array{$s=$this->connection()->prepare('SELECT * FROM bank_confirmations WHERE company_id=:c AND settlement_id=:s AND confirmation_id=:id');$s->execute(['c'=>$companyId,'s'=>$settlementId,'id'=>$confirmationId]);$r=$s->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;}
    public function branding(int $companyId): array{$s=$this->connection()->prepare('SELECT c.name company_name,c.legal_name,c.contact_email,c.contact_phone,b.* FROM companies c LEFT JOIN company_document_branding b ON b.company_id=c.company_id WHERE c.company_id=:c');$s->execute(['c'=>$companyId]);return $s->fetch(PDO::FETCH_ASSOC)?:[];}
    private function event(PDO $c,int $companyId,int $id,string $action,?string $from,?string $to,?string $reason,int $actor): void{$c->prepare('INSERT INTO sales_settlement_events(company_id,settlement_id,action,from_status,to_status,reason,actor_id) VALUES(:c,:s,:a,:f,:t,:r,:u)')->execute(['c'=>$companyId,'s'=>$id,'a'=>$action,'f'=>$from,'t'=>$to,'r'=>$reason,'u'=>$actor]);}
}
