<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class FinanceExpenseService
{
    private function company(): int { return (new TenantContext())->companyId(); }

    public function workspace(array $filters = []): array
    {
        $company = $this->company();
        $status = trim((string)($filters['status'] ?? ''));
        $search = mb_substr(trim((string)($filters['search'] ?? '')), 0, 100);
        if (!in_array($status, ['', 'draft', 'submitted', 'approved', 'rejected', 'paid', 'reversed', 'cancelled'], true)) $status = '';
        $query = static function (string $sql, int $company): array {
            $statement = \db()->prepare($sql);
            $statement->execute(['company' => $company]);
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        };
        return [
            'expenses' => $this->expenseRows($company, $status, $search),
            'filters' => ['status' => $status, 'search' => $search],
            'employees' => $query('SELECT employee_id,employee_number,first_name,last_name FROM hr_employees WHERE company_id=:company AND deleted_at IS NULL ORDER BY first_name,last_name LIMIT 500', $company),
            'categories' => $query('SELECT category_id,name FROM finance_expense_categories WHERE company_id=:company AND active=TRUE AND deleted_at IS NULL ORDER BY name', $company),
            'accounts' => $query("SELECT account_id,account_code,account_name,account_type FROM finance_accounts WHERE company_id=:company AND active=TRUE AND deleted_at IS NULL AND account_type IN('expense','asset') ORDER BY account_code", $company),
            'journals' => $query("SELECT journal_id,journal_code,journal_name,journal_type FROM finance_journals WHERE company_id=:company AND active=TRUE AND journal_type IN('cash','bank') ORDER BY journal_name", $company),
            'history' => $query('SELECT h.expense_request_id,h.from_status,h.to_status,h.action,h.reason,h.actor_id,h.occurred_at FROM finance_expense_history h JOIN finance_expense_requests r ON r.company_id=h.company_id AND r.expense_request_id=h.expense_request_id WHERE h.company_id=:company AND r.deleted_at IS NULL ORDER BY h.history_id DESC LIMIT 500', $company),
        ];
    }

    private function expenseRows(int $company, string $status, string $search): array
    {
        $sql = "SELECT r.*,CONCAT(e.first_name,' ',e.last_name) employee_name,c.name category_name,a.account_code,a.account_name,b.batch_number FROM finance_expense_requests r JOIN hr_employees e ON e.company_id=r.company_id AND e.employee_id=r.requested_by_employee_id LEFT JOIN finance_expense_categories c ON c.company_id=r.company_id AND c.category_id=r.category_id LEFT JOIN finance_accounts a ON a.company_id=r.company_id AND a.account_id=r.expense_account_id LEFT JOIN finance_journal_batches b ON b.company_id=r.company_id AND b.journal_batch_id=r.journal_batch_id WHERE r.company_id=:company AND r.deleted_at IS NULL";
        $params = ['company' => $company];
        if ($status !== '') { $sql .= ' AND r.status=:status'; $params['status'] = $status; }
        if ($search !== '') { $sql .= ' AND (r.request_number LIKE :search OR r.title LIKE :title)'; $params['search'] = '%'.$search.'%'; $params['title'] = '%'.$search.'%'; }
        $statement = \db()->prepare($sql.' ORDER BY r.created_at DESC,r.expense_request_id DESC LIMIT 100');
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function save(array $input, int $actor, ?int $id = null): int
    {
        $company = $this->company();
        $employee = (int)($input['employee_id'] ?? 0);
        $category = (int)($input['category_id'] ?? 0);
        $account = (int)($input['expense_account_id'] ?? 0);
        $taxAccount = (int)($input['tax_account_id'] ?? 0);
        $kind = trim((string)($input['expense_kind'] ?? ''));
        $date = $this->date($input['expense_date'] ?? '');
        $currency = strtoupper(trim((string)($input['currency'] ?? '')));
        $title = mb_substr(trim((string)($input['title'] ?? '')), 0, 150);
        $net = round((float)($input['net_amount'] ?? 0), 2);
        $tax = round((float)($input['tax_amount'] ?? 0), 2);
        if ($actor < 1 || $employee < 1 || $account < 1 || $title === '' || $net <= 0 || $tax < 0 || !is_finite($net) || !is_finite($tax) || !in_array($kind, ['company_paid','reimbursement','petty_cash'], true) || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || ($tax > 0 && $taxAccount < 1)) throw new RuntimeException('Complete the employee, expense account, type, date, currency and positive amounts. Tax requires an asset tax account.');
        $db = \db(); $db->beginTransaction();
        try {
            $this->assertReference('hr_employees','employee_id',$employee,$company,'deleted_at IS NULL');
            if ($category > 0) $this->assertReference('finance_expense_categories','category_id',$category,$company,'active=TRUE AND deleted_at IS NULL');
            $this->assertReference('finance_accounts','account_id',$account,$company,"active=TRUE AND deleted_at IS NULL AND account_type='expense'");
            if ($tax > 0) $this->assertReference('finance_accounts','account_id',$taxAccount,$company,"active=TRUE AND deleted_at IS NULL AND account_type='asset'");
            $this->assertAccountCurrency($company,$account,$currency);
            if ($tax > 0) $this->assertAccountCurrency($company,$taxAccount,$currency);
            if ($id !== null) {
                $existing = $this->locked($company,$id);
                if ($existing['status'] !== 'draft' || (int)$existing['created_by'] !== $actor) throw new RuntimeException('Only the creator may edit a draft expense.');
                $statement = $db->prepare('UPDATE finance_expense_requests SET requested_by_employee_id=:employee,category_id=:category,expense_kind=:kind,expense_account_id=:account,tax_account_id=:tax_account,net_amount=:net,tax_amount=:tax,amount=:amount,currency=:currency,expense_date=:date,title=:title,description=:description,evidence_reference=:evidence,updated_by=:actor WHERE company_id=:company AND expense_request_id=:id AND status=\'draft\'');
                $statement->execute(['employee'=>$employee,'category'=>$category?:null,'kind'=>$kind,'account'=>$account,'tax_account'=>$tax>0?$taxAccount:null,'net'=>$net,'tax'=>$tax,'amount'=>round($net+$tax,2),'currency'=>$currency,'date'=>$date,'title'=>$title,'description'=>trim((string)($input['description'] ?? '')),'evidence'=>mb_substr(trim((string)($input['evidence_reference']??'')),0,500)?:null,'actor'=>$actor,'company'=>$company,'id'=>$id]);
            } else {
                $number = 'EXP-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
                $statement = $db->prepare("INSERT INTO finance_expense_requests(company_id,request_number,requested_by_employee_id,category_id,title,description,evidence_reference,amount,net_amount,tax_amount,expense_account_id,tax_account_id,expense_kind,currency,expense_date,status,created_by,updated_by) VALUES(:company,:number,:employee,:category,:title,:description,:evidence,:amount,:net,:tax,:account,:tax_account,:kind,:currency,:date,'draft',:actor,:updated)");
                $statement->execute(['company'=>$company,'number'=>$number,'employee'=>$employee,'category'=>$category?:null,'title'=>$title,'description'=>trim((string)($input['description'] ?? '')),'evidence'=>mb_substr(trim((string)($input['evidence_reference']??'')),0,500)?:null,'amount'=>round($net+$tax,2),'net'=>$net,'tax'=>$tax,'account'=>$account,'tax_account'=>$tax>0?$taxAccount:null,'kind'=>$kind,'currency'=>$currency,'date'=>$date,'actor'=>$actor,'updated'=>$actor]);
                $id = (int)$db->lastInsertId();
                $this->history($company,$id,null,'draft','created',null,$actor);
            }
            $db->commit(); return $id;
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }

    public function transition(int $id, string $action, int $actor, string $reason = ''): void
    {
        $company=$this->company(); $db=\db(); $db->beginTransaction();
        try {
            $row=$this->locked($company,$id); $from=(string)$row['status'];
            $to=match($action){'submit'=>'submitted','approve'=>'approved','reject'=>'rejected','cancel'=>'cancelled',default=>throw new RuntimeException('Invalid expense action.')};
            $allowed=['submit'=>'draft','approve'=>'submitted','reject'=>'submitted','cancel'=>'draft'];
            if ($from!==$allowed[$action]) throw new RuntimeException('Expense is not in the required state.');
            if (in_array($action,['submit','cancel'],true) && (int)$row['created_by']!==$actor) throw new RuntimeException('Only the creator may submit or cancel this draft.');
            if (in_array($action,['approve','reject'],true) && (int)$row['created_by']===$actor) throw new RuntimeException('The requester cannot review their own expense.');
            if ($action==='reject' && trim($reason)==='') throw new RuntimeException('A rejection reason is required.');
            $statement=$db->prepare('UPDATE finance_expense_requests SET status=:status,submitted_at=IF(:action_submit=\'submit\',NOW(),submitted_at),reviewed_by=IF(:action_review IN(\'approve\',\'reject\'),:actor,reviewed_by),reviewed_at=IF(:action_time IN(\'approve\',\'reject\'),NOW(),reviewed_at),review_notes=:notes,updated_by=:updated WHERE company_id=:company AND expense_request_id=:id');
            $statement->execute(['status'=>$to,'action_submit'=>$action,'action_review'=>$action,'action_time'=>$action,'actor'=>$actor,'notes'=>$reason?:null,'updated'=>$actor,'company'=>$company,'id'=>$id]);
            $this->history($company,$id,$from,$to,$action,$reason?:null,$actor);
            $notices=new UserNotificationService($db);
            if($action==='submit')$notices->notifyFinanceAction($company,'finance.requests.approve',$actor,'finance.expense.review','Expense review required','Review '.$row['request_number'],'expense',$id,'/finance/expenses#expense-'.$id,'finance.expense.review.'.$id);
            if(in_array($action,['approve','reject','cancel'],true))$notices->resolveFinanceActions($company,'expense',$id);
            if($action==='approve')$notices->notifyFinanceAction($company,'finance.records.manage',0,'finance.expense.process','Approved expense needs processing',($row['expense_kind']==='reimbursement'?'Recognize reimbursement ':'Pay expense ').$row['request_number'],'expense',$id,'/finance/expenses#expense-'.$id,'finance.expense.process.'.$id);
            $db->commit();
        } catch(Throwable $e){ if($db->inTransaction())$db->rollBack(); throw $e; }
    }

    public function pay(int $id, int $journalId, string $date, int $actor): void
    {
        $company=$this->company(); $date=$this->date($date); $db=\db(); $db->beginTransaction();
        try {
            $row=$this->locked($company,$id);
            if ($row['status']==='paid') { $db->commit(); return; }
            if ($row['status']!=='approved' || (int)$row['expense_account_id']<1) throw new RuntimeException('Only an approved, fully configured expense can be paid.');
            $journal=$db->prepare("SELECT journal_type,default_debit_account_id FROM finance_journals WHERE company_id=:company AND journal_id=:id AND active=TRUE AND journal_type IN('bank','cash') FOR UPDATE");
            $journal->execute(['company'=>$company,'id'=>$journalId]); $cash=$journal->fetch(PDO::FETCH_ASSOC);
            if (!is_array($cash) || (int)$cash['default_debit_account_id']<1) throw new RuntimeException('Select an active cash or bank journal with a configured account.');
            $this->assertAccountCurrency($company,(int)$cash['default_debit_account_id'],(string)$row['currency']);
            if ($row['expense_kind']==='petty_cash' && $cash['journal_type']!=='cash') throw new RuntimeException('Petty cash expenses require a cash journal.');
            if ($row['expense_kind']==='reimbursement') {
                if ((int)$row['recognition_batch_id']<1) throw new RuntimeException('Recognize the employee payable before reimbursement.');
                $recognized=$db->prepare('SELECT posting_date FROM finance_journal_batches WHERE company_id=:company AND journal_batch_id=:batch');$recognized->execute(['company'=>$company,'batch'=>$row['recognition_batch_id']]);if($date<(string)$recognized->fetchColumn())throw new RuntimeException('Reimbursement cannot precede expense recognition.');
                $payable=$this->employeePayableAccount($company,(string)$row['currency'],$actor);
                $lines=[['account_id'=>$payable,'debit'=>(float)$row['amount'],'credit'=>0,'description'=>'Settle employee payable']];
            } else {
                $lines=$this->expenseDebitLines($row);
            }
            $lines[]=['account_id'=>(int)$cash['default_debit_account_id'],'debit'=>0,'credit'=>(float)$row['amount'],'description'=>'Expense payment'];
            $posted=(new FinancePostingService())->postBalancedJournal($company,'EXP-'.$id,'expense',(string)$id,(string)$row['request_number'],$date,(string)$row['currency'],'Expense '.$row['request_number'],'finance-expense-'.$company.'-'.$id,$lines,$actor);
            $update=$db->prepare("UPDATE finance_expense_requests SET status='paid',paid_at=NOW(),paid_by=:actor,payment_journal_id=:journal,journal_batch_id=:batch,settlement_method=:method,updated_by=:updated WHERE company_id=:company AND expense_request_id=:id AND status='approved'");
            $update->execute(['actor'=>$actor,'journal'=>$journalId,'batch'=>$posted['journalBatchId'],'method'=>$cash['journal_type'],'updated'=>$actor,'company'=>$company,'id'=>$id]);
            if ($update->rowCount()!==1) throw new RuntimeException('Expense state changed before payment completed.');
            $this->history($company,$id,'approved','paid','paid',null,$actor);
            (new UserNotificationService($db))->resolveFinanceActions($company,'expense',$id);
            $db->commit();
        } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public function recognize(int $id,string $date,int $actor): void
    {
        $company=$this->company();$date=$this->date($date);$db=\db();$db->beginTransaction();
        try{
            $row=$this->locked($company,$id);
            if((int)$row['recognition_batch_id']>0){$db->commit();return;}
            if($row['status']!=='approved'||$row['expense_kind']!=='reimbursement')throw new RuntimeException('Only an approved employee reimbursement can be recognized.');
            $payable=$this->employeePayableAccount($company,(string)$row['currency'],$actor);
            $lines=$this->expenseDebitLines($row);$lines[]=['account_id'=>$payable,'debit'=>0,'credit'=>(float)$row['amount'],'description'=>'Employee reimbursement payable'];
            $posted=(new FinancePostingService())->postBalancedJournal($company,'EXPA-'.$id,'expense_reimbursement_accrual',(string)$id,(string)$row['request_number'],$date,(string)$row['currency'],'Accrue reimbursement '.$row['request_number'],'finance-expense-accrual-'.$company.'-'.$id,$lines,$actor);
            $update=$db->prepare('UPDATE finance_expense_requests SET recognition_batch_id=:batch,recognized_at=NOW(),recognized_by=:actor WHERE company_id=:company AND expense_request_id=:id AND status=\'approved\' AND recognition_batch_id IS NULL');$update->execute(['batch'=>$posted['journalBatchId'],'actor'=>$actor,'company'=>$company,'id'=>$id]);if($update->rowCount()!==1)throw new RuntimeException('Expense state changed before recognition completed.');
            $this->history($company,$id,'approved','approved','recognized',null,$actor);$notices=new UserNotificationService($db);$notices->resolveFinanceActions($company,'expense',$id);$notices->notifyFinanceAction($company,'finance.records.manage',0,'finance.expense.process','Reimbursement ready for payment','Pay reimbursement '.$row['request_number'],'expense',$id,'/finance/expenses#expense-'.$id,'finance.expense.pay.'.$id);$db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public function reverse(int $id,string $date,string $reason,int $actor): void
    {
        $company=$this->company();$date=$this->date($date);$reason=mb_substr(trim($reason),0,500);if($reason==='')throw new RuntimeException('A reversal reason is required.');$db=\db();$db->beginTransaction();
        try{
            $row=$this->locked($company,$id);
            if($row['status']==='reversed'){$db->commit();return;}
            if($row['status']!=='paid'||(int)$row['journal_batch_id']<1)throw new RuntimeException('Only a paid expense can be reversed.');
            if((int)$row['paid_by']===$actor)throw new RuntimeException('The payment processor cannot reverse their own expense posting.');
            $original=$db->prepare('SELECT posting_date FROM finance_journal_batches WHERE company_id=:company AND journal_batch_id=:batch');$original->execute(['company'=>$company,'batch'=>$row['journal_batch_id']]);if($date<(string)$original->fetchColumn())throw new RuntimeException('Reversal cannot precede the original payment.');
            $paymentReversal=$this->reverseBatch($company,(int)$row['journal_batch_id'],'EXPR-'.$id,'expense_payment_reversal',$id,$row,$date,$actor);
            $recognitionReversal=null;
            if((int)$row['recognition_batch_id']>0)$recognitionReversal=$this->reverseBatch($company,(int)$row['recognition_batch_id'],'EXAR-'.$id,'expense_accrual_reversal',$id,$row,$date,$actor);
            $update=$db->prepare("UPDATE finance_expense_requests SET status='reversed',reversal_batch_id=:payment,recognition_reversal_batch_id=:recognition,reversed_by=:actor,reversed_at=NOW(),reversal_reason=:reason WHERE company_id=:company AND expense_request_id=:id AND status='paid'");$update->execute(['payment'=>$paymentReversal,'recognition'=>$recognitionReversal,'actor'=>$actor,'reason'=>$reason,'company'=>$company,'id'=>$id]);if($update->rowCount()!==1)throw new RuntimeException('Expense state changed before reversal completed.');
            $this->history($company,$id,'paid','reversed','reversed',$reason,$actor);(new UserNotificationService($db))->resolveFinanceActions($company,'expense',$id);$db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    private function reverseBatch(int $company,int $batchId,string $number,string $source,int $expenseId,array $expense,string $date,int $actor): int
    {
        $query=\db()->prepare("SELECT e.account_id,e.debit_amount,e.credit_amount,e.description FROM finance_journal_entries e JOIN finance_journal_batches b ON b.company_id=e.company_id AND b.journal_batch_id=e.journal_batch_id WHERE e.company_id=:company AND e.journal_batch_id=:batch AND b.status='posted' ORDER BY e.line_number FOR UPDATE");$query->execute(['company'=>$company,'batch'=>$batchId]);$entries=$query->fetchAll(PDO::FETCH_ASSOC);if(count($entries)<2)throw new RuntimeException('Original posted journal is unavailable for reversal.');
        $lines=[];foreach($entries as $entry)$lines[]=['account_id'=>(int)$entry['account_id'],'debit'=>(float)$entry['credit_amount'],'credit'=>(float)$entry['debit_amount'],'description'=>'Reverse '.($entry['description']??'expense')];
        $posted=(new FinancePostingService())->postBalancedJournal($company,$number,$source,(string)$expenseId,(string)$expense['request_number'],$date,(string)$expense['currency'],'Reverse expense '.$expense['request_number'],'finance-expense-reversal-'.$company.'-'.$number,$lines,$actor);
        return(int)$posted['journalBatchId'];
    }

    private function expenseDebitLines(array $row): array
    {
        if((int)$row['expense_account_id']<1||(float)$row['net_amount']<=0)throw new RuntimeException('Expense account and net amount are required.');
        $this->assertAccountCurrency((int)$row['company_id'],(int)$row['expense_account_id'],(string)$row['currency']);
        $lines=[['account_id'=>(int)$row['expense_account_id'],'debit'=>(float)$row['net_amount'],'credit'=>0,'description'=>(string)$row['title']]];
        if((float)$row['tax_amount']>0){if((int)$row['tax_account_id']<1)throw new RuntimeException('Recoverable tax account is required.');$this->assertAccountCurrency((int)$row['company_id'],(int)$row['tax_account_id'],(string)$row['currency']);$lines[]=['account_id'=>(int)$row['tax_account_id'],'debit'=>(float)$row['tax_amount'],'credit'=>0,'description'=>'Recoverable tax per approved expense'];}
        return $lines;
    }

    private function employeePayableAccount(int $company,string $currency,int $actor): int
    {
        $lock=\db()->prepare('SELECT company_id FROM companies WHERE company_id=:company AND deleted_at IS NULL FOR UPDATE');$lock->execute(['company'=>$company]);if($lock->fetchColumn()===false)throw new RuntimeException('Company was not found.');
        $query=\db()->prepare("SELECT account_id,account_type,currency,active FROM finance_accounts WHERE company_id=:company AND system_key='employee_payable' AND deleted_at IS NULL FOR UPDATE");$query->execute(['company'=>$company]);$row=$query->fetch(PDO::FETCH_ASSOC);
        if(!$row){$insert=\db()->prepare("INSERT INTO finance_accounts(company_id,account_code,account_name,account_type,normal_balance,system_key,currency,active,allow_manual_posting,created_by,updated_by) VALUES(:company,'EMP-PAYABLE','Employee Reimbursements Payable','liability','credit','employee_payable',NULL,TRUE,FALSE,:actor,:updated)");$insert->execute(['company'=>$company,'actor'=>$actor,'updated'=>$actor]);return(int)\db()->lastInsertId();}
        if($row['account_type']!=='liability'||!(bool)$row['active']||($row['currency']!==null&&$row['currency']!==$currency))throw new RuntimeException('Employee payable account is not configured for this currency.');return(int)$row['account_id'];
    }

    private function date(mixed $value): string
    {
        $value=trim((string)$value); $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$parsed || $parsed->format('Y-m-d')!==$value) throw new RuntimeException('Enter a valid date.');
        return $value;
    }
    private function assertReference(string $table,string $column,int $id,int $company,string $condition): void
    {
        $query=\db()->prepare("SELECT $column FROM $table WHERE company_id=:company AND $column=:id AND $condition FOR UPDATE");
        $query->execute(['company'=>$company,'id'=>$id]);
        if($query->fetchColumn()===false)throw new RuntimeException('A selected employee, category or account is unavailable in this company.');
    }
    private function assertAccountCurrency(int $company,int $account,string $currency): void
    {
        $query=\db()->prepare('SELECT currency FROM finance_accounts WHERE company_id=:company AND account_id=:account AND active=TRUE AND deleted_at IS NULL');
        $query->execute(['company'=>$company,'account'=>$account]);
        $value=$query->fetchColumn();
        if($value===false || ($value!==null && $value!==$currency))throw new RuntimeException('The selected account currency does not match the expense currency.');
    }
    private function locked(int $company,int $id): array
    {
        $query=\db()->prepare('SELECT * FROM finance_expense_requests WHERE company_id=:company AND expense_request_id=:id AND deleted_at IS NULL FOR UPDATE');
        $query->execute(['company'=>$company,'id'=>$id]); $row=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new RuntimeException('Expense was not found in this company.');
        return $row;
    }
    private function history(int $company,int $id,?string $from,string $to,string $action,?string $reason,int $actor): void
    {
        $query=\db()->prepare('INSERT INTO finance_expense_history(company_id,expense_request_id,from_status,to_status,action,reason,actor_id) VALUES(:company,:id,:from,:to,:action,:reason,:actor)');
        $query->execute(['company'=>$company,'id'=>$id,'from'=>$from,'to'=>$to,'action'=>$action,'reason'=>$reason,'actor'=>$actor]);
    }
}
