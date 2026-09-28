<?php

declare(strict_types=1);

namespace App\Services\Lists;

use App\Services\TenantContext;
use InvalidArgumentException;

final class FinanceListService
{
    public function listing(
        string $entity,
        array $input,
        string $prefix = ''
    ): SqlList {
        /*
         * Preserve the existing DataExchange invoice filter names while
         * standardising the Finance screen on from/to and q.
         */
        if ($entity === 'invoices') {

            if (
                !isset($input['from'])
                && isset($input['date_from'])
            ) {
                $input['from'] = $input['date_from'];
            }

            if (
                !isset($input['to'])
                && isset($input['date_to'])
            ) {
                $input['to'] = $input['date_to'];
            }
        }

        if ($entity === 'staff-loans') {
            $legacyStatus =
                trim((string)($input['status'] ?? ''));

            if ($legacyStatus === 'due') {
                unset($input['status']);
                $input['due_state'] = 'due';
            } elseif ($legacyStatus === 'overdue') {
                unset($input['status']);
                $input['overdue'] = '1';
            }
        }

        $company =
            (new TenantContext())->companyId();

        [
            $sql,
            $primaryKey,
            $search,
            $sorts,
            $filters,
            $defaultSort,
            $defaultDirection,
        ] = $this->definition($entity);

        $query = new ListQuery(
            $input,
            $sorts,
            $defaultSort,
            array_keys($filters),
            $defaultDirection,
            $prefix
        );

        return new SqlList(
            \db(),
            $sql,
            ['company_id' => $company],
            $query,
            $search,
            $sorts,
            $primaryKey,
            $filters
        );
    }

    public function controls(string $entity): array
    {
        [,,,$sorts,$filters]=$this->definition($entity);
        $domains=[
            'active'=>['1'=>'Active','0'=>'Inactive'],
            'payment'=>['finance_invoices','payment_status'],
            'loan_type'=>['loan'=>'Loan','advance'=>'Advance'],
            'due_state'=>['due'=>'Due or overdue','upcoming'=>'Not yet due'],
            'overdue'=>['1'=>'Yes','0'=>'No'],
            'aging'=>['Current'=>'Current','1-30'=>'1-30 days','31-60'=>'31-60 days','61-90'=>'61-90 days','90+'=>'90+ days','Settled'=>'Settled'],
            'type'=>['finance_accounts','account_type'],
            // FinanceOperationsService::recordReceipt validation.
            'method'=>['bank_transfer'=>'Bank transfer','cash'=>'Cash','check'=>'Check','card'=>'Card'],
        ];
        $table=match($entity) {
            'invoices'=>'finance_invoices','journals'=>'finance_journal_batches','expenses'=>'finance_expense_requests',
            'bank-mappings'=>'finance_bank_account_gl_mappings','bank-statements'=>'finance_bank_reconciliations',default=>null,
        };
        if($table)$domains['status']=[$table,'status'];
        if($entity==='accounting-periods')$domains['status']=['finance_accounting_periods','status'];
        if($entity==='fiscal-years')$domains['status']=['finance_fiscal_years','status'];
        if($entity==='period-history')$domains['action']=['finance_accounting_period_history','action'];
        if($entity==='expense-history')$domains['status']=$this->statusOptions('expenses');
        // These are computed business states, not the raw stored workflow state.
        if(in_array($entity,['receivables','staff-loans','expenses'],true))$domains['status']=$this->statusOptions($entity);
        return FilterOptions::controls($this->listing($entity,[]),$filters,$sorts,$domains,
            ['bank'=>"CONCAT(bank_name,' - ',account_number)",'account'=>"CONCAT(account_code,' - ',account_name)"]);
    }

