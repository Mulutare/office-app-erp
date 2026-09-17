<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class FinanceStatementService
{
    private function rows(string $sql,array $params): array{$query=\db()->prepare($sql);$query->execute($params);return $query->fetchAll(PDO::FETCH_ASSOC);}
    public function statement(string $kind,array $input): array
    {
        if(!in_array($kind,['customer','supplier'],true))throw new RuntimeException('Unknown statement type.');
        $company=(new TenantContext())->companyId();$party=(int)($input['party_id']??0);$currency=strtoupper(trim((string)($input['currency']??'')));$from=$this->date($input['from']??'');$to=$this->date($input['to']??'');
        if($currency!==''&&preg_match('/^[A-Z]{3}$/',$currency)!==1)throw new RuntimeException('Invalid currency.');if($from!==''&&$to!==''&&$from>$to)throw new RuntimeException('Invalid date range.');
        $parties=$kind==='customer'?$this->rows('SELECT customer_id id,name label FROM sales_customers WHERE company_id=:company ORDER BY name LIMIT 1000',['company'=>$company]):$this->rows('SELECT supplier_id id,business_name label FROM purchase_suppliers WHERE company_id=:company ORDER BY business_name LIMIT 1000',['company'=>$company]);
        $result=['kind'=>$kind,'parties'=>$parties,'party_id'=>$party,'currency'=>$currency,'from'=>$from,'to'=>$to,'lines'=>[],'totals'=>[]];if($party<1)return $result;
        $lookup=$kind==='customer'?'SELECT name label FROM sales_customers WHERE company_id=:company AND customer_id=:party':'SELECT business_name label FROM purchase_suppliers WHERE company_id=:company AND supplier_id=:party';
        $selected=$this->rows($lookup,['company'=>$company,'party'=>$party]);if(!$selected)throw new RuntimeException('Party was not found in this company.');$result['party_name']=$selected[0]['label'];
        $partyColumn=$kind==='customer'?'customer_id':'vendor_id';$docTypes=$kind==='customer'?"'customer_invoice','customer_credit'":"'vendor_bill','vendor_credit'";
        $status=$kind==='supplier'?"i.status IN('posted','reversed')":"i.status='posted'";
        $documents=$this->rows("SELECT i.invoice_id,i.invoice_date activity_date,i.invoice_number reference,i.document_type kind,i.currency,i.total_amount,i.supplier_invoice_number FROM finance_invoices i WHERE i.company_id=:company AND i.$partyColumn=:party AND i.document_type IN($docTypes) AND $status ORDER BY i.invoice_date,i.invoice_id",['company'=>$company,'party'=>$party]);
        $payments=$this->rows("SELECT p.payment_id,p.payment_date activity_date,p.payment_number reference,p.currency,p.reference_number,SUM(a.amount) amount FROM finance_payment_allocations a JOIN finance_payments p ON p.company_id=a.company_id AND p.payment_id=a.payment_id JOIN finance_invoices i ON i.company_id=a.company_id AND i.invoice_id=a.invoice_id WHERE a.company_id=:company AND i.$partyColumn=:party AND p.status='posted' GROUP BY p.payment_id,p.payment_date,p.payment_number,p.currency,p.reference_number ORDER BY p.payment_date,p.payment_id",['company'=>$company,'party'=>$party]);
        $events=[];
        foreach($documents as $d){$type=$d['kind'];$amount=(float)$d['total_amount'];$debit=($type==='customer_invoice')?$amount:0;$credit=($type==='customer_credit'||$type==='vendor_bill')?$amount:0;if($type==='vendor_credit')$debit=$amount;$events[]=['date'=>$d['activity_date'],'reference'=>$d['reference'],'document'=>$type,'invoice'=>$type==='customer_invoice'?$amount:0,'bill'=>$type==='vendor_bill'?$amount:0,'credit_note'=>$type==='customer_credit'||$type==='vendor_credit'?$amount:0,'payment'=>0,'debit'=>$debit,'credit'=>$credit,'currency'=>$d['currency'],'sort'=>10,'id'=>$d['invoice_id']];}
        foreach($payments as $p){$amount=(float)$p['amount'];$events[]=['date'=>$p['activity_date'],'reference'=>$p['reference'].($p['reference_number']?' / '.$p['reference_number']:''),'document'=>'payment','invoice'=>0,'bill'=>0,'credit_note'=>0,'payment'=>$amount,'debit'=>$kind==='supplier'?$amount:0,'credit'=>$kind==='customer'?$amount:0,'currency'=>$p['currency'],'sort'=>20,'id'=>$p['payment_id']];}
        if($kind==='supplier'){
            $reversals=$this->rows("SELECT b.posting_date activity_date,i.invoice_number reference,i.currency,i.total_amount,i.invoice_id FROM finance_invoices i JOIN finance_journal_batches b ON b.company_id=i.company_id AND b.source_type='vendor_bill_reversal' AND b.source_id=CAST(i.invoice_id AS CHAR) AND b.status='posted' WHERE i.company_id=:company AND i.vendor_id=:party AND i.document_type='vendor_bill' AND i.status='reversed'",['company'=>$company,'party'=>$party]);
            foreach($reversals as $r){$events[]=['date'=>$r['activity_date'],'reference'=>'Reversal '.$r['reference'],'document'=>'bill_reversal','invoice'=>0,'bill'=>0,'credit_note'=>(float)$r['total_amount'],'payment'=>0,'debit'=>(float)$r['total_amount'],'credit'=>0,'currency'=>$r['currency'],'sort'=>30,'id'=>$r['invoice_id']];}
        }
        usort($events,static fn(array $a,array $b):int=>[$a['date'],$a['sort'],$a['id']]<=>[$b['date'],$b['sort'],$b['id']]);$balances=[];
        foreach($events as $event){if($currency!==''&&$event['currency']!==$currency)continue;if($to!==''&&$event['date']>$to)continue;$ccy=$event['currency'];$balances[$ccy]=round(($balances[$ccy]??0)+(float)$event['debit']-(float)$event['credit'],2);if($from!==''&&$event['date']<$from){$result['opening'][$ccy]=$balances[$ccy];continue;}$event['balance']=$balances[$ccy];$result['lines'][]=$event;}
        $result['totals']=$balances;
        return $result;
    }
    private function date(mixed $value): string{$value=trim((string)$value);if($value==='')return '';$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new RuntimeException('Invalid date.');return $value;}
}
