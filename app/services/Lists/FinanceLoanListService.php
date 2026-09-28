<?php
declare(strict_types=1);
namespace App\Services\Lists;

use App\Services\TenantContext;

final class FinanceLoanListService
{
    private function definition(string $entity): array
    {
        return match($entity) {
            'installments'=>["SELECT i.*,l.loan_number,l.currency,
                CASE WHEN i.remaining_due<=0 THEN 'paid' WHEN l.status NOT IN('disbursed','active') THEN 'upcoming'
                    WHEN i.amount_paid>0 THEN 'partially_paid' WHEN i.due_date<CURRENT_DATE THEN 'overdue'
                    WHEN i.due_date=CURRENT_DATE THEN 'due' ELSE 'upcoming' END display_status
                FROM finance_staff_loan_installments i JOIN finance_staff_loans l ON l.company_id=i.company_id AND l.loan_id=i.loan_id
                WHERE i.company_id=:company AND i.loan_id=:loan",
                'installment_id',['installment_number'],['number'=>'installment_number','date'=>'due_date','amount'=>'total_due','remaining'=>'remaining_due','status'=>'display_status'],
                ['status'=>'display_status','from'=>['due_date','>='],'to'=>['due_date','<=']],'number','asc'],
            'payments'=>['SELECT p.*,l.loan_number,l.currency,b.batch_number,u.display_name poster_name FROM finance_staff_loan_payments p
                JOIN finance_staff_loans l ON l.company_id=p.company_id AND l.loan_id=p.loan_id
                JOIN finance_journal_batches b ON b.company_id=p.company_id AND b.journal_batch_id=p.journal_batch_id
                LEFT JOIN users u ON u.user_id=p.posted_by WHERE p.company_id=:company AND p.loan_id=:loan',
                'loan_payment_id',['loan_number','payment_number','reference_number','batch_number','poster_name'],['date'=>'payment_date','number'=>'payment_number','reference'=>'reference_number','amount'=>'amount'],
                ['from'=>['payment_date','>='],'to'=>['payment_date','<=']],'date','asc'],
            'history'=>['SELECT h.*,l.loan_number,u.display_name actor_name FROM finance_staff_loan_history h
                JOIN finance_staff_loans l ON l.company_id=h.company_id AND l.loan_id=h.loan_id
                LEFT JOIN users u ON u.user_id=h.actor_id WHERE h.company_id=:company AND h.loan_id=:loan',
                'history_id',['loan_number','action','reason','actor_name'],['date'=>'occurred_at','action'=>'action','actor'=>'actor_name','status'=>'to_status'],
                ['status'=>'to_status','action'=>'action','from'=>['DATE(occurred_at)','>='],'to'=>['DATE(occurred_at)','<=']],'date','desc'],
            default=>throw new \InvalidArgumentException('Unknown loan register.'),
        };
    }

    public function listing(string $entity,int $loan,array $input): SqlList
    {
        [$sql,$id,$search,$sorts,$filters,$sort,$direction]=$this->definition($entity);
        return new SqlList(\db(),$sql,['company'=>(new TenantContext())->companyId(),'loan'=>$loan],
            new ListQuery($input,$sorts,$sort,array_keys($filters),$direction,$entity),$search,$sorts,$id,$filters);
    }

    public function controls(string $entity,int $loan): array
    {
        [,,,$sorts,$filters]=$this->definition($entity);
        return FilterOptions::controls($this->listing($entity,$loan,[]),$filters,$sorts,
            ['status'=>$entity==='installments'?FilterOptions::labels(['paid','upcoming','partially_paid','overdue','due']):['finance_staff_loans','status']]);
    }

    public static function columns(string $entity): array
    {
        return ['loan_number'=>'Loan']+match($entity) {
            'installments'=>['installment_number'=>'Installment','due_date'=>'Due date','opening_balance'=>'Opening balance','principal_due'=>'Principal','interest_due'=>'Interest','total_due'=>'Total','amount_paid'=>'Paid','remaining_due'=>'Remaining','currency'=>'Currency','display_status'=>'Status'],
            'payments'=>['payment_number'=>'Payment','payment_date'=>'Date','reference_number'=>'Reference','principal_amount'=>'Principal','interest_amount'=>'Interest','amount'=>'Amount','currency'=>'Currency','batch_number'=>'Journal','poster_name'=>'Posted by'],
            'history'=>['occurred_at'=>'Date','action'=>'Action','from_status'=>'Previous status','to_status'=>'Status','reason'=>'Reason','actor_name'=>'Recorded by'],
            default=>throw new \InvalidArgumentException('Unknown loan register.'),
        };
    }
}