    public function columns(string $entity): array
    {
        return match ($entity) {
            'expense-categories'=>['code'=>'Category code','name'=>'Category','expense_code'=>'Expense account','expense_name'=>'Expense account name','tax_code'=>'Tax account','tax_name'=>'Tax account name','active'=>'Active'],
            'expense-history'=>['request_number'=>'Expense','title'=>'Title','occurred_at'=>'Date','action'=>'Action','from_status'=>'Previous status','to_status'=>'Status','reason'=>'Reason','actor_name'=>'Recorded by'],
            'accounting-periods'=>['period_name'=>'Period','fiscal_year_name'=>'Fiscal year','date_from'=>'Start','date_to'=>'End','status'=>'Status','closed_at'=>'Closed','locked_at'=>'Locked'],
            'fiscal-years'=>['fiscal_year_name'=>'Fiscal year','date_from'=>'Start','date_to'=>'End','status'=>'Status'],
            'period-history'=>['period_name'=>'Period','acted_at'=>'Date','action'=>'Action','status_from'=>'Previous status','status_to'=>'Status','reason'=>'Reason','actor_name'=>'Recorded by'],
            'receivables' => [
                'order_number' => 'Order',
                'customer_number' => 'Customer number',
                'customer_name' => 'Customer',
                'currency' => 'Currency',
                'original_amount' => 'Original',
                'paid_amount' => 'Paid',
                'balance_amount' => 'Outstanding',
                'due_date' => 'Due date',
                'list_status' => 'Status',
            ],

            'invoices' => [
                'invoice_number' => 'Invoice',
                'customer_number' => 'Customer number',
                'customer_name' => 'Customer',
                'order_number' => 'Sales order',
                'invoice_date' => 'Invoice date',
                'due_date' => 'Due date',
                'currency' => 'Currency',
                'total_amount' => 'Total',
                'residual_amount' => 'Residual',
                'status' => 'State',
                'payment_status' => 'Payment',
            ],

            'receipts' => [
                'receipt_number' => 'Receipt',
                'order_number' => 'Order',
                'customer_number' => 'Customer number',
                'customer_name' => 'Customer',
                'payment_date' => 'Payment date',
                'payment_method' => 'Method',
                'reference_number' => 'Reference',
                'currency' => 'Currency',
                'amount' => 'Amount',
                'posted_at' => 'Posted at',
            ],

            'journals' => [
                'batch_number' => 'Batch',
                'source_type' => 'Source type',
                'source_number' => 'Source',
                'description' => 'Description',
                'posting_date' => 'Posting date',
                'currency' => 'Currency',
                'total_debit' => 'Debit',
                'total_credit' => 'Credit',
                'status' => 'Status',
                'posted_at' => 'Posted at',
            ],

            'expenses' => [
                'request_number' => 'Request',
                'title' => 'Title',
                'requester_name' => 'Requester',
                'category_name' => 'Category',
                'expense_date' => 'Expense date',
                'currency' => 'Currency',
                'amount' => 'Amount',
                'status' => 'Status',
                'submitted_at' => 'Submitted',
            ],

            'accounts' => [
                'account_code' => 'Code',
                'account_name' => 'Account',
                'account_type' => 'Type',
                'normal_balance' => 'Normal balance',
                'currency' => 'Currency',
                'system_key' => 'Control / system key',
                'active' => 'Active',
            ],

            'ledger' => [
                'posting_date' => 'Date',
                'batch_number' => 'Journal',
                'source_type' => 'Source type',
                'source_number' => 'Reference',
                'account_code' => 'Account',
                'account_name' => 'Name',
                'description' => 'Description',
                'debit_amount' => 'Debit',
                'credit_amount' => 'Credit',
                'currency' => 'Currency',
                'poster_name' => 'Posted by',
                'reversal_number' => 'Reverses',
                'created_at' => 'Created at',
                'posted_at' => 'Posted at',
            ],

            'ar-aging' => [
                'customer_name' => 'Customer',
                'customer_number' => 'Customer number',
                'invoice_number' => 'ERP document',
                'invoice_date' => 'Date',
                'due_date' => 'Due',
                'total_amount' => 'Original',
                'paid_amount' => 'Paid',
                'residual_amount' => 'Outstanding',
                'currency' => 'Currency',
                'payment_status' => 'Payment',
                'aging_bucket' => 'Aging',
            ],

            'payables' => [
                'supplier_name' => 'Supplier',
                'invoice_number' => 'ERP document',
                'supplier_invoice_number' => 'Supplier invoice',
                'po_number' => 'PO',
                'invoice_date' => 'Date',
                'due_date' => 'Due',
                'total_amount' => 'Original',
                'paid_amount' => 'Paid',
                'residual_amount' => 'Outstanding',
                'currency' => 'Currency',
                'payment_status' => 'Payment',
                'aging_bucket' => 'Aging',
            ],

            'cash-bank' => [
                'posting_date' => 'Date',
                'batch_number' => 'Journal',
                'source_number' => 'Reference',
                'account_code' => 'Account',
                'account_name' => 'Name',
                'description' => 'Description',
                'debit_amount' => 'Receipt / Debit',
                'credit_amount' => 'Payment / Credit',
                'currency' => 'Currency',
            ],

            'staff-loans' => [
                'loan_number' => 'Loan number',
                'employee_number' => 'Employee number',
                'employee_name' => 'Employee',
                'loan_type' => 'Type',
                'purpose' => 'Purpose',
                'request_date' => 'Request date',
                'currency' => 'Currency',
                'principal_amount' => 'Principal',
                'amount_paid' => 'Paid',
                'outstanding_principal' =>
                    'Outstanding principal',
                'outstanding_interest' =>
                    'Outstanding interest',
                'next_due_date' => 'Next due',
                'installments_remaining' =>
                    'Installments remaining',
                'overdue_amount' => 'Overdue amount',
                'list_status' => 'Status',
                'due_state' => 'Due state',
            ],

            'bank-mappings' => [
                'bank_name' => 'Bank',
                'bank_account_name' => 'Bank account',
                'account_number' => 'Account number',
                'account_code' => 'GL code',
                'account_name' => 'GL account',
                'currency' => 'Currency',
                'effective_from' => 'Effective from',
                'effective_to' => 'Effective to',
                'opening_gl_balance' => 'Opening posted GL',
                'status' => 'Status',
                'approver_name' => 'Approver',
                'cutover_reason' => 'Cutover reason',
            ],

            'bank-statements' => [
                'reconciliation_version' => 'Reconciliation version',
                'bank_name' => 'Bank',
                'bank_account_name' => 'Bank account',
                'account_number' => 'Account number',
                'account_code' => 'GL code',
                'gl_account_name' => 'GL account',
                'statement_reference' => 'Statement reference',
                'period_start' => 'Period start',
                'period_end' => 'Period end',
                'statement_date' => 'Statement date',
                'opening_balance' => 'Opening balance',
                'ending_balance' => 'Ending balance',
                'currency' => 'Currency',
                'preparer_name' => 'Preparer',
                'reviewer_name' => 'Reviewer',
                'reconciliation_status' => 'Reconciliation status',
            ],

            default => throw new InvalidArgumentException(
                'Unknown Finance export.'
            ),
        };
    }

