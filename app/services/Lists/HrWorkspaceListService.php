<?php

declare(strict_types=1);

namespace App\Services\Lists;

use App\Services\TenantContext;

final class HrWorkspaceListService
{
    public static function columns(string $entity): array
    {
        return match($entity) {
            'team'=>['employee_number'=>'Employee number','display_name'=>'Employee','email'=>'Email','job_title'=>'Job title','department_name'=>'Department','employment_status'=>'Status','recorded'=>'Recorded days','exceptions'=>'Attendance exceptions','pending_leave_count'=>'Pending leave requests','next_leave_date'=>'Next leave'],
            'assignments'=>['employee_number'=>'Employee number','employee_name'=>'Employee','job_title'=>'Job title','calendar_name'=>'Calendar','timezone'=>'Timezone','effective_from'=>'Effective from','effective_to'=>'Effective to'],
            'adjustments'=>['leave_type_code'=>'Policy code','leave_type_name'=>'Policy','effective_date'=>'Effective date','adjustment_days'=>'Adjustment days','reason'=>'Reason','created_by_name'=>'Recorded by','created_at'=>'Recorded at'],
            'balances'=>['code'=>'Policy code','name'=>'Policy','annual_entitlement'=>'Entitlement','carry_over_days'=>'Carry-over','adjustment_days'=>'Adjustments','available_days'=>'Available','used_days'=>'Used','remaining_days'=>'Remaining'],
            default=>throw new \InvalidArgumentException('Unknown HR register.'),
        };
    }

    public function balances(array $input, int $employee, int $year): SqlList
    {
        $sorts=['name'=>'name','code'=>'code','available'=>'available_days','used'=>'used_days','remaining'=>'remaining_days'];
        $sql="SELECT amounts.*,annual_entitlement+carry_over_days+adjustment_days available_days,
            annual_entitlement+carry_over_days+adjustment_days-used_days remaining_days FROM (
            SELECT t.leave_type_id,t.code,t.name,COALESCE(l.entitlement_days,t.annual_entitlement) annual_entitlement,
                COALESCE(l.carry_over_days,0) carry_over_days,
                COALESCE((SELECT SUM(a.adjustment_days) FROM hr_leave_balance_adjustments a
                    WHERE a.company_id=l.company_id AND a.allocation_id=l.allocation_id),0) adjustment_days,
                COALESCE((SELECT SUM(r.requested_days) FROM hr_leave_requests r
                    WHERE r.company_id=t.company_id AND r.leave_type_id=t.leave_type_id AND r.employee_id=:usage_employee
                    AND r.request_status='approved' AND r.start_date BETWEEN :year_start AND :year_end),0) used_days
            FROM hr_leave_types t LEFT JOIN hr_leave_allocations l ON l.company_id=t.company_id
                AND l.leave_type_id=t.leave_type_id AND l.employee_id=:employee AND l.allocation_year=:year
            WHERE t.company_id=:company AND t.active=1 AND t.deleted_at IS NULL
                AND EXISTS(SELECT 1 FROM hr_employees e WHERE e.company_id=t.company_id AND e.employee_id=:authorized_employee AND e.deleted_at IS NULL)
            ) amounts";
        return new SqlList(\db(),$sql,['company'=>(new TenantContext())->companyId(),'employee'=>$employee,
            'usage_employee'=>$employee,'authorized_employee'=>$employee,'year'=>$year,
            'year_start'=>sprintf('%04d-01-01',$year),'year_end'=>sprintf('%04d-12-31',$year)],
            new ListQuery($input,$sorts,'name',[],'asc','balances'),['code','name'],$sorts,'leave_type_id');
    }

