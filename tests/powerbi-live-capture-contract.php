<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';

use App\Services\PowerBiLiveCaptureService;
use App\Services\WorkspaceAccessService;

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

try {
    $check(class_exists(PowerBiLiveCaptureService::class), 'Power BI live capture service loads');
    $check(count(PowerBiLiveCaptureService::DAILY_FIELDS) === 13, 'Exactly 13 explicit daily shop metrics are writable');
    $check(
        array_intersect(PowerBiLiveCaptureService::DAILY_FIELDS, PowerBiLiveCaptureService::LOCKED_SEMANTIC_FIELDS) === [],
        'Unconfirmed Safaricom semantics are not writable by the daily capture workflow'
    );

    $objects = ['bi_powerbi_shop_daily_metrics', 'bi_powerbi_employee_deposit_events', 'bi_powerbi_reporting_control'];
    $quoted = implode(',', array_map(static fn(string $name): string => db()->quote($name), $objects));
    $count = (int)db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN($quoted)")->fetchColumn();
    $check($count === count($objects), 'Required Power BI capture tables exist');

    $definitions = WorkspaceAccessService::definitions();
    $check(isset($definitions['sales']['daily_shop_metrics']), 'Daily Shop Metrics is registered in Sales workspace navigation');

    $control = db()->query("SELECT reporting_mode,live_cutover_date FROM bi_powerbi_reporting_control WHERE company_id=2")->fetch(PDO::FETCH_ASSOC);
    $check(is_array($control), 'Power BI reporting control exists');
    if (is_array($control)) {
        $check((string)$control['reporting_mode'] === 'HISTORY_ONLY', 'Local validation remains HISTORY_ONLY');
        $check($control['live_cutover_date'] === null, 'No live cutover date has been activated');
    }

    $service = new PowerBiLiveCaptureService();
    $settlementId = (int)db()->query('SELECT MIN(settlement_id) FROM sales_settlements WHERE company_id=2')->fetchColumn();
    if ($settlementId > 0) {
        $attribution = $service->bankConfirmationAttribution(2, $settlementId);
        $check(isset($attribution['status']), 'Settlement attribution probe executes without writing data');
        echo 'INFO sample settlement attribution: ' . json_encode($attribution, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo 'INFO no local company-2 settlement exists for attribution probe.' . PHP_EOL;
    }
} catch (Throwable $exception) {
    echo 'FAIL unexpected: ' . $exception->getMessage() . PHP_EOL;
    $failed++;
}

echo PHP_EOL . ($passed + $failed) . ' checks, ' . $failed . ' failures' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
