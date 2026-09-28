<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage() . '\n' . $error->getTraceAsString() . PHP_EOL);
    exit(1);
});

use App\Services\Lists\ListQuery;
use App\Services\Lists\HrListService;
use App\Services\DataExchange\ImportService;
use App\Services\DataExchange\ImportConfirmation;
use App\Services\DataExchange\ExportDataProvider;
use App\Services\DataExchange\ExportService;
use App\Services\DataExchange\SchemaRegistry;

if (getenv('APP_ENV') !== 'testing') throw new RuntimeException('Run against an isolated testing database.');
$db = db();
$passed = 0;
$check = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) throw new RuntimeException('FAIL ' . $message);
    ++$passed;
    echo 'PASS ' . $message . PHP_EOL;
};
$reject = static function (callable $call): bool { try { $call(); return false; } catch (RuntimeException $e) { return true; } };
$context = $db->query('SELECT cu.company_id,cu.user_id,d.department_id,d.code department_code FROM company_users cu JOIN hr_departments d ON d.company_id=cu.company_id AND d.active=TRUE AND d.deleted_at IS NULL WHERE cu.active=TRUE ORDER BY cu.company_id,cu.user_id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$context) throw new RuntimeException('Seed company memberships and departments first.');
$company = (int)$context['company_id'];
$actor = (int)$context['user_id'];
$_SESSION['auth'] = ['user_id'=>$actor,'company'=>['company_id'=>$company]];
$prefix = 'GRID' . strtoupper(bin2hex(random_bytes(4)));
$ids = [];
$upload = tempnam(sys_get_temp_dir(), 'grid-confirm-');
$trigger = 'grid_atomic_' . strtolower($prefix);
try {
    $insert = $db->prepare("INSERT INTO hr_employees(company_id,employee_number,first_name,last_name,work_email,department_id,job_title,employment_type,employment_status,hire_date,created_by)
        VALUES(?,?, 'Same','Name',?,?,'Analyst','full_time',?,'2025-01-01',?)");
    for ($i=1;$i<=105;++$i) {
        $number=$prefix.sprintf('%03d',$i);
        $insert->execute([$company,$number,strtolower($number).'@example.test',$context['department_id'],$i===105?'on_leave':'active',$actor]);
        $ids[]=(int)$db->lastInsertId();
    }
    $lists = new HrListService();
    $first=$lists->employees(['q'=>$prefix])->page();
    $check(count($first['rows'])===25 && $first['pagination']['total']===105,'Count covers the full scoped dataset before paging');
    $found=$lists->employees(['q'=>$prefix.'105'])->page();
    $check(count($found['rows'])===1 && $found['rows'][0]['employee_number']===$prefix.'105','Search finds a record beyond the first page');
    $filtered=$lists->employees(['q'=>$prefix,'status'=>'on_leave','page'=>40])->page();
    $check($filtered['pagination']['total']===1 && $filtered['pagination']['page']===1,'Filters precede count and page clamping');
    $q=new ListQuery(['q'=>$prefix,'department'=>(string)$context['department_id'],'page'=>2,'per_page'=>50,'sort'=>'number','direction'=>'desc'],HrListService::EMPLOYEE_SORTS,'name',['department']);
    parse_str((string)parse_url($q->url('/hr',['page'=>3]),PHP_URL_QUERY),$params);
    $check($params['q']===$prefix && $params['page']==='3' && $params['per_page']==='50' && $params['department']===(string)$context['department_id'] && $params['direction']==='desc','Page links preserve search, filters, sort and page size');
    $second=$lists->employees(['q'=>$prefix,'page'=>2])->page();
    $check(array_intersect(array_column($first['rows'],'employee_id'),array_column($second['rows'],'employee_id'))===[] && (int)$second['rows'][0]['employee_id']===$ids[25],'Equal sort values have a deterministic primary-key tiebreaker');
    $unsafe=$lists->employees(['q'=>$prefix,'sort'=>'employee_id DESC; DROP TABLE users','direction'=>'evil','per_page'=>999999])->page();
    $check($unsafe['query']->sort==='name' && $unsafe['query']->direction==='asc' && count($unsafe['rows'])===25,'Invalid sort/direction/page sizes fall back safely');
    $check($lists->employees(['q'=>"' OR 1=1 --"])->page()['pagination']['total']===0,'Search SQL injection stays a bound literal');
    $check($lists->employees(['q'=>$prefix.'%'])->page()['pagination']['total']===0,'Search percent and underscore are literal characters');
    $check($lists->employees(['q'=>$prefix,'branch'=>'999999999'])->page()['pagination']['total']===0,'Unknown branch narrows the dataset instead of broadening it');
    $foreign=(int)$db->query('SELECT company_id FROM companies WHERE company_id<>'.$company.' ORDER BY company_id LIMIT 1')->fetchColumn();
    $_SESSION['auth']['company']['company_id']=$foreign;
    $check($lists->employees(['q'=>$prefix])->page()['pagination']['total']===0,'Company isolation is applied before search');
    $_SESSION['auth']['company']['company_id']=$company;
    $export=(new ExportDataProvider())->rows('employees',['q'=>$prefix,'status'=>'on_leave','sort'=>'number','direction'=>'desc']);
    $check(count($export)===1 && $export[0]['employee_number']===$prefix.'105','Export uses exactly the same search/filter dataset');
    $check(count((new ExportDataProvider())->rows('employees',['q'=>$prefix]))===105,'Export traverses the full authorized result, not one page');
    $check($reject(fn()=>$lists->employees(['q'=>$prefix])->export(100)),'Export cap fails explicitly without silently truncating');
    $csv=(new ExportService())->export('employees','csv',$export);
    $check(!str_contains($csv['contents'],'employee_id') && str_contains($csv['contents'],'Employee Number'),'Export columns contain business identifiers and no internal employee IDs');

    $schemas=new SchemaRegistry();$imports=new ImportService();
    $keys=['employee_number','first_name','last_name','work_email','department_code','job_title','employment_type','employment_status','hire_date'];
    $row=[$prefix.'IMPORT','New','Person',strtolower($prefix).'import@example.test',$context['department_code'],'Analyst','full_time','active','2025-01-01'];
    $invalid=$row;$invalid[3]='bad@';$invalid[4]='NO-SUCH-DEPARTMENT';
    $preview=$imports->test('employees',[$row,$invalid],$keys)['result'];
    $check($preview->invalidRows===1 && $preview->duplicateRows===1 && $preview->errors[0]['row']===3,'Employee preview reports exact invalid/duplicate rows');
    ob_start();view('data-exchange.import',['schema'=>$schemas->get('employees'),'preview'=>[
        'token'=>'test','headers'=>$keys,'rows'=>[$row,$invalid],'mapping'=>$keys,'result'=>$preview,
    ]]);$previewHtml=(string)ob_get_clean();
    $check(str_contains($previewHtml,'Invalid rows') && str_contains($previewHtml,'Duplicate rows') && str_contains($previewHtml,'<th>Value</th>') && str_contains($previewHtml,'bad@'),'Import preview visibly renders counts and exact rejected values');
    $before=(int)$db->query('SELECT COUNT(*) FROM hr_employees')->fetchColumn();
    $result=$imports->import('employees',[$row,$invalid],$keys,$actor);
    $check($result->created===0 && (int)$db->query('SELECT COUNT(*) FROM hr_employees')->fetchColumn()===$before,'Invalid spreadsheet creates no partial employee data');
    $db->exec("CREATE TRIGGER $trigger BEFORE INSERT ON hr_employees FOR EACH ROW BEGIN IF NEW.employee_number='".$prefix."FAIL' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Intentional atomicity test failure'; END IF; END");
    $firstAtomic=$row;$firstAtomic[0]=$prefix.'ATOMIC';$firstAtomic[3]='atomic'.strtolower($prefix).'@example.test';
    $secondAtomic=$row;$secondAtomic[0]=$prefix.'FAIL';$secondAtomic[3]='fail'.strtolower($prefix).'@example.test';
    $atomic=$imports->import('employees',[$firstAtomic,$secondAtomic],$keys,$actor);
    $check($atomic->created===0 && $atomic->errors!==[] && (int)$db->query('SELECT COUNT(*) FROM hr_employees')->fetchColumn()===$before,'A failure during the second domain write rolls back the entire spreadsheet');
    $db->exec("DROP TRIGGER $trigger");
    $result=$imports->import('employees',[$row],$keys,$actor);
    $check($result->created===1 && $result->errors===[],'Confirmed valid employee import uses the existing domain writer');
    $duplicate=$imports->test('employees',[$row],$keys)['result'];
    $check($duplicate->duplicateRows===1 && $duplicate->valid===0,'Existing employees are rejected, never silently updated');
    $foreignRow=$row;$foreignRow[0].='FOREIGN';$foreignRow[3]='foreign'.strtolower($prefix).'@example.test';
    $_SESSION['auth']['company']['company_id']=$foreign;
    $check($imports->test('employees',[$foreignRow],$keys)['result']->valid===0,'Employee import cannot resolve another company department');
    $_SESSION['auth']['company']['company_id']=$company;

    $attendanceKeys=['employee_number','attendance_date','attendance_status','check_in','check_out'];
    $attendance=[$prefix.'001',date('Y-m-d'),'present','08:00','17:00'];
    $unknown=$attendance;$unknown[0]='UNKNOWN-'.$prefix;
    $check($imports->test('attendance',[$unknown],$attendanceKeys)['result']->invalidRows===1,'Attendance preview rejects unknown employee mapping');
    $badTime=$attendance;$badTime[3]='29:00';
    $check($imports->test('attendance',[$badTime],$attendanceKeys)['result']->invalidRows===1,'Attendance preview validates exact time rules');
    $count=(int)$db->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
    $bad=$imports->import('attendance',[$attendance,$unknown],$attendanceKeys,$actor);
    $check($bad->created===0 && (int)$db->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn()===$count,'Failed attendance validation has no partial business writes');
    $result=$imports->import('attendance',[$attendance],$attendanceKeys,$actor);
    $check($result->created===1 && $result->errors===[],'Attendance import records through the existing work-policy/session/audit path');
    $sessionSource=$db->prepare('SELECT source FROM attendance_sessions WHERE company_id=? AND employee_id=? AND active=TRUE');$sessionSource->execute([$company,$ids[0]]);
    $check($sessionSource->fetchColumn()==='import','Imported attendance sessions retain their source');
    $check($imports->test('attendance',[$attendance],$attendanceKeys)['result']->duplicateRows===1,'Attendance employee/date replay is rejected');
    $_SESSION['auth']['company']['company_id']=$foreign;
    $check($imports->test('attendance',[$attendance],$attendanceKeys)['result']->valid===0,'Attendance cannot cross companies');
    $_SESSION['auth']['company']['company_id']=$company;
    $attendancePage=$lists->attendance(['q'=>$prefix,'status'=>'present','date'=>date('Y-m-d')])->page();
    $check($attendancePage['pagination']['total']===1 && $attendancePage['rows'][0]['source']==='import','Attendance search and status filter run before pagination');
    $attendanceExport=(new ExportDataProvider())->rows('attendance',['q'=>$prefix,'status'=>'present','date'=>date('Y-m-d')]);
    $check(count($attendanceExport)===1 && $attendanceExport[0]['employee_number']===$prefix.'001','Attendance export shares the daily register scope');

    $directory=(new App\Services\EmployeeDirectoryService())->directory($prefix,'',0,1,['q'=>$prefix,'per_page'=>50]);
    ob_start();view('hr.index',$directory+['canViewDirectory'=>true,'canManage'=>true]);$html=(string)ob_get_clean();
    $check(str_contains($html,'name="q"') && str_contains($html,'Export filtered') && str_contains($html,'of 106 records'),'Employee view renders shared filters, result count and export actions');
    $dashboard=(new App\Services\AttendanceManagementService())->dashboard(date('Y-m-d'),['q'=>$prefix]);
    ob_start();view('attendance.index',$dashboard+['canManage'=>true,'listOptions'=>$lists->options(),'employeeOptions'=>$lists->employeeOptions()]);$html=(string)ob_get_clean();
    $check(str_contains($html,$prefix.'105') && str_contains($html,'of 106 records'),'Attendance view keeps the complete manual employee selector alongside paginated rows');
    $workbook=(new ExportService())->template('employees','xlsx');
    file_put_contents($upload,$workbook['contents']);
    $sheet=\PhpOffice\PhpSpreadsheet\IOFactory::load($upload);
    $sheet->getSheet(0)->setCellValue('L2',\PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTimeImmutable('2025-01-01')));
    $sheet->getSheet(0)->getStyle('L2')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
    $sheet->setActiveSheetIndex(0);
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($upload);
    $sheet->disconnectWorksheets();
    $parsed=(new App\Services\DataExchange\SpreadsheetCodec())->read($upload);
    $check($parsed['rows'][0][11]==='2025-01-01','Native Excel date cells normalize to the supported date format');

    file_put_contents($upload,'sample');
    $stored=ImportConfirmation::context()+['path'=>$upload,'validated'=>ImportConfirmation::fingerprint($upload,$keys)];
    ImportConfirmation::assertConfirmed($stored,$keys);
    $check($reject(fn()=>ImportConfirmation::assertConfirmed($stored,array_reverse($keys))),'Changed mapping requires a new preview/test');
    $_SESSION['auth']['company']['company_id']=$foreign;
    $check($reject(fn()=>ImportConfirmation::assertConfirmed($stored,$keys)),'Preview cannot be confirmed after switching companies');
    $_SESSION['auth']['company']['company_id']=$company;
    $_SESSION['auth']['user_id']=$actor+100000;
    $check($reject(fn()=>ImportConfirmation::assertConfirmed($stored,$keys)),'Preview cannot be confirmed by another actor');
    $_SESSION['auth']['user_id']=$actor;
    file_put_contents($upload,'changed');
    $check($reject(fn()=>ImportConfirmation::assertConfirmed($stored,$keys)),'Changed upload requires revalidation');
    $migration=require __DIR__.'/../database/migrations/mysql/099_sales_manager_incentive_settlement_permission.php';
    $before=(int)$db->query('SELECT COUNT(*) FROM company_role_permissions')->fetchColumn();
    foreach($migration['statements'] as $sql)$db->exec($sql);
    foreach($migration['statements'] as $sql)$db->exec($sql);
    $check((int)$db->query('SELECT COUNT(*) FROM company_role_permissions')->fetchColumn()===$before,'Migration 099 is idempotent');
    $grants=(int)$db->query("SELECT COUNT(*) FROM company_role_permissions crp JOIN roles r ON r.role_id=crp.role_id JOIN permissions p ON p.permission_id=crp.permission_id WHERE r.code='sales_manager' AND p.code='sales.incentive.settle'")->fetchColumn();
    $check($grants>0,'Migration 099 propagates the code-resolved company permission');
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $db->exec("DROP TRIGGER IF EXISTS $trigger");
    $_SESSION['auth']=['user_id'=>$actor,'company'=>['company_id'=>$company]];
    $s=$db->prepare('SELECT employee_id FROM hr_employees WHERE company_id=? AND employee_number LIKE ?');$s->execute([$company,$prefix.'%']);
    foreach($s->fetchAll(PDO::FETCH_COLUMN) as $id){
        $db->prepare('DELETE FROM attendance_sessions WHERE company_id=? AND employee_id=?')->execute([$company,$id]);
        $db->prepare('DELETE FROM attendance_records WHERE company_id=? AND employee_id=?')->execute([$company,$id]);
        $db->prepare('DELETE FROM hr_employees WHERE company_id=? AND employee_id=?')->execute([$company,$id]);
    }
    if(is_file($upload))unlink($upload);
}
echo "$passed smart-list checks passed\n";
