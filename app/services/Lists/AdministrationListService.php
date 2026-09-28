<?php
declare(strict_types=1);
namespace App\Services\Lists;

use App\Services\{AuthService,TenantContext};

/** Administrative read models. Every exporter uses an explicit non-secret projection. */
final class AdministrationListService
{
    public function listing(string $entity,array $input,string $prefix='',array $context=[]): SqlList
    {
        if(in_array($entity,['companies','company-users'],true) && !(new AuthService())->isPlatformAdministrator())throw new \RuntimeException('Platform administrator access is required.');
        $company=match($entity){'companies'=>0,'company-users'=>(int)($context['company_id']??0),default=>(new TenantContext())->companyId()};
        [$sql,$parameters,$id,$search,$sorts,$filters,$default,$direction]=$this->definition($entity,$company,$context);
        if($prefix==='') {
            if(($input['status']??'')==='all')$input['status']='';
            if(($input['type']??'')==='all')$input['type']='';
            if(!isset($input['from']) && isset($input['date_from']))$input['from']=$input['date_from'];
            if(!isset($input['to']) && isset($input['date_to']))$input['to']=$input['date_to'];
            unset($input['date_from'],$input['date_to']);
        }
        $query=new ListQuery($input,$sorts,$default,array_keys($filters),$direction,$prefix);
        if(in_array($entity,['users','company-users'],true) && ($query->filters['status']??'')==='locked')$filters['status']="CASE WHEN is_locked=1 THEN 'locked' ELSE '' END";
        if($entity==='companies')$filters['status']=match($query->filters['status']??'') {
            'pending'=>"CASE WHEN approval_status='pending' THEN 'pending' ELSE '' END",
            'active'=>"CASE WHEN approval_status='approved' AND active=1 AND subscription_status='active' AND (subscription_expires_at IS NULL OR subscription_expires_at>NOW()) THEN 'active' ELSE '' END",
            'trial'=>"CASE WHEN active=1 AND subscription_status='trial' AND (subscription_expires_at IS NULL OR subscription_expires_at>NOW()) THEN 'trial' ELSE '' END",
            'expired'=>"CASE WHEN subscription_expires_at<=NOW() THEN 'expired' ELSE '' END",
            'suspended'=>"CASE WHEN subscription_status='suspended' THEN 'suspended' ELSE '' END",
            'inactive'=>"CASE WHEN active=0 THEN 'inactive' ELSE '' END",default=>'subscription_status',
        };
        return new SqlList(\db(),$sql,$parameters,$query,$search,$sorts,$id,$filters);
    }

    public function controls(string $entity,SqlList $list,array $context=[]): array
    {
        [,,,,$sorts,$filters]=$this->definition($entity,$entity==='companies'?0:(new TenantContext())->companyId(),$context);
        $domains=['active'=>['1'=>'Active','0'=>'Inactive'],'system'=>['1'=>'System','0'=>'Custom']];
        $domains['status']=match($entity) {
            'users','company-users'=>['active'=>'Active','inactive'=>'Inactive','locked'=>'Locked'],
            'companies'=>FilterOptions::labels(['pending','active','trial','expired','suspended','inactive']),
            'events'=>['integration_outbox','status'],default=>[],
        };
        if($entity==='companies')$domains['approval']=['companies','approval_status'];
        return FilterOptions::controls($list,$filters,$sorts,$domains,['actor'=>"COALESCE(actor_name,'System / deleted actor')"]);
    }

    public function workspace(string $entity,array $input,string $prefix='',array $context=[]): array
    {
        $query=$this->listing($entity,$input,$prefix,$context);$list=$query->page();
        if($entity==='users') {
            $users=new \App\Models\User();$roles=$users->roleCodesForUsers((new TenantContext())->companyId(),array_column($list['rows'],'user_id'));
            foreach($list['rows'] as &$user)$user['roles']=$roles[(int)$user['user_id']]??[];unset($user);
        }
        return ['list'=>$list,'exportList'=>$query,'controls'=>$this->controls($entity,$query,$context),'pagination'=>$list['pagination'],'filters'=>$list['query']->parameters(),'rows'=>$list['rows']];
    }

