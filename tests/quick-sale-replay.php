<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/helpers/bootstrap.php';
use App\Services\SalesQuickSaleService;

if (!in_array(getenv('APP_ENV'), ['development', 'testing'], true)) throw new RuntimeException('Local testing only.');
$passed = $failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$saved = $_SESSION;
try {
    $db = db();
    $row = $db->query("SELECT qs.company_id,qs.quick_sale_id,qs.manager_user_id,r.report_id
        FROM sales_quick_sales qs INNER JOIN users u ON u.user_id=qs.user_id
        INNER JOIN sales_quick_sale_reports r ON r.company_id=qs.company_id AND r.quick_sale_id=qs.quick_sale_id
        WHERE u.username='qs_dsa_test' AND qs.status='closed' AND r.status='confirmed'
          AND r.finance_handoff_at IS NOT NULL ORDER BY qs.quick_sale_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Existing closed and handed-off local DSA fixture required.');
    $company = (int) $row['company_id']; $id = (int) $row['quick_sale_id'];
    $manager = (int) $row['manager_user_id']; $report = (int) $row['report_id'];
    $_SESSION['auth'] = ['user_id' => $manager, 'company' => ['company_id' => $company]];
    $snapshot = static function () use ($db, $company, $id, $report): array {
        $q = $db->prepare('SELECT * FROM sales_quick_sale_reports WHERE company_id=? AND report_id=?');
        $q->execute([$company, $report]);
        $audit = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE company_id=? AND table_name='sales_quick_sales' AND record_id=?");
        $audit->execute([$company, (string) $id]);
        return [$q->fetch(PDO::FETCH_ASSOC), (int) $audit->fetchColumn(),
            (int) $db->query('SELECT COUNT(*) FROM finance_invoices')->fetchColumn(),
            (int) $db->query('SELECT COUNT(*) FROM inventory_sales_commitments')->fetchColumn()];
    };
    $before = $snapshot(); $service = new SalesQuickSaleService();
    for ($i = 0; $i < 2; $i++) {
        $result = $service->handoffToFinance($id, $report, $manager);
        $check(!empty($result['successful']) && !empty($result['replayed']), 'Finance handoff retry ' . ($i + 1) . ' is idempotent');
        $result = $service->confirmReport($id, $report, $manager);
        $check(!empty($result['successful']) && !empty($result['replayed']), 'Confirmed report retry ' . ($i + 1) . ' is idempotent');
    }
    $check($snapshot() === $before, 'Retries leave report, receipt metadata, invoice count, reservation count and audit count unchanged');
    $q = $db->prepare("SELECT ur.user_id FROM company_user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.company_id=? AND r.code='company_owner' LIMIT 1");
    $q->execute([$company]); $finance = (int) $q->fetchColumn();
    $queue = $service->financeQueue($finance);
    $check(in_array($id, array_map('intval', array_column($queue, 'quick_sale_id')), true), 'Handed-off transaction appears in authorized Finance queue');
    $check(!array_key_exists('evidence_path', $queue[0] ?? []), 'Finance queue exposes evidence boolean, never storage path');
    $check($service->reportEvidence($id, $report, $finance) !== null, 'Authorized Finance reader can retrieve handed-off receipt');
} catch (Throwable $e) {
    $failed++; echo 'FAIL unexpected: ' . $e->getMessage() . PHP_EOL;
} finally { $_SESSION = $saved; }
echo "$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
