<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/** Read-only accounting workspaces over posted Finance and Procurement records. */
final class FinanceAccountingWorkspaceService
{
    private const SECTIONS = ['accounts', 'ledger', 'receivables', 'payables', 'reports', 'cash-bank'];

    public function workspace(string $section, array $input): array
    {
        if (!in_array($section, self::SECTIONS, true)) {
            throw new RuntimeException('Unknown Finance workspace.');
        }
        $company = (new TenantContext())->companyId();
        $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
        if ($currency !== '' && preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new RuntimeException('Invalid currency filter.');
        }
        $from = $this->date($input['from'] ?? '');
        $to = $this->date($input['to'] ?? '');
        if ($from !== '' && $to !== '' && $from > $to) {
            throw new RuntimeException('The start date must precede the end date.');
        }
        $filters = ['currency' => $currency, 'from' => $from, 'to' => $to];
        $report = trim((string) ($input['report'] ?? ''));
        if ($section === 'reports' && !in_array($report, ['', 'trial-balance', 'profit-loss', 'balance-sheet'], true)) {
            throw new RuntimeException('Unknown Finance report.');
        }
        return match ($section) {
            'accounts' => ['title' => 'Chart of Accounts', 'rows' => $this->accounts($company), 'columns' => ['account_code' => 'Code', 'account_name' => 'Account', 'account_type' => 'Type', 'normal_balance' => 'Normal balance', 'currency' => 'Currency', 'system_key' => 'Control / system key', 'active' => 'Active']],
            'ledger' => ['title' => 'General Ledger', 'rows' => $this->ledger($company, $filters), 'columns' => ['posting_date' => 'Date', 'batch_number' => 'Journal', 'journal_batch_id' => 'Batch ID', 'source_type' => 'Source', 'source_number' => 'Reference', 'account_code' => 'Account', 'account_name' => 'Name', 'description' => 'Description', 'debit_amount' => 'Debit', 'credit_amount' => 'Credit', 'currency' => 'Currency', 'poster_name' => 'Posted by', 'reversal_number' => 'Reverses', 'created_at' => 'Created at', 'posted_at' => 'Posted at']],
            'receivables' => ['title' => 'Accounts Receivable', 'rows' => $this->invoices($company, 'customer_invoice', $filters), 'columns' => $this->invoiceColumns('customer_name', 'Customer')],
            'payables' => ['title' => 'Accounts Payable', 'rows' => $this->invoices($company, 'vendor_bill', $filters), 'columns' => $this->invoiceColumns('supplier_name', 'Supplier') + ['supplier_invoice_number' => 'Supplier invoice', 'po_number' => 'PO']],
            'cash-bank' => ['title' => 'Cash & Bank', 'rows' => $this->cashBank($company, $filters), 'columns' => ['posting_date' => 'Date', 'batch_number' => 'Journal', 'source_number' => 'Reference', 'account_code' => 'Account', 'account_name' => 'Name', 'debit_amount' => 'Receipt / Debit', 'credit_amount' => 'Payment / Credit', 'currency' => 'Currency']],
            'reports' => ['title' => 'Finance Reports', 'selected_report' => $report, 'reports' => $report === '' ? [] : $this->reports($company, $filters, $report)],
        } + ['section' => $section, 'filters' => $filters];
    }

    private function date(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') return '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new RuntimeException('Invalid date filter.');
        return $value;
    }

