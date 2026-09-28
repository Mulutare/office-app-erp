<?php
declare(strict_types=1);
namespace App\Services\Lists;

use App\Services\TenantContext;
use PDO;

final class AssetListService
{
    public function listing(string $entity,array $input,?int $assetId=null): SqlList
    {
        [$sql,$id,$search,$sorts,$filters,$default,$direction]=$this->definition($entity);
        $params=['company_id'=>(new TenantContext())->companyId()];
        if(!in_array($entity,['register','categories'],true))$params['asset_id']=$assetId??0;
        $query=new ListQuery($input,$sorts,$default,array_keys($filters),$direction,$entity==='register'?'assets':$entity);
        return new SqlList(\db(),$sql,$params,$query,$search,$sorts,$id,$filters);
    }

    public function controls(string $entity,SqlList $list): array
    {
        [,,,$sorts,$filters]=$this->definition($entity);
        $table=match($entity){'register'=>'fixed_assets','schedule'=>'asset_depreciation_schedule','maintenance'=>'asset_maintenance_records',default=>''};
        return FilterOptions::controls($list,$filters,$sorts,[
            'status'=>[$table,'status'],'active'=>['1'=>'Active','0'=>'Inactive'],
            'presence'=>['fixed_assets','presence_status'],'health'=>['fixed_assets','health_status'],
            'source'=>['direct'=>'Direct purchase','inventory'=>'Inventory capitalization'],
            'type'=>['asset_maintenance_records','maintenance_type'],
        ]);
    }

    public function workspace(array $input,?int $assetId=null): array
    {
        $company=(new TenantContext())->companyId();
        $workspace=AssetListSql::options(\db(),$company,true);
        $workspace['categoryOptions']=$workspace['categories'];
        $workspace['lists']=[];$workspace['exportLists']=[];$workspace['controls']=[];
        foreach($assetId===null?['register','categories']:['schedule','transfers','maintenance','history'] as $entity) {
            $list=$this->listing($entity,$input,$assetId);$workspace['lists'][$entity]=$list->page();
            $workspace['exportLists'][$entity]=$list;$workspace['controls'][$entity]=$this->controls($entity,$list);
        }
        if($assetId===null) {
            $workspace['assets']=$workspace['lists']['register']['rows'];
            $workspace['categories']=$workspace['lists']['categories']['rows'];
            $workspace['summary']=$workspace['exportLists']['register']->aggregate(['total'=>'COUNT(*)','cost'=>'COALESCE(SUM(acquisition_cost+additional_capitalized_cost),0)','accumulated'=>'COALESCE(SUM(accumulated_depreciation),0)','book_value'=>'COALESCE(SUM(book_value),0)']);
            foreach(['cost','accumulated','book_value'] as $key)$workspace['summary'][$key]=(float)$workspace['summary'][$key];
        } else {
            $statement=\db()->prepare(AssetListSql::header());$statement->execute(['company_id'=>$company,'asset_id'=>$assetId]);
            $asset=$statement->fetch(PDO::FETCH_ASSOC);
            if($asset)foreach($workspace['lists'] as $entity=>$list)$asset[$entity]=$list['rows'];
            $workspace['asset']=$asset?:null;
        }
        return $workspace;
    }

    public function columns(string $entity): array
    {
        return match($entity) {
            'register'=>['asset_number'=>'Asset number','asset_name'=>'Asset','serial_number'=>'Serial','category_name'=>'Category','custodian_name'=>'Custodian','department_name'=>'Department','location_name'=>'Location','acquisition_date'=>'Acquired','source'=>'Source','currency'=>'Currency','acquisition_cost'=>'Cost','accumulated_depreciation'=>'Depreciation','book_value'=>'Book value','presence_status'=>'Presence','health_status'=>'Health','status'=>'Status'],
            'categories'=>['category_code'=>'Code','category_name'=>'Category','useful_life_months'=>'Useful life (months)','depreciation_method'=>'Method','depreciation_frequency'=>'Frequency','salvage_behavior'=>'Salvage','active'=>'Active'],
            'schedule'=>['asset_number'=>'Asset','period_number'=>'Period','depreciation_date'=>'Date','currency'=>'Currency','depreciation_amount'=>'Depreciation','accumulated_amount'=>'Accumulated','book_value_after'=>'Book value','status'=>'Status','batch_number'=>'Journal'],
            'transfers'=>['asset_number'=>'Asset','transferred_at'=>'Date','from_custodian'=>'From custodian','to_custodian'=>'To custodian','from_department'=>'From department','to_department'=>'To department','from_location_name'=>'From location','to_location_name'=>'To location','reason'=>'Reason','actor_name'=>'Recorded by'],
            'maintenance'=>['asset_number'=>'Asset','maintenance_date'=>'Date','maintenance_type'=>'Type','description'=>'Description','vendor_name'=>'Vendor','currency'=>'Currency','cost'=>'Cost','status'=>'Status','actor_name'=>'Recorded by'],
            'history'=>['asset_number'=>'Asset','occurred_at'=>'Date','action'=>'Action','from_status'=>'Previous status','to_status'=>'Status','actor_name'=>'Recorded by'],
            default=>throw new \InvalidArgumentException('Unknown asset register.'),
        };
    }

