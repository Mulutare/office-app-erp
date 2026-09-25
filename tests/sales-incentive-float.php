<?php

declare(strict_types=1);

require __DIR__ . '/../app/helpers/bootstrap.php';

use App\Models\CompanyMembership;
use App\Services\SalesIncentiveService;

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
});
if (getenv('DB_DATABASE') !== 'office_app_cumulative_test') throw new RuntimeException('Dedicated office_app_cumulative_test database required');

$pdo = db();
$service = new SalesIncentiveService();
$company = 2;
$manager = 122;
$dsa = 123;
$suffix = bin2hex(random_bytes(6));
$savedSession = $_SESSION;
$checks = 0;
$created = [];
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    ++$checks;
    fwrite(STDOUT, 'PASS ' . $label . "\n");
};
$reject = static function (callable $work): bool {
    try { $work(); return false; } catch (RuntimeException) { return true; }
};
$login = static function (int $actor) use ($company): void {
    $_SESSION['auth'] = ['user_id' => $actor, 'company' => (new App\Models\CompanyModule())->companyById($company),
        'permissions' => (new CompanyMembership())->permissionCodes($actor, $company), 'is_platform_admin' => false];
};
$insert = static function (string $table, string $key, array $row) use ($pdo, &$created): int {
    unset($row[$key]);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($row));
    $id = (int) $pdo->lastInsertId();
    $created[] = [$table, $key, $id];
    return $id;
};
$snapshot = static function () use ($pdo): array {
    $out = [];
    foreach (['inventory_stock_balances', 'inventory_stock_movements', 'finance_journal_entries', 'finance_invoice_lines'] as $table) {
        $rows = array_map('json_encode', $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC));
        sort($rows);
        $out[$table] = hash('sha256', implode("\n", $rows));
    }
    return $out;
};
$grants = $pdo->query("SELECT * FROM company_user_permission_overrides WHERE company_id=$company AND user_id IN ($manager,$dsa)")->fetchAll(PDO::FETCH_ASSOC);
$product = $pdo->query("SELECT * FROM sales_products WHERE company_id=$company AND sku='FLOAT' AND deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC);
if (!$product) throw new RuntimeException('FLOAT fixture required');
$productId = (int) $product['product_id'];
$baseline = $snapshot();
$claimIds = [];

try {
    $pdo->prepare("INSERT INTO company_user_permission_overrides (company_id,user_id,permission_id,allowed,updated_by)
        SELECT ?,?,permission_id,1,? FROM permissions WHERE code IN ('sales.module.enabled','sales.incentive.view','sales.incentive.approve','sales.incentive.settle')
        ON DUPLICATE KEY UPDATE allowed=1")->execute([$company, $manager, $manager]);
    $pdo->prepare("INSERT INTO company_user_permission_overrides (company_id,user_id,permission_id,allowed,updated_by)
        SELECT ?,?,permission_id,1,? FROM permissions WHERE code IN ('sales.module.enabled','sales.incentive.view','sales.incentive.submit','sales.incentive.approve')
        ON DUPLICATE KEY UPDATE allowed=1")->execute([$company, $dsa, $manager]);
    $login($manager);
    $template = $pdo->query("SELECT * FROM sales_quick_sales WHERE company_id=$company AND user_id=$dsa LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$template) throw new RuntimeException('DSA quick-sale fixture required');
    $quotation = $pdo->query('SELECT * FROM sales_quotations WHERE quotation_id=' . (int) $template['quotation_id'])->fetch(PDO::FETCH_ASSOC);
    $quotation['quotation_number'] = 'FLOAT-TEST-' . $suffix;
    $quotation['sales_order_id'] = null;
    $quotationId = $insert('sales_quotations', 'quotation_id', $quotation);
    $template['quotation_id'] = $quotationId;
    $template['status'] = 'closed';
    $quickId = $insert('sales_quick_sales', 'quick_sale_id', $template);
    $report = $pdo->query("SELECT * FROM sales_quick_sale_reports WHERE company_id=$company AND status='confirmed' AND finance_invoice_id IS NOT NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$report) throw new RuntimeException('Confirmed report fixture required');
    $report['quick_sale_id'] = $quickId;
    $report['reported_by_user_id'] = $dsa;
    $reportId = $insert('sales_quick_sale_reports', 'report_id', $report);
    $sold = (float) $pdo->query('SELECT COALESCE(SUM(total_amount),0) FROM finance_invoice_lines WHERE invoice_id='.(int)$report['finance_invoice_id'])->fetchColumn();
    $currency = $pdo->query('SELECT currency FROM finance_invoices WHERE invoice_id='.(int)$report['finance_invoice_id'])->fetchColumn();
    $amount = round($sold + 1000, 2);
    $input = ['dsa_dsp_user_id' => $dsa, 'product_id' => $productId, 'report_id' => $reportId, 'amount' => $amount, 'issued_date' => '2026-09-25', 'reference' => 'FLOAT-' . $suffix];
    $check($reject(fn () => $service->issueFloat(array_replace($input, ['report_id' => 99999999]), $manager)), 'Forged report context cannot issue float');
    $otherProduct = (int) $pdo->query("SELECT product_id FROM sales_products WHERE company_id=$company AND sku<>'FLOAT' LIMIT 1")->fetchColumn();
    $check($reject(fn () => $service->issueFloat(array_replace($input, ['product_id' => $otherProduct]), $manager)), 'Crafted non-FLOAT product rejected');
    $check($reject(fn () => $service->issueFloat($input, $dsa)), 'DSA with approval permission still cannot issue their own float');
    $check($reject(fn () => $service->issueFloat($input, 121)), 'Different manager cannot issue to another manager’s DSA');
    $pdo->exec("UPDATE sales_products SET active=0 WHERE product_id=$productId");
    $check($reject(fn () => $service->issueFloat($input, $manager)), 'Inactive FLOAT blocks issuance');
    $pdo->exec("UPDATE sales_products SET active=1 WHERE product_id=$productId");
    $id = $service->issueFloat($input, $manager);
    $created[] = ['sales_dsa_float_issuances', 'float_id', $id];
    $float = $pdo->query("SELECT * FROM sales_dsa_float_issuances WHERE float_id=$id")->fetch(PDO::FETCH_ASSOC);
    $check((int) $float['product_id'] === $productId && (float) $float['amount'] === $amount && $float['status'] === 'issued', 'Issuance persists FLOAT identity and actual amount in the existing table');
    $check($reject(fn () => $service->issueFloat($input, $manager)), 'Duplicate issuance reference cannot issue twice');
    $check($snapshot() === $baseline, 'Issuance changes no stock, movements, Finance journals or invoice lines');

    // Seed an old-format claim, as existing pre-098 claims are retained, not resubmitted.
    $pdo->prepare("INSERT INTO sales_incentive_claims(company_id,originating_report_id,float_id,dsa_dsp_user_id,responsible_manager_id,currency,proposed_amount,external_body,status,submitted_by,submitted_at) VALUES(?,?,?,?,?,?,100,'Safaricom','submitted',?,NOW())")->execute([$company,$reportId,$id,$dsa,$manager,$currency,$dsa]);
    $claimId=(int)$pdo->lastInsertId();$claimIds[]=$claimId;
    $historical=$service->detail($claimId,$manager);
    $check($historical['claim']['claim_basis']==='legacy_float' && $historical['claim']['confirmed_sales_snapshot']===null,'Old-format claims retain legacy basis without a fabricated snapshot');
    ob_start();view('sales.incentive-detail',['incentiveDetail'=>$historical]);$html=(string)ob_get_clean();
    $check(str_contains($html,'Historical cash float') && str_contains($html,'Historical report'),'Historical report and float context remain readable');
    $login($manager);
    $service->decideClaim($claimId, true, 80, 'Test approval', $manager);
    $detail = $service->detail($claimId, $manager)['claim'];
    $check((float) $detail['approved_amount'] === 80.0 && (float) $detail['unexplained_variance'] === 920.0, 'Approval and variance still use issued cash less sold and approved amounts');
    $service->settle($claimId, ['amount' => 30, 'settlement_date' => '2026-09-25', 'currency' => $currency, 'external_payment_reference' => 'FLOAT-PAY1-' . $suffix], $manager);
    $check((float) $service->detail($claimId, $manager)['claim']['outstanding'] === 50.0, 'Partial Safaricom settlement preserves derived outstanding');
    $service->settle($claimId, ['amount' => 50, 'settlement_date' => '2026-09-25', 'currency' => $currency, 'external_payment_reference' => 'FLOAT-PAY2-' . $suffix], $manager);
    $check($service->detail($claimId, $manager)['claim']['status'] === 'settled', 'Existing full Safaricom settlement completes the claim');
    $check($snapshot() === $baseline, 'Full incentive lifecycle posts no inventory movement or Finance journal');

    $foreignCompany = (int) $pdo->query("SELECT company_id FROM companies WHERE company_id<>$company LIMIT 1")->fetchColumn();
    $_SESSION['auth']['company'] = ['company_id' => $foreignCompany];
    $check($reject(fn () => $service->issueFloat($input, $manager)), 'Cross-company legacy issuance is rejected');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($claimIds as $claimId) {
        $pdo->prepare("DELETE FROM user_notifications WHERE company_id=? AND reference_type='sales_incentive_claim' AND reference_id=?")->execute([$company, $claimId]);
        foreach (['sales_incentive_events', 'sales_incentive_settlements', 'sales_incentive_claims'] as $table) {
            $pdo->prepare("DELETE FROM $table WHERE company_id=? AND incentive_claim_id=?")->execute([$company, $claimId]);
        }
    }
    foreach (array_reverse($created) as [$table, $key, $id]) $pdo->prepare("DELETE FROM $table WHERE $key=?")->execute([$id]);
    $pdo->prepare('UPDATE sales_products SET active=? WHERE product_id=?')->execute([$product['active'], $productId]);
    $pdo->exec("DELETE FROM company_user_permission_overrides WHERE company_id=$company AND user_id IN ($manager,$dsa)");
    foreach ($grants as $row) {
        $pdo->prepare('INSERT INTO company_user_permission_overrides (company_id,user_id,permission_id,allowed,updated_by,updated_at) VALUES (?,?,?,?,?,?)')->execute(array_values($row));
    }
    $_SESSION = $savedSession;
}
echo "$checks float workflow checks passed\n";