    public function team(array $input, int $manager, string $from, string $to): SqlList
    {
        $sorts = ['name'=>'display_name','number'=>'employee_number','department'=>'department_name','status'=>'employment_status'];
        $query = new ListQuery($input, $sorts, 'name', ['status','month']);
        $sql = "SELECT m.user_id, u.display_name, u.email, u.last_login_at,
            e.employee_id,e.employee_number,e.first_name,e.last_name,e.preferred_name,e.job_title,e.employment_status,
            d.name department_name, a.attendance_status,a.check_in_at,a.check_out_at,a.work_minutes,
            COALESCE(t.recorded,0) recorded,COALESCE(t.exceptions,0) exceptions,
            (SELECT COUNT(*) FROM hr_leave_requests r WHERE r.company_id=m.company_id AND r.employee_id=e.employee_id AND r.request_status='pending') pending_leave_count,
            (SELECT MIN(r.start_date) FROM hr_leave_requests r WHERE r.company_id=m.company_id AND r.employee_id=e.employee_id AND r.request_status='approved' AND r.end_date>=:upcoming) next_leave_date
            FROM company_users m INNER JOIN users u ON u.user_id=m.user_id
            LEFT JOIN hr_employees e ON e.company_id=m.company_id AND e.user_id=m.user_id AND e.deleted_at IS NULL
            LEFT JOIN hr_departments d ON d.company_id=e.company_id AND d.department_id=e.department_id AND d.deleted_at IS NULL
            LEFT JOIN attendance_records a ON a.company_id=m.company_id AND a.employee_id=e.employee_id AND a.attendance_date=:today
            LEFT JOIN (SELECT company_id,employee_id,COUNT(*) recorded,SUM(attendance_status IN ('absent','late')) exceptions
                FROM attendance_records WHERE company_id=:history_company AND attendance_date BETWEEN :from_date AND :to_date
                GROUP BY company_id,employee_id) t ON t.company_id=m.company_id AND t.employee_id=e.employee_id
            WHERE m.company_id=:company AND m.manager_user_id=:manager AND m.active=1 AND u.active=1 AND u.deleted_at IS NULL";
        $company = (new TenantContext())->companyId();
        return new SqlList(\db(), $sql, ['company'=>$company,'manager'=>$manager,'history_company'=>$company,
            'from_date'=>$from,'to_date'=>$to,'today'=>date('Y-m-d'),'upcoming'=>date('Y-m-d')],
            $query, ['display_name','employee_number','email','job_title','department_name','employment_status'],
            $sorts, 'user_id', ['status'=>'employment_status']);
    }

    public function assignments(array $input): SqlList
    {
        $sorts = ['date'=>'effective_from','employee'=>'employee_name','number'=>'employee_number','calendar'=>'calendar_name'];
        $query = new ListQuery($input, $sorts, 'date', ['calendar','year'], 'desc');
        $sql = "SELECT s.*,c.name calendar_name,c.timezone,e.employee_number,e.first_name,e.last_name,e.preferred_name,e.job_title,
            CONCAT_WS(' ',e.first_name,e.last_name) employee_name
            FROM employee_work_schedules s
            INNER JOIN workforce_calendars c ON c.company_id=s.company_id AND c.calendar_id=s.calendar_id
            INNER JOIN hr_employees e ON e.company_id=s.company_id AND e.employee_id=s.employee_id
            WHERE s.company_id=:company AND s.active=1";
        $parameters=['company'=>(new TenantContext())->companyId()];
        if ((int)($input['calendar'] ?? 0)>0) {
            $sql.=' AND s.calendar_id=:calendar';
            $parameters['calendar']=(int)$input['calendar'];
        }
        $year=(int)($input['year'] ?? 0);
        if ($year>=2000 && $year<=2100) {
            $sql.=' AND s.effective_from<=:year_end AND (s.effective_to IS NULL OR s.effective_to>=:year_start)';
            $parameters['year_start']=sprintf('%04d-01-01',$year);
            $parameters['year_end']=sprintf('%04d-12-31',$year);
        }
        return new SqlList(\db(),$sql,$parameters,$query,
            ['employee_number','employee_name','preferred_name','job_title','calendar_name'],$sorts,'schedule_id');
    }

    public function adjustments(array $input, int $employee, int $year): SqlList
    {
        $sorts = ['date'=>'effective_date','policy'=>'leave_type_name','days'=>'adjustment_days'];
        $query = new ListQuery($input,$sorts,'date',['employee','year','policy'],'desc');
        $sql = 'SELECT a.*, l.leave_type_id,t.name leave_type_name,t.code leave_type_code,u.display_name created_by_name
            FROM hr_leave_balance_adjustments a
            INNER JOIN hr_leave_allocations l ON l.company_id=a.company_id AND l.allocation_id=a.allocation_id
            INNER JOIN hr_leave_types t ON t.company_id=l.company_id AND t.leave_type_id=l.leave_type_id
            LEFT JOIN users u ON u.user_id=a.created_by
            WHERE a.company_id=:company AND l.employee_id=:employee AND l.allocation_year=:year';
        return new SqlList(\db(),$sql,['company'=>(new TenantContext())->companyId(),'employee'=>$employee,'year'=>$year],
            $query,['leave_type_name','leave_type_code','reason','created_by_name'],$sorts,'adjustment_id');
    }
}