    public function columns(string $entity): array
    {
        return match($entity) {
            'users','company-users','role-users'=>['username'=>'Username','display_name'=>'Name','email'=>'Email','active'=>'Active','last_login_at'=>'Last login'],
            'companies'=>['code'=>'Company code','name'=>'Company','legal_name'=>'Legal name','contact_email'=>'Email','country_code'=>'Country','default_currency'=>'Currency','timezone'=>'Timezone','owner_name'=>'Owner','approval_status'=>'Approval','subscription_status'=>'Subscription','subscription_expires_at'=>'Expires','active'=>'Active','enabled_module_count'=>'Enabled modules'],
            'roles'=>['code'=>'Code','name'=>'Role','description'=>'Description','is_system'=>'System role','active'=>'Active','permission_count'=>'Permissions','user_count'=>'Assigned users','active_user_count'=>'Active users'],
            'role-permissions'=>['code'=>'Code','name'=>'Permission','module'=>'Module','description'=>'Description','active'=>'Active','granted_at'=>'Granted'],
            'audit','employee-activity'=>['created_at'=>'Date','actor_name'=>'Actor','actor_username'=>'Username','action'=>'Action','module'=>'Module','table_name'=>'Record type'],
            'events'=>['event_type'=>'Event','aggregate_type'=>'Record type','status'=>'Status','attempts'=>'Attempts','created_at'=>'Created','available_at'=>'Available','processed_at'=>'Processed','dead_lettered_at'=>'Dead-lettered'],
            'user-activity'=>['occurred_at'=>'Date','source'=>'Source','action'=>'Action','category'=>'Module','actor_name'=>'Actor','actor_username'=>'Username','successful'=>'Successful'],
            default=>throw new \InvalidArgumentException('Unknown Administration export.'),
        };
    }

