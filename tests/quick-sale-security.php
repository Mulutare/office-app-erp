<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/helpers/bootstrap.php';

use App\Services\SalesQuickSaleService;
use App\Services\SettlementService;

if (!in_array(getenv('APP_ENV'), ['development', 'testing'], true)) {
    throw new RuntimeException('Local testing only.');
}
$passed = $failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$saved = $_SESSION;
try {
    $row = db()->query("SELECT qs.* FROM sales_quick_sales qs INNER JOIN users u ON u.user_id=qs.user_id
        WHERE u.username='qs_dsa_test' ORDER BY qs.quick_sale_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Existing qs_dsa_test Quick Sale is required.');
    $company = (int) $row['company_id'];
    $owner = (int) $row['user_id'];
    $manager = (int) $row['manager_user_id'];
    $id = (int) $row['quick_sale_id'];
    $_SESSION['auth'] = ['user_id' => $owner, 'company' => ['company_id' => $company]];
    $service = new SalesQuickSaleService();
    $check(!empty($service->detail($id, $owner)['successful']), 'DSA can read own Quick Sale');
    $check(empty($service->confirm($id, $owner, [])['successful']), 'DSA cannot allocate through service');
    $check(empty($service->escalate($id, $owner, 'unauthorized test')['successful']), 'DSA cannot escalate through service');
    $check(empty($service->confirmReport($id, 0, $owner)['successful']), 'DSA cannot confirm report');
    $check(empty($service->requestReportCorrection($id, 0, $owner, 'unauthorized test')['successful']), 'DSA cannot return report');
    $check(empty($service->handoffToFinance($id, 0, $owner)['successful']), 'DSA cannot hand off to Finance');
    $check($service->financeQueue($owner) === [], 'DSA cannot read Finance queue');
    $check(empty((new SettlementService())->transition(0, 'reconcile', 'unauthorized test', $owner)['successful']), 'DSA cannot reconcile through service');
    $check(!empty($service->detail($id, $manager)['successful']), 'Current manager can read assigned request');
    $check(empty($service->detail($id, 2147483647, true)['successful']), 'Caller-supplied reviewer flag cannot bypass authorization');
    $check($service->reportEvidence($id, 2147483647, $owner) === null, 'Unrelated report ID cannot retrieve evidence');
    $_SESSION['auth']['company']['company_id'] = $company + 100000;
    $check(empty($service->detail($id, $owner, true)['successful']), 'Cross-company ID cannot retrieve Quick Sale');
    $check($service->reportEvidence($id, 1, $owner, true) === null, 'Cross-company evidence denied');
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL unexpected: ' . $e->getMessage() . PHP_EOL;
} finally {
    $_SESSION = $saved;
}
echo "$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
