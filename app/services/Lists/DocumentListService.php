<?php
declare(strict_types=1);
namespace App\Services\Lists;
use App\Services\TenantContext;

/** Child registers. Call only after the existing domain service authorizes the parent document. */
final class DocumentListService
{
    public function workspace(array $entities,array $input,int $parent,string $path,bool $canExport): array
    {
        $result=['lists'=>[],'exportLists'=>[],'controls'=>[],'columns'=>[],'path'=>$path,'canExport'=>$canExport];
        foreach($entities as $entity){
            $list=$this->listing($entity,$input,$parent);
            $result['exportLists'][$entity]=$list;$result['lists'][$entity]=$list->page();
            $result['controls'][$entity]=$this->controls($entity,$list);$result['columns'][$entity]=$this->columns($entity);
        }
        return $result;
    }
    public function listing(string $entity,array $input,int $parent): SqlList
    {
        [$sql,$id,$search,$sorts,$filters]=$this->definition($entity);
        $params=['company'=>(new TenantContext())->companyId()];
        if($entity!=='bank-accounts')$params['parent']=$parent;
        if($entity==='quick-sale-events')$params['actor']=(int)($_SESSION['auth']['user_id']??0);
        if($entity==='team-members'){
            $scope=new \App\Services\SalesHierarchyScope();$actor=(int)($_SESSION['auth']['user_id']??0);$company=$params['company'];
            if(!$scope->hasPermission($company,$actor,'sales.view'))$sql.=' AND 1=0';
            elseif(!$scope->hasCompanyWideAccess($company,$actor)){
                $users=$scope->userIds($company,$actor);$keys=[];
                foreach($users as $n=>$user){$key='scope_'.$n;$keys[]=':'.$key;$params[$key]=(int)$user;}
                $sql.=$keys?' AND e.user_id IN ('.implode(',',$keys).')':' AND 1=0';
            }
        }
        return new SqlList(\db(),$sql,$params,new ListQuery($input,$sorts,array_key_first($sorts),array_keys($filters),in_array($entity,['pricing-rules','team-members','bank-accounts'],true)?'asc':'desc',str_replace('-','_',$entity)),$search,$sorts,$id,$filters);
    }
    public function controls(string $entity,SqlList $list): array
    {
        [,,,$sorts,$filters,$domains]=$this->definition($entity);
        return FilterOptions::controls($list,$filters,$sorts,$domains);
    }
    public function columns(string $entity): array {return $this->definition($entity)[6];}
    public static function download(array $workspace,array $input): void
    {
        if(!isset($input['download']))return;
        if(!$workspace['canExport'])throw new \RuntimeException('Export permission is required.');
        $entity=ListQuery::text($input['register']??'');
        if(!isset($workspace['exportLists'][$entity]))throw new \InvalidArgumentException('Choose a document register.');
        ListDownload::send($entity,$workspace['exportLists'][$entity],$workspace['columns'][$entity],$input['download']);
    }
    private function definition(string $entity): array
    {
        $dates=static fn(string $column):array=>['from'=>["DATE($column)",'>='],'to'=>["DATE($column)",'<=']];
        return match($entity){
            'employee-positions'=>['SELECT a.*,u.display_name assigned_by_name FROM hr_employee_position_assignments a LEFT JOIN users u ON u.user_id=a.created_by JOIN hr_employees e ON e.company_id=a.company_id AND e.employee_id=a.employee_id AND e.deleted_at IS NULL WHERE a.company_id=:company AND a.employee_id=:parent',
                'assignment_id',['position_code_snapshot','position_name_snapshot','department_name_snapshot','job_title_name_snapshot','branch_name_snapshot','notes','assigned_by_name'],['date'=>'effective_from','position'=>'position_name_snapshot'],['status'=>'assignment_status','department'=>'department_name_snapshot','branch'=>'branch_name_snapshot']+$dates('effective_from'),['status'=>['hr_employee_position_assignments','assignment_status']],
                ['position_code_snapshot'=>'Position code','position_name_snapshot'=>'Position','department_name_snapshot'=>'Department','job_title_name_snapshot'=>'Job title','branch_name_snapshot'=>'Branch','effective_from'=>'Effective from','effective_to'=>'Effective to','assignment_status'=>'Status','assigned_by_name'=>'Assigned by','notes'=>'Notes']],
            'employee-reports'=>["SELECT e.employee_id,e.employee_number,CONCAT_WS(' ',COALESCE(NULLIF(e.preferred_name,''),e.first_name),e.last_name) displayName,e.job_title,e.employment_status,d.name department_name FROM hr_employees e JOIN hr_employees m ON m.company_id=e.company_id AND m.employee_id=e.manager_employee_id AND m.deleted_at IS NULL LEFT JOIN hr_departments d ON d.company_id=e.company_id AND d.department_id=e.department_id WHERE e.company_id=:company AND e.manager_employee_id=:parent AND e.deleted_at IS NULL",
                'employee_id',['employee_number','displayName','job_title','department_name'],['name'=>'displayName','number'=>'employee_number','department'=>'department_name'],['status'=>'employment_status','department'=>'department_name'],['status'=>['hr_employees','employment_status']],
                ['employee_number'=>'Employee number','displayName'=>'Employee','job_title'=>'Job title','department_name'=>'Department','employment_status'=>'Status']],
            'incentive-settlements'=>["SELECT s.*,u.display_name entered_by_name FROM sales_incentive_settlements s LEFT JOIN users u ON u.user_id=s.entered_by WHERE s.company_id=:company AND s.incentive_claim_id=:parent",
                'incentive_settlement_id',['external_payment_reference','evidence_reference','entered_by_name'],['date'=>'settlement_date','amount'=>'amount'],['currency'=>'currency']+$dates('settlement_date'),[],
                ['settlement_date'=>'Date','amount'=>'Amount','currency'=>'Currency','external_payment_reference'=>'Payment reference','evidence_reference'=>'Evidence reference','entered_by_name'=>'Entered by','reversed_at'=>'Reversed']],
            'incentive-events'=>["SELECT e.*,u.display_name actor_name FROM sales_incentive_events e LEFT JOIN users u ON u.user_id=e.actor_id WHERE e.company_id=:company AND e.incentive_claim_id=:parent",
                'incentive_event_id',['event_type','reason_reference','actor_name'],['date'=>'occurred_at','event'=>'event_type'],['event'=>'event_type','status'=>'to_status']+$dates('occurred_at'),['status'=>['sales_incentive_claims','status']],
                ['occurred_at'=>'Date','event_type'=>'Event','from_status'=>'From','to_status'=>'To','actor_name'=>'Actor','reason_reference'=>'Reason / reference']],
            'settlement-confirmations'=>["SELECT bc.*,u.display_name creator_name FROM bank_confirmations bc JOIN users u ON u.user_id=bc.created_by WHERE bc.company_id=:company AND bc.settlement_id=:parent",
                'confirmation_id',['bank_reference','creator_name'],['date'=>'transaction_date','amount'=>'confirmed_amount','reference'=>'bank_reference'],$dates('transaction_date'),[],
                ['bank_reference'=>'Bank reference','transaction_date'=>'Transaction date','confirmed_amount'=>'Confirmed amount','creator_name'=>'Added by']],
            'settlement-events'=>["SELECT e.*,u.display_name actor_name FROM sales_settlement_events e LEFT JOIN users u ON u.user_id=e.actor_id WHERE e.company_id=:company AND e.settlement_id=:parent",
                'event_id',['action','reason','actor_name'],['date'=>'created_at','action'=>'action'],['action'=>'action','status'=>'to_status']+$dates('created_at'),['status'=>FilterOptions::domain('sales_settlements','workflow_status')+FilterOptions::domain('sales_settlements','reconciliation_status')],
                ['created_at'=>'Date','action'=>'Action','actor_name'=>'Actor','from_status'=>'From','to_status'=>'To','reason'=>'Reason']],
            'pricing-rules'=>["SELECT r.*,p.sku,p.name product_name FROM sales_pricelist_rules r LEFT JOIN sales_products p ON p.company_id=r.company_id AND p.product_id=r.product_id WHERE r.company_id=:company AND r.pricelist_id=:parent",
                'rule_id',['sku','product_name','category'],['priority'=>'priority','minimum'=>'minimum_quantity','product'=>'product_name'],['active'=>'active','calculation'=>'calculation','category'=>'category'],['active'=>['1'=>'Active','0'=>'Inactive'],'calculation'=>['sales_pricelist_rules','calculation']],
                ['sku'=>'SKU','product_name'=>'Product','category'=>'Category','minimum_quantity'=>'Minimum quantity','calculation'=>'Calculation','fixed_price'=>'Fixed price','percentage_adjustment'=>'Percentage adjustment','valid_from'=>'Valid from','valid_to'=>'Valid to','priority'=>'Priority','active'=>'Active']],
            'delivery-returns'=>["SELECT picking_id,picking_number,status,created_at,completed_at FROM inventory_pickings WHERE company_id=:company AND original_picking_id=:parent AND picking_type='customer_return'",
                'picking_id',['picking_number'],['date'=>'created_at','number'=>'picking_number'],['status'=>'status']+$dates('created_at'),['status'=>['inventory_pickings','status']],
                ['picking_number'=>'Return','status'=>'Status','created_at'=>'Created','completed_at'=>'Completed']],
            'order-pickings'=>["SELECT picking_id,picking_number,picking_type,status,created_at,completed_at FROM inventory_pickings WHERE company_id=:company AND sales_order_id=:parent",
                'picking_id',['picking_number'],['date'=>'created_at','number'=>'picking_number'],['type'=>'picking_type','status'=>'status']+$dates('created_at'),['type'=>['inventory_pickings','picking_type'],'status'=>['inventory_pickings','status']],
                ['picking_number'=>'Document','picking_type'=>'Type','status'=>'Status','created_at'=>'Created','completed_at'=>'Completed']],
            'order-invoices'=>["SELECT invoice_id,invoice_number,document_type,status,payment_status,total_amount,residual_amount,currency,invoice_date FROM finance_invoices WHERE company_id=:company AND sales_order_id=:parent AND document_type IN('customer_invoice','customer_credit') AND status<>'cancelled'",
                'invoice_id',['invoice_number'],['date'=>'invoice_date','number'=>'invoice_number','amount'=>'total_amount'],['type'=>'document_type','status'=>'status','payment'=>'payment_status']+$dates('invoice_date'),['type'=>['customer_invoice'=>'Customer invoice','customer_credit'=>'Customer credit'],'status'=>['finance_invoices','status'],'payment'=>['finance_invoices','payment_status']],
                ['invoice_number'=>'Invoice','invoice_date'=>'Date','document_type'=>'Type','status'=>'Status','payment_status'=>'Payment','currency'=>'Currency','total_amount'=>'Total','residual_amount'=>'Residual']],
            'order-revisions'=>["SELECT r.*,u.display_name actor_name FROM sales_order_revisions r LEFT JOIN users u ON u.user_id=r.actor_id WHERE r.company_id=:company AND r.order_id=:parent",
                'revision_id',['reason','actor_name','source_event'],['revision'=>'revision_number','date'=>'created_at'],['event'=>'source_event']+$dates('created_at'),[],
                ['revision_number'=>'Revision','created_at'=>'Date','source_event'=>'Event','previous_status'=>'Previous status','reason'=>'Reason','actor_name'=>'Actor']],
            'requisition-history'=>["SELECT h.*,u.display_name actor_name FROM purchase_requisition_status_history h LEFT JOIN users u ON u.user_id=h.actor_id WHERE h.company_id=:company AND h.requisition_id=:parent",
                'history_id',['action','reason','actor_name'],['date'=>'occurred_at','action'=>'action'],['action'=>'action','status'=>'to_status']+$dates('occurred_at'),['status'=>['purchase_requisitions','status']],
                ['occurred_at'=>'Date','action'=>'Action','from_status'=>'From','to_status'=>'To','reason'=>'Reason','actor_name'=>'Actor']],
            'bank-accounts'=>['SELECT bank_account_id,bank_name,account_name,account_number,branch,currency,swift_bic,provider_code,is_default,active FROM company_bank_accounts WHERE company_id=:company',
                'bank_account_id',['bank_name','account_name','account_number','branch','swift_bic','provider_code'],['bank'=>'bank_name','account'=>'account_name','currency'=>'currency'],['active'=>'active','currency'=>'currency','bank'=>'bank_name'],['active'=>['1'=>'Active','0'=>'Inactive']],
                ['bank_name'=>'Bank','account_name'=>'Account name','account_number'=>'Account number','branch'=>'Branch','currency'=>'Currency','swift_bic'=>'SWIFT / BIC','provider_code'=>'Provider','is_default'=>'Default','active'=>'Active']],
            'team-members'=>['SELECT a.agent_id,a.agent_code,a.name,a.agent_type,a.employee_id,e.user_id FROM sales_team_members tm JOIN sales_agents a ON a.company_id=tm.company_id AND a.agent_id=tm.agent_id LEFT JOIN hr_employees e ON e.company_id=a.company_id AND e.employee_id=a.employee_id AND e.deleted_at IS NULL WHERE tm.company_id=:company AND tm.team_id=:parent',
                'agent_id',['agent_code','name'],['name'=>'name','code'=>'agent_code'],['type'=>'agent_type'],['type'=>['sales_agents','agent_type']],['agent_code'=>'Agent code','name'=>'Member','agent_type'=>'Type']],
            'invoice-payments'=>['SELECT a.allocation_id,p.payment_id,p.payment_number,p.payment_date,p.currency,p.amount,a.amount allocated_amount,p.method,p.reference_number,p.status,b.batch_number posting_reference FROM finance_payment_allocations a JOIN finance_payments p ON p.company_id=a.company_id AND p.payment_id=a.payment_id LEFT JOIN finance_journal_batches b ON b.company_id=p.company_id AND b.journal_batch_id=p.journal_batch_id WHERE a.company_id=:company AND a.invoice_id=:parent',
                'allocation_id',['payment_number','reference_number','posting_reference'],['date'=>'payment_date','number'=>'payment_number','amount'=>'amount'],['method'=>'method','status'=>'status']+$dates('payment_date'),['method'=>['finance_payments','method'],'status'=>['finance_payments','status']],
                ['payment_number'=>'Payment','payment_date'=>'Date','currency'=>'Currency','amount'=>'Amount','allocated_amount'=>'Allocated','method'=>'Method','reference_number'=>'Reference','posting_reference'=>'Posting reference','status'=>'Status']],
            'purchase-receipts'=>['SELECT goods_receipt_id,receipt_number,status,receipt_date FROM inventory_goods_receipts WHERE company_id=:company AND purchase_order_id=:parent',
                'goods_receipt_id',['receipt_number'],['date'=>'receipt_date','number'=>'receipt_number'],['status'=>'status']+$dates('receipt_date'),['status'=>['inventory_goods_receipts','status']],
                ['receipt_number'=>'Receipt','receipt_date'=>'Date','status'=>'Status']],
            'purchase-bills'=>['SELECT invoice_id,invoice_number,supplier_invoice_number,invoice_date,document_type,currency,status,payment_status,total_amount,residual_amount FROM finance_invoices WHERE company_id=:company AND purchase_order_id=:parent',
                'invoice_id',['invoice_number','supplier_invoice_number'],['date'=>'invoice_date','number'=>'invoice_number','amount'=>'total_amount'],['status'=>'status','payment'=>'payment_status','type'=>'document_type']+$dates('invoice_date'),['status'=>['finance_invoices','status'],'payment'=>['finance_invoices','payment_status'],'type'=>['vendor_bill'=>'Vendor bill','vendor_credit'=>'Vendor credit']],
                ['invoice_number'=>'Bill','supplier_invoice_number'=>'Supplier reference','invoice_date'=>'Date','document_type'=>'Type','currency'=>'Currency','status'=>'Status','payment_status'=>'Payment','total_amount'=>'Total','residual_amount'=>'Residual']],
            'purchase-returns'=>['SELECT vendor_return_id,return_number,return_date,reason,status FROM procurement_vendor_returns WHERE company_id=:company AND purchase_order_id=:parent',
                'vendor_return_id',['return_number','reason'],['date'=>'return_date','number'=>'return_number'],['status'=>'status']+$dates('return_date'),['status'=>['procurement_vendor_returns','status']],
                ['return_number'=>'Return','return_date'=>'Date','reason'=>'Reason','status'=>'Status']],
            'quick-sale-events'=>["SELECT a.audit_log_id,a.action,a.created_at,a.new_values,u.display_name actor_name,JSON_UNQUOTE(JSON_EXTRACT(a.new_values,'$.reason')) reason
                FROM audit_logs a LEFT JOIN users u ON u.user_id=a.user_id JOIN sales_quick_sales qs ON qs.company_id=a.company_id AND BINARY a.record_id=BINARY CAST(qs.quick_sale_id AS CHAR)
                WHERE a.company_id=:company AND a.table_name='sales_quick_sales' AND qs.quick_sale_id=:parent AND (qs.user_id<>:actor OR a.action<>'quick_sale.finance_handoff')",
                'audit_log_id',['action','actor_name','reason'],['date'=>'created_at','action'=>'action'],['action'=>'action']+$dates('created_at'),[],
                ['created_at'=>'Date','action'=>'Action','actor_name'=>'Actor','reason'=>'Reason']],
            'bank-matches'=>["SELECT m.*,l.line_number,b.batch_number,CASE WHEN m.removed_at IS NULL THEN 'active' ELSE 'removed' END match_status FROM finance_bank_reconciliation_matches m JOIN finance_bank_statement_lines l ON l.company_id=m.company_id AND l.statement_line_id=m.statement_line_id JOIN finance_journal_entries e ON e.company_id=m.company_id AND e.journal_entry_id=m.journal_entry_id JOIN finance_journal_batches b ON b.company_id=e.company_id AND b.journal_batch_id=e.journal_batch_id WHERE m.company_id=:company AND m.reconciliation_id=:parent",
                'match_id',['batch_number','line_number'],['date'=>'created_at','amount'=>'applied_amount','line'=>'line_number'],['status'=>'match_status']+$dates('created_at'),['status'=>['active'=>'Active','removed'=>'Removed']],
                ['line_number'=>'Statement line','batch_number'=>'GL batch','applied_amount'=>'Amount','created_at'=>'Created','match_status'=>'Status','removed_at'=>'Removed']],
            'bank-events'=>['SELECT e.*,u.display_name actor_name FROM finance_bank_reconciliation_events e JOIN users u ON u.user_id=e.actor_id WHERE e.company_id=:company AND e.reconciliation_id=:parent',
                'event_id',['event_type','reason','actor_name'],['date'=>'created_at','event'=>'event_type'],['event'=>'event_type','status'=>'to_status']+$dates('created_at'),['status'=>['finance_bank_reconciliations','status']],
                ['created_at'=>'Date','event_type'=>'Event','actor_name'=>'Actor','from_status'=>'From','to_status'=>'To','reason'=>'Reason']],
            default=>throw new \InvalidArgumentException('Unknown document register.'),
        };
    }
}