    private function rows(string $sql, array $params): array
    {
        $query = \db()->prepare($sql);
        $query->execute($params);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private function accounts(int $company): array
    {
        return $this->rows('SELECT account_code,account_name,account_type,normal_balance,currency,system_key,active FROM finance_accounts WHERE company_id=:company AND deleted_at IS NULL ORDER BY account_code', ['company' => $company]);
    }

    private function ledger(int $company, array $filters): array
    {
        $where = ["b.company_id=:company", "b.status='posted'"];
        $params = ['company' => $company];
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if ($filters[$key] !== '') { $where[] = "b.posting_date $operator :$key"; $params[$key] = $filters[$key]; }
        }
        if ($filters['currency'] !== '') { $where[] = 'b.currency=:currency'; $params['currency'] = $filters['currency']; }
        return $this->rows('SELECT b.posting_date,b.batch_number,b.journal_batch_id,b.source_type,b.source_id,b.source_number,b.created_at,b.posted_at,u.display_name poster_name,original.batch_number reversal_number,e.description,e.debit_amount,e.credit_amount,e.currency,a.account_code,a.account_name FROM finance_journal_entries e JOIN finance_journal_batches b ON b.company_id=e.company_id AND b.journal_batch_id=e.journal_batch_id JOIN finance_accounts a ON a.company_id=e.company_id AND a.account_id=e.account_id LEFT JOIN users u ON u.user_id=b.posted_by LEFT JOIN finance_journal_batches original ON original.company_id=b.company_id AND original.journal_batch_id=b.reversal_of_batch_id WHERE '.implode(' AND ', $where).' ORDER BY b.posting_date DESC,b.journal_batch_id DESC,e.line_number LIMIT 500', $params);
    }

    private function invoiceColumns(string $party, string $label): array
    {
        return [$party => $label, 'invoice_number' => 'ERP document', 'invoice_date' => 'Date', 'due_date' => 'Due', 'total_amount' => 'Original', 'paid_amount' => 'Paid', 'residual_amount' => 'Outstanding', 'currency' => 'Currency', 'payment_status' => 'Payment', 'aging_bucket' => 'Aging'];
    }

    private function invoices(int $company, string $type, array $filters): array
    {
        $payable = $type === 'vendor_bill';
        $party = $payable ? 's.business_name supplier_name' : 'c.name customer_name';
        $join = $payable ? 'JOIN purchase_suppliers s ON s.company_id=i.company_id AND s.supplier_id=i.vendor_id LEFT JOIN purchase_orders o ON o.company_id=i.company_id AND o.purchase_order_id=i.purchase_order_id' : 'JOIN sales_customers c ON c.company_id=i.company_id AND c.customer_id=i.customer_id';
        $extra = $payable ? ',i.supplier_invoice_number,o.po_number,i.purchase_order_id' : '';
        $where = ["i.company_id=:company", 'i.document_type=:type', "i.status='posted'"];
        $params = ['company' => $company, 'type' => $type];
        if ($filters['currency'] !== '') { $where[] = 'i.currency=:currency'; $params['currency'] = $filters['currency']; }
        if ($filters['from'] !== '') { $where[] = 'i.invoice_date>=:from'; $params['from'] = $filters['from']; }
        if ($filters['to'] !== '') { $where[] = 'i.invoice_date<=:to'; $params['to'] = $filters['to']; }
        $sql = "SELECT $party,i.invoice_id,i.invoice_number,i.invoice_date,i.due_date,i.total_amount,ROUND(i.total_amount-i.residual_amount,2) paid_amount,i.residual_amount,i.currency,i.payment_status,CASE WHEN i.residual_amount<=0 THEN 'Settled' WHEN i.due_date>=CURRENT_DATE THEN 'Current' WHEN DATEDIFF(CURRENT_DATE,i.due_date)<=30 THEN '1-30' WHEN DATEDIFF(CURRENT_DATE,i.due_date)<=60 THEN '31-60' WHEN DATEDIFF(CURRENT_DATE,i.due_date)<=90 THEN '61-90' ELSE '90+' END aging_bucket $extra FROM finance_invoices i $join WHERE ".implode(' AND ', $where).' ORDER BY i.due_date ASC,i.invoice_id DESC LIMIT 500';
        return $this->rows($sql, $params);
    }

    private function cashBank(int $company, array $filters): array
    {
        $where = ["b.company_id=:company", "b.status='posted'", "a.system_key='cash'"];
        $params = ['company' => $company];
        if ($filters['currency'] !== '') { $where[] = 'b.currency=:currency'; $params['currency'] = $filters['currency']; }
        if ($filters['from'] !== '') { $where[] = 'b.posting_date>=:from'; $params['from'] = $filters['from']; }
        if ($filters['to'] !== '') { $where[] = 'b.posting_date<=:to'; $params['to'] = $filters['to']; }
        return $this->rows('SELECT b.posting_date,b.batch_number,b.source_number,a.account_code,a.account_name,e.debit_amount,e.credit_amount,e.currency FROM finance_journal_entries e JOIN finance_journal_batches b ON b.company_id=e.company_id AND b.journal_batch_id=e.journal_batch_id JOIN finance_accounts a ON a.company_id=e.company_id AND a.account_id=e.account_id WHERE '.implode(' AND ', $where).' ORDER BY b.posting_date DESC,b.journal_batch_id DESC LIMIT 500', $params);
    }

