<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';

use App\Services\PowerBiLiveCaptureService;

$passed = 0;
$failed = 0;

$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$pdo = db();

try {
    $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $isolated = getenv('OFFICEAPP_REHEARSAL_ISOLATED') === '1' && getenv('DB_HOST') === 'db' && getenv('DB_DATABASE') === 'passiontech_officeapp' && preg_match('/^officeapp-rehearsal-[a-f0-9]{32}-php$/', (string)getenv('OFFICEAPP_REHEARSAL_CONTAINER')) === 1;
    $safe = getenv('OFFICEAPP_REHEARSAL_ISOLATED') === '1' ? ($isolated && $database === 'passiontech_officeapp') : $database === 'office_app_dev';
    $check($safe, 'Running against the permitted development or isolated rehearsal database');
    if (!$safe) {
        throw new RuntimeException('REFUSING: database isolation guard failed.');
    }

    $_SESSION['auth']['company'] = ['company_id' => 2];

    $scope = $pdo->query(
        "SELECT wa.user_id,wa.warehouse_id
         FROM inventory_user_warehouse_access wa
         INNER JOIN inventory_warehouses w
           ON w.company_id=wa.company_id
          AND w.warehouse_id=wa.warehouse_id
          AND w.active=TRUE
          AND w.deleted_at IS NULL
         WHERE wa.company_id=2
           AND wa.active=TRUE
         ORDER BY CASE WHEN wa.warehouse_id=23 THEN 0 ELSE 1 END,
                  wa.warehouse_id,wa.user_id
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if (!is_array($scope)) {
        throw new RuntimeException('No active company-2 warehouse/user scope was found.');
    }

    $actorId = (int)$scope['user_id'];
    $warehouseId = (int)$scope['warehouse_id'];

    $reportDate = null;
    for ($i = 0; $i < 31; $i++) {
        $candidate = (new DateTimeImmutable('today'))->modify("-{$i} day")->format('Y-m-d');
        $q = $pdo->prepare(
            'SELECT COUNT(*) FROM bi_powerbi_shop_daily_metrics
             WHERE company_id=2 AND warehouse_id=? AND report_date=?'
        );
        $q->execute([$warehouseId, $candidate]);
        if ((int)$q->fetchColumn() === 0) {
            $reportDate = $candidate;
            break;
        }
    }

    if ($reportDate === null) {
        throw new RuntimeException('Could not find a free local test date in the last 31 days.');
    }

    $controlBefore = $pdo->query(
        "SELECT reporting_mode,live_cutover_date
         FROM bi_powerbi_reporting_control WHERE company_id=2"
    )->fetch(PDO::FETCH_ASSOC);

    $auditCount = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_logs
         WHERE company_id=2
           AND action='powerbi.shop_daily_metrics.saved'
           AND record_id=?"
    );
    $auditRecordId = $warehouseId . ':' . $reportDate;
    $auditCount->execute([$auditRecordId]);
    $auditBefore = (int)$auditCount->fetchColumn();

    $pdo->beginTransaction();

    $service = new PowerBiLiveCaptureService();
    $result = $service->saveDailyMetrics([
        'warehouse_id' => $warehouseId,
        'report_date' => $reportDate,
        'manager_reported_deposit_birr' => '123.45',
        'reward_sim_cards_pieces' => '2',
        'incentive_sim_cards_birr' => '20',
        'total_float_returned_birr' => '50',
        'notes' => 'LOCAL_ROLLBACK_TEST_POWERBI_LIVE_CAPTURE',
    ], $actorId);

    $check(!empty($result['successful']), 'Application service saves daily shop metrics');

    if (empty($result['successful'])) {
        throw new RuntimeException((string)($result['errors']['form'] ?? 'Daily capture failed.'));
    }

    $base = $pdo->prepare(
        'SELECT manager_reported_deposit_birr,reward_sim_cards_pieces,
                incentive_sim_cards_birr,total_float_returned_birr,
                provenance,source_reference
         FROM bi_powerbi_shop_daily_metrics
         WHERE company_id=2 AND warehouse_id=? AND report_date=?'
    );
    $base->execute([$warehouseId, $reportDate]);
    $baseRow = $base->fetch(PDO::FETCH_ASSOC);

    $check(
        is_array($baseRow)
        && abs((float)$baseRow['manager_reported_deposit_birr'] - 123.45) < 0.0001
        && abs((float)$baseRow['reward_sim_cards_pieces'] - 2.0) < 0.0001
        && abs((float)$baseRow['incentive_sim_cards_birr'] - 20.0) < 0.0001
        && abs((float)$baseRow['total_float_returned_birr'] - 50.0) < 0.0001
        && (string)$baseRow['provenance'] === 'ERP_APP_DAILY_CAPTURE',
        'Capture table stores the application values with ERP provenance'
    );

    $live = $pdo->prepare(
        'SELECT manager_reported_deposit_birr,reward_sim_cards_pieces,
                incentive_sim_cards_birr,total_float_returned_birr,SourceSystem
         FROM vw_powerbi_live_shop_daily_supplemental
         WHERE company_id=2 AND warehouse_id=? AND report_date=?'
    );
    $live->execute([$warehouseId, $reportDate]);
    $liveRow = $live->fetch(PDO::FETCH_ASSOC);

    $check(
        is_array($liveRow)
        && abs((float)$liveRow['manager_reported_deposit_birr'] - 123.45) < 0.0001
        && (string)$liveRow['SourceSystem'] === 'ERP_SUPPLEMENTAL',
        'Captured row flows into the live Power BI supplemental view'
    );

    $compat = $pdo->prepare(
        "SELECT COUNT(*)
         FROM vw_powerbi_compat_shop_daily_supplemental
         WHERE company_id=2
           AND report_date=?
           AND SourceSystem<>'POWERBI_HISTORY'"
    );
    $compat->execute([$reportDate]);
    $check(
        (int)$compat->fetchColumn() === 0,
        'HISTORY_ONLY prevents the new live row from entering compatibility output'
    );

    $controlDuring = $pdo->query(
        "SELECT reporting_mode,live_cutover_date
         FROM bi_powerbi_reporting_control WHERE company_id=2"
    )->fetch(PDO::FETCH_ASSOC);

    $check(
        is_array($controlBefore)
        && is_array($controlDuring)
        && $controlBefore === $controlDuring
        && (string)$controlDuring['reporting_mode'] === 'HISTORY_ONLY'
        && $controlDuring['live_cutover_date'] === null,
        'Application capture does not change Power BI cutover control'
    );

    $pdo->rollBack();

    $gone = $pdo->prepare(
        'SELECT COUNT(*) FROM bi_powerbi_shop_daily_metrics
         WHERE company_id=2 AND warehouse_id=? AND report_date=?'
    );
    $gone->execute([$warehouseId, $reportDate]);
    $check((int)$gone->fetchColumn() === 0, 'Test capture row is rolled back cleanly');

    $auditCount->execute([$auditRecordId]);
    $auditAfter = (int)$auditCount->fetchColumn();
    $check($auditAfter === $auditBefore, 'Test audit record is rolled back cleanly');

    echo 'INFO actor_id=' . $actorId
        . ' warehouse_id=' . $warehouseId
        . ' report_date=' . $reportDate . PHP_EOL;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo 'FAIL unexpected: ' . $exception->getMessage() . PHP_EOL;
    $failed++;
}

echo PHP_EOL . ($passed + $failed) . ' checks, ' . $failed . ' failures' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
