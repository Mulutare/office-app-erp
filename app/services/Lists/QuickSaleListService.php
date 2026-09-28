<?php
declare(strict_types=1);
namespace App\Services\Lists;

use App\Services\SalesHierarchyScope;
use App\Services\TenantContext;
use InvalidArgumentException;

/** Scope matches the existing action queues and reporting-tree overview. */
final class QuickSaleListService
{
    public function listing(string $register,int $actor,bool $manager,array $input): SqlList
    {
        $company=(new TenantContext())->companyId();
        $params=['company'=>$company,'actor'=>$actor];
        $scope=new SalesHierarchyScope();
        $where=match($register) {
            'tasks'=>"qs.user_id=:actor AND qs.status IN ('submitted','allocated','reported','return_requested')",
            'queue'=>"qs.manager_user_id=:actor AND (qs.status='submitted' OR (qs.status='reported' AND latest.status='submitted'))",
            'waiting'=>"qs.manager_user_id=:actor AND qs.status='reported' AND latest.status='correction_required'",
            'history'=>($manager?'qs.manager_user_id':'qs.user_id')."=:actor AND qs.status='closed'",
            'hierarchy'=>'',
            default=>throw new InvalidArgumentException('Unknown Quick Sales register.'),
        };
        if($register==='hierarchy') {
            $ids=$scope->userIds($company,$actor);
            if($ids===[]) { $where='1=0'; unset($params['actor']); }
            elseif($scope->hasCompanyWideAccess($company,$actor)) { $where='1=1'; unset($params['actor']); }
            else $where='(qs.user_id IN ('.SalesScopeSql::ids($ids).') OR qs.manager_user_id=:actor)';
        }
        if(in_array($register,['queue','waiting','hierarchy'],true)
            && !$scope->canReviewQuickSale($company,$actor) && !$scope->canReviewSalesReport($company,$actor)) $where.=' AND 1=0';
        $event=match($register) {'queue'=>'qs.created_at','waiting'=>'latest.reviewed_at','history'=>'COALESCE(r.reviewed_at,qs.updated_at)',default=>'qs.updated_at'};
        $history=$register==='history';
        $extra=$history ? ",qs.updated_at closed_at,r.report_id,r.invoice_reference,r.reviewed_at,r.finance_invoice_id,
            (r.evidence_path IS NOT NULL AND r.evidence_path<>'') has_evidence,
            COALESCE((SELECT SUM(sold_quantity) FROM sales_quick_sale_report_lines WHERE company_id=r.company_id AND report_id=r.report_id),0) sold_quantity,
            COALESCE((SELECT SUM(returned_quantity) FROM sales_quick_sale_report_lines WHERE company_id=r.company_id AND report_id=r.report_id),0) returned_quantity"
            : ($register==='waiting'?',latest.report_id,latest.review_note,latest.reviewed_at':'');
        $historyJoin=$history ? "LEFT JOIN sales_quick_sale_reports r ON r.company_id=qs.company_id AND r.quick_sale_id=qs.quick_sale_id AND r.status='confirmed'" : '';
        $join=in_array($register,['tasks','hierarchy'],true)?'LEFT JOIN':'INNER JOIN';
        $sql="SELECT qs.quick_sale_id,qs.quotation_id,qs.status,qs.created_at,qs.updated_at,
            q.quotation_number,q.total_amount,q.currency,COALESCE(a.name,owner.display_name) agent_name,
            t.name team_name,w.code warehouse_code,w.name warehouse_name,manager.display_name manager_name,
            $event event_at,DATE($event) event_date,CASE WHEN qs.status='submitted' THEN 0 ELSE 1 END queue_priority $extra
            FROM sales_quick_sales qs
            INNER JOIN sales_quotations q ON q.company_id=qs.company_id AND q.quotation_id=qs.quotation_id
            $join sales_agents a ON a.company_id=qs.company_id AND a.agent_id=qs.agent_id
            $join sales_teams t ON t.company_id=qs.company_id AND t.team_id=qs.team_id
            $join inventory_warehouses w ON w.company_id=qs.company_id AND w.warehouse_id=qs.warehouse_id
            LEFT JOIN users owner ON owner.user_id=qs.user_id
            LEFT JOIN users manager ON manager.user_id=qs.manager_user_id
            LEFT JOIN sales_quick_sale_reports latest ON latest.company_id=qs.company_id AND latest.quick_sale_id=qs.quick_sale_id
                AND latest.report_id=(SELECT MAX(scan.report_id) FROM sales_quick_sale_reports scan WHERE scan.company_id=qs.company_id AND scan.quick_sale_id=qs.quick_sale_id)
            $historyJoin WHERE qs.company_id=:company AND ($where)";
        $sorts=['date'=>'event_at','reference'=>'quotation_number','agent'=>'agent_name','manager'=>'manager_name','shop'=>'warehouse_name','status'=>'status'];
        if($register==='queue') $sorts=['priority'=>'queue_priority ASC, event_at']+$sorts;
        $query=new ListQuery($input,$sorts,$register==='queue'?'priority':'date',['status','from','to','shop'],'desc',$register);
        return new SqlList(\db(),$sql,$params,$query,
            ['quotation_number','agent_name','manager_name','warehouse_name','warehouse_code','team_name','status','event_date',...($history?['invoice_reference']:[])],
            $sorts,$history?'quick_sale_id ASC, report_id':'quick_sale_id',
            ['status'=>'status','from'=>['event_date','>='],'to'=>['event_date','<='],'shop'=>'warehouse_code']);
    }
}