    private function reports(int $company, array $filters, string $selected): array
    {
        $where = ["b.company_id=:company", "b.status='posted'"];
        $params = ['company' => $company];
        if ($filters['currency'] !== '') { $where[] = 'b.currency=:currency'; $params['currency'] = $filters['currency']; }
        if ($filters['from'] !== '') { $where[] = 'b.posting_date>=:from'; $params['from'] = $filters['from']; }
        if ($filters['to'] !== '') { $where[] = 'b.posting_date<=:to'; $params['to'] = $filters['to']; }
        $sql='SELECT a.account_code,a.account_name,a.account_type,b.currency,SUM(e.debit_amount) debit,SUM(e.credit_amount) credit,SUM(e.debit_amount-e.credit_amount) net FROM finance_journal_entries e JOIN finance_journal_batches b ON b.company_id=e.company_id AND b.journal_batch_id=e.journal_batch_id JOIN finance_accounts a ON a.company_id=e.company_id AND a.account_id=e.account_id WHERE ';
        $group=' GROUP BY a.account_id,a.account_code,a.account_name,a.account_type,b.currency ORDER BY b.currency,a.account_code';
        $trial=$selected === 'balance-sheet' ? [] : $this->rows($sql.implode(' AND ',$where).$group,$params);
        $asOfWhere=array_values(array_filter($where,static fn(string $condition):bool=>!str_contains($condition,':from')));$asOfParams=$params;unset($asOfParams['from']);
        if($filters['to']==='')$asOfWhere[]='b.posting_date<=CURRENT_DATE';
        $asOf=$selected === 'balance-sheet' ? $this->rows($sql.implode(' AND ',$asOfWhere).$group,$asOfParams) : [];
        $pnl=array_values(array_filter($trial,static fn(array $r):bool=>in_array($r['account_type'],['revenue','expense'],true)));
        $balance=array_values(array_filter($asOf,static fn(array $r):bool=>in_array($r['account_type'],['asset','liability','equity'],true)));
        $trialTotals=[];$pnlTotals=[];$balanceTotals=[];
        foreach($trial as $row){$currency=$row['currency'];$trialTotals[$currency]['debit']=round(($trialTotals[$currency]['debit']??0)+(float)$row['debit'],2);$trialTotals[$currency]['credit']=round(($trialTotals[$currency]['credit']??0)+(float)$row['credit'],2);}
        foreach($pnl as $row){$currency=$row['currency'];$kind=$row['account_type'];$pnlTotals[$currency][$kind]=round(($pnlTotals[$currency][$kind]??0)+($kind==='revenue'?(float)$row['credit']-(float)$row['debit']:(float)$row['debit']-(float)$row['credit']),2);}
        foreach($asOf as $row){$currency=$row['currency'];$kind=$row['account_type'];if(in_array($kind,['revenue','expense'],true)){$balanceTotals[$currency]['current_earnings']=round(($balanceTotals[$currency]['current_earnings']??0)+($kind==='revenue'?(float)$row['credit']-(float)$row['debit']:(float)$row['credit']-(float)$row['debit']),2);}else{$balanceTotals[$currency][$kind]=round(($balanceTotals[$currency][$kind]??0)+($kind==='asset'?(float)$row['debit']-(float)$row['credit']:(float)$row['credit']-(float)$row['debit']),2);}}
        return ['trial_balance'=>$trial,'trial_totals'=>$trialTotals,'profit_loss'=>$pnl,'profit_loss_totals'=>$pnlTotals,'balance_sheet'=>$balance,'balance_sheet_totals'=>$balanceTotals];
    }
}