    /**
     * Full customer selector source, intentionally independent
     * from whichever invoice page is currently visible.
     *
     * @return list<string>
     */
    public function customerOptions(): array
    {
        $company =
            (new TenantContext())->companyId();

        $statement = \db()->prepare(
            "SELECT DISTINCT c.name
             FROM finance_invoices i
             INNER JOIN sales_customers c
               ON c.company_id=i.company_id
              AND c.customer_id=i.customer_id
              AND c.deleted_at IS NULL
             WHERE i.company_id=?
               AND i.document_type='customer_invoice'
               AND i.status<>'cancelled'
             ORDER BY c.name"
        );

        $statement->execute([$company]);

        return array_values(array_filter(
            array_map(
                'strval',
                $statement->fetchAll(
                    \PDO::FETCH_COLUMN
                )
            ),
            static fn(string $value): bool =>
                trim($value) !== ''
        ));
    }

    private function statusOptions(
        string $entity
    ): array {
        return match ($entity) {
            'receivables' => [
                'open' => 'Open',
                'overdue' => 'Overdue',
                'partially_paid' => 'Partially paid',
                'paid' => 'Paid',
                'cancelled' => 'Cancelled',
            ],

            'invoices' => [
                'draft' => 'Draft',
                'posted' => 'Posted',
                'cancelled' => 'Cancelled',
                'reversed' => 'Reversed',
            ],

            'journals' => [
                'draft' => 'Draft',
                'posted' => 'Posted',
                'reversed' => 'Reversed',
            ],

            'expenses' => [
                'draft' => 'Draft',
                'submitted' => 'Submitted',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                'paid' => 'Paid',
                'cancelled' => 'Cancelled',
                'reversed' => 'Reversed',
            ],

            'staff-loans' => [
                'draft' => 'Draft',
                'submitted' => 'Submitted',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                'active' => 'Active',
                'paid' => 'Paid',
                'cancelled' => 'Cancelled',
            ],

            'bank-mappings' => [
                'draft' => 'Draft',
                'approved' => 'Approved',
                'superseded' => 'Superseded',
            ],

            'bank-statements' => [
                'draft' => 'Draft',
                'in_review' => 'In review',
                'completed' => 'Completed',
                'superseded' => 'Superseded',
            ],

            default => [],
        };
    }

