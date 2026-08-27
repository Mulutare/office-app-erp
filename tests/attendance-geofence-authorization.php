<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';

use App\Services\AttendanceSelfServiceService;
use App\Services\BranchManagementService;

$passed=0;$failed=0;
$check=static function(bool $ok,string $label)use(&$passed,&$failed):void{echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;$ok?$passed++:$failed++;};
$pdo=db();$originalAuth=$_SESSION['auth']??[];$pdo->beginTransaction();
try {
    $context=$pdo->query(
        "SELECT e.company_id,e.user_id,e.employee_id,a.assignment_id,p.position_id,p.branch_id,b.name branch_name
         FROM hr_employees e
         INNER JOIN hr_employee_position_assignments a ON a.company_id=e.company_id AND a.employee_id=e.employee_id AND a.current_marker=1
         INNER JOIN organization_positions p ON p.company_id=a.company_id AND p.position_id=a.position_id
         INNER JOIN organization_branches b ON b.company_id=p.company_id AND b.branch_id=p.branch_id
         WHERE e.employment_status='active' AND e.user_id IS NOT NULL AND b.active=1
         ORDER BY e.employee_id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if(!is_array($context))throw new RuntimeException('No active employee with a current branch position is available.');
    $company=(int)$context['company_id'];$user=(int)$context['user_id'];$employee=(int)$context['employee_id'];$branch=(int)$context['branch_id'];
    $_SESSION['auth']['company']=['company_id'=>$company];
    $_SESSION['auth']['company_id']=$company;
    $branchRow=$pdo->query('SELECT * FROM organization_branches WHERE branch_id='.$branch)->fetch(PDO::FETCH_ASSOC);
    $input=array_merge($branchRow,[
        'attendance_geofence_enabled'=>true,
        'attendance_latitude'=>'-1.2921000','attendance_longitude'=>'36.8219000','attendance_radius_meters'=>'100',
    ]);
    $saved=(new BranchManagementService())->update($branch,$input,$user);
    $reloaded=(new BranchManagementService())->form($branch);
    $check(!empty($saved['successful'])&&!empty($reloaded['attendance_geofence_enabled'])
        && abs((float)$reloaded['attendance_latitude']+1.2921)<0.0000001
        && (int)$reloaded['attendance_radius_meters']===100,
        'HR branch service validates, saves, and reloads the attendance geofence');

    $service=new AttendanceSelfServiceService();
    $at=static fn(string $time)=>new DateTimeImmutable('2026-08-19 '.$time,new DateTimeZone('Africa/Nairobi'));
    $missing=$service->scan($user,'geo-missing-20260819-000001','geo-test',$at('08:10:00'),[]);
    $invalid=$service->scan($user,'geo-invalid-20260819-000001','geo-test',$at('08:11:00'),['latitude'=>'NaN','longitude'=>'36.8','accuracy'=>'5']);
    $badAccuracy=$service->scan($user,'geo-accuracy-20260819-00001','geo-test',$at('08:12:00'),['latitude'=>'-1.2921','longitude'=>'36.8219','accuracy'=>'-1']);
    $outside=$service->scan($user,'geo-outside-20260819-000001','geo-test',$at('08:13:00'),['latitude'=>'-1.2821','longitude'=>'36.8219','accuracy'=>'4']);
    $boundaryLatitude=-1.2921+rad2deg(100/6371008.8);
    $insideIn=$service->scan($user,'geo-inside-in-20260819-00001','geo-test',$at('08:20:00'),['latitude'=>(string)$boundaryLatitude,'longitude'=>'36.8219','accuracy'=>'3']);
    $insideOut=$service->scan($user,'geo-inside-out-20260819-0001','geo-test',$at('17:00:00'),['latitude'=>'-1.2920','longitude'=>'36.8219','accuracy'=>'3']);
    $duplicate=$service->scan($user,'geo-inside-out-20260819-0001','geo-test',$at('17:01:00'),['latitude'=>'0','longitude'=>'0','accuracy'=>'3']);
    $check(!$missing['successful']&&str_contains((string)$missing['errors']['form'],'requires a valid device location'),'Enabled geofence rejects a punch without location');
    $check(!$invalid['successful']&&!$badAccuracy['successful'],'Malformed coordinates and negative accuracy are rejected server-side');
    $check(!$outside['successful']&&str_contains((string)$outside['errors']['form'],'outside'),'Outside-radius punch is rejected');
    $check(!empty($insideIn['successful'])&&($insideIn['eventType']??'')==='clock_in','Exact-radius boundary Sign In succeeds');
    $check(!empty($insideOut['successful'])&&($insideOut['eventType']??'')==='clock_out','Inside-radius Sign Out succeeds');
    $check(!empty($duplicate['successful'])&&!empty($duplicate['duplicate']),'Geofence enforcement preserves request-key idempotency');

    $events=$pdo->prepare("SELECT * FROM attendance_scan_events WHERE company_id=? AND employee_id=? AND request_key LIKE 'geo-%'");
    $events->execute([$company,$employee]);$rows=$events->fetchAll(PDO::FETCH_ASSOC);
    $accepted=array_values(array_filter($rows,fn($r)=>$r['processing_result']==='accepted'));
    $rejected=array_values(array_filter($rows,fn($r)=>$r['processing_result']==='rejected'));
    $outsideRow=array_values(array_filter($rows,fn($r)=>$r['request_key']==='geo-outside-20260819-000001'))[0]??[];
    $check(count($accepted)===2&&count($rejected)===4,'Accepted and rejected punch attempts are both retained');
    $check((int)($accepted[0]['geofence_enforced']??0)===1&&(int)($accepted[0]['geofence_branch_id']??0)===$branch
        &&($accepted[0]['geofence_branch_name_snapshot']??'')===$context['branch_name']
        &&abs((float)($accepted[0]['geofence_latitude_snapshot']??0)+1.2921)<0.0000001
        &&(float)($accepted[0]['location_accuracy_meters']??-1)===3.0,
        'Accepted punch stores branch configuration and device-location snapshots');
    $check((float)($outsideRow['geofence_distance_meters']??0)>100&&str_contains((string)($outsideRow['result_reason']??''),'outside_geofence'),
        'Rejected outside punch stores calculated server distance and reason');

    $pdo->prepare('UPDATE organization_branches SET attendance_geofence_enabled=0 WHERE company_id=? AND branch_id=?')->execute([$company,$branch]);
    $disabled=$service->scan($user,'geo-disabled-20260820-000001','geo-test',new DateTimeImmutable('2026-08-20 08:20:00',new DateTimeZone('Africa/Nairobi')),[]);
    $check(!empty($disabled['successful']),'Disabled geofence preserves attendance without requiring GPS');
    $pdo->prepare('UPDATE organization_positions SET branch_id=NULL WHERE company_id=? AND position_id=?')->execute([$company,$context['position_id']]);
    $unassigned=$service->scan($user,'geo-unassigned-20260821-0001','geo-test',new DateTimeImmutable('2026-08-21 08:20:00',new DateTimeZone('Africa/Nairobi')),[]);
    $check(!$unassigned['successful']&&str_contains((string)$unassigned['errors']['form'],'not assigned'),'Employee without a current workplace branch fails safely without arbitrary fallback');

    $forbidden="'hr.records.view','hr.records.manage','organization.branches.view','organization.branches.manage','organization.job_titles.view','organization.job_titles.manage','organization.departments.view','organization.departments.manage','organization.positions.view','organization.positions.manage','attendance.records.view','attendance.records.manage','attendance.team.view','hr.leave.view','hr.leave.manage','hr.leave.approve','hr.leave.team.approve','hr.leave.policy.manage','hr.leave.balance.manage'";
    $leaks=(int)$pdo->query("SELECT COUNT(*) FROM role_permissions rp INNER JOIN roles r ON r.role_id=rp.role_id INNER JOIN permissions p ON p.permission_id=rp.permission_id WHERE r.code NOT IN('system_administrator','company_owner','hr_administrator') AND p.code IN($forbidden)")->fetchColumn();
    $adminCoverage=(int)$pdo->query("SELECT COUNT(DISTINCT r.code) FROM role_permissions rp INNER JOIN roles r ON r.role_id=rp.role_id INNER JOIN permissions p ON p.permission_id=rp.permission_id WHERE r.code IN('system_administrator','company_owner','hr_administrator') AND p.code IN('hr.records.manage','organization.branches.manage','attendance.records.manage','hr.leave.approve')")->fetchColumn();
    $selfCoverage=(int)$pdo->query("SELECT COUNT(*) FROM role_permissions rp INNER JOIN roles r ON r.role_id=rp.role_id INNER JOIN permissions p ON p.permission_id=rp.permission_id WHERE r.code='employee_self_service' AND p.code IN('attendance.self.view','attendance.self.record','hr.leave.self.view','hr.leave.self.request')")->fetchColumn();
    $check($leaks===0,'Business roles have no implicit HR, organization, attendance-admin, or leave-admin grants');
    $check($adminCoverage===3,'System Administrator, Company Owner, and HR Administrator retain administration authority');
    $check($selfCoverage===4,'Normal employee retains only intended attendance and leave self-service grants');
    $branchController=file_get_contents(__DIR__.'/../app/controllers/BranchController.php');
    $attendanceController=file_get_contents(__DIR__.'/../app/controllers/AttendanceController.php');
    $organizationController=file_get_contents(__DIR__.'/../app/controllers/OrganizationSetupController.php');
    $check(str_contains($branchController,"'organization.branches.manage'")
        && str_contains($branchController,'private function requireManagement')
        && str_contains($attendanceController,"'attendance.records.manage'")
        && str_contains($attendanceController,'private function requireManage'),
        'Branch and company attendance mutation endpoints retain explicit administrative permission gates');
    $check(!str_contains($organizationController,"'attendance.self.view'")
        && !str_contains($organizationController,"'hr.leave.self.view'"),
        'Self-service permissions cannot open Organization Setup by direct URL');
    $branchView=file_get_contents(__DIR__.'/../resources/views/organization/branches/form.php');
    $employeeView=file_get_contents(__DIR__.'/../resources/views/attendance/self/index.php');
    $check(str_contains($branchView,'branch-geofence-map')
        && str_contains($branchView,'vendor/leaflet/leaflet.css')
        && str_contains($branchView,'vendor/leaflet/leaflet.js')
        && !str_contains($employeeView,'branch-geofence-map'),
        'Workplace map selector is confined to authorized HR branch configuration');
}catch(Throwable $e){$failed++;echo 'FAIL unexpected: '.$e->getMessage().PHP_EOL;}finally{if($pdo->inTransaction())$pdo->rollBack();$_SESSION['auth']=$originalAuth;}
echo PHP_EOL.($passed+$failed).' authorization/geofence checks, '.$failed.' failures'.PHP_EOL;exit($failed===0?0:1);
