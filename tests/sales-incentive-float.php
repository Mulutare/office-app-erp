<?php

declare(strict_types=1);

require __DIR__ . '/../app/helpers/bootstrap.php';

use App\Models\CompanyMembership;
use App\Services\SalesIncentiveService;

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
});
if (getenv('DB_DATABASE') !== 'office_app_float_test') throw new RuntimeException('Dedicated office_app_float_test database required');

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
    $options = $service->register($manager, ['report_id' => $reportId]);
    $sold = (float) $options['selectedReport']['sold_amount'];
    $amount = round($sold + 1000, 2);
    $currency = $options['selectedReport']['currency'];
    $check((int) $options['floatProduct']['product_id'] === $productId, 'FLOAT comes from the active company Sales Products master');
    $check(in_array($dsa, array_map('intval', array_column($options['issuableUsers'], 'user_id')), true)
        && !in_array($manager, array_map('intval', array_column($options['issuableUsers'], 'user_id')), true), 'Manager issuance choices include direct DSA reports only');

    $input = ['dsa_dsp_user_id' => $dsa, 'product_id' => $productId, 'report_id' => $reportId, 'amount' => $amount, 'issued_date' => '2026-09-25', 'reference' => 'FLOAT-' . $suffix];
    $check($reject(fn () => $service->issueFloat(array_replace($input, ['report_id' => 99999999]), $manager)), 'Forged report context cannot issue float');
    $otherProduct = (int) $pdo->query("SELECT product_id FROM sales_products WHERE company_id=$company AND sku<>'FLOAT' LIMIT 1")->fetchColumn();
    $check($reject(fn () => $service->issueFloat(array_replace($input, ['product_id' => $otherProduct]), $manager)), 'Crafted non-FLOAT product rejected');
    $check($reject(fn () => $service->issueFloat($input, $dsa)), 'DSA with approval permission still cannot issue their own float');
    $check($reject(fn () => $service->issueFloat($input, 121)), 'Different manager cannot issue to another manager’s DSA');
    $pdo->exec("UPDATE sales_products SET active=0 WHERE product_id=$productId");
    $check($reject(fn () => $service->issueFloat($input, $manager)), 'Inactive FLOAT blocks issuance');
    $inactive = $service->register($manager, ['report_id' => $reportId]);
    ob_start(); view('sales.incentives', ['incentiveData' => $inactive]); $html = (string) ob_get_clean();
    $check(str_contains($html, 'Active FLOAT product is not configured in Sales Products.'), 'Missing active master has an explicit UI message');
    $pdo->exec("UPDATE sales_products SET active=1 WHERE product_id=$productId");
    $id = $service->issueFloat($input, $manager);
    $created[] = ['sales_dsa_float_issuances', 'float_id', $id];
    $float = $pdo->query("SELECT * FROM sales_dsa_float_issuances WHERE float_id=$id")->fetch(PDO::FETCH_ASSOC);
    $check((int) $float['product_id'] === $productId && (float) $float['amount'] === $amount && $float['status'] === 'issued', 'Issuance persists FLOAT identity and actual amount in the existing table');
    $check($reject(fn () => $service->issueFloat($input, $manager)), 'Duplicate issuance reference cannot issue twice');
    $check($snapshot() === $baseline, 'Issuance changes no stock, movements, Finance journals or invoice lines');

    $bad = [];
    foreach (['reversed' => ['status' => 'reversed'], 'other_dsa' => ['dsa_dsp_user_id' => $manager],
        'other_manager' => ['manager_user_id' => 121], 'currency' => ['currency' => $currency === 'USD' ? 'ETB' : 'USD'],
        'other_product' => ['product_id' => $otherProduct], 'unlinked' => ['product_id' => null],
        'exhausted' => ['amount' => max(0.01, $sold)]] as $key => $changes) {
        $bad[$key] = $insert('sales_dsa_float_issuances', 'float_id', array_replace($float, $changes, ['reference' => $key . '-' . $suffix]));
    }
    $login($dsa);
    $form = $service->register($dsa, ['report_id' => $reportId]);
    $ids = array_map('intval', array_column($form['floats'], 'float_id'));
    $check(in_array($id, $ids, true) && array_intersect(array_values($bad), $ids) === [], 'Dropdown excludes reversed, unrelated, wrong-manager/currency/product, unlinked and exhausted floats');
    $check(!$form['canIssueFloat'], 'DSA issuance action remains hidden even with crafted approval permission');
    ob_start(); view('sales.incentives', ['incentiveData' => $form, 'canSubmitIncentive' => true]); $html = (string) ob_get_clean();
    $check(str_contains($html, $currency . ' ' . number_format($amount, 2)) && str_contains($html, 'issued 2026-09-25')
        && !str_contains($html, 'id="issue-cash-float"'), 'Dropdown displays real issued money, date and manager; DSA sees no issue action');
    $check($service->register($dsa, ['report_id' => 99999999])['floats'] === [], 'Unknown or inaccessible selected report exposes no float');
    $claimInput = ['report_id' => $reportId, 'float_id' => $id, 'proposed_amount' => 100];
    foreach (['reversed', 'other_dsa', 'other_manager', 'currency', 'other_product', 'unlinked'] as $key) {
        $check($reject(fn () => $service->submitClaim(array_replace($claimInput, ['float_id' => $bad[$key]]), $dsa)), 'Crafted claim rejects ' . $key . ' float');
    }
    $check($reject(fn () => $service->submitClaim(array_replace($claimInput, ['proposed_amount' => 1001]), $dsa)), 'Claim retains float-minus-confirmed-sales ceiling');
    $claimId = $service->submitClaim($claimInput, $dsa);
    $claimIds[] = $claimId;
    $check($service->register($dsa, ['report_id' => $reportId])['selectedReport'] === null, 'Consumed report is removed from new-claim choices');
    $check($reject(fn () => $service->submitClaim($claimInput, $dsa)), 'Consumed float/report cannot be claimed twice');

    $login($manager);
    $service->decideClaim($claimId, true, 80, 'Test approval', $manager);
    $detail = $service->detail($claimId, $manager)['claim'];
    $check((float) $detail['approved_amount'] === 80.0 && (float) $detail['unexplained_variance'] === 920.0, 'Approval and variance still use issued cash less sold and approved amounts');
    $service->settle($claimId, ['amount' => 30, 'settlement_date' => '2026-09-25', 'currency' => $currency, 'external_payment_reference' => 'FLOAT-PAY1-' . $suffix], $manager);
    $check((float) $service->detail($claimId, $manager)['claim']['outstanding'] === 50.0, 'Partial Safaricom settlement preserves derived outstanding');
    $service->settle($claimId, ['amount' => 50, 'settlement_date' => '2026-09-25', 'currency' => $currency, 'external_payment_reference' => 'FLOAT-PAY2-' . $suffix], $manager);
    $check($service->detail($claimId, $manager)['claim']['status'] === 'settled', 'Existing full Safaricom settlement completes the claim');
    $check($snapshot() === $baseline, 'Full incentive lifecycle posts no inventory movement or Finance journal');

    $anotherReportId = $insert('sales_quick_sale_reports', 'report_id', $report);
    $empty = $service->register($manager, ['report_id' => $anotherReportId]);
    $check($empty['floats'] === [], 'Consumed issuance is unavailable for another otherwise eligible report');
    ob_start(); view('sales.incentives', ['incentiveData' => $empty]); $html = (string) ob_get_clean();
    $check(str_contains($html, 'No issued cash float is available for this DSA/DSP.')
        && str_contains($html, 'href="#issue-cash-float"'), 'Empty report shows clear guidance and authorized manager issuance action');
    $newId = $service->issueFloat(array_replace($input, ['report_id' => $anotherReportId, 'reference' => 'FLOAT-NEW-' . $suffix]), $manager);
    $created[] = ['sales_dsa_float_issuances', 'float_id', $newId];
    $check(in_array($newId, array_map('intval', array_column($service->register($manager, ['report_id' => $anotherReportId])['floats'], 'float_id')), true), 'New issuance appears immediately for the selected report');

    $foreignCompany = (int) $pdo->query("SELECT company_id FROM companies WHERE company_id<>$company LIMIT 1")->fetchColumn();
    $_SESSION['auth']['company'] = ['company_id' => $foreignCompany];
    $check($reject(fn () => $service->issueFloat($input, $manager)) && $reject(fn () => $service->submitClaim($claimInput, $dsa)), 'Cross-company issuance and claim requests are rejected');
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
