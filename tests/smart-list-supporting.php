<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/bootstrap.php';
use App\Services\Lists\OrganizationListService;
use App\Services\Lists\LeaveListService;
use App\Services\Lists\HrWorkspaceListService;

set_exception_handler(static function(Throwable $e):void {fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
if (getenv('APP_ENV')!=='testing') throw new RuntimeException('Isolated testing database required.');
$db=db();$passed=0;
$check=static function(bool $ok,string $message)use(&$passed):void{if(!$ok)throw new RuntimeException('FAIL '.$message);++$passed;echo 'PASS '.$message.PHP_EOL;};
$company=(int)$db->query('SELECT company_id FROM companies WHERE deleted_at IS NULL ORDER BY company_id LIMIT 1')->fetchColumn();
$actor=(int)$db->query('SELECT user_id FROM company_users WHERE active=1 AND company_id='.$company.' LIMIT 1')->fetchColumn();
$_SESSION['auth']=['user_id'=>$actor,'company'=>['company_id'=>$company]];
$prefix='SUP'.strtoupper(bin2hex(random_bytes(3)));
$db->beginTransaction();
try {
    $catalogue=new OrganizationListService();$ids=[];
    foreach (['departments'=>['hr_departments','department_id'],'job-titles'=>['organization_job_titles','job_title_id'],
        'branches'=>['organization_branches','branch_id'],'leave-policies'=>['hr_leave_types','leave_type_id']] as $entity=>[$table,$id]) {
        $stmt=$db->prepare("INSERT INTO $table(company_id,code,name,active) VALUES(?,?,?,?)");
        for($i=1;$i<=305;++$i){$stmt->execute([$company,$prefix.$i,$prefix.sprintf('%03d',$i),$i===305?0:1]);$ids[$entity]=(int)$db->lastInsertId();}
        $first=$catalogue->listing($entity,['q'=>$prefix]);
        $check(count($first['list']['rows'])===25&&$first['list']['pagination']['total']===305,"$entity counts the complete dataset");
        $check(count($first['exportList']->export())===305,"$entity exports every matching row");
        foreach(['csv','xlsx'] as $format) {
            $file=(new App\Services\DataExchange\ExportService())->register($entity,$format,$catalogue->columns($entity),$first['exportList']->export());
            $check(strlen($file['contents'])>100,"$entity produces a $format download");
        }
        $found=$catalogue->listing($entity,['q'=>$prefix.'305']);
        $check(count($found['list']['rows'])===1,"$entity finds records beyond the former 250-row cap");
        $filtered=$catalogue->listing($entity,['q'=>$prefix,'active'=>'0','page'=>200]);
        $check($filtered['list']['pagination']['total']===1&&$filtered['list']['pagination']['page']===1,"$entity filters inactive rows before pagination");
        $_SESSION['auth']['company']['company_id']=$company+100000;
        $check($catalogue->listing($entity,['q'=>$prefix])['list']['pagination']['total']===0,"$entity is company isolated");
        $_SESSION['auth']['company']['company_id']=$company;
    }
    $stmt=$db->prepare('INSERT INTO organization_positions(company_id,code,name,department_id,job_title_id,status) VALUES(?,?,?,?,?,?)');
    for($i=1;$i<=505;++$i)$stmt->execute([$company,$prefix.$i,$prefix.sprintf('%03d',$i),$ids['departments'],$ids['job-titles'],$i===505?'frozen':'open']);
    $check($catalogue->listing('positions',['q'=>$prefix])['list']['pagination']['total']===505,'Positions count beyond the former 500-row cap');
    $check($catalogue->listing('positions',['q'=>$prefix.'505','status'=>'frozen'])['list']['pagination']['total']===1,'Position search and status precede pagination');
    $stmt=$db->prepare("INSERT INTO hr_employees(company_id,employee_number,first_name,last_name,department_id,job_title,employment_type,employment_status,hire_date,work_email) VALUES(?,?,?,'Test',?,'Analyst','full_time','active','2025-01-01',?)");
    $stmt->execute([$company,$prefix.'SELF',$prefix,$ids['departments'],$prefix.'self@example.test']);$self=(int)$db->lastInsertId();
    $stmt->execute([$company,$prefix.'OTHER',$prefix,$ids['departments'],$prefix.'other@example.test']);$other=(int)$db->lastInsertId();
    $position=(int)$db->query('SELECT position_id FROM organization_positions WHERE company_id='.$company.' ORDER BY position_id DESC LIMIT 1')->fetchColumn();
    $assign=$db->prepare("INSERT INTO hr_employee_position_assignments(company_id,employee_id,position_id,effective_from,effective_to,assignment_status,current_marker,position_code_snapshot,position_name_snapshot,department_name_snapshot,job_title_name_snapshot,notes) VALUES(?,?,?,'2025-01-01','2025-01-02','ended',NULL,?,?,?,'Analyst',?)");
    $report=$db->prepare("INSERT INTO hr_employees(company_id,employee_number,first_name,last_name,job_title,employment_type,employment_status,hire_date,manager_employee_id,work_email) VALUES(?,?,?,'Report','Analyst','full_time','active','2025-01-01',?,?)");
    for($i=1;$i<=105;++$i){$ref=$prefix.'PROFILE'.sprintf('%03d',$i);$assign->execute([$company,$self,$position,$ref,$ref,'Department',$ref]);$report->execute([$company,$ref,$ref,$self,$ref.'@example.test']);}
    $profileLists=new App\Services\Lists\DocumentListService();
    foreach(['employee-positions','employee-reports'] as $entity){
        $key=str_replace('-','_',$entity);$list=$profileLists->listing($entity,[$key=>['q'=>$prefix.'PROFILE']],$self);
        $check($list->page()['pagination']['total']===105&&count($list->page()['rows'])===25,"$entity counts all 105 records before paging");
        foreach([25,50,100] as $size)$check(count($profileLists->listing($entity,[$key=>['per_page'=>$size]],$self)->page()['rows'])===$size,"$entity page size $size");
        $check(count($profileLists->listing($entity,[$key=>['page'=>2,'per_page'=>100]],$self)->page()['rows'])===5,"$entity reaches page two");
        $check(count($profileLists->listing($entity,[$key=>['q'=>$prefix.'PROFILE105']],$self)->export())===1,"$entity searches beyond the first page");
        $check($profileLists->listing($entity,[$key=>['q'=>'missing-profile-fixture']],$self)->export()===[],"$entity keeps empty results");
        foreach(['csv','xlsx'] as $format){$file=(new App\Services\DataExchange\ExportService())->register($entity,$format,$profileLists->columns($entity),$list->export());$check(count($list->export())===105&&strlen($file['contents'])>1000,"$entity exports every matching row in $format");}
        $check($profileLists->listing($entity,[],$other)->export()===[],"$entity isolates the employee parent");
        $check(count($profileLists->controls($entity,$list)['filters']['status']['options'])>0,"$entity has real status options");
        $_SESSION['auth']['company']['company_id']=$company+100000;$check($profileLists->listing($entity,[],$self)->export()===[],"$entity company isolation");$_SESSION['auth']['company']['company_id']=$company;
    }
    $profile=(new App\Services\EmployeeDirectoryService())->profile($self,false);$overview=(new App\Services\EmployeePositionAssignmentService())->overview($self,false);
    $related=$profileLists->workspace(['employee-positions','employee-reports'],['id'=>$self,'employee_reports'=>['q'=>$prefix.'PROFILE105']],$self,'/hr/employees/view',true);
    ob_start();view('hr.show',['employee'=>$profile['employee'],'currentPosition'=>$overview['current'],'positionHistory'=>$related['lists']['employee-positions']['rows'],'directReports'=>$related['lists']['employee-reports']['rows'],'related'=>$related]);$html=ob_get_clean();
    $check(str_contains($html,'register=employee-positions')&&str_contains($html,'register=employee-reports')&&str_contains($html,'id='.$self),'Employee profile preserves parent context and separate history/report exports');
    $positionOptions=(new App\Repositories\MySql\EmployeePositionAssignmentRepository())->positionOptions($company);
    $check(count(array_filter($positionOptions,static fn(array $row):bool=>str_starts_with($row['code'],$prefix)))===504,'Position assignment form includes all authorized open positions beyond 500');
    $stmt=$db->prepare("INSERT INTO hr_leave_requests(company_id,employee_id,leave_type_id,start_date,end_date,requested_days,request_status) VALUES(?,?,?,'2026-01-01','2026-01-02',2,?)");
    for($i=1;$i<=105;++$i)$stmt->execute([$company,$self,$ids['leave-policies'],$i===105?'cancelled':'pending']);
    $stmt->execute([$company,$other,$ids['leave-policies'],'cancelled']);
    $leave=new LeaveListService();
    $page=$leave->requests(['q'=>$prefix],$actor,$self,false,false)->page();
    $check($page['pagination']['total']===105&&count($page['rows'])===25,'Self leave scope and count precede pagination');
    $check($leave->requests(['q'=>$prefix.'OTHER'],$actor,$self,false,false)->page()['pagination']['total']===0,'Self search cannot discover another employee');
    $check($leave->requests(['q'=>$prefix,'status'=>'cancelled'],$actor,$self,false,false)->page()['pagination']['total']===1,'Leave status filters reach beyond page one');
    $check(count($leave->requests(['q'=>$prefix],$actor,$self,false,false)->export())===105,'Leave export query is identical to authorized register query');
    $check($leave->requests(['q'=>$prefix],$actor,$self,true,false)->page()['pagination']['total']===106,'Company leave authority sees legitimate full scope');
    $workspaces=new HrWorkspaceListService();
    $check(is_array($workspaces->team([], $actor, '2026-01-01','2026-01-31')->page()),'Direct-team register executes with authority in SQL');
    $check(is_array($workspaces->assignments([])->page()),'Employee schedule query executes');
    $check(is_array($workspaces->adjustments([], $self, 2026)->page()),'Balance adjustment query executes');
    $balanceList=$workspaces->balances(['balances'=>['q'=>$prefix]],$self,2026);
    $check($balanceList->page()['pagination']['total']===304&&count($balanceList->export())===304,'Leave balances page and export all matching active policies');
    $check($workspaces->balances(['balances'=>['q'=>$prefix]],$self+1000000,2026)->page()['pagination']['total']===0,'Balances require an employee in this company');
    $balanceWorkspace=(new App\Services\LeaveBalanceManagementService())->workspace($self,2026,0,['balances'=>['q'=>$prefix,'per_page'=>'50']]);
    $check(count($balanceWorkspace['balances'])===50&&(int)$balanceWorkspace['summary']['policies']===304,'Leave allocation summary counts all matching policies');
    $url=$balanceWorkspace['list']['query']->url('/balances',['page'=>2]);
    parse_str(parse_url($url,PHP_URL_QUERY),$urlQuery);
    $check($urlQuery['balances']['q']===$prefix&&$urlQuery['employee']==$self,'Adjustment navigation preserves balance search and employee');
    ob_start();view('hr.leave.balances.index',['workspace'=>$balanceWorkspace]);$html=ob_get_clean();
    $check(str_contains($html,'register=balances')&&str_contains($html,'register=adjustments'),'Balance view exports explicitly target independent registers');
    $insert=$db->prepare("INSERT INTO workforce_calendars(company_id,code,name,timezone) VALUES(?,?,?,'Africa/Nairobi')");
    $insert->execute([$company,$prefix,$prefix]);$calendar=(int)$db->lastInsertId();
    $insert->execute([$company,$prefix.'SECOND',$prefix.'SECOND']);$otherCalendar=(int)$db->lastInsertId();
    $schedule=$db->prepare('INSERT INTO employee_work_schedules(company_id,employee_id,calendar_id,effective_from,effective_to) VALUES(?,?,?,?,?)');
    for($i=1;$i<=105;++$i) {
        $day=(new DateTimeImmutable('2026-01-01'))->modify('+'.$i.' days')->format('Y-m-d');
        $schedule->execute([$company,$self,$calendar,$day,$day]);
    }
    $schedule->execute([$company,$self,$otherCalendar,'2026-01-01','2026-12-31']);
    $schedule->execute([$company,$self,$calendar,'2025-01-01','2025-12-31']);
    $assignments=$workspaces->assignments(['calendar'=>$calendar,'year'=>2026,'per_page'=>100]);
    $check($assignments->page()['pagination']['total']===105&&count($assignments->page()['rows'])===100,'Schedule calendar and year filter before pagination');
    $check(count($assignments->export())===105,'Schedule export obeys selected calendar and year');
    $check($workspaces->assignments(['calendar'=>$calendar,'year'=>2025])->page()['pagination']['total']===1,'Schedule year excludes nonoverlapping assignments');

    $holiday=$db->prepare("INSERT INTO workforce_holidays(company_id,calendar_id,holiday_date,name,holiday_type,day_portion) VALUES(?,?,?,?,'company','full')");
    for($i=1;$i<=105;++$i){
        $insert->execute([$company,$prefix.'C'.$i,$prefix.'C'.sprintf('%03d',$i)]);
        $day=(new DateTimeImmutable('2026-01-01'))->modify('+'.$i.' days')->format('Y-m-d');
        $holiday->execute([$company,$calendar,$day,$prefix.'H'.sprintf('%03d',$i)]);
    }
    $factory=new App\Services\Lists\CalendarListService();
    foreach(['calendars'=>'C','holidays'=>'H'] as $entity=>$marker){
        $scope=[$entity=>['q'=>$prefix.$marker]];$list=$factory->listing($entity,$scope,$calendar,2026);
        $check($list->page()['pagination']['total']===105&&count($list->page()['rows'])===25,"$entity counts 105 scoped rows before paging");
        foreach([25,50,100] as $size)$check(count($factory->listing($entity,[$entity=>['q'=>$prefix.$marker,'per_page'=>$size]],$calendar,2026)->page()['rows'])===$size,"$entity supports page size $size");
        $check($factory->listing($entity,[$entity=>['q'=>$prefix.$marker.'105']],$calendar,2026)->page()['pagination']['total']===1,"$entity searches beyond page one");
        foreach(['xlsx','csv'] as $format){$file=(new App\Services\DataExchange\ExportService())->register($entity,$format,$factory->columns($entity),$list->export());$check(count($list->export())===105&&strlen($file['contents'])>1000,"$entity exports all 105 records as $format");}
        $check($factory->listing($entity,[$entity=>['q'=>'not-a-fixture-record']],$calendar,2026)->export()===[],"$entity empty search does not fall back");
    }
    $check($factory->listing('holidays',[],$otherCalendar,2026)->page()['pagination']['total']===0,'Holiday scope requires selected calendar');
    $controls=$factory->controls('holidays',$factory->listing('holidays',[],$calendar,2026));
    $check(isset($controls['filters']['type']['options']['company']),'Holiday type dropdown uses authoritative domain');
    $calendarWorkspace=(new App\Services\WorkforceCalendarService())->workspace($calendar,2026,['calendars'=>['q'=>$prefix.'C'],'holidays'=>['q'=>$prefix.'H'],'page'=>2]);
    ob_start();view('attendance.calendars.index',['workspace'=>$calendarWorkspace]);$html=ob_get_clean();
    $check(str_contains($html,'register=calendars')&&str_contains($html,'register=holidays')&&str_contains($html,'register=assignments'),'Calendar view has three independent export targets');
    $db->prepare('UPDATE hr_employees SET user_id=? WHERE employee_id=?')->execute([$actor,$self]);
    $record=$db->prepare("INSERT INTO attendance_records(company_id,employee_id,attendance_date,attendance_status,source,notes) VALUES(?,?,?,'present','manual',?)");
    for($i=1;$i<=31;++$i)$record->execute([$company,$self,sprintf('2026-01-%02d',$i),$prefix.'DAY'.$i]);
    $record->execute([$company,$other,'2026-01-31',$prefix.'OTHER']);
    $personal=new App\Services\Lists\PersonalAttendanceListService();$list=$personal->listing(['history'=>['sort'=>'date','direction'=>'asc']],$actor,'2026-01-01','2026-01-31');
    $check($list->page()['pagination']['total']===31&&count($list->page()['rows'])===25&&count($list->export())===31,'Personal history pages and exports the complete authorized month');
    $check($personal->listing(['history'=>['q'=>$prefix.'DAY31']],$actor,'2026-01-01','2026-01-31')->page()['pagination']['total']===1,'Personal history searches beyond page one');
    $check($personal->listing(['history'=>['q'=>$prefix.'OTHER']],$actor,'2026-01-01','2026-01-31')->export()===[],'Personal history cannot export another employee');
    $check($personal->listing(['history'=>['status'=>'absent']],$actor,'2026-01-01','2026-01-31')->export()===[],'Personal status filter preserves empty results');
    $workspace=(new App\Services\LeaveManagementService())->workspace($actor,'',true,true,true,false,false,['q'=>$prefix]);
    $check(count($workspace['requests'])===25&&(int)$workspace['summary']['pending']===104,'Leave presentation and full result summary stay consistent');
    // Render real templates to catch missing view variables and data contracts.
    foreach (['branches'=>'branches','departments'=>'departments','job-titles'=>'jobTitles','positions'=>'positions','leave-policies'=>'policies'] as $entity=>$key) {
        $result=$catalogue->listing($entity,['q'=>$prefix]);
        $data=[$key=>$result[$key],'summary'=>$result['summary'],'list'=>$result['list'],'listSorts'=>$result['sorts'],'canManage'=>true];
        ob_start();view($entity==='leave-policies'?'hr.leave.policies.index':'organization.'.$entity.'.index',$data);$html=ob_get_clean();
        $check(str_contains($html,'name="q"')&&str_contains($html,'aria-current="page"'),"$entity renders shared search and numbered pagination");
    }
} finally {$db->rollBack();}
echo "$passed supporting-list checks passed".PHP_EOL;