    private function definition(string $entity,int $company,array $context): array
    {
        $params=['company'=>$company];$dates=['from'=>['DATE(created_at)','>='],'to'=>['DATE(created_at)','<=']];
        if(in_array($entity,['users','company-users'],true))return ["SELECT u.user_id,u.username,u.display_name,u.email,u.is_platform_admin,(u.active=1 AND m.active=1) active,m.active membership_active,
            u.must_change_password,u.failed_login_count,u.locked_until,(u.locked_until>NOW()) is_locked,u.last_login_at,u.password_changed_at,u.created_at,u.updated_at,
            CASE WHEN u.active=1 AND m.active=1 THEN 'active' ELSE 'inactive' END account_status
            FROM company_users m JOIN users u ON u.user_id=m.user_id WHERE m.company_id=:company AND u.deleted_at IS NULL",
            $params,'user_id',['username','display_name','email'],['username'=>'username','display_name'=>'display_name','email'=>'email','last_login_at'=>'last_login_at','created_at'=>'created_at'],['status'=>'account_status']+$dates,'created_at','desc'];
        if($entity==='companies')return ["SELECT c.company_id,c.code,c.name,c.legal_name,c.contact_email,c.country_code,c.default_currency,c.timezone,c.subscription_status,c.subscription_expires_at,c.brand_primary_color,c.approval_status,c.approved_at,c.active,c.created_at,
            owner.display_name owner_name,owner.username owner_username,provisioner.display_name provisioned_by_name,
            (SELECT COUNT(*) FROM company_modules m WHERE m.company_id=c.company_id) catalog_module_count,
            (SELECT COUNT(*) FROM company_modules m WHERE m.company_id=c.company_id AND m.enabled=1 AND m.license_status IN ('active','trial') AND (m.expires_at IS NULL OR m.expires_at>NOW())) enabled_module_count
            FROM companies c LEFT JOIN users owner ON owner.user_id=c.owner_user_id LEFT JOIN users provisioner ON provisioner.user_id=c.provisioned_by WHERE c.deleted_at IS NULL",
            [],'company_id',['code','name','legal_name','contact_email','owner_name'],['name'=>'name','code'=>'code','created_at'=>'created_at','expires'=>'subscription_expires_at'],['status'=>'subscription_status','approval'=>'approval_status','currency'=>'default_currency']+$dates,'created_at','desc'];
        if($entity==='roles')return ["SELECT r.role_id,r.code,r.name,r.description,r.is_system,r.active,r.created_at,
            (SELECT COUNT(*) FROM company_role_permissions p WHERE p.company_id=$company AND p.role_id=r.role_id) permission_count,
            (SELECT COUNT(*) FROM company_user_roles a JOIN users u ON u.user_id=a.user_id AND u.deleted_at IS NULL JOIN company_users m ON m.company_id=a.company_id AND m.user_id=a.user_id WHERE a.company_id=$company AND a.role_id=r.role_id) user_count,
            (SELECT COUNT(*) FROM company_user_roles a JOIN users u ON u.user_id=a.user_id AND u.deleted_at IS NULL AND u.active=1 JOIN company_users m ON m.company_id=a.company_id AND m.user_id=a.user_id AND m.active=1 WHERE a.company_id=$company AND a.role_id=r.role_id) active_user_count
            FROM roles r",[],'role_id',['code','name','description'],['name'=>'name','code'=>'code','users'=>'user_count','permissions'=>'permission_count'],['active'=>'active','system'=>'is_system'],'name','asc'];
        if($entity==='role-users')return ["SELECT u.user_id,u.username,u.display_name,u.email,(u.active=1 AND m.active=1) active,u.last_login_at,a.assigned_at,actor.display_name assigned_by_name
            FROM company_user_roles a JOIN company_users m ON m.company_id=a.company_id AND m.user_id=a.user_id JOIN users u ON u.user_id=a.user_id AND u.deleted_at IS NULL LEFT JOIN users actor ON actor.user_id=a.assigned_by WHERE a.company_id=:company AND a.role_id=:role",
            $params+['role'=>(int)($context['role_id']??0)],'user_id',['username','display_name','email','assigned_by_name'],['name'=>'display_name','username'=>'username','assigned'=>'assigned_at'],['active'=>'active'],'name','asc'];
        if($entity==='role-permissions')return ['SELECT p.permission_id,p.code,p.name,p.module,p.description,p.active,r.granted_at FROM company_role_permissions r JOIN permissions p ON p.permission_id=r.permission_id WHERE r.company_id=:company AND r.role_id=:role',
            $params+['role'=>(int)($context['role_id']??0)],'permission_id',['code','name','description','module'],['name'=>'name','code'=>'code','module'=>'module'],['active'=>'active','module'=>'module'],'module','asc'];
        if(in_array($entity,['audit','employee-activity'],true)) {
            $sql="SELECT a.audit_log_id,a.action,a.module,a.table_name,a.record_id,a.ip_address,a.created_at,u.user_id actor_user_id,u.display_name actor_name,u.username actor_username,COALESCE(CAST(a.user_id AS CHAR),'system') actor_key";
            if($entity==='employee-activity'){$sql.=',a.old_values,a.new_values,a.user_agent';$params['employee']=(int)($context['employee_id']??0);}
            $sql.=' FROM audit_logs a LEFT JOIN users u ON u.user_id=a.user_id WHERE a.company_id=:company';
            if($entity==='employee-activity')$sql.=" AND a.table_name='hr_employees' AND BINARY a.record_id=BINARY CAST(:employee AS CHAR)";
            return [$sql,$params,'audit_log_id',['action','module','table_name','actor_name','actor_username'],['date'=>'created_at','action'=>'action','module'=>'module','actor'=>'actor_name'],['module'=>'module','action'=>'action','actor'=>'actor_key']+$dates,'date','desc'];
        }
        if($entity==='events')return ['SELECT event_id,event_type,aggregate_type,aggregate_id,status,attempts,created_at,claimed_at,available_at,processed_at,dead_lettered_at,last_error FROM integration_outbox WHERE company_id=:company',
            $params,'event_id',['event_type','aggregate_type','event_id'],['date'=>'created_at','type'=>'event_type','status'=>'status','attempts'=>'attempts'],['status'=>'status','event_type'=>'event_type','source'=>'aggregate_type']+$dates,'date','desc'];
        if($entity==='user-activity') {
            $query=(new \App\Repositories\MySql\UserActivityRepository())->listDefinition($company,(int)($context['user_id']??0));
            return [implode(' UNION ALL ',$query['parts']),$query['parameters'],'event_key',['action','category','actor_name','actor_username'],['date'=>'occurred_at','action'=>'action','actor'=>'actor_name'],['type'=>'category','source'=>'source','from'=>['DATE(occurred_at)','>='],'to'=>['DATE(occurred_at)','<=']],'date','desc'];
        }
        throw new \InvalidArgumentException('Unknown Administration register.');
    }
}
