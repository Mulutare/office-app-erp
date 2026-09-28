<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/helpers/bootstrap.php';

use App\Services\AttendanceManagementService;
use App\Services\DataExchange\ExportDataProvider;
use App\Services\DataExchange\ExportService;
use App\Services\Lists\HrListService;

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
    exit(1);
});
if (getenv('APP_ENV') !== 'testing') throw new RuntimeException('Isolated testing database required.');
$db = db(); $passed = 0;
$check = static function (bool $ok, string $message) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $message);
    ++$passed; echo 'PASS ' . $message . PHP_EOL;
};
$range = HrListService::attendancePeriod(['period'=>'weekly','date'=>'2025-01-01']);
$check($range['start']==='2024-12-30' && $range['end']==='2025-01-05', 'Weeks cross years from Monday through Sunday');
$range = HrListService::attendancePeriod(['period'=>'weekly','date'=>'2025-01-05']);
$check($range['start']==='2024-12-30', 'Sunday belongs to the preceding Monday week');
$range = HrListService::attendancePeriod(['period'=>'monthly','date'=>'2024-02-14']);
$check($range['start']==='2024-02-01' && $range['end']==='2024-02-29', 'Monthly bounds include leap day');
$range = HrListService::attendancePeriod(['period'=>'monthly','date'=>'2025-02-14']);
$check($range['end']==='2025-02-28', 'Non-leap February ends on the 28th');
$range = HrListService::attendancePeriod(['period'=>['weekly'],'date'=>'2026-02-31']);
$check($range['period']==='daily' && $range['date']===date('Y-m-d'), 'Invalid date and period fall back safely');

