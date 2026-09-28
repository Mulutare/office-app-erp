<?php
declare(strict_types=1);
namespace App\Services\Lists;
use App\Services\SalesHierarchyScope;
use App\Services\TenantContext;

final class SettlementListService
{
    public function listing(array $input): SqlList
    {
        $company=(new TenantContext())->companyId();$actor=(int)($_SESSION['auth']['user_id']??0);
        $scope=new SalesHierarchyScope();
        $predicate='1=0';
        if($scope->hasPermission($company,$actor,'finance.settlements.view')) $predicate='1=1';
        elseif($scope->hasPermission($company,$actor,'sales.settlements.view')) $predicate=$scope->hasCompanyWideAccess($company,$actor)
            ? '1=1' : 's.created_by IN ('.SalesScopeSql::ids($scope->userIds($company,$actor)).')';
        $sorts=['date'=>'created_at','reference'=>'settlement_number','bank'=>'bank_name','amount'=>'expected_amount','status'=>'workflow_status'];
        $query=new ListQuery($input,$sorts,'date',['status','reconciliation','from','to'],'desc');
        $params=['company'=>$company];
        if($query->q!=='') {
            $matches=[];$pattern='%'.strtr($query->q,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            foreach(['s.settlement_number','b.bank_name','u.display_name','s.workflow_status','s.reconciliation_status','DATE(s.created_at)'] as $index=>$field) {
                $matches[]="$field LIKE :search_$index ESCAPE '!'"; $params['search_'.$index]=$pattern;
            }
            $matches[]="EXISTS(SELECT 1 FROM sales_settlement_lines sl JOIN sales_orders o ON o.company_id=sl.company_id AND o.order_id=sl.sales_order_id
                JOIN sales_customers c ON c.company_id=o.company_id AND c.customer_id=o.customer_id
                WHERE sl.company_id=s.company_id AND sl.settlement_id=s.settlement_id
                    AND (o.order_number LIKE :order_search ESCAPE '!' OR c.name LIKE :customer_search ESCAPE '!'))";
            $params['order_search']=$params['customer_search']=$pattern;
            $predicate.=' AND ('.implode(' OR ',$matches).')';
        }
        $sql="SELECT s.*,b.bank_name,b.account_number,u.display_name creator_name,DATE(s.created_at) document_date
            FROM sales_settlements s JOIN company_bank_accounts b ON b.company_id=s.company_id AND b.bank_account_id=s.bank_account_id
            JOIN users u ON u.user_id=s.created_by WHERE s.company_id=:company AND ($predicate)";
        return new SqlList(\db(),$sql,$params,$query,[],
            $sorts,'settlement_id',['status'=>'workflow_status','reconciliation'=>'reconciliation_status','from'=>['document_date','>='],'to'=>['document_date','<=']]);
    }
}
