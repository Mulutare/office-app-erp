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
    $check($database === 'office_app_dev', 'Running only against office_app_dev');
    if ($database !== 'office_app_dev') {
        throw new RuntimeException('REFUSING: database is not office_app_dev.');
    }

    $_SESSION['auth']['company'] = ['company_id' => 2];

    $settlement = $pdo->query(
        "SELECT s.settlement_id,s.settlement_number,
                COALESCE(s.submitted_by,s.created_by) AS actor_id
         FROM sales_settlements s
         WHERE s.company_id=2
         ORDER BY s.settlement_id
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if (!is_array($settlement)) {
        throw new RuntimeException('No company-2 settlement exists for the bank capture smoke test.');
    }

    $settlementId = (int)$settlement['settlement_id'];
    $actorId = (int)$settlement['actor_id'];

    $service = new PowerBiLiveCaptureService();
    $scope = $service->bankConfirmationAttribution(2, $settlementId);

    $check(
        ($scope['status'] ?? '') === 'resolved',
        'Sample settlement resolves to exactly one employee and one shop'
    );

    if (($scope['status'] ?? '') !== 'resolved') {
        throw new RuntimeException(
            'Sample settlement attribution is not resolved: ' .
            json_encode($scope, JSON_UNESCAPED_SLASHES)
        );
    }

    $warehouseId = (int)$scope['warehouse_id'];
    $employeeId = (int)$scope['employee_id'];
    $reference = 'TEST-PBI-BANK-CAPTURE-ROLLBACK';
    $transactionDate = date('Y-m-d');
    $amount = '321.45';
    $sha = hash('sha256', 'POWERBI_BANK_CAPTURE_ROLLBACK_TEST');

    $source = file_get_contents(__DIR__ . '/../app/services/SettlementService.php');
    $check(
        is_string($source)
        && str_contains($source, 'captureBankConfirmation(')
        && str_contains($source, 'powerbi.employee_deposit capture'),
        'SettlementService contains the automatic Power BI bank-capture hook'
    );

    $controlBefore = $pdo->query(
        "SELECT reporting_mode,live_cutover_date
         FROM bi_powerbi_reporting_control
         WHERE company_id=2"
    )->fetch(PDO::FETCH_ASSOC);

    $auditBeforeStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_logs
         WHERE company_id=2
           AND action='powerbi.employee_deposit.auto_captured'"
    );
    $auditBeforeStmt->execute();
    $auditBefore = (int)$auditBeforeStmt->fetchColumn();

    $pdo->beginTransaction();

    $insert = $pdo->prepare(
        "INSERT INTO bank_confirmations (
            company_id,settlement_id,bank_reference,transaction_date,
            confirmed_amount,currency,evidence_path,evidence_original_name,
            evidence_mime,evidence_size,evidence_sha256,source,created_by
         ) VALUES (
            2,:settlement_id,:bank_reference,:transaction_date,
            :confirmed_amount,'ETB',:evidence_path,'powerbi-bank-capture-smoke.pdf',
            'application/pdf',:evidence_size,:evidence_sha256,'manual',:created_by
         )"
    );
    $insert->execute([
        'settlement_id' => $settlementId,
        'bank_reference' => $reference,
        'transaction_date' => $transactionDate,
        'confirmed_amount' => $amount,
        'evidence_path' => __FILE__,
        'evidence_size' => filesize(__FILE__),
        'evidence_sha256' => $sha,
        'created_by' => $actorId,
    ]);

    $confirmationId = (int)$pdo->lastInsertId();
    $check($confirmationId > 0, 'Synthetic local bank confirmation is staged inside the rollback transaction');

    $capture = $service->captureBankConfirmation(
        2,
        $settlementId,
        $confirmationId,
        $actorId
    );

    $check(
        ($capture['status'] ?? '') === 'captured'
        && (int)($capture['deposit_event_id'] ?? 0) > 0,
        'Automatic employee bank-deposit capture succeeds'
    );

    $eventId = (int)($capture['deposit_event_id'] ?? 0);

    $event = $pdo->prepare(
        "SELECT report_date,warehouse_id,employee_id,bank_transaction_id,
                amount_birr,source_reference,idempotency_key,provenance
         FROM bi_powerbi_employee_deposit_events
         WHERE company_id=2 AND deposit_event_id=?"
    );
    $event->execute([$eventId]);
    $eventRow = $event->fetch(PDO::FETCH_ASSOC);

    $check(
        is_array($eventRow)
        && (int)$eventRow['warehouse_id'] === $warehouseId
        && (int)$eventRow['employee_id'] === $employeeId
        && (string)$eventRow['bank_transaction_id'] === $reference
        && abs((float)$eventRow['amount_birr'] - 321.45) < 0.0001
        && (string)$eventRow['provenance'] === 'ERP_BANK_CONFIRMATION_AUTO'
        && (string)$eventRow['idempotency_key'] === 'BANK_CONFIRMATION:' . $confirmationId,
        'Deposit capture stores the resolved employee, shop, reference, amount and provenance'
    );

    $live = $pdo->prepare(
        "SELECT first_bank_transaction_id,total_cash_deposit_birr,SourceSystem
         FROM vw_powerbi_live_bank_transaction_detail
         WHERE company_id=2
           AND report_date=?
           AND first_bank_transaction_id=?"
    );
    $live->execute([$transactionDate, $reference]);
    $liveRow = $live->fetch(PDO::FETCH_ASSOC);

    $check(
        is_array($liveRow)
        && (string)$liveRow['first_bank_transaction_id'] === $reference
        && abs((float)$liveRow['total_cash_deposit_birr'] - 321.45) < 0.0001
        && (string)$liveRow['SourceSystem'] === 'ERP_DEPOSIT_CAPTURE',
        'Captured deposit flows into the live Power BI bank-transaction view'
    );

    $compat = $pdo->prepare(
        "SELECT COUNT(*)
         FROM vw_powerbi_compat_bank_transaction_detail
         WHERE company_id=2
           AND report_date=?
           AND SourceSystem<>'POWERBI_HISTORY'"
    );
    $compat->execute([$transactionDate]);

    $check(
        (int)$compat->fetchColumn() === 0,
        'HISTORY_ONLY prevents the new live deposit from entering compatibility output'
    );

    $captureAgain = $service->captureBankConfirmation(
        2,
        $settlementId,
        $confirmationId,
        $actorId
    );

    $sameEventStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM bi_powerbi_employee_deposit_events
         WHERE company_id=2 AND idempotency_key=?"
    );
    $sameEventStmt->execute(['BANK_CONFIRMATION:' . $confirmationId]);

    $check(
        ($captureAgain['status'] ?? '') === 'captured'
        && (int)$sameEventStmt->fetchColumn() === 1,
        'Repeated capture is idempotent and does not duplicate the deposit event'
    );

    $controlDuring = $pdo->query(
        "SELECT reporting_mode,live_cutover_date
         FROM bi_powerbi_reporting_control
         WHERE company_id=2"
    )->fetch(PDO::FETCH_ASSOC);

    $check(
        is_array($controlBefore)
        && is_array($controlDuring)
        && $controlBefore === $controlDuring
        && (string)$controlDuring['reporting_mode'] === 'HISTORY_ONLY'
        && $controlDuring['live_cutover_date'] === null,
        'Bank capture does not change Power BI cutover control'
    );

    $pdo->rollBack();

    $confirmationGone = $pdo->prepare(
        "SELECT COUNT(*) FROM bank_confirmations
         WHERE company_id=2 AND bank_reference=?"
    );
    $confirmationGone->execute([$reference]);

    $eventGone = $pdo->prepare(
        "SELECT COUNT(*) FROM bi_powerbi_employee_deposit_events
         WHERE company_id=2 AND bank_transaction_id=?"
    );
    $eventGone->execute([$reference]);

    $check(
        (int)$confirmationGone->fetchColumn() === 0
        && (int)$eventGone->fetchColumn() === 0,
        'Synthetic confirmation and deposit event are rolled back cleanly'
    );

    $auditBeforeStmt->execute();
    $auditAfter = (int)$auditBeforeStmt->fetchColumn();
    $check(
        $auditAfter === $auditBefore,
        'Synthetic bank-capture audit records are rolled back cleanly'
    );

    echo 'INFO settlement_id=' . $settlementId
        . ' warehouse_id=' . $warehouseId
        . ' employee_id=' . $employeeId
        . ' report_date=' . $transactionDate . PHP_EOL;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo 'FAIL unexpected: ' . $exception->getMessage() . PHP_EOL;
    $failed++;
}

echo PHP_EOL . ($passed + $failed) . ' checks, ' . $failed . ' failures' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
