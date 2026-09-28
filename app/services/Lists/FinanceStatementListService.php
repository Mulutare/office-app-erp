<?php
declare(strict_types=1);
namespace App\Services\Lists;

use App\Services\TenantContext;

/** Running balances are calculated over the whole authorized ledger before list filtering. */
final class FinanceStatementListService
{
    private const SORTS=['date'=>'date','reference'=>'reference','document'=>'document','currency'=>'currency','debit'=>'debit','credit'=>'credit'];
    private const FILTERS=['currency'=>'currency','document'=>'document','from'=>['date','>='],'to'=>['date','<=']];

    public function events(string $kind,int $party): array
    {
        if(!in_array($kind,['customer','supplier'],true))throw new \InvalidArgumentException('Unknown statement type.');
        $company=(new TenantContext())->companyId();
        $column=$kind==='customer'?'customer_id':'vendor_id';
        $types=$kind==='customer'?"'customer_invoice','customer_credit'":"'vendor_bill','vendor_credit'";
        $status=$kind==='customer'?"i.status='posted'":"i.status IN ('posted','reversed')";
        $sql="SELECT i.invoice_date date,i.invoice_number reference,i.document_type document,
            IF(i.document_type='customer_invoice',i.total_amount,0) invoice,
            IF(i.document_type='vendor_bill',i.total_amount,0) bill,
            IF(i.document_type IN('customer_credit','vendor_credit'),i.total_amount,0) credit_note,0 payment,
            IF(i.document_type IN('customer_invoice','vendor_credit'),i.total_amount,0) debit,
            IF(i.document_type IN('customer_credit','vendor_bill'),i.total_amount,0) credit,
            i.currency,10 sort,i.invoice_id id
            FROM finance_invoices i WHERE i.company_id=? AND i.$column=? AND i.document_type IN($types) AND $status
            UNION ALL
            SELECT p.payment_date,CONCAT(p.payment_number,IF(COALESCE(p.reference_number,'')='','',CONCAT(' / ',p.reference_number))),
            'payment',0,0,0,SUM(a.amount),".($kind==='supplier'?'SUM(a.amount),0':'0,SUM(a.amount)').",
            p.currency,20,p.payment_id
            FROM finance_payment_allocations a JOIN finance_payments p ON p.company_id=a.company_id AND p.payment_id=a.payment_id
            JOIN finance_invoices i ON i.company_id=a.company_id AND i.invoice_id=a.invoice_id
            WHERE a.company_id=? AND i.$column=? AND p.status='posted'
            GROUP BY p.payment_id,p.payment_date,p.payment_number,p.currency,p.reference_number";
        $params=[$company,$party,$company,$party];
        if($kind==='supplier') {
            $sql.=" UNION ALL SELECT b.posting_date,CONCAT('Reversal ',i.invoice_number),'bill_reversal',0,0,i.total_amount,0,i.total_amount,0,i.currency,30,b.journal_batch_id
                FROM finance_invoices i JOIN finance_journal_batches b ON b.company_id=i.company_id
                    AND b.source_type='vendor_bill_reversal' AND BINARY b.source_id=BINARY CAST(i.invoice_id AS CHAR) AND b.status='posted'
                WHERE i.company_id=? AND i.vendor_id=? AND i.document_type='vendor_bill' AND i.status='reversed'";
            array_push($params,$company,$party);
        }
        return [$sql,$params];
    }

    public function listing(string $kind,int $party,array $input): SqlList
    {
        [$sql,$params]=$this->events($kind,$party);
        $sql="SELECT events.*,SUM(debit-credit) OVER(PARTITION BY currency ORDER BY date,sort,id ROWS UNBOUNDED PRECEDING) balance,
            CONCAT(date,LPAD(sort,2,'0'),LPAD(id,20,'0')) chronology FROM ($sql) events";
        return new SqlList(\db(),$sql,$params,new ListQuery($input,self::SORTS,'date',array_keys(self::FILTERS)),
            ['reference','document','currency'],self::SORTS,'chronology',self::FILTERS);
    }

    public function controls(string $kind,int $party): array
    {
        $documents=$kind==='customer'?['customer_invoice','customer_credit','payment']:['vendor_bill','vendor_credit','payment','bill_reversal'];
        return FilterOptions::controls($this->listing($kind,$party,[]),self::FILTERS,self::SORTS,['document'=>FilterOptions::labels($documents)]);
    }

    /** Date/currency define statement balances; search and row sorting never redefine them. */
    public function balances(string $kind,int $party,string $from,string $to,string $currency): array
    {
        [$sql,$params]=$this->events($kind,$party);
        $where=[];
        if($to!==''){$where[]='date<=?';$params[]=$to;}
        if($currency!==''){$where[]='currency=?';$params[]=$currency;}
        $query=\db()->prepare("SELECT currency,SUM(debit-credit) closing,
            SUM(CASE WHEN date<? THEN debit-credit ELSE 0 END) opening,SUM(date<?) opening_count
            FROM ($sql) events".($where?' WHERE '.implode(' AND ',$where):'').' GROUP BY currency');
        $query->execute(array_merge([$from,$from],$params));
        $result=['opening'=>[],'totals'=>[]];
        foreach($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result['totals'][$row['currency']]=(float)$row['closing'];
            if((int)$row['opening_count']>0)$result['opening'][$row['currency']]=(float)$row['opening'];
        }
        return $result;
    }

    public static function columns(): array
    {
        return ['date'=>'Date','document'=>'Document','reference'=>'Reference','invoice'=>'Invoice','bill'=>'Bill',
            'credit_note'=>'Credit / reversal','payment'=>'Payment','debit'=>'Debit','credit'=>'Credit','currency'=>'Currency','balance'=>'Running balance'];
    }
}