    private function definition(
        string $entity
    ): array {
        if($entity==='expense-categories')return [
            'SELECT c.*,a.account_code expense_code,a.account_name expense_name,t.account_code tax_code,t.account_name tax_name FROM finance_expense_categories c LEFT JOIN finance_accounts a ON a.company_id=c.company_id AND a.account_id=c.default_expense_account_id LEFT JOIN finance_accounts t ON t.company_id=c.company_id AND t.account_id=c.default_recoverable_tax_account_id WHERE c.company_id=:company_id AND c.deleted_at IS NULL',
            'category_id',['code','name','expense_code','expense_name','tax_code','tax_name'],['name'=>'name','code'=>'code','status'=>'active','expense'=>'expense_code','tax'=>'tax_code'],['active'=>'active'],'name','asc'];
        if($entity==='expense-history')return [
            'SELECT h.*,r.request_number,r.title,u.display_name actor_name FROM finance_expense_history h JOIN finance_expense_requests r ON r.company_id=h.company_id AND r.expense_request_id=h.expense_request_id LEFT JOIN users u ON u.user_id=h.actor_id WHERE h.company_id=:company_id AND r.deleted_at IS NULL',
            'history_id',['request_number','title','action','reason','actor_name'],['date'=>'occurred_at','expense'=>'request_number','action'=>'action','status'=>'to_status','actor'=>'actor_name'],['status'=>'to_status','action'=>'action','from'=>['DATE(occurred_at)','>='],'to'=>['DATE(occurred_at)','<=']],'date','desc'];
        if($entity==='accounting-periods')return [
            'SELECT p.*,y.fiscal_year_name FROM finance_accounting_periods p LEFT JOIN finance_fiscal_years y ON y.company_id=p.company_id AND y.fiscal_year_id=p.fiscal_year_id WHERE p.company_id=:company_id',
            'period_id',['period_name','fiscal_year_name','status'],['date'=>'date_from','name'=>'period_name','year'=>'fiscal_year_name','status'=>'status'],
            ['status'=>'status','year'=>'fiscal_year_name','from'=>['date_from','>='],'to'=>['date_from','<=']],'date','desc'];
        if($entity==='fiscal-years')return ['SELECT * FROM finance_fiscal_years WHERE company_id=:company_id',
            'fiscal_year_id',['fiscal_year_name','status'],['date'=>'date_from','name'=>'fiscal_year_name','status'=>'status'],['status'=>'status','from'=>['date_from','>='],'to'=>['date_from','<=']],'date','desc'];
        if($entity==='period-history')return ['SELECT h.*,p.period_name,u.display_name actor_name FROM finance_accounting_period_history h JOIN finance_accounting_periods p ON p.company_id=h.company_id AND p.period_id=h.period_id LEFT JOIN users u ON u.user_id=h.acted_by WHERE h.company_id=:company_id',
            'period_history_id',['period_name','action','reason','actor_name'],['date'=>'acted_at','period'=>'period_name','action'=>'action','actor'=>'actor_name'],['action'=>'action','from'=>['DATE(acted_at)','>='],'to'=>['DATE(acted_at)','<=']],'date','desc'];
        if ($entity === 'receivables') {
            return [
                "SELECT
                    r.*,
                    r.due_date AS document_date,
                    c.customer_number,
                    c.name AS customer_name,

                    CASE
                        WHEN r.balance_amount > 0
                         AND r.due_date < CURRENT_DATE
                        THEN 1
                        ELSE 0
                    END AS is_overdue,

                    CASE
                        WHEN r.balance_amount > 0
                         AND r.due_date < CURRENT_DATE
                        THEN 'overdue'
                        ELSE r.status
                    END AS list_status

                 FROM finance_sales_receivables r

                 LEFT JOIN sales_customers c
                   ON c.company_id=r.company_id
                  AND c.customer_id=r.customer_id
                  AND c.deleted_at IS NULL

                 WHERE r.company_id=:company_id",

                'receivable_id',

                [
                    'order_number',
                    'customer_number',
                    'customer_name',
                    'currency',
                    'list_status',
                ],

                [
                    'due_date' => 'due_date',
                    'order' => 'order_number',
                    'customer' => 'customer_name',
                    'balance' => 'balance_amount',
                    'status' => 'list_status',
                ],

                [
                    'status' => 'list_status',
                    'currency' => 'currency',
                    'from' => [
                        'document_date',
                        '>=',
                    ],
                    'to' => [
                        'document_date',
                        '<=',
                    ],
                ],

                'due_date',
                'asc',
            ];
        }

        if ($entity === 'invoices') {

            return [
                "SELECT
                    i.*,
                    i.invoice_date AS document_date,
                    c.customer_number,
                    c.name AS customer_name,
                    o.order_number

                 FROM finance_invoices i

                 INNER JOIN sales_customers c
                   ON c.company_id=i.company_id
                  AND c.customer_id=i.customer_id
                  AND c.deleted_at IS NULL

                 LEFT JOIN sales_orders o
                   ON o.company_id=i.company_id
                  AND o.order_id=i.sales_order_id

                 WHERE i.company_id=:company_id
                   AND i.document_type='customer_invoice'
                   AND i.status<>'cancelled'",

                'invoice_id',

                [
                    'invoice_number',
                    'customer_number',
                    'customer_name',
                    'order_number',
                    'currency',
                    'status',
                    'payment_status',
                ],

                [
                    'date' => 'invoice_date',
                    'invoice' => 'invoice_number',
                    'customer' => 'customer_name',
                    'due_date' => 'due_date',
                    'total' => 'total_amount',
                    'residual' => 'residual_amount',
                    'status' => 'status',
                    'payment' => 'payment_status',
                ],

                [
                    'status' => 'status',
                    'payment' => 'payment_status',
                    'currency' => 'currency',
                    'customer' => 'customer_name',
                    'from' => [
                        'document_date',
                        '>=',
                    ],
                    'to' => [
                        'document_date',
                        '<=',
                    ],
                ],

                'date',
                'desc',
            ];
        }

        if ($entity === 'receipts') {
            return [
                "SELECT
                    sr.*,
                    sr.payment_date AS document_date,
                    r.order_number,
                    r.currency,
                    c.customer_number,
                    c.name AS customer_name

                 FROM finance_sales_receipts sr

                 LEFT JOIN finance_sales_receivables r
                   ON r.company_id=sr.company_id
                  AND r.order_id=sr.order_id

                 LEFT JOIN sales_customers c
                   ON c.company_id=r.company_id
                  AND c.customer_id=r.customer_id
                  AND c.deleted_at IS NULL

                 WHERE sr.company_id=:company_id",

                'posting_id',

                [
                    'receipt_number',
                    'reference_number',
                    'order_number',
                    'customer_number',
                    'customer_name',
                    'payment_method',
                    'currency',
                ],

                [
                    'date' => 'payment_date',
                    'receipt' => 'receipt_number',
                    'order' => 'order_number',
                    'customer' => 'customer_name',
                    'amount' => 'amount',
                    'method' => 'payment_method',
                ],

                [
                    'method' => 'payment_method',
                    'currency' => 'currency',
                    'from' => [
                        'document_date',
                        '>=',
                    ],
                    'to' => [
                        'document_date',
                        '<=',
                    ],
                ],

                'date',
                'desc',
            ];
        }

        if ($entity === 'journals') {
            return [
                "SELECT
                    b.*,
                    b.posting_date AS document_date
                 FROM finance_journal_batches b
                 WHERE b.company_id=:company_id",

                'journal_batch_id',

                [
                    'batch_number',
                    'source_type',
                    'source_number',
                    'description',
                    'currency',
                    'status',
                ],

                [
                    'date' => 'posting_date',
                    'batch' => 'batch_number',
                    'source' => 'source_number',
                    'type' => 'source_type',
                    'debit' => 'total_debit',
                    'credit' => 'total_credit',
                    'status' => 'status',
                ],

                [
                    'status' => 'status',
                    'currency' => 'currency',
                    'source' => 'source_type',
                    'from' => [
                        'document_date',
                        '>=',
                    ],
                    'to' => [
                        'document_date',
                        '<=',
                    ],
                ],

                'date',
                'desc',
            ];
        }

        if ($entity === 'expenses') {
            return [
                "SELECT
                    requests.*,
                    requests.expense_date AS document_date,
                    categories.code AS category_code,
                    categories.name AS category_name,

                    employee.preferred_name,
                    employee.first_name,
                    employee.last_name,

                    TRIM(
                        CONCAT_WS(
                            ' ',
                            employee.first_name,
                            employee.last_name
                        )
                    ) AS employee_name,

                    accounts.account_code,
                    accounts.account_name,
                    batches.batch_number,

                    TRIM(
                        CONCAT_WS(
                            ' ',
                            NULLIF(
                                employee.preferred_name,
                                ''
                            ),
                            CASE
                                WHEN employee.preferred_name IS NULL
                                  OR employee.preferred_name=''
                                THEN employee.first_name
                                ELSE NULL
                            END,
                            CASE
                                WHEN employee.preferred_name IS NULL
                                  OR employee.preferred_name=''
                                THEN employee.last_name
                                ELSE employee.last_name
                            END
                        )
                    ) AS requester_name

                 FROM finance_expense_requests requests

                 LEFT JOIN hr_employees employee
                   ON employee.company_id=requests.company_id
                  AND employee.employee_id=
                      requests.requested_by_employee_id
                  AND employee.deleted_at IS NULL

                 LEFT JOIN finance_expense_categories categories
                   ON categories.company_id=requests.company_id
                  AND categories.category_id=
                      requests.category_id
                  AND categories.deleted_at IS NULL

                 LEFT JOIN finance_accounts accounts
                   ON accounts.company_id=requests.company_id
                  AND accounts.account_id=
                      requests.expense_account_id
                  AND accounts.deleted_at IS NULL

                 LEFT JOIN finance_journal_batches batches
                   ON batches.company_id=requests.company_id
                  AND batches.journal_batch_id=
                      requests.journal_batch_id

                 WHERE requests.company_id=:company_id
                   AND requests.deleted_at IS NULL",

                'expense_request_id',

                [
                    'request_number',
                    'title',
                    'description',
                    'requester_name',
                    'category_code',
                    'category_name',
                    'currency',
                    'status',
                ],

                [
                    'date' => 'expense_date',
                    'request' => 'request_number',
                    'title' => 'title',
                    'requester' => 'requester_name',
                    'category' => 'category_name',
                    'amount' => 'amount',
                    'status' => 'status',
                ],

                [
                    'status' => 'status',
                    'currency' => 'currency',
                    'category' => 'category_name',
                    'from' => [
                        'document_date',
                        '>=',
                    ],
                    'to' => [
                        'document_date',
                        '<=',
                    ],
                ],

                'date',
                'desc',
            ];
        }

        if ($entity === 'accounts') {
            return [
                "SELECT
                    a.*
                 FROM finance_accounts a
                 WHERE a.company_id=:company_id
                   AND a.deleted_at IS NULL",

                'account_id',

                [
                    'account_code',
                    'account_name',
                    'account_type',
                    'normal_balance',
                    'currency',
                    'system_key',
                ],

                [
                    'code' => 'account_code',
                    'name' => 'account_name',
                    'type' => 'account_type',
                    'currency' => 'currency',
                    'active' => 'active',
                ],

                [
                    'type' => 'account_type',
                    'currency' => 'currency',
                    'active' => 'active',
                ],

                'code',
                'asc',
            ];
        }

        if ($entity === 'ledger') {
            return [
                "SELECT
                    e.journal_entry_id,
                    e.line_number,
                    e.description,
                    e.debit_amount,
                    e.credit_amount,
                    e.currency,

                    b.posting_date AS document_date,
                    b.posting_date,
                    b.batch_number,
                    b.journal_batch_id,
                    b.source_type,
                    b.source_id,
                    b.source_number,
                    b.created_at,
                    b.posted_at,

                    u.display_name AS poster_name,
                    original.batch_number AS reversal_number,

                    a.account_code,
                    a.account_name

                 FROM finance_journal_entries e

                 INNER JOIN finance_journal_batches b
                   ON b.company_id=e.company_id
                  AND b.journal_batch_id=e.journal_batch_id

                 INNER JOIN finance_accounts a
                   ON a.company_id=e.company_id
                  AND a.account_id=e.account_id

                 LEFT JOIN users u
                   ON u.user_id=b.posted_by

                 LEFT JOIN finance_journal_batches original
                   ON original.company_id=b.company_id
                  AND original.journal_batch_id=
                      b.reversal_of_batch_id

                 WHERE b.company_id=:company_id
                   AND b.status='posted'",

                'journal_entry_id',

                [
                    'batch_number',
                    'source_type',
                    'source_number',
                    'account_code',
                    'account_name',
                    'description',
                    'poster_name',
                    'currency',
                ],

                [
                    'date' => 'posting_date',
                    'batch' => 'batch_number',
                    'source' => 'source_number',
                    'account' => 'account_code',
                    'debit' => 'debit_amount',
                    'credit' => 'credit_amount',
                ],

                [
                    'currency' => 'currency',
                    'source' => 'source_type',
                    'account' => 'account_code',
                    'from' => [
                        'document_date',
                        '>=',
                    ],
                    'to' => [
                        'document_date',
                        '<=',
                    ],
                ],

                'date',
                'desc',
            ];
        }

        if ($entity === 'ar-aging') {
            return [
                "SELECT
                    i.invoice_id,
                    i.invoice_number,
                    i.invoice_date AS document_date,
                    i.invoice_date,
                    i.due_date,
                    i.total_amount,
                    ROUND(
                        i.total_amount-i.residual_amount,
                        2
                    ) AS paid_amount,
                    i.residual_amount,
                    i.currency,
                    i.payment_status,

                    c.customer_number,
                    c.name AS customer_name,

                    CASE
                        WHEN i.residual_amount<=0
                            THEN 'Settled'
                        WHEN i.due_date>=CURRENT_DATE
                            THEN 'Current'
                        WHEN DATEDIFF(
                            CURRENT_DATE,
                            i.due_date
                        )<=30
                            THEN '1-30'
                        WHEN DATEDIFF(
                            CURRENT_DATE,
                            i.due_date
                        )<=60
                            THEN '31-60'
                        WHEN DATEDIFF(
                            CURRENT_DATE,
                            i.due_date
                        )<=90
                            THEN '61-90'
                        ELSE '90+'
                    END AS aging_bucket

                 FROM finance_invoices i

                 INNER JOIN sales_customers c
                   ON c.company_id=i.company_id
                  AND c.customer_id=i.customer_id

                 WHERE i.company_id=:company_id
                   AND i.document_type='customer_invoice'
                   AND i.status='posted'",

                'invoice_id',

                [
                    'invoice_number',
                    'customer_number',
                    'customer_name',
                    'currency',
                    'payment_status',
                    'aging_bucket',
                ],

                [
                    'due' => 'due_date',
                    'date' => 'invoice_date',
                    'invoice' => 'invoice_number',
                    'customer' => 'customer_name',
                    'total' => 'total_amount',
                    'outstanding' => 'residual_amount',
                    'payment' => 'payment_status',
                    'aging' => 'aging_bucket',
                ],

                [
                    'payment' => 'payment_status',
                    'aging' => 'aging_bucket',
                    'currency' => 'currency',
                    'from' => [
                        'document_date',
                        '>=',
                    ],
                    'to' => [
                        'document_date',
                        '<=',
                    ],
                ],

                'due',
                'asc',
            ];
        }

        if ($entity === 'payables') {
            return [
                "SELECT
                    i.invoice_id,
                    i.invoice_number,
                    i.supplier_invoice_number,
                    i.invoice_date AS document_date,
                    i.invoice_date,
                    i.due_date,
                    i.total_amount,
                    ROUND(
                        i.total_amount-i.residual_amount,
                        2
                    ) AS paid_amount,
                    i.residual_amount,
                    i.currency,
                    i.payment_status,
                    i.purchase_order_id,

                    s.business_name AS supplier_name,
                    o.po_number,

                    CASE
                        WHEN i.residual_amount<=0
                            THEN 'Settled'
                        WHEN i.due_date>=CURRENT_DATE
                            THEN 'Current'
                        WHEN DATEDIFF(
                            CURRENT_DATE,
                            i.due_date
                        )<=30
                            THEN '1-30'
                        WHEN DATEDIFF(
                            CURRENT_DATE,
                            i.due_date
                        )<=60
                            THEN '31-60'
                        WHEN DATEDIFF(
                            CURRENT_DATE,
                            i.due_date
                        )<=90
                            THEN '61-90'
                        ELSE '90+'
                    END AS aging_bucket

                 FROM finance_invoices i

                 INNER JOIN purchase_suppliers s
                   ON s.company_id=i.company_id
                  AND s.supplier_id=i.vendor_id

                 LEFT JOIN purchase_orders o
                   ON o.company_id=i.company_id
                  AND o.purchase_order_id=
                      i.purchase_order_id

                 WHERE i.company_id=:company_id
                   AND i.document_type='vendor_bill'
                   AND i.status='posted'",

                'invoice_id',

                [
                    'invoice_number',
                    'supplier_invoice_number',
                    'supplier_name',
                    'po_number',
                    'currency',
                    'payment_status',
                    'aging_bucket',
                ],

                [
                    'due' => 'due_date',
                    'date' => 'invoice_date',
                    'invoice' => 'invoice_number',
                    'supplier' => 'supplier_name',
                    'po' => 'po_number',
                    'total' => 'total_amount',
                    'outstanding' => 'residual_amount',
                    'payment' => 'payment_status',
                    'aging' => 'aging_bucket',
                ],

                [
                    'payment' => 'payment_status',
                    'aging' => 'aging_bucket',
                    'currency' => 'currency',
                    'from' => [
                        'document_date',
                        '>=',
                    ],
                    'to' => [
                        'document_date',
                        '<=',
                    ],
                ],

                'due',
                'asc',
            ];
        }

        if ($entity === 'cash-bank') {
            return [
                "SELECT
                    e.journal_entry_id,
                    e.description,
                    e.debit_amount,
                    e.credit_amount,
                    e.currency,

                    b.posting_date AS document_date,
                    b.posting_date,
                    b.batch_number,
                    b.source_type,
                    b.source_number,

                    a.account_code,
                    a.account_name

                 FROM finance_journal_entries e

                 INNER JOIN finance_journal_batches b
                   ON b.company_id=e.company_id
                  AND b.journal_batch_id=e.journal_batch_id

                 INNER JOIN finance_accounts a
                   ON a.company_id=e.company_id
                  AND a.account_id=e.account_id

                 WHERE b.company_id=:company_id
                   AND b.status='posted'
                   AND a.system_key='cash'",

                'journal_entry_id',

                [
                    'batch_number',
                    'source_type',
                    'source_number',
                    'account_code',
                    'account_name',
                    'description',
                    'currency',
                ],

                [
                    'date' => 'posting_date',
                    'batch' => 'batch_number',
                    'source' => 'source_number',
                    'account' => 'account_code',
                    'debit' => 'debit_amount',
                    'credit' => 'credit_amount',
                ],

                [
                    'currency' => 'currency',
                    'account' => 'account_code',
                    'from' => [
                        'document_date',
                        '>=',
                    ],
                    'to' => [
                        'document_date',
                        '<=',
                    ],
                ],

                'date',
                'desc',
            ];
        }

        if ($entity === 'staff-loans') {
            return [
                "SELECT
                    l.*,
                    l.request_date AS document_date,

                    e.employee_number,

                    TRIM(
                        CONCAT_WS(
                            ' ',
                            e.first_name,
                            e.last_name
                        )
                    ) AS employee_name,

                    (
                        SELECT COUNT(*)
                        FROM finance_staff_loan_installments i
                        WHERE i.company_id=l.company_id
                          AND i.loan_id=l.loan_id
                          AND i.remaining_due>0
                    ) AS installments_remaining,

                    (
                        SELECT COALESCE(
                            SUM(i.remaining_due),
                            0
                        )
                        FROM finance_staff_loan_installments i
                        WHERE i.company_id=l.company_id
                          AND i.loan_id=l.loan_id
                          AND i.remaining_due>0
                          AND i.due_date<CURRENT_DATE
                          AND l.status IN(
                              'disbursed',
                              'active'
                          )
                    ) AS overdue_amount,

                    CASE
                        WHEN l.status IN(
                            'disbursed',
                            'active'
                        )
                        THEN 'active'
                        ELSE l.status
                    END AS list_status,

                    CASE
                        WHEN l.status IN(
                            'disbursed',
                            'active'
                        )
                        AND EXISTS(
                            SELECT 1
                            FROM finance_staff_loan_installments d
                            WHERE d.company_id=l.company_id
                              AND d.loan_id=l.loan_id
                              AND d.remaining_due>0
                              AND d.due_date<=CURRENT_DATE
                        )
                        THEN 'due'
                        ELSE 'upcoming'
                    END AS due_state,

                    CASE
                        WHEN l.status IN(
                            'disbursed',
                            'active'
                        )
                        AND EXISTS(
                            SELECT 1
                            FROM finance_staff_loan_installments od
                            WHERE od.company_id=l.company_id
                              AND od.loan_id=l.loan_id
                              AND od.remaining_due>0
                              AND od.due_date<CURRENT_DATE
                        )
                        THEN 1
                        ELSE 0
                    END AS is_overdue

                 FROM finance_staff_loans l

                 INNER JOIN hr_employees e
                   ON e.company_id=l.company_id
                  AND e.employee_id=l.employee_id

                 WHERE l.company_id=:company_id",

                'loan_id',

                [
                    'loan_number',
                    'employee_number',
                    'employee_name',
                    'loan_type',
                    'purpose',
                    'currency',
                    'list_status',
                    'due_state',
                ],

                [
                    'loan' => 'loan_number',
                    'employee' => 'employee_name',
                    'request_date' => 'request_date',
                    'principal' => 'principal_amount',
                    'paid' => 'amount_paid',
                    'outstanding' =>
                        'outstanding_principal',
                    'next_due' => 'next_due_date',
                    'overdue' => 'overdue_amount',
                    'status' => 'list_status',
                ],

                [
                    'status' => 'list_status',
                    'currency' => 'currency',
                    'loan_type' => 'loan_type',
                    'due_state' => 'due_state',
                    'overdue' => 'is_overdue',

                    'from' => [
                        'document_date',
                        '>=',
                    ],

                    'to' => [
                        'document_date',
                        '<=',
                    ],
                ],

                'loan',
                'desc',
            ];
        }

        if ($entity === 'bank-mappings') {
            return [
                "SELECT
                    m.*,
                    m.effective_from AS document_date,

                    b.bank_name,
                    b.account_name AS bank_account_name,
                    b.account_number,

                    a.account_code,
                    a.account_name,

                    u.display_name AS approver_name

                 FROM finance_bank_account_gl_mappings m

                 INNER JOIN company_bank_accounts b
                   ON b.company_id=m.company_id
                  AND b.bank_account_id=m.bank_account_id

                 INNER JOIN finance_accounts a
                   ON a.company_id=m.company_id
                  AND a.account_id=m.finance_account_id

                 LEFT JOIN users u
                   ON u.user_id=m.approved_by

                 WHERE m.company_id=:company_id",

                'mapping_id',

                [
                    'bank_name',
                    'bank_account_name',
                    'account_number',
                    'account_code',
                    'account_name',
                    'currency',
                    'status',
                    'cutover_reason',
                    'approver_name',
                ],

                [
                    'effective' => 'effective_from',
                    'bank' => 'bank_name',
                    'account' => 'account_number',
                    'gl' => 'account_code',
                    'status' => 'status',
                    'created' => 'created_at',
                ],

                [
                    'status' => 'status',
                    'currency' => 'currency',
                    'bank' => 'account_number',

                    'from' => [
                        'document_date',
                        '>=',
                    ],

                    'to' => [
                        'document_date',
                        '<=',
                    ],
                ],

                'effective',
                'desc',
            ];
        }

        if ($entity === 'bank-statements') {
            return [
                "SELECT
                    s.*,
                    s.period_end AS document_date,

                    b.bank_name,
                    b.account_name AS bank_account_name,
                    b.account_number,

                    a.account_code,
                    a.account_name AS gl_account_name,

                    r.reconciliation_id,
                    r.version_number AS reconciliation_version,
                    r.status AS reconciliation_status,

                    pu.display_name AS preparer_name,
                    ru.display_name AS reviewer_name

                 FROM finance_bank_statements s

                 INNER JOIN company_bank_accounts b
                   ON b.company_id=s.company_id
                  AND b.bank_account_id=s.bank_account_id

                 INNER JOIN finance_bank_account_gl_mappings m
                   ON m.company_id=s.company_id
                  AND m.mapping_id=s.mapping_id

                 INNER JOIN finance_accounts a
                   ON a.company_id=m.company_id
                  AND a.account_id=m.finance_account_id

                 LEFT JOIN finance_bank_reconciliations r
                   ON r.company_id=s.company_id
                  AND r.statement_id=s.statement_id

                 LEFT JOIN users pu
                   ON pu.user_id=r.prepared_by

                 LEFT JOIN users ru
                   ON ru.user_id=r.reviewed_by

                 WHERE s.company_id=:company_id",

                'COALESCE(reconciliation_id, -statement_id)',

                [
                    'statement_reference',
                    'bank_name',
                    'bank_account_name',
                    'account_number',
                    'account_code',
                    'gl_account_name',
                    'currency',
                    'reconciliation_status',
                    'preparer_name',
                    'reviewer_name',
                ],

                [
                    'period_end' => 'period_end',
                    'period_start' => 'period_start',
                    'reference' => 'statement_reference',
                    'bank' => 'bank_name',
                    'account' => 'account_number',
                    'opening' => 'opening_balance',
                    'ending' => 'ending_balance',
                    'status' => 'reconciliation_status',
                ],

                [
                    'status' => 'reconciliation_status',
                    'currency' => 'currency',
                    'bank' => 'account_number',

                    'from' => [
                        'document_date',
                        '>=',
                    ],

                    'to' => [
                        'document_date',
                        '<=',
                    ],
                ],

                'period_end',
                'desc',
            ];
        }

        throw new InvalidArgumentException(
            'Unknown Finance register.'
        );
    }
}