    private function definition(string $entity): array
    {
        if($entity==='register')return [
            "SELECT asset.*,CASE WHEN source_inventory_movement_id IS NULL THEN 'direct' ELSE 'inventory' END source FROM (".AssetListSql::register().') asset',
            'asset_id',['asset_number','asset_name','serial_number','category_code','category_name','custodian_name','department_name','location_name','vendor_name'],
            ['number'=>'asset_number','name'=>'asset_name','date'=>'acquisition_date','category'=>'category_name','custodian'=>'custodian_name','value'=>'book_value','status'=>'status'],
            ['status'=>'status','category'=>'category_name','department'=>'department_name','location'=>'location_name','presence'=>'presence_status','health'=>'health_status','source'=>'source','from'=>['acquisition_date','>='],'to'=>['acquisition_date','<=']],'number','asc'];
        if($entity==='categories')return ['SELECT * FROM asset_categories WHERE company_id=:company_id','asset_category_id',['category_code','category_name'],['code'=>'category_code','name'=>'category_name','life'=>'useful_life_months','status'=>'active'],['active'=>'active'],'name','asc'];
        $base=' JOIN fixed_assets asset ON asset.company_id=detail.company_id AND asset.asset_id=detail.asset_id ';
        $where=' WHERE detail.company_id=:company_id AND detail.asset_id=:asset_id';
        $select='SELECT detail.*,asset.asset_number,asset.currency';
        return match($entity) {
            'schedule'=>[$select.',batch.batch_number FROM asset_depreciation_schedule detail'.$base.' LEFT JOIN finance_journal_batches batch ON batch.company_id=detail.company_id AND batch.journal_batch_id=detail.journal_batch_id'.$where,
                'depreciation_line_id',['asset_number','batch_number','status'],['period'=>'period_number','date'=>'depreciation_date','amount'=>'depreciation_amount','status'=>'status'],['status'=>'status','from'=>['depreciation_date','>='],'to'=>['depreciation_date','<=']],'period','asc'],
            'transfers'=>[$select.",CONCAT_WS(' ',old.first_name,old.last_name) from_custodian,CONCAT_WS(' ',new.first_name,new.last_name) to_custodian,od.name from_department,nd.name to_department,u.display_name actor_name FROM asset_transfers detail".$base.
                ' LEFT JOIN hr_employees old ON old.company_id=detail.company_id AND old.employee_id=detail.from_custodian_employee_id LEFT JOIN hr_employees new ON new.company_id=detail.company_id AND new.employee_id=detail.to_custodian_employee_id LEFT JOIN hr_departments od ON od.company_id=detail.company_id AND od.department_id=detail.from_department_id LEFT JOIN hr_departments nd ON nd.company_id=detail.company_id AND nd.department_id=detail.to_department_id LEFT JOIN users u ON u.user_id=detail.transferred_by'.$where,
                'asset_transfer_id',['asset_number','from_custodian','to_custodian','from_department','to_department','from_location_name','to_location_name','reason','actor_name'],['date'=>'transferred_at','custodian'=>'to_custodian','location'=>'to_location_name'],['department'=>'to_department','location'=>'to_location_name','from'=>['DATE(transferred_at)','>='],'to'=>['DATE(transferred_at)','<=']],'date','desc'],
            'maintenance'=>[$select.',u.display_name actor_name FROM asset_maintenance_records detail'.$base.' LEFT JOIN users u ON u.user_id=detail.created_by'.$where,
                'asset_maintenance_id',['asset_number','description','vendor_name','maintenance_type','actor_name'],['date'=>'maintenance_date','cost'=>'cost','type'=>'maintenance_type','status'=>'status'],['status'=>'status','type'=>'maintenance_type','from'=>['maintenance_date','>='],'to'=>['maintenance_date','<=']],'date','desc'],
            'history'=>[$select.',u.display_name actor_name FROM asset_history detail'.$base.' LEFT JOIN users u ON u.user_id=detail.actor_id'.$where,
                'asset_history_id',['asset_number','action','actor_name','from_status','to_status'],['date'=>'occurred_at','action'=>'action','actor'=>'actor_name'],['action'=>'action','from'=>['DATE(occurred_at)','>='],'to'=>['DATE(occurred_at)','<=']],'date','desc'],
            default=>throw new \InvalidArgumentException('Unknown asset register.'),
        };
    }
}
