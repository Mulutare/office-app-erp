<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class FinanceStaffLoanService
{
    private function company(): int { return (new TenantContext())->companyId(); }

    public function workspace(string $filter = ''): array
    {
        $company=$this->company();
        $allowed=['','active','paid','overdue']; if(!in_array($filter,$allowed,true))$filter='';
        $where=$filter==='overdue' ? "AND l.status IN('disbursed','active') AND EXISTS(SELECT 1 FROM finance_staff_loan_installments i WHERE i.company_id=l.company_id AND i.loan_id=l.loan_id AND i.remaining_due>0 AND i.due_date<CURRENT_DATE)" : ($filter==='active' ? "AND l.status IN('disbursed','active')" : ($filter!=='' ? "AND l.status=:status" : ''));
        $params=['company'=>$company]; if($filter==='paid')$params['status']=$filter;
        $statement=\db()->prepare("SELECT l.*,CONCAT(e.first_name,' ',e.last_name) employee_name,(SELECT COUNT(*) FROM finance_staff_loan_installments i WHERE i.company_id=l.company_id AND i.loan_id=l.loan_id AND i.remaining_due>0) installments_remaining,(SELECT COALESCE(SUM(i.remaining_due),0) FROM finance_staff_loan_installments i WHERE i.company_id=l.company_id AND i.loan_id=l.loan_id AND i.remaining_due>0 AND i.due_date<CURRENT_DATE AND l.status IN('disbursed','active')) overdue_amount FROM finance_staff_loans l JOIN hr_employees e ON e.company_id=l.company_id AND e.employee_id=l.employee_id WHERE l.company_id=:company $where ORDER BY l.loan_id DESC LIMIT 100");
        $statement->execute($params);
        $summary=$this->rows("SELECT currency,COUNT(*) active_loans,COALESCE(SUM(outstanding_principal),0) outstanding_principal FROM finance_staff_loans WHERE company_id=:company AND status IN('disbursed','active') GROUP BY currency ORDER BY currency",['company'=>$company]);
        $due=$this->rows("SELECT l.currency,COALESCE(SUM(CASE WHEN i.due_date BETWEEN DATE_FORMAT(CURRENT_DATE,'%Y-%m-01') AND LAST_DAY(CURRENT_DATE) THEN i.remaining_due ELSE 0 END),0) due_this_month,COALESCE(SUM(CASE WHEN i.due_date<CURRENT_DATE THEN i.remaining_due ELSE 0 END),0) overdue_amount FROM finance_staff_loan_installments i JOIN finance_staff_loans l ON l.company_id=i.company_id AND l.loan_id=i.loan_id WHERE i.company_id=:company AND l.status IN('disbursed','active') AND i.remaining_due>0 GROUP BY l.currency",['company'=>$company]);$dueMap=[];foreach($due as $item)$dueMap[$item['currency']]=$item;foreach($summary as &$item)$item+=($dueMap[$item['currency']]??['due_this_month'=>0,'overdue_amount'=>0]);unset($item);
        return ['loans'=>$statement->fetchAll(PDO::FETCH_ASSOC),'summary'=>$summary,'employees'=>$this->rows('SELECT employee_id,employee_number,first_name,last_name FROM hr_employees WHERE company_id=:company AND deleted_at IS NULL ORDER BY first_name,last_name LIMIT 500',['company'=>$company]),'journals'=>$this->journals(),'filter'=>$filter];
    }

    public function detail(int $id): ?array
    {
        $company=$this->company();
        $rows=$this->rows("SELECT l.*,CONCAT(e.first_name,' ',e.last_name) employee_name FROM finance_staff_loans l JOIN hr_employees e ON e.company_id=l.company_id AND e.employee_id=l.employee_id WHERE l.company_id=:company AND l.loan_id=:id",['company'=>$company,'id'=>$id]);
        if(!$rows)return null;
        $loan=$rows[0];
        $loan['installments']=$this->rows('SELECT * FROM finance_staff_loan_installments WHERE company_id=:company AND loan_id=:id ORDER BY installment_number',['company'=>$company,'id'=>$id]);
        $loan['payments']=$this->rows('SELECT p.*,b.batch_number FROM finance_staff_loan_payments p JOIN finance_journal_batches b ON b.company_id=p.company_id AND b.journal_batch_id=p.journal_batch_id WHERE p.company_id=:company AND p.loan_id=:id ORDER BY p.payment_date,p.loan_payment_id',['company'=>$company,'id'=>$id]);
        $loan['history']=$this->rows('SELECT * FROM finance_staff_loan_history WHERE company_id=:company AND loan_id=:id ORDER BY history_id DESC',['company'=>$company,'id'=>$id]);
        $loan['installments_remaining']=count(array_filter($loan['installments'],static fn(array $i):bool=>(float)$i['remaining_due']>0));
        $isDisbursed=in_array($loan['status'],['disbursed','active'],true);
        $loan['overdue_amount']=$isDisbursed?array_sum(array_map(static fn(array $i):float=>((float)$i['remaining_due']>0 && $i['due_date']<date('Y-m-d'))?(float)$i['remaining_due']:0,$loan['installments'])):0;
        $loan['days_overdue']=0;
        foreach($loan['installments'] as $i){if($isDisbursed && (float)$i['remaining_due']>0 && $i['due_date']<date('Y-m-d')){$loan['days_overdue']=(int)(new \DateTimeImmutable($i['due_date']))->diff(new \DateTimeImmutable('today'))->days;break;}}
        $loan['next_installment_amount']=0;
        foreach($loan['installments'] as $i){if((float)$i['remaining_due']>0){$loan['next_installment_amount']=$i['remaining_due'];break;}}
        $loan['journals']=$this->journals();
        return $loan;
    }

    public function create(array $input,int $actor): int
    {
        $company=$this->company(); $employee=(int)($input['employee_id']??0); $principal=$this->cents($input['principal_amount']??0); $count=(int)($input['installment_count']??0); $rate=(float)($input['interest_rate']??0);
        $currency=strtoupper(trim((string)($input['currency']??''))); $type=trim((string)($input['loan_type']??'')); $purpose=trim((string)($input['purpose']??'')); $frequency=trim((string)($input['installment_frequency']??''));
        $requested=$this->date($input['request_date']??''); $first=$this->date($input['first_due_date']??'');
        if($actor<1||$employee<1||$principal<1||$count<1||$count>360||$count>$principal||!is_finite($rate)||$rate<0||$rate>100||!in_array($frequency,['weekly','monthly'],true)||!in_array($type,['loan','advance'],true)||$purpose===''||strlen($purpose)>500||preg_match('/^[A-Z]{3}$/',$currency)!==1||$first<$requested)throw new RuntimeException('Enter valid employee, loan terms and a due date after the request date.');
        $db=\db();$db->beginTransaction();
        try{
            $employeeRow=$db->prepare('SELECT employee_id FROM hr_employees WHERE company_id=:company AND employee_id=:employee AND deleted_at IS NULL FOR UPDATE');$employeeRow->execute(['company'=>$company,'employee'=>$employee]);if($employeeRow->fetchColumn()===false)throw new RuntimeException('Employee is not in this company.');
            $number='SL-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
            $query=$db->prepare("INSERT INTO finance_staff_loans(company_id,employee_id,loan_number,loan_type,purpose,currency,principal_amount,interest_rate,interest_method,request_date,installment_frequency,installment_count,first_due_date,outstanding_principal,status,created_by) VALUES(:company,:employee,:number,:type,:purpose,:currency,:principal,:rate,:method,:requested,:frequency,:count,:first,:outstanding,'draft',:actor)");
            $query->execute(['company'=>$company,'employee'=>$employee,'number'=>$number,'type'=>$type,'purpose'=>$purpose,'currency'=>$currency,'principal'=>$principal/100,'rate'=>$rate>0?$rate:null,'method'=>$rate>0?'simple_annual':null,'requested'=>$requested,'frequency'=>$frequency,'count'=>$count,'first'=>$first,'outstanding'=>$principal/100,'actor'=>$actor]);
            $id=(int)$db->lastInsertId();$this->history($company,$id,'created',null,'draft',null,$actor);$db->commit();return $id;
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public function transition(int $id,string $action,int $actor,string $reason=''): void
    {
        $company=$this->company();$db=\db();$db->beginTransaction();
        try{
            $loan=$this->locked($company,$id);$from=$loan['status'];$to=match($action){'submit'=>'submitted','approve'=>'approved','reject'=>'rejected','cancel'=>'cancelled',default=>throw new RuntimeException('Invalid loan action.')};
            $required=['submit'=>'draft','approve'=>'submitted','reject'=>'submitted','cancel'=>'draft'];if($from!==$required[$action])throw new RuntimeException('Loan is not in the required state.');
            if(in_array($action,['submit','cancel'],true)&&(int)$loan['created_by']!==$actor)throw new RuntimeException('Only the creator may submit or cancel this loan.');
            if(in_array($action,['approve','reject'],true)&&(int)$loan['created_by']===$actor)throw new RuntimeException('Loan creator cannot approve their own request.');
            if($action==='reject'&&trim($reason)==='')throw new RuntimeException('A rejection reason is required.');
            $updates=$action==='approve'?'approval_date=CURRENT_DATE,approved_by=:actor,':'';
            $query=$db->prepare("UPDATE finance_staff_loans SET {$updates}status=:status WHERE company_id=:company AND loan_id=:id");$params=['status'=>$to,'company'=>$company,'id'=>$id];if($action==='approve')$params['actor']=$actor;$query->execute($params);
            if($action==='approve')$this->schedule($company,$loan);
            $this->history($company,$id,$action,$from,$to,$reason?:null,$actor);
            $notices=new UserNotificationService($db);
            if($action==='submit')$notices->notifyFinanceAction($company,'finance.requests.approve',$actor,'finance.loan.review','Staff loan review required','Review '.$loan['loan_number'],'staff_loan',$id,'/finance/staff-loans/'.$id,'finance.loan.review.'.$id);
            if(in_array($action,['approve','reject','cancel'],true))$notices->resolveFinanceActions($company,'staff_loan',$id);
            if($action==='approve')$notices->notifyFinanceAction($company,'finance.records.manage',$actor,'finance.loan.disburse','Staff loan ready for disbursement','Disburse '.$loan['loan_number'],'staff_loan',$id,'/finance/staff-loans/'.$id,'finance.loan.disburse.'.$id);
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public function disburse(int $id,int $journalId,string $date,int $actor): void
    {
        $company=$this->company();$date=$this->date($date);$db=\db();$db->beginTransaction();
        try{
            $loan=$this->locked($company,$id);if(in_array($loan['status'],['disbursed','active','paid'],true)){$db->commit();return;}if($loan['status']!=='approved')throw new RuntimeException('Only an approved loan can be disbursed.');if((int)$loan['approved_by']===$actor)throw new RuntimeException('The loan approver cannot disburse the same loan.');if($date<$loan['approval_date'])throw new RuntimeException('Disbursement cannot precede approval.');if($date>$loan['first_due_date'])throw new RuntimeException('The first installment is due before disbursement. Create a new request with a later due date.');
            $cash=$this->cashAccount($company,$journalId,$loan['currency']);$accounts=$this->loanAccounts($company,$loan['currency'],$actor);
            $posted=(new FinancePostingService())->postBalancedJournal($company,'SLD-'.$id,'staff_loan_disbursement',(string)$id,$loan['loan_number'],$date,$loan['currency'],'Staff loan disbursement '.$loan['loan_number'],'staff-loan-disburse-'.$company.'-'.$id,[['account_id'=>$accounts['staff_loans_receivable'],'debit'=>$loan['principal_amount'],'credit'=>0],['account_id'=>$cash,'debit'=>0,'credit'=>$loan['principal_amount']]],$actor);
            $query=$db->prepare("UPDATE finance_staff_loans SET status='disbursed',disbursement_date=:date,disbursed_by=:actor,disbursement_journal_id=:journal,disbursement_batch_id=:batch WHERE company_id=:company AND loan_id=:id AND status='approved'");$query->execute(['date'=>$date,'actor'=>$actor,'journal'=>$journalId,'batch'=>$posted['journalBatchId'],'company'=>$company,'id'=>$id]);if($query->rowCount()!==1)throw new RuntimeException('Loan state changed before disbursement completed.');
            $this->history($company,$id,'disburse','approved','disbursed',null,$actor);(new UserNotificationService($db))->resolveFinanceActions($company,'staff_loan',$id);$db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public function repay(int $id,array $input,int $actor): void
    {
        $company=$this->company();$amount=$this->cents($input['amount']??0);$journalId=(int)($input['journal_id']??0);$date=$this->date($input['payment_date']??'');$key=trim((string)($input['idempotency_key']??''));
        if($amount<1||$journalId<1||strlen($key)<16||strlen($key)>190)throw new RuntimeException('Enter a positive payment, journal and valid request key.');
        $db=\db();$db->beginTransaction();
        try{
            $loan=$this->locked($company,$id);$existing=$db->prepare('SELECT loan_id,amount,payment_date,journal_id,reference_number FROM finance_staff_loan_payments WHERE company_id=:company AND idempotency_key=:key');$existing->execute(['company'=>$company,'key'=>$key]);$prior=$existing->fetch(PDO::FETCH_ASSOC);if($prior){if((int)$prior['loan_id']!==$id||$this->cents($prior['amount'])!==$amount||$prior['payment_date']!==$date||(int)$prior['journal_id']!==$journalId||($prior['reference_number']??'')!==trim((string)($input['reference_number']??'')))throw new RuntimeException('Repayment key was already used for another transaction.');$db->commit();return;}
            if(!in_array($loan['status'],['disbursed','active'],true))throw new RuntimeException('Only a disbursed or active loan can receive repayment.');
            if($date<$loan['disbursement_date'])throw new RuntimeException('Repayment cannot precede disbursement.');
            $balance=$this->cents($loan['outstanding_principal'])+$this->cents($loan['outstanding_interest']);if($amount>$balance)throw new RuntimeException('Payment exceeds the remaining loan balance.');
            $cash=$this->cashAccount($company,$journalId,$loan['currency']);$accounts=$this->loanAccounts($company,$loan['currency'],$actor);
            $query=$db->prepare('SELECT * FROM finance_staff_loan_installments WHERE company_id=:company AND loan_id=:id AND remaining_due>0 ORDER BY installment_number FOR UPDATE');$query->execute(['company'=>$company,'id'=>$id]);$installments=$query->fetchAll(PDO::FETCH_ASSOC);
            $previous=$this->rows('SELECT a.installment_id,SUM(a.principal_amount) principal,SUM(a.interest_amount) interest FROM finance_staff_loan_allocations a JOIN finance_staff_loan_installments i ON i.company_id=a.company_id AND i.installment_id=a.installment_id WHERE a.company_id=:company AND i.loan_id=:id GROUP BY a.installment_id',['company'=>$company,'id'=>$id]);$paidByInstallment=[];foreach($previous as $item)$paidByInstallment[(int)$item['installment_id']]=$item;
            $left=$amount;$principalPaid=0;$interestPaid=0;$allocations=[];
            foreach($installments as $installment){if($left<=0)break;$prior=$paidByInstallment[(int)$installment['installment_id']]??['principal'=>0,'interest'=>0];$interest=min($left,max(0,$this->cents($installment['interest_due'])-$this->cents($prior['interest'])));$left-=$interest;$principal=min($left,max(0,$this->cents($installment['principal_due'])-$this->cents($prior['principal'])));$left-=$principal;if($interest+$principal<1)continue;$allocations[]=['id'=>(int)$installment['installment_id'],'principal'=>$principal,'interest'=>$interest,'old_paid'=>$this->cents($installment['amount_paid']),'total'=>$this->cents($installment['total_due']),'due'=>$installment['due_date']];$principalPaid+=$principal;$interestPaid+=$interest;}
            if($left!==0)throw new RuntimeException('Payment could not be allocated to the approved schedule.');
            $lines=[['account_id'=>$cash,'debit'=>$amount/100,'credit'=>0],['account_id'=>$accounts['staff_loans_receivable'],'debit'=>0,'credit'=>$principalPaid/100]];if($interestPaid>0)$lines[]=['account_id'=>$accounts['staff_loan_interest_income'],'debit'=>0,'credit'=>$interestPaid/100];
            $number='SLP-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
            $posted=(new FinancePostingService())->postBalancedJournal($company,$number,'staff_loan_payment',(string)$id,$loan['loan_number'],$date,$loan['currency'],'Staff loan repayment '.$loan['loan_number'],'staff-loan-payment-'.$company.'-'.$key,$lines,$actor);
            $insert=$db->prepare('INSERT INTO finance_staff_loan_payments(company_id,loan_id,payment_number,payment_date,amount,principal_amount,interest_amount,journal_id,journal_batch_id,idempotency_key,reference_number,posted_by) VALUES(:company,:loan,:number,:date,:amount,:principal,:interest,:journal,:batch,:key,:reference,:actor)');$insert->execute(['company'=>$company,'loan'=>$id,'number'=>$number,'date'=>$date,'amount'=>$amount/100,'principal'=>$principalPaid/100,'interest'=>$interestPaid/100,'journal'=>$journalId,'batch'=>$posted['journalBatchId'],'key'=>$key,'reference'=>trim((string)($input['reference_number']??''))?:null,'actor'=>$actor]);$paymentId=(int)$db->lastInsertId();
            $allocation=$db->prepare('INSERT INTO finance_staff_loan_allocations(company_id,loan_payment_id,installment_id,principal_amount,interest_amount) VALUES(:company,:payment,:installment,:principal,:interest)');$update=$db->prepare('UPDATE finance_staff_loan_installments SET amount_paid=:paid,remaining_due=:remaining,paid_at=:paid_at,status=:status WHERE company_id=:company AND installment_id=:id');
            foreach($allocations as $a){$allocation->execute(['company'=>$company,'payment'=>$paymentId,'installment'=>$a['id'],'principal'=>$a['principal']/100,'interest'=>$a['interest']/100]);$paid=$a['old_paid']+$a['principal']+$a['interest'];$remaining=$a['total']-$paid;$status=$remaining===0?'paid':($paid>0?'partially_paid':($a['due']<date('Y-m-d')?'overdue':'upcoming'));$update->execute(['paid'=>$paid/100,'remaining'=>$remaining/100,'paid_at'=>$remaining===0?date('Y-m-d H:i:s'):null,'status'=>$status,'company'=>$company,'id'=>$a['id']]);}
            $newPrincipal=$this->cents($loan['outstanding_principal'])-$principalPaid;$newInterest=$this->cents($loan['outstanding_interest'])-$interestPaid;$next=$db->prepare('SELECT due_date FROM finance_staff_loan_installments WHERE company_id=:company AND loan_id=:id AND remaining_due>0 ORDER BY installment_number LIMIT 1');$next->execute(['company'=>$company,'id'=>$id]);$nextDate=$next->fetchColumn()?:null;$newStatus=$newPrincipal+$newInterest===0?'paid':'active';
            $db->prepare('UPDATE finance_staff_loans SET amount_paid=amount_paid+:amount,outstanding_principal=:principal,outstanding_interest=:interest,next_due_date=:next,status=:status WHERE company_id=:company AND loan_id=:id')->execute(['amount'=>$amount/100,'principal'=>$newPrincipal/100,'interest'=>$newInterest/100,'next'=>$nextDate,'status'=>$newStatus,'company'=>$company,'id'=>$id]);
            $this->history($company,$id,'payment',$loan['status'],$newStatus,$number,$actor);$db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    private function schedule(int $company,array $loan): void
    {
        $count=(int)$loan['installment_count'];$principal=$this->cents($loan['principal_amount']);$base=intdiv($principal,$count);$remaining=$principal;$rate=(float)$loan['interest_rate'];$first=new \DateTimeImmutable($loan['first_due_date']);$insert=\db()->prepare('INSERT INTO finance_staff_loan_installments(company_id,loan_id,installment_number,due_date,opening_balance,principal_due,interest_due,total_due,remaining_due,status) VALUES(:company,:loan,:number,:due,:opening,:principal,:interest,:total,:remaining,:status)');$totalInterest=0;$last='';
        for($n=1;$n<=$count;$n++){$due=$this->dueDate($first,$loan['installment_frequency'],$n-1);$portion=$n===$count?$remaining:$base;$interest=$rate>0?(int)round($remaining*$rate/100*($loan['installment_frequency']==='monthly'?1/12:7/365)):0;$total=$portion+$interest;$insert->execute(['company'=>$company,'loan'=>$loan['loan_id'],'number'=>$n,'due'=>$due,'opening'=>$remaining/100,'principal'=>$portion/100,'interest'=>$interest/100,'total'=>$total/100,'remaining'=>$total/100,'status'=>'upcoming']);$remaining-=$portion;$totalInterest+=$interest;$last=$due;}
        \db()->prepare('UPDATE finance_staff_loans SET outstanding_interest=:interest,next_due_date=:next,planned_completion_date=:completion WHERE company_id=:company AND loan_id=:id')->execute(['interest'=>$totalInterest/100,'next'=>$loan['first_due_date'],'completion'=>$last,'company'=>$company,'id'=>$loan['loan_id']]);
    }
    private function dueDate(\DateTimeImmutable $first,string $frequency,int $offset): string
    {
        if($frequency==='weekly')return $first->modify('+'.($offset*7).' days')->format('Y-m-d');
        $month=$first->modify('first day of this month')->modify('+'.$offset.' months');$day=min((int)$first->format('d'),(int)$month->format('t'));return $month->setDate((int)$month->format('Y'),(int)$month->format('m'),$day)->format('Y-m-d');
    }
    private function cashAccount(int $company,int $journal,string $currency): int
    {
        $query=\db()->prepare("SELECT j.default_debit_account_id,a.currency FROM finance_journals j JOIN finance_accounts a ON a.company_id=j.company_id AND a.account_id=j.default_debit_account_id WHERE j.company_id=:company AND j.journal_id=:journal AND j.journal_type IN('bank','cash') AND j.active=TRUE AND a.active=TRUE AND a.deleted_at IS NULL FOR UPDATE");$query->execute(['company'=>$company,'journal'=>$journal]);$row=$query->fetch(PDO::FETCH_ASSOC);if(!is_array($row)||($row['currency']!==null&&$row['currency']!==$currency))throw new RuntimeException('Choose a cash or bank journal in the loan currency.');return(int)$row['default_debit_account_id'];
    }
    private function loanAccounts(int $company,string $currency,int $actor): array
    {
        $lock=\db()->prepare('SELECT company_id FROM companies WHERE company_id=:company AND deleted_at IS NULL FOR UPDATE');$lock->execute(['company'=>$company]);if($lock->fetchColumn()===false)throw new RuntimeException('Company was not found.');
        $definitions=['staff_loans_receivable'=>['STAFF-LOAN-AR','Staff Loans Receivable','asset','debit'],'staff_loan_interest_income'=>['STAFF-LOAN-INT','Staff Loan Interest Income','revenue','credit']];$result=[];
        foreach($definitions as $key=>[$code,$name,$type,$normal]){$query=\db()->prepare('SELECT account_id,account_type,currency,active FROM finance_accounts WHERE company_id=:company AND system_key=:key AND deleted_at IS NULL FOR UPDATE');$query->execute(['company'=>$company,'key'=>$key]);$row=$query->fetch(PDO::FETCH_ASSOC);if(!$row){$insert=\db()->prepare('INSERT INTO finance_accounts(company_id,account_code,account_name,account_type,normal_balance,system_key,currency,active,allow_manual_posting,created_by,updated_by) VALUES(:company,:code,:name,:type,:normal,:key,NULL,TRUE,FALSE,:actor,:updated)');$insert->execute(['company'=>$company,'code'=>$code,'name'=>$name,'type'=>$type,'normal'=>$normal,'key'=>$key,'actor'=>$actor,'updated'=>$actor]);$result[$key]=(int)\db()->lastInsertId();}else{if($row['account_type']!==$type||!(bool)$row['active']||($row['currency']!==null&&$row['currency']!==$currency))throw new RuntimeException('Staff loan control-account configuration is invalid for this currency.');$result[$key]=(int)$row['account_id'];}}
        return $result;
    }
    private function locked(int $company,int $id): array
    {
        $query=\db()->prepare('SELECT * FROM finance_staff_loans WHERE company_id=:company AND loan_id=:id FOR UPDATE');$query->execute(['company'=>$company,'id'=>$id]);$row=$query->fetch(PDO::FETCH_ASSOC);if(!is_array($row))throw new RuntimeException('Loan was not found in this company.');return $row;
    }
    private function history(int $company,int $id,string $action,?string $from,string $to,?string $reason,int $actor): void
    {
        \db()->prepare('INSERT INTO finance_staff_loan_history(company_id,loan_id,action,from_status,to_status,reason,actor_id) VALUES(:company,:loan,:action,:from,:to,:reason,:actor)')->execute(['company'=>$company,'loan'=>$id,'action'=>$action,'from'=>$from,'to'=>$to,'reason'=>$reason,'actor'=>$actor]);
    }
    private function rows(string $sql,array $params): array{$query=\db()->prepare($sql);$query->execute($params);return $query->fetchAll(PDO::FETCH_ASSOC);}
    private function cents(mixed $value): int { $number=(float)$value;if(!is_finite($number)||$number<0)throw new RuntimeException('Invalid amount.');return(int)round($number*100); }
    private function date(mixed $value): string{$value=trim((string)$value);$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new RuntimeException('Invalid date.');return $value;}
    private function journals(): array{return $this->rows("SELECT journal_id,journal_name,journal_type FROM finance_journals WHERE company_id=:company AND active=TRUE AND journal_type IN('cash','bank') ORDER BY journal_name",['company'=>$this->company()]);}
}
