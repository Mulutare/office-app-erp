<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class FinanceReconciliationService
{
    private function rows(string $sql,int $company): array{$q=\db()->prepare($sql);$q->execute(['company'=>$company]);return $q->fetchAll(PDO::FETCH_ASSOC);}
    public function summary(): array
    {
        $company=(new TenantContext())->companyId();
        $ar=$this->rows("SELECT currency,SUM(CASE WHEN document_type='customer_invoice' THEN residual_amount ELSE -total_amount END) balance FROM finance_invoices WHERE company_id=:company AND status='posted' AND document_type IN('customer_invoice','customer_credit') GROUP BY currency",$company);
        $ap=$this->rows("SELECT currency,SUM(CASE WHEN document_type='vendor_bill' THEN residual_amount ELSE -total_amount END) balance FROM finance_invoices WHERE company_id=:company AND status='posted' AND document_type IN('vendor_bill','vendor_credit') GROUP BY currency",$company);
        $loans=$this->rows("SELECT currency,SUM(outstanding_principal) balance FROM finance_staff_loans WHERE company_id=:company AND status IN('disbursed','active','paid') GROUP BY currency",$company);
        $gl=$this->rows("SELECT a.system_key control_key,b.currency,SUM(CASE WHEN a.system_key='accounts_payable' THEN e.credit_amount-e.debit_amount ELSE e.debit_amount-e.credit_amount END) balance FROM finance_journal_entries e JOIN finance_journal_batches b ON b.company_id=e.company_id AND b.journal_batch_id=e.journal_batch_id JOIN finance_accounts a ON a.company_id=e.company_id AND a.account_id=e.account_id WHERE b.company_id=:company AND b.status='posted' AND a.system_key IN('accounts_receivable','accounts_payable','staff_loans_receivable') GROUP BY a.system_key,b.currency",$company);
        $buckets=[];$sources=['AR'=>[$ar,'accounts_receivable'],'AP'=>[$ap,'accounts_payable'],'Staff Loans'=>[$loans,'staff_loans_receivable']];foreach($sources as $label=>[$rows,$key])foreach($rows as $r)$buckets[$label][$r['currency']]['subledger']=(float)$r['balance'];
        foreach($gl as $r){$label=match($r['control_key']){'accounts_receivable'=>'AR','accounts_payable'=>'AP',default=>'Staff Loans'};$buckets[$label][$r['currency']]['gl']=(float)$r['balance'];}
        $result=[];foreach($buckets as $label=>$currencies)foreach($currencies as $currency=>$values){$sub=round($values['subledger']??0,2);$control=round($values['gl']??0,2);$difference=round($sub-$control,2);$result[]=['subledger'=>$label,'currency'=>$currency,'subledger_amount'=>$sub,'gl_amount'=>$control,'difference'=>$difference,'status'=>abs($difference)<0.005?'MATCHED':'DIFFERENCE'];}
        return $result;
    }
}
