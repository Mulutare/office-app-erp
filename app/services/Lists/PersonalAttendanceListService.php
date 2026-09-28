<?php
declare(strict_types=1);
namespace App\Services\Lists;
use App\Services\TenantContext;

final class PersonalAttendanceListService
{
    public function listing(array $input,int $actor,string $from,string $to): SqlList
    {
        $sorts=['date'=>'attendance_date','status'=>'attendance_status','minutes'=>'work_minutes'];
        $filters=['status'=>'attendance_status','source'=>'source','from'=>['attendance_date','>='],'to'=>['attendance_date','<=']];
        return new SqlList(\db(),"SELECT a.* FROM attendance_records a JOIN hr_employees e ON e.company_id=a.company_id AND e.employee_id=a.employee_id
            WHERE a.company_id=:company AND e.user_id=:actor AND e.deleted_at IS NULL AND a.attendance_date BETWEEN :start AND :end",
            ['company'=>(new TenantContext())->companyId(),'actor'=>$actor,'start'=>$from,'end'=>$to],
            new ListQuery($input,$sorts,'date',array_keys($filters),'desc','history'),['attendance_date','notes'],$sorts,'attendance_id',$filters);
    }
    public function controls(SqlList $list): array
    {
        return FilterOptions::controls($list,['status'=>'attendance_status','source'=>'source','from'=>['attendance_date','>='],'to'=>['attendance_date','<=']],
            ['date'=>'Date','status'=>'Status','minutes'=>'Working minutes'],['status'=>['attendance_records','attendance_status'],'source'=>['attendance_records','source']]);
    }
    public function columns(): array
    {
        return ['attendance_date'=>'Date','attendance_status'=>'Status','check_in_at'=>'Check-in','check_out_at'=>'Check-out','work_minutes'=>'Net working minutes','gross_minutes'=>'Gross minutes','break_minutes'=>'Break minutes','target_work_minutes'=>'Target minutes','work_variance_minutes'=>'Variance minutes','source'=>'Source','notes'=>'Notes'];
    }
}
