<?php
declare(strict_types=1);
namespace App\Services\Lists;
use App\Services\TenantContext;

final class CalendarListService
{
    public function listing(string $entity,array $input,int $calendar=0,int $year=0): SqlList
    {
        $company=(new TenantContext())->companyId();$parameters=['company'=>$company];
        if($entity==='calendars') {
            $sql='SELECT c.*,(SELECT COUNT(*) FROM employee_work_schedules s WHERE s.company_id=c.company_id AND s.calendar_id=c.calendar_id AND s.active=1) assignment_count,
                (SELECT COUNT(*) FROM workforce_holidays h WHERE h.company_id=c.company_id AND h.calendar_id=c.calendar_id AND h.holiday_date>=CURRENT_DATE) holiday_count FROM workforce_calendars c WHERE c.company_id=:company';
            $id='calendar_id';$search=['code','name','country_code','subdivision_code','timezone'];$sorts=['name'=>'name','code'=>'code','timezone'=>'timezone','default'=>'is_default'];
            $filters=['active'=>'active','country'=>'country_code','timezone'=>'timezone'];$default='name';
        } elseif($entity==='holidays') {
            $sql='SELECT h.*,c.name calendar_name FROM workforce_holidays h JOIN workforce_calendars c ON c.company_id=h.company_id AND c.calendar_id=h.calendar_id WHERE h.company_id=:company AND h.calendar_id=:calendar AND h.holiday_date BETWEEN :start AND :end';
            $parameters+=['calendar'=>$calendar,'start'=>sprintf('%04d-01-01',$year),'end'=>sprintf('%04d-12-31',$year)];
            $id='holiday_id';$search=['name','description','calendar_name'];$sorts=['date'=>'holiday_date','name'=>'name','type'=>'holiday_type'];
            $filters=['type'=>'holiday_type','portion'=>'day_portion','observed'=>'observed','from'=>['holiday_date','>='],'to'=>['holiday_date','<=']];$default='date';
        } else throw new \InvalidArgumentException('Unknown calendar register.');
        return new SqlList(\db(),$sql,$parameters,new ListQuery($input,$sorts,$default,array_keys($filters),'asc',$entity),$search,$sorts,$id,$filters);
    }
    public function controls(string $entity,SqlList $list): array
    {
        return $entity==='calendars'
            ?FilterOptions::controls($list,['active'=>'active','country'=>'country_code','timezone'=>'timezone'],['name'=>'Name','code'=>'Code','timezone'=>'Timezone','default'=>'Company default'],['active'=>['1'=>'Active','0'=>'Inactive']])
            :FilterOptions::controls($list,['type'=>'holiday_type','portion'=>'day_portion','observed'=>'observed','from'=>['holiday_date','>='],'to'=>['holiday_date','<=']],['date'=>'Date','name'=>'Name','type'=>'Type'],['type'=>['workforce_holidays','holiday_type'],'portion'=>['workforce_holidays','day_portion'],'observed'=>['1'=>'Observed','0'=>'Not observed']]);
    }
    public function columns(string $entity): array
    {
        return $entity==='calendars'
            ?['code'=>'Code','name'=>'Calendar','country_code'=>'Country','subdivision_code'=>'Subdivision','timezone'=>'Timezone','is_default'=>'Company default','active'=>'Active','assignment_count'=>'Assignments','holiday_count'=>'Upcoming holidays']
            :['calendar_name'=>'Calendar','holiday_date'=>'Date','name'=>'Holiday','holiday_type'=>'Type','day_portion'=>'Duration','observed'=>'Observed','description'=>'Description'];
    }
}
