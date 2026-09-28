<?php

declare(strict_types=1);

namespace App\Services\Lists;

use App\Services\TenantContext;

final class LeaveListService
{
    public const SORTS = ['date'=>'start_date','employee'=>'employee_name','number'=>'employee_number',
        'policy'=>'leave_type_name','status'=>'request_status','days'=>'requested_days'];

    public static function columns(): array
    {
        return ['employee_number'=>'Employee number','employee_name'=>'Employee','department_name'=>'Department',
            'leave_type_code'=>'Policy code','leave_type_name'=>'Leave policy','start_date'=>'Start','end_date'=>'End',
            'requested_days'=>'Days','request_status'=>'Status','reason'=>'Reason','decided_by_name'=>'Decision by',
            'decided_at'=>'Decision date','decision_note'=>'Decision note'];
    }

    /** The leave workspace supplies its existing company/self/direct-manager authority. */
    public function requests(array $input, int $actor, int $selfEmployeeId, bool $company, bool $team): SqlList
    {
        $query = new ListQuery($input, self::SORTS, 'date', ['status','from','to','end_from'], 'desc');
        $sql = "SELECT r.*, t.code leave_type_code, t.name leave_type_name, t.annual_entitlement,
            e.employee_number, e.first_name, e.last_name, e.preferred_name,
            CONCAT_WS(' ',e.first_name,e.last_name) employee_name, d.name department_name,
            u.display_name decided_by_name, m.manager_user_id, m.active membership_active
            FROM hr_leave_requests r
            INNER JOIN hr_leave_types t ON t.company_id=r.company_id AND t.leave_type_id=r.leave_type_id
            INNER JOIN hr_employees e ON e.company_id=r.company_id AND e.employee_id=r.employee_id
            LEFT JOIN hr_departments d ON d.company_id=e.company_id AND d.department_id=e.department_id
            LEFT JOIN users u ON u.user_id=r.decided_by
            LEFT JOIN company_users m ON m.company_id=e.company_id AND m.user_id=e.user_id
            WHERE r.company_id=:company";
        $params = ['company'=>(new TenantContext())->companyId()];
        if (!$company) {
            $scope = ['r.employee_id=:self'];
            $params['self'] = $selfEmployeeId;
            if ($team) {
                $scope[] = '(m.manager_user_id=:manager AND m.active=1)';
                $params['manager'] = $actor;
            }
            $sql .= ' AND (' . implode(' OR ', $scope) . ')';
        }
        return new SqlList(\db(), $sql, $params, $query,
            ['employee_number','employee_name','preferred_name','department_name','leave_type_code','leave_type_name','request_status'],
            self::SORTS, 'leave_request_id', ['status'=>'request_status','from'=>['start_date','>='],'to'=>['start_date','<='],'end_from'=>['end_date','>=']]);
    }
}
