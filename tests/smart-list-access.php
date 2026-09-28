<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/bootstrap.php';
if (getenv('APP_ENV') !== 'testing') throw new RuntimeException('Isolated testing database required.');
$entity = $argv[1] ?? 'employees';
$operation = $argv[2] ?? 'export';
if (!in_array($entity,['employees','attendance'],true) || !in_array($operation,['export','import'],true)) throw new RuntimeException('Unknown test case.');
$prefix = $entity === 'employees' ? 'hr' : 'attendance';
$membership = new App\Models\CompanyMembership();
$candidate = null;
foreach (db()->query('SELECT cu.company_id,cu.user_id FROM company_users cu JOIN users u ON u.user_id=cu.user_id WHERE cu.active=TRUE AND u.active=TRUE AND u.is_platform_admin=FALSE AND u.must_change_password=FALSE')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $permissions = $membership->permissionCodes((int)$row['user_id'],(int)$row['company_id']);
    if (array_intersect([$prefix.'.records.view',$prefix.'.records.manage'],$permissions) === []) { $candidate=$row; break; }
}
if (!$candidate) throw new RuntimeException('A user without HR record permissions is required.');
$_SESSION['auth']=['user_id'=>(int)$candidate['user_id'],'company'=>['company_id'=>(int)$candidate['company_id']]];
$_GET=['format'=>'csv','company_id'=>999999,'q'=>'','per_page'=>999999];
ob_start();
register_shutdown_function(static function () use ($entity,$operation): void {
    $body=(string)ob_get_clean();
    $ok=http_response_code()===403 && !str_contains($body,'Employee Number,Employee Name');
    echo ($ok?'PASS ':'FAIL ')."Unauthorized $entity $operation is denied server-side\n";
    exit($ok?0:1);
});
$controller=new App\Controllers\DataExchangeController();
if($operation==='export')$controller->export($entity);else $controller->show($entity);
