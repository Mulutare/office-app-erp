<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/bootstrap.php';
use App\Services\Lists\FinanceListService;
use App\Services\DataExchange\ExportService;
if(getenv('APP_ENV')!=='testing')throw new RuntimeException('Isolated testing database required.');
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
$db=db();$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL '.$message);++$checks;echo 'PASS '.$message.PHP_EOL;};
$company=(int)$db->query('SELECT company_id FROM companies WHERE deleted_at IS NULL ORDER BY company_id LIMIT 1')->fetchColumn();
$actor=(int)$db->query('SELECT user_id FROM company_users WHERE company_id='.$company.' AND active=1 LIMIT 1')->fetchColumn();
$oldAuth=$_SESSION['auth']??null;$_SESSION['auth']=['user_id'=>$actor,'permissions'=>['finance.export'],'company'=>['company_id'=>$company]];
$prefix='FIN'.strtoupper(bin2hex(random_bytes(3)));
$insert=static function(string $table,array $values)use($db):int{
    $db->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES('.implode(',',array_fill(0,count($values),'?')).')')->execute(array_values($values));
    return (int)$db->lastInsertId();
};
$db->beginTransaction();
try {
    // Licensed fixture and actors exist only inside this rolled-back transaction.
    $db->prepare("UPDATE companies SET active=1,approval_status='approved',subscription_status='active',subscription_expires_at=NULL WHERE company_id=?")->execute([$company]);
    $db->prepare("INSERT INTO company_modules(company_id,module_id,enabled,license_status) SELECT ?,module_id,1,'active' FROM erp_modules WHERE active=1 AND available=1 AND release_status='released' ON DUPLICATE KEY UPDATE enabled=1,license_status='active',expires_at=NULL")->execute([$company]);
    $actor=$insert('users',['username'=>$prefix.'OWNER','email'=>$prefix.'OWNER@example.test','password_hash'=>'test-only-disabled','display_name'=>$prefix.' Finance','must_change_password'=>0]);
    $insert('company_users',['company_id'=>$company,'user_id'=>$actor,'active'=>1]);
    $db->prepare("INSERT INTO company_user_roles(company_id,user_id,role_id) SELECT ?,?,role_id FROM roles WHERE code='company_owner'")->execute([$company,$actor]);
    $_SESSION['auth']['user_id']=$actor;
    $dsa=$insert('users',['username'=>$prefix.'DSA','email'=>$prefix.'DSA@example.test','password_hash'=>'test-only-disabled','display_name'=>$prefix.' DSA','must_change_password'=>0]);
    $insert('company_users',['company_id'=>$company,'user_id'=>$dsa,'active'=>1,'manager_user_id'=>$actor]);
    $dsaEmployee=$insert('hr_employees',['company_id'=>$company,'user_id'=>$dsa,'employee_number'=>$prefix.'DSA','first_name'=>'DSA','last_name'=>$prefix,'job_title'=>'DSA','employment_type'=>'full_time','employment_status'=>'active','hire_date'=>'2025-01-01','work_email'=>$prefix.'dsa@work.test']);
    $agent=$insert('sales_agents',['company_id'=>$company,'employee_id'=>$dsaEmployee,'agent_code'=>$prefix,'name'=>$prefix.' Agent','agent_type'=>'DSA']);
    $team=$insert('sales_teams',['company_id'=>$company,'name'=>$prefix.' Team']);
    $warehouse=$insert('inventory_warehouses',['company_id'=>$company,'code'=>$prefix,'name'=>$prefix.' Shop','manager_user_id'=>$actor]);
    $customer=$insert('sales_customers',['company_id'=>$company,'customer_number'=>$prefix,'name'=>$prefix.' Customer']);
    $supplier=$insert('purchase_suppliers',['company_id'=>$company,'supplier_code'=>$prefix,'business_name'=>$prefix.' Supplier','currency'=>'ETB']);
    $employee=$insert('hr_employees',['company_id'=>$company,'employee_number'=>$prefix,'first_name'=>$prefix,'last_name'=>'Employee','job_title'=>'Accountant','employment_type'=>'full_time','employment_status'=>'active','hire_date'=>'2025-01-01','work_email'=>$prefix.'@example.test']);
    $journal=$insert('finance_journals',['company_id'=>$company,'journal_code'=>$prefix,'journal_name'=>$prefix,'journal_type'=>'general']);
    $cash=(int)$db->query("SELECT account_id FROM finance_accounts WHERE company_id=$company AND system_key='cash' AND deleted_at IS NULL LIMIT 1")->fetchColumn();
    if(!$cash)$cash=$insert('finance_accounts',['company_id'=>$company,'account_code'=>'CASH'.bin2hex(random_bytes(3)),'account_name'=>'Cash','account_type'=>'asset','normal_balance'=>'debit','system_key'=>'cash']);
    $accounts=[];$lastReconciliation=[];
    for($i=1;$i<=105;++$i) {
        $ref=$prefix.'-'.sprintf('%03d',$i);
        $accounts[$i]=$insert('finance_accounts',['company_id'=>$company,'account_code'=>$ref,'account_name'=>$ref,'account_type'=>'asset','normal_balance'=>'debit','currency'=>'ETB','active'=>$i===105?0:1]);
        $order=$insert('sales_orders',['company_id'=>$company,'customer_id'=>$customer,'order_number'=>$ref,'order_date'=>'2026-01-01','due_date'=>'2099-01-31','currency'=>'ETB','total_amount'=>100]);
        $insert('finance_sales_receivables',['company_id'=>$company,'order_id'=>$order,'customer_id'=>$customer,'order_number'=>$ref,'currency'=>'ETB','original_amount'=>100,'balance_amount'=>100,'due_date'=>'2099-01-31']);
        $payment=$insert('sales_payments',['company_id'=>$company,'order_id'=>$order,'receipt_number'=>$ref,'amount'=>100,'payment_date'=>'2026-01-01','payment_method'=>$i===105?'check':'cash']);
        $insert('finance_sales_receipts',['company_id'=>$company,'order_id'=>$order,'payment_id'=>$payment,'receipt_number'=>$ref,'amount'=>100,'payment_date'=>'2026-01-01','payment_method'=>$i===105?'check':'cash','posted_at'=>'2026-01-01 09:00:00']);
        $batch=$insert('finance_journal_batches',['company_id'=>$company,'batch_number'=>$ref,'source_type'=>'manual','source_number'=>$ref,'posting_date'=>'2026-01-01','currency'=>'ETB','description'=>$ref,'status'=>'posted','total_debit'=>100,'total_credit'=>100,'idempotency_key'=>$ref,'posted_by'=>$actor]);
        foreach([[$cash,100,0],[$accounts[$i],0,100]] as $line=>[$account,$debit,$credit])$insert('finance_journal_entries',['company_id'=>$company,'journal_batch_id'=>$batch,'line_number'=>$line+1,'account_id'=>$account,'debit_amount'=>$debit,'credit_amount'=>$credit,'currency'=>'ETB']);
        foreach(['customer_invoice','vendor_bill'] as $type)$insert('finance_invoices',['company_id'=>$company,'journal_id'=>$journal,'document_type'=>$type,'invoice_number'=>$ref.($type==='vendor_bill'?'V':'C'),'customer_id'=>$type==='customer_invoice'?$customer:null,'sales_order_id'=>$type==='customer_invoice'?$order:null,'vendor_id'=>$type==='vendor_bill'?$supplier:null,'invoice_date'=>'2026-01-01','due_date'=>'2099-01-31','currency'=>'ETB','status'=>'posted','payment_status'=>$i===105?'partially_paid':'unpaid','total_amount'=>100,'residual_amount'=>$i===105?50:100]);
        $expense=$insert('finance_expense_requests',['company_id'=>$company,'request_number'=>$ref,'title'=>$ref,'requested_by_employee_id'=>$employee,'amount'=>100,'currency'=>'ETB','expense_date'=>'2026-01-01','status'=>$i===105?'cancelled':'draft']);
        $insert('finance_expense_categories',['company_id'=>$company,'code'=>$ref,'name'=>$ref,'active'=>$i===105?0:1]);
        $insert('finance_expense_history',['company_id'=>$company,'expense_request_id'=>$expense,'to_status'=>'draft','action'=>'created','actor_id'=>$actor]);
        $loan=$insert('finance_staff_loans',['company_id'=>$company,'employee_id'=>$employee,'loan_number'=>$ref,'loan_type'=>$i===105?'advance':'loan','purpose'=>$ref,'currency'=>'ETB','principal_amount'=>100,'outstanding_principal'=>100,'request_date'=>'2026-01-01','installment_frequency'=>'monthly','installment_count'=>1,'first_due_date'=>'2099-01-31','created_by'=>$actor]);
        $quotation=$insert('sales_quotations',['company_id'=>$company,'quotation_number'=>$ref,'customer_id'=>$customer,'sales_order_id'=>$order,'quotation_date'=>'2026-01-01','currency'=>'ETB','untaxed_amount'=>100,'tax_amount'=>0,'total_amount'=>100]);
        $quick=$insert('sales_quick_sales',['company_id'=>$company,'quotation_id'=>$quotation,'user_id'=>$dsa,'agent_id'=>$agent,'team_id'=>$team,'manager_user_id'=>$actor,'origin_manager_user_id'=>$actor,'warehouse_id'=>$warehouse,'origin_warehouse_id'=>$warehouse,'status'=>'closed']);
        $invoiceQuery=$db->prepare('SELECT invoice_id FROM finance_invoices WHERE company_id=? AND invoice_number=?');$invoiceQuery->execute([$company,$ref.'C']);$quickInvoice=(int)$invoiceQuery->fetchColumn();
        $insert('sales_quick_sale_reports',['company_id'=>$company,'quick_sale_id'=>$quick,'reported_by_user_id'=>$dsa,'status'=>'confirmed','invoice_reference'=>$ref,'finance_invoice_id'=>$quickInvoice,'finance_handoff_at'=>'2026-01-01 09:00:00']);
        $bank=$insert('company_bank_accounts',['company_id'=>$company,'bank_name'=>$ref,'account_name'=>$ref,'account_number'=>$ref,'currency'=>'ETB']);
        $binding=$insert('finance_bank_gl_bindings',['company_id'=>$company,'bank_account_id'=>$bank,'finance_account_id'=>$accounts[$i],'currency'=>'ETB','created_by'=>$actor]);
        $mapping=$insert('finance_bank_account_gl_mappings',['company_id'=>$company,'bank_account_id'=>$bank,'finance_account_id'=>$accounts[$i],'binding_id'=>$binding,'currency'=>'ETB','effective_from'=>'2026-01-01','opening_gl_balance'=>0,'cutover_reason'=>$ref,'created_by'=>$actor]);
        $statement=$insert('finance_bank_statements',['company_id'=>$company,'bank_account_id'=>$bank,'mapping_id'=>$mapping,'currency'=>'ETB','statement_reference'=>$ref,'period_start'=>'2026-01-01','period_end'=>'2026-01-31','statement_date'=>'2026-01-31','opening_balance'=>0,'ending_balance'=>0,'created_by'=>$actor]);
        $lastReconciliation=['company_id'=>$company,'bank_account_id'=>$bank,'mapping_id'=>$mapping,'statement_id'=>$statement,'currency'=>'ETB','gl_account_id'=>$accounts[$i],'statement_opening_balance'=>0,'statement_ending_balance'=>0,'prepared_by'=>$actor,'prepared_at'=>'2026-01-31 09:00:00'];
        $insert('finance_bank_reconciliations',$lastReconciliation);
        $date=(new DateTimeImmutable('2070-01-01'))->modify('+'.$i.' days')->format('Y-m-d');
        $year=$insert('finance_fiscal_years',['company_id'=>$company,'fiscal_year_name'=>$ref,'date_from'=>$date,'date_to'=>$date]);
        $period=$insert('finance_accounting_periods',['company_id'=>$company,'fiscal_year_id'=>$year,'period_name'=>$ref,'date_from'=>$date,'date_to'=>$date,'status'=>$i===105?'locked':'open']);
        $insert('finance_accounting_period_history',['company_id'=>$company,'period_id'=>$period,'action'=>$i===105?'reopened':'created','status_to'=>'open','reason'=>$ref,'acted_by'=>$actor]);
    }
    $factory=new FinanceListService();
    $entities=['receivables','invoices','receipts','journals','expenses','accounts','ledger','ar-aging','payables','cash-bank','staff-loans','bank-mappings','bank-statements','accounting-periods','fiscal-years','period-history','expense-categories','expense-history'];
    foreach($entities as $entity) {
        $expected=$entity==='ledger'?210:105;
        $list=$factory->listing($entity,['q'=>$prefix]);$first=$list->page();
        $check($first['pagination']['total']===$expected&&count($first['rows'])===25,"$entity filters and counts full dataset before paging");
        foreach([50,100] as $size)$check(count($factory->listing($entity,['q'=>$prefix,'per_page'=>$size])->page()['rows'])===$size,"$entity supports page size $size");
        $second=$factory->listing($entity,['q'=>$prefix,'per_page'=>100,'page'=>2])->page();
        $check(count($second['rows'])===min(100,$expected-100),"$entity reaches page two");
        $check($factory->listing($entity,['q'=>$prefix.'-105'])->page()['pagination']['total']===($entity==='ledger'?2:1),"$entity searches beyond first page");
        $check($factory->listing($entity,['q'=>$prefix.'MISSING','page'=>999])->page()['pagination']['total']===0,"$entity has an empty state without fallback rows");
        $rows=$list->export();$check(count($rows)===$expected,"$entity exports all matching rows");
        foreach(['csv','xlsx'] as $format){$file=(new ExportService())->register($entity,$format,$factory->columns($entity),$rows);$check(strlen($file['contents'])>100,"$entity produces $format");}
        $_SESSION['auth']['company']['company_id']=$company+1000000;
        $check($factory->listing($entity,['q'=>$prefix])->page()['pagination']['total']===0,"$entity cannot leak another company's rows");
        $_SESSION['auth']['company']['company_id']=$company;
    }
    foreach(['invoices'=>['payment'=>'partially_paid'],'expenses'=>['status'=>'cancelled'],'accounts'=>['active'=>'0'],'staff-loans'=>['loan_type'=>'advance'],'receipts'=>['method'=>'check'],'accounting-periods'=>['status'=>'locked'],'period-history'=>['action'=>'reopened']] as $entity=>$filters)
        $check($factory->listing($entity,['q'=>$prefix]+$filters)->page()['pagination']['total']===1,"$entity categorical filter preserves exact value");
    $options=$factory->controls('bank-mappings')['filters']['bank']['options'];
    $check(isset($options[$prefix.'-105']),'Bank dropdown includes values beyond the visible page');
    $check(count($factory->controls('accounting-periods')['filters']['year']['options'])>=105,'Period year dropdown includes all authorized years');
    $insert('finance_bank_reconciliations',$lastReconciliation+['version_number'=>2]);
    $versions=$factory->listing('bank-statements',['q'=>$prefix.'-105'])->export();
    $check(count($versions)===2&&$versions[0]['reconciliation_id']!==$versions[1]['reconciliation_id'],'Bank register retains both reconciliation versions with stable unique ordering');
    $periodWorkspace=(new App\Services\AccountingPeriodService())->workspace(['periods'=>['q'=>$prefix],'history'=>['q'=>$prefix,'per_page'=>50],'fiscal_years'=>['q'=>$prefix,'per_page'=>100]]);
    $check(count($periodWorkspace['periods'])===25&&count($periodWorkspace['history'])===50&&count($periodWorkspace['fiscal_years'])===100,'Period registers have independent page sizes');
    ob_start();view('finance.accounting-periods',['workspace'=>$periodWorkspace,'periodData'=>$periodWorkspace]+$periodWorkspace);$html=ob_get_clean();
    $check(str_contains($html,'periods%5Bq%5D')&&str_contains($html,'register=period-history'),'Period view preserves namespaces and explicit export target');

    $reconciliation=(int)$versions[0]['reconciliation_id'];
    $entryQuery=$db->prepare('SELECT journal_entry_id FROM finance_journal_entries WHERE company_id=? AND journal_batch_id=? ORDER BY journal_entry_id LIMIT 1');$entryQuery->execute([$company,$batch]);$entry=(int)$entryQuery->fetchColumn();
    for($i=1;$i<=105;++$i){
        $line=$insert('finance_bank_statement_lines',['company_id'=>$company,'statement_id'=>$statement,'bank_account_id'=>$bank,'currency'=>'ETB','line_number'=>$i,'transaction_date'=>'2026-01-01','description'=>$prefix.'BANK'.sprintf('%03d',$i),'direction'=>'credit','amount'=>1,'created_by'=>$actor]);
        $insert('finance_bank_reconciliation_matches',['company_id'=>$company,'reconciliation_id'=>$reconciliation,'statement_line_id'=>$line,'journal_entry_id'=>$entry,'applied_amount'=>1,'created_by'=>$actor]);
        $insert('finance_bank_reconciliation_events',['company_id'=>$company,'reconciliation_id'=>$reconciliation,'event_type'=>'created','to_status'=>'draft','reason'=>$prefix.'BANK'.sprintf('%03d',$i),'actor_id'=>$actor]);
    }
    $documents=new App\Services\Lists\DocumentListService();
    foreach(['bank-matches','bank-events'] as $entity){
        $ns=str_replace('-','_',$entity);$list=$documents->listing($entity,[],$reconciliation);
        $check($list->page()['pagination']['total']===105&&count($list->page()['rows'])===25,"$entity counts all 105 events before paging");
        foreach([25,50,100] as $size)$check(count($documents->listing($entity,[$ns=>['per_page'=>$size]],$reconciliation)->page()['rows'])===$size,"$entity size $size");
        $check(count($documents->listing($entity,[$ns=>['q'=>$entity==='bank-matches'?'105':'BANK105']],$reconciliation)->export())===($entity==='bank-matches'?105:1),"$entity searches business references");
        foreach(['xlsx','csv'] as $format)$check(strlen((new ExportService())->register($entity,$format,$documents->columns($entity),$list->export())['contents'])>1000&&count($list->export())===105,"$entity full $format export");
        $check($documents->listing($entity,[$ns=>['q'=>'NOT-A-BANK-EVENT']],$reconciliation)->export()===[],"$entity empty search stays empty");
        $_SESSION['auth']['company']['company_id']=$company+1000000;$check($documents->listing($entity,[],$reconciliation)->export()===[],"$entity company isolation");$_SESSION['auth']['company']['company_id']=$company;
    }
    $legacyWorksheet=(new App\Services\FinanceBankReconciliationService())->worksheet($reconciliation);
    $pagedWorksheet=(new App\Services\FinanceBankReconciliationService())->worksheet($reconciliation,false);
    $check($legacyWorksheet['figures']===$pagedWorksheet['figures']&&$legacyWorksheet['lines']===$pagedWorksheet['lines']&&$legacyWorksheet['books']===$pagedWorksheet['books'],'Worksheet list changes preserve all financial figures and complete matching choices');
    $pagedWorksheet['related']=$documents->workspace(['bank-matches','bank-events'],[],$reconciliation,'/bank',true);
    ob_start();view('finance.bank-reconciliation-worksheet',['worksheet'=>$pagedWorksheet]);$html=ob_get_clean();
    $check(str_contains($html,'register=bank-matches')&&str_contains($html,'register=bank-events'),'Worksheet renders separate clearing and event exports');
    $overview=(new App\Services\FinanceDashboardService())->smartOverview('dashboard',['receivables'=>['q'=>$prefix,'per_page'=>50]],true);
    $check(count($overview['overviewRegister']['lists']['receivables']['rows'])===50&&$overview['overviewRegister']['lists']['receivables']['pagination']['total']===105,'Finance landing page uses the complete shared register');
    ob_start();view('finance.index',$overview+['user'=>['permissions'=>[]]]);$html=ob_get_clean();$check(str_contains($html,'name="receivables[q]"')&&str_contains($html,'register=receivables'),'Finance landing page renders scoped filters and exports');
    $expenseWorkspace=(new App\Services\FinanceExpenseService())->workspace(['categories'=>['q'=>$prefix,'per_page'=>50],'history'=>['q'=>$prefix]],$factory->listing('expenses',['q'=>$prefix])->page()['rows']);
    $check(count($expenseWorkspace['categorySettings'])===50&&count($expenseWorkspace['history'])===25&&count($expenseWorkspace['categories'])>=104,'Expense forms retain full category choices beside independent paged history and settings');
    ob_start();view('finance.expenses',['expenseData'=>$expenseWorkspace,'expenseList'=>$factory->listing('expenses',['q'=>$prefix])->page(),'expenseControls'=>$factory->controls('expenses'),'canExport'=>true,'user'=>['user_id'=>$actor,'permissions'=>['finance.records.manage']]]);$html=ob_get_clean();
    $check(str_contains($html,'register=expense-history')&&str_contains($html,'register=expense-categories'),'Expense support registers render working export targets');
    for($i=1;$i<=105;++$i){
        $ref=$prefix.'CHILD'.sprintf('%03d',$i);
        $insert('finance_staff_loan_installments',['company_id'=>$company,'loan_id'=>$loan,'installment_number'=>$i,'due_date'=>'2099-01-31','opening_balance'=>10500-100*($i-1),'principal_due'=>100,'total_due'=>100,'remaining_due'=>100]);
        $insert('finance_staff_loan_payments',['company_id'=>$company,'loan_id'=>$loan,'payment_number'=>$ref,'payment_date'=>'2026-01-01','amount'=>100,'principal_amount'=>100,'interest_amount'=>0,'journal_id'=>$journal,'journal_batch_id'=>$batch,'idempotency_key'=>$ref,'reference_number'=>$ref,'posted_by'=>$actor]);
        $insert('finance_staff_loan_history',['company_id'=>$company,'loan_id'=>$loan,'action'=>'created','to_status'=>'draft','reason'=>$ref,'actor_id'=>$actor]);
    }
    $loanFactory=new App\Services\Lists\FinanceLoanListService();
    foreach(['installments','payments','history'] as $entity){
        $child=$loanFactory->listing($entity,$loan,[]);$check($child->page()['pagination']['total']===105&&count($child->page()['rows'])===25,'Loan '.$entity.' counts beyond first page');
        $check(count($child->export())===105,'Loan '.$entity.' export includes full scoped child history');
        $check(count($loanFactory->listing($entity,$loan,[$entity=>['page'=>2,'per_page'=>100]])->page()['rows'])===5,'Loan '.$entity.' reaches page two');
        $check(count($loanFactory->listing($entity,$loan,[$entity=>['q'=>$entity==='installments'?'105':'CHILD105']])->export())===1,'Loan '.$entity.' searches beyond page one');
        $_SESSION['auth']['company']['company_id']=$company+1000000;
        $check($loanFactory->listing($entity,$loan,[])->export()===[],'Loan '.$entity.' rejects foreign-company parent');
        $_SESSION['auth']['company']['company_id']=$company;
    }
    $loanService=new App\Services\FinanceStaffLoanService();$legacyLoan=$loanService->detail($loan);$pagedLoan=$loanService->detail($loan,['installments'=>['q'=>'105']]);
    $check($legacyLoan['installments_remaining']==$pagedLoan['installments_remaining']&&$legacyLoan['next_installment_amount']==$pagedLoan['next_installment_amount']&&count($pagedLoan['installments'])===1,'Loan accounting summary remains unchanged by detail search');
    $queueFactory=new App\Services\Lists\FinanceQuickSaleListService();$queue=$queueFactory->listing($actor,['quick_sales'=>['q'=>$prefix]]);
    $check($queue->page()['pagination']['total']===105&&count($queue->page()['rows'])===25,'Finance handoff queue scopes and counts before paging');
    $check(count($queue->export())===105,'Finance handoff export includes all matching reports');
    $check($queueFactory->listing($actor,['quick_sales'=>['q'=>$prefix.'-105']])->page()['pagination']['total']===1,'Finance handoff queue finds records beyond first page');
    $check($queueFactory->listing($dsa,['quick_sales'=>['q'=>$prefix]])->export()===[],'DSA cannot use the Finance queue to discover company invoices');
    $check(count((new App\Services\SalesQuickSaleService())->financeQueue($actor,$quickInvoice))===1,'Invoice evidence lookup reads only the selected invoice');
    $check(!isset(App\Services\Lists\FinanceQuickSaleListService::columns()['evidence_path']),'Finance handoff exports exclude private evidence paths');
    $olderInvoice=$insert('finance_invoices',['company_id'=>$company,'journal_id'=>$journal,'document_type'=>'customer_invoice','invoice_number'=>$prefix.'OPENING','customer_id'=>$customer,'invoice_date'=>'2025-12-01','due_date'=>'2025-12-31','currency'=>'ETB','status'=>'posted','total_amount'=>50,'residual_amount'=>25]);
    $postedPayment=$insert('finance_payments',['company_id'=>$company,'journal_id'=>$journal,'customer_id'=>$customer,'payment_number'=>$prefix.'PAYMENT','direction'=>'inbound','payment_date'=>'2026-01-02','currency'=>'ETB','amount'=>25,'allocated_amount'=>25,'unallocated_amount'=>0,'method'=>'cash','status'=>'posted']);
    $insert('finance_payment_allocations',['company_id'=>$company,'payment_id'=>$postedPayment,'invoice_id'=>$olderInvoice,'amount'=>25,'allocated_at'=>'2026-01-02 09:00:00']);
    $insert('finance_invoices',['company_id'=>$company,'journal_id'=>$journal,'document_type'=>'customer_invoice','invoice_number'=>$prefix.'USD','customer_id'=>$customer,'invoice_date'=>'2026-01-01','due_date'=>'2026-01-31','currency'=>'USD','status'=>'posted','total_amount'=>70,'residual_amount'=>70]);
    $statementService=new App\Services\FinanceStatementService();
    $statementInput=['party_id'=>$customer,'from'=>'2026-01-01','to'=>'2026-01-31','currency'=>'ETB','per_page'=>100];
    $statement=$statementService->statement('customer',$statementInput);
    $check($statement['list']['pagination']['total']===106&&count($statement['lines'])===100,'Statement counts posted documents and allocated payments before paging');
    $check($statement['opening']['ETB']===50.0&&$statement['totals']['ETB']===10525.0,'Statement preserves opening balance and full-period closing balance');
    $statementSecond=$statementService->statement('customer',$statementInput+['page'=>2]);
    $check(count($statementSecond['lines'])===6&&(float)$statementSecond['lines'][5]['balance']===10525.0,'Statement page two keeps the complete running balance');
    $searchedStatement=$statementService->statement('customer',$statementInput+['q'=>$prefix.'-105']);
    $check(count($searchedStatement['lines'])===1&&(float)$searchedStatement['lines'][0]['balance']===10550.0&&$searchedStatement['totals']['ETB']===10525.0,'Statement search does not recompute the ledger balance from matching rows');
    $check(count($statement['exportList']->export())===106,'Statement export includes every matching activity');
    $usd=$statementService->statement('customer',['party_id'=>$customer,'currency'=>'USD']);
    $check($usd['totals']===['USD'=>70.0]&&(float)$usd['lines'][0]['balance']===70.0,'Statement balances remain separate per currency');
    $reversedBill=$insert('finance_invoices',['company_id'=>$company,'journal_id'=>$journal,'document_type'=>'vendor_bill','invoice_number'=>$prefix.'REVERSED','vendor_id'=>$supplier,'invoice_date'=>'2025-12-01','due_date'=>'2025-12-31','currency'=>'ETB','status'=>'reversed','total_amount'=>50,'residual_amount'=>0]);
    $insert('finance_journal_batches',['company_id'=>$company,'batch_number'=>$prefix.'REV','source_type'=>'vendor_bill_reversal','source_id'=>(string)$reversedBill,'posting_date'=>'2026-01-02','currency'=>'ETB','description'=>'Reverse bill','status'=>'posted','idempotency_key'=>$prefix.'REV']);
    $supplierStatement=$statementService->statement('supplier',['party_id'=>$supplier,'from'=>'2026-01-01','to'=>'2026-01-31']);
    $check($supplierStatement['opening']['ETB']===-50.0&&$supplierStatement['totals']['ETB']===-10500.0&&$supplierStatement['list']['pagination']['total']===106,'Supplier statement preserves bill reversal and opening liability semantics');
    foreach(['csv','xlsx'] as $format){$file=(new ExportService())->register('statement',$format,App\Services\Lists\FinanceStatementListService::columns(),$statement['exportList']->export());$check(strlen($file['contents'])>100,'Statement produces '.$format);}
    $_SESSION['auth']['company']['company_id']=$company+1000000;$denied=false;
    try{$statementService->statement('customer',$statementInput);}catch(RuntimeException $e){$denied=true;}
    $check($denied,'Statement rejects a foreign-company party before exposing history');
    $_SESSION['auth']['company']['company_id']=$company;
} finally {$db->rollBack();$_SESSION['auth']=$oldAuth;}
echo "$checks Finance smart-list checks passed".PHP_EOL;