$context = $db->query('SELECT cu.company_id,cu.user_id,d.department_id FROM company_users cu JOIN hr_departments d ON d.company_id=cu.company_id AND d.active=1 AND d.deleted_at IS NULL WHERE cu.active=1 ORDER BY cu.company_id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$context) throw new RuntimeException('Seed test company, department and membership first.');
$company = (int)$context['company_id']; $actor = (int)$context['user_id'];
$_SESSION['auth'] = ['user_id'=>$actor,'company'=>['company_id'=>$company]];
$prefix = 'PERIOD' . strtoupper(bin2hex(random_bytes(3)));
$file = tempnam(sys_get_temp_dir(), 'attendance-period-');
$db->beginTransaction();
try {
    $employee = $db->prepare("INSERT INTO hr_employees(company_id,employee_number,first_name,last_name,work_email,department_id,job_title,employment_type,employment_status,hire_date) VALUES(?,?, 'Same','Name',?,?,'Analyst','full_time','active','2024-01-01')");
    $attendance = $db->prepare("INSERT INTO attendance_records(company_id,employee_id,attendance_date,attendance_status,source) VALUES(?,?,?,?,'manual')");
    for ($person=1; $person<=7; ++$person) {
        $number = $prefix . sprintf('%03d',$person);
        $employee->execute([$company,$number,strtolower($number).'@example.test',$context['department_id']]);
        $id = (int)$db->lastInsertId();
        if ($person===7) continue; // Daily roster must retain the unrecorded employee.
        for ($day=new DateTimeImmutable('2024-01-31'); $day<=new DateTimeImmutable('2024-03-01'); $day=$day->modify('+1 day')) {
            $attendance->execute([$company,$id,$day->format('Y-m-d'),$day->format('Y-m-d')==='2024-02-29'?'late':'present']);
        }
    }
    $lists = new HrListService(); $provider = new ExportDataProvider();
    $filters = ['q'=>$prefix,'period'=>'monthly','date'=>'2024-02-14'];
    $month = $lists->attendance($filters)->page();
    $check($month['pagination']['total']===174 && count($month['rows'])===25, 'Month counts all employee-days before pagination');
    $second = $lists->attendance($filters+['page'=>2])->page();
    $check(array_intersect(array_column($month['rows'],'attendance_id'),array_column($second['rows'],'attendance_id'))===[], 'Same-name employee-days have stable nonoverlapping pages');
    $weekFilters = array_replace($filters,['period'=>'weekly','date'=>'2024-02-28']);
    $week = $lists->attendance($weekFilters)->page();
    $check($week['pagination']['total']===30 && count($week['rows'])===25, 'Week includes month-crossing records and pages in SQL');
    $daily = $lists->attendance(array_replace($filters,['period'=>'daily']))->page();
    $check($daily['pagination']['total']===7 && count(array_filter($daily['rows'],static fn(array $r):bool=>$r['attendance_status']==='not_recorded'))===1, 'Daily retains unrecorded roster entries');
    $check($lists->attendance(array_replace($filters,['q'=>$prefix.'006']))->page()['pagination']['total']===29, 'Search finds the final employee across every day of the month');
    $check($lists->attendance($filters+['status'=>'late','page'=>99])->page()['pagination']['total']===6, 'Status is filtered across the month before count and pagination');
    $check($lists->attendance($filters+['department'=>'999999999'])->page()['pagination']['total']===0, 'Department scope remains effective for longer periods');
    $check($lists->attendance($filters+['branch'=>'999999999'])->page()['pagination']['total']===0, 'Branch scope remains effective for longer periods');
    parse_str((string)parse_url($month['query']->url('/attendance',['page'=>2]),PHP_URL_QUERY),$params);
    $check($params['period']==='monthly' && $params['date']==='2024-02-14' && $params['q']===$prefix, 'Pagination preserves period, anchor date and search');
    $export = $provider->rows('attendance',$filters+['page'=>2]);
    $dates = array_column($export,'attendance_date');
    $check(count($export)===174 && min($dates)==='2024-02-01' && max($dates)==='2024-02-29', 'Export includes the entire filtered month and excludes adjacent dates');
    $check(count($provider->rows('attendance',$weekFilters))===30, 'Weekly export uses exactly the weekly list range');
    $check(count($provider->rows('attendance',$filters+['status'=>'late']))===6, 'Period export honors status filtering');
    $dashboard = (new AttendanceManagementService())->dashboard('2024-02-14',$filters);
    $check($dashboard['summary']['total']===174 && $dashboard['summary']['present']===168 && $dashboard['summary']['late']===6, 'Summary reflects all matching employee-days, not just the page');
    $filteredDashboard = (new AttendanceManagementService())->dashboard('2024-02-14',$filters+['status'=>'late']);
    $check($filteredDashboard['summary']['total']===6 && $filteredDashboard['summary']['present']===0, 'Summary shares the current filters');
    ob_start(); view('attendance.index',$dashboard+['canManage'=>false,'listOptions'=>$lists->options()]); $html=(string)ob_get_clean();
    $check(str_contains($html,'value="weekly"') && str_contains($html,'value="monthly"') && str_contains($html,'<th>Date</th>') && str_contains($html,'period=monthly') && str_contains($html,'Thu, 29 Feb 2024'), 'Attendance view exposes period controls, date column and period-aware exports');
    $exporter = new ExportService();
    $csv = $exporter->export('attendance','csv',$export);
    file_put_contents($file,$csv['contents']); $handle=fopen($file,'r'); $csvRows=[];
    while (($row=fgetcsv($handle,0,',','"',''))!==false) $csvRows[]=$row;
    fclose($handle);
    $check(count($csvRows)===175 && !str_contains($csv['contents'],'attendance_id'), 'CSV contains every monthly row with business fields only');
    $xlsx = $exporter->export('attendance','xlsx',$export); file_put_contents($file,$xlsx['contents']);
    $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($file);
    $check($book->getSheet(0)->getHighestDataRow()===175, 'Excel contains every monthly row'); $book->disconnectWorksheets();
    $_GET = $filters;
    $method = new ReflectionMethod(App\Controllers\DataExchangeController::class,'exportFilters');
    $carried = $method->invoke(new App\Controllers\DataExchangeController(),'attendance');
    $check($carried['period']==='monthly' && $carried['date']==='2024-02-14', 'Export configuration carries the selected period to download');
    $_SESSION['auth']['company']['company_id']=$company+100000;
    $check($lists->attendance($filters)->page()['pagination']['total']===0 && $provider->rows('attendance',$filters)===[], 'Monthly search and export cannot cross company scope');
} finally {
    $db->rollBack();
    if (is_file($file)) unlink($file);
}
echo "$passed attendance period checks passed" . PHP_EOL;
