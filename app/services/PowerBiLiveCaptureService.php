<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RepositoryFactory;
use PDO;
use RuntimeException;

final class PowerBiLiveCaptureService
{
    /** Fields that the ERP application is allowed to capture directly. */
    public const DAILY_FIELDS = [
        'manager_reported_deposit_birr',
        'reward_sim_cards_pieces',
        'incentive_sim_cards_birr',
        'float_airtime_incentive_birr',
        'total_float_returned_birr',
        'safaricom_sim_value_borrowed_birr',
        'safaricom_mifi_value_borrowed_pcs',
        'qty_returned_birr_10_pieces',
        'qty_returned_birr_15_pieces',
        'qty_returned_birr_20_pieces',
        'qty_returned_birr_25_pieces',
        'qty_returned_birr_50_pieces',
        'qty_returned_birr_100_pieces',
    ];

    /**
     * These two fields remain intentionally locked until their business meaning
     * is proven equivalent to the ERP Safaricom incentive workflow.
     */
    public const LOCKED_SEMANTIC_FIELDS = [
        'float_incentive_to_safaricom_birr',
        'float_incentive_refund_from_safaricom_birr',
    ];

    public function __construct(private ?TenantContext $tenant = null)
    {
        $this->tenant ??= new TenantContext();
    }

    /** @return array<string,mixed> */
    public function pageState(int $actorId, array $input = []): array
    {
        $companyId = $this->tenant->companyId();
        $warehouses = $this->warehousesForActor($companyId, $actorId);
        if ($warehouses === []) {
            throw new RuntimeException('No active shop is available in your reporting scope.');
        }

        $allowed = array_map(static fn(array $row): int => (int)$row['warehouse_id'], $warehouses);
        $warehouseId = (int)($input['warehouse_id'] ?? 0);
        if (!in_array($warehouseId, $allowed, true)) {
            $warehouseId = $allowed[0];
        }

        $date = trim((string)($input['date'] ?? date('Y-m-d')));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $date = date('Y-m-d');
        }

        return [
            'warehouses' => $warehouses,
            'selectedWarehouseId' => $warehouseId,
            'selectedDate' => $date,
            'metric' => $this->metric($companyId, $warehouseId, $date),
            'recentMetrics' => $this->recentMetrics($companyId, $allowed),
            'reportingControl' => $this->reportingControl($companyId),
        ];
    }

    /** @return array{successful:bool,errors?:array<string,string>} */
    public function saveDailyMetrics(array $input, int $actorId): array
    {
        try {
            $companyId = $this->tenant->companyId();
            $warehouseId = (int)($input['warehouse_id'] ?? 0);
            $date = trim((string)($input['report_date'] ?? ''));
            if ($warehouseId < 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new RuntimeException('Choose a valid shop and report date.');
            }
            if ($date > date('Y-m-d')) {
                throw new RuntimeException('A daily shop report cannot be dated in the future.');
            }

            $allowed = array_map(
                static fn(array $row): int => (int)$row['warehouse_id'],
                $this->warehousesForActor($companyId, $actorId)
            );
            if (!in_array($warehouseId, $allowed, true)) {
                throw new RuntimeException('The selected shop is outside your reporting scope.');
            }

            $values = [];
            $hasMetric = false;
            foreach (self::DAILY_FIELDS as $field) {
                $raw = trim((string)($input[$field] ?? ''));
                if ($raw === '') {
                    $values[$field] = null;
                    continue;
                }
                if (!is_numeric($raw) || (float)$raw < 0) {
                    throw new RuntimeException('Daily metric values must be zero or positive numbers.');
                }
                $values[$field] = number_format((float)$raw, 4, '.', '');
                $hasMetric = true;
            }

            $notes = trim((string)($input['notes'] ?? ''));
            if (mb_strlen($notes) > 1000) {
                throw new RuntimeException('Notes cannot exceed 1,000 characters.');
            }
            if (!$hasMetric && $notes === '') {
                throw new RuntimeException('Enter at least one daily metric or a note.');
            }

            $columns = implode(',', self::DAILY_FIELDS);
            $placeholders = implode(',', array_map(static fn(string $field): string => ':' . $field, self::DAILY_FIELDS));
            $updates = implode(',', array_map(
                static fn(string $field): string => $field . '=VALUES(' . $field . ')',
                self::DAILY_FIELDS
            ));

            $sql = "INSERT INTO bi_powerbi_shop_daily_metrics (
                        company_id,report_date,warehouse_id,$columns,
                        source_reference,provenance,notes
                    ) VALUES (
                        :company_id,:report_date,:warehouse_id,$placeholders,
                        :source_reference,'ERP_APP_DAILY_CAPTURE',:notes
                    ) ON DUPLICATE KEY UPDATE
                        $updates,
                        source_reference=VALUES(source_reference),
                        provenance=VALUES(provenance),
                        notes=VALUES(notes)";

            $params = [
                'company_id' => $companyId,
                'report_date' => $date,
                'warehouse_id' => $warehouseId,
                'source_reference' => sprintf('ERP_DAILY_SHOP_CAPTURE:%d:%s', $warehouseId, $date),
                'notes' => $notes !== '' ? $notes : null,
            ] + $values;

            $statement = \db()->prepare($sql);
            $statement->execute($params);

            RepositoryFactory::auditLogs()->record(
                $actorId,
                'powerbi.shop_daily_metrics.saved',
                'sales',
                'bi_powerbi_shop_daily_metrics',
                $warehouseId . ':' . $date,
                null,
                [
                    'warehouse_id' => $warehouseId,
                    'report_date' => $date,
                    'captured_fields' => array_keys(array_filter(
                        $values,
                        static fn(mixed $value): bool => $value !== null
                    )),
                ],
                $companyId
            );

            return ['successful' => true];
        } catch (\Throwable $exception) {
            return [
                'successful' => false,
                'errors' => ['form' => $exception->getMessage()],
            ];
        }
    }

    /**
     * Capture a bank confirmation only when every settlement line resolves to
     * one employee and one warehouse. Ambiguous settlements are deliberately
     * skipped instead of fabricating attribution.
     *
     * @return array{status:string,reason?:string,deposit_event_id?:int}
     */
    public function captureBankConfirmation(
        int $companyId,
        int $settlementId,
        int $confirmationId,
        int $actorId
    ): array {
        $connection = \db();
        $confirmation = $connection->prepare(
            'SELECT bc.confirmation_id,bc.bank_reference,bc.transaction_date,bc.confirmed_amount,bc.currency,
                    s.settlement_number
             FROM bank_confirmations bc
             INNER JOIN sales_settlements s
               ON s.company_id=bc.company_id AND s.settlement_id=bc.settlement_id
             WHERE bc.company_id=:company_id
               AND bc.settlement_id=:settlement_id
               AND bc.confirmation_id=:confirmation_id'
        );
        $confirmation->execute([
            'company_id' => $companyId,
            'settlement_id' => $settlementId,
            'confirmation_id' => $confirmationId,
        ]);
        $bank = $confirmation->fetch(PDO::FETCH_ASSOC);
        if (!is_array($bank)) {
            return ['status' => 'skipped', 'reason' => 'confirmation_not_found'];
        }
        if (strtoupper((string)$bank['currency']) !== 'ETB') {
            return ['status' => 'skipped', 'reason' => 'non_etb_confirmation'];
        }

        $scope = $this->bankConfirmationAttribution($companyId, $settlementId);
        if (($scope['status'] ?? '') !== 'resolved') {
            return ['status' => 'skipped', 'reason' => (string)($scope['reason'] ?? 'ambiguous_attribution')];
        }

        $idempotency = 'BANK_CONFIRMATION:' . $confirmationId;
        $insert = $connection->prepare(
            "INSERT INTO bi_powerbi_employee_deposit_events (
                company_id,report_date,warehouse_id,employee_id,bank_transaction_id,
                amount_birr,source_reference,idempotency_key,provenance,notes
             ) VALUES (
                :company_id,:report_date,:warehouse_id,:employee_id,:bank_transaction_id,
                :amount_birr,:source_reference,:idempotency_key,'ERP_BANK_CONFIRMATION_AUTO',:notes
             ) ON DUPLICATE KEY UPDATE
                report_date=VALUES(report_date),
                warehouse_id=VALUES(warehouse_id),
                employee_id=VALUES(employee_id),
                bank_transaction_id=VALUES(bank_transaction_id),
                amount_birr=VALUES(amount_birr),
                source_reference=VALUES(source_reference),
                provenance=VALUES(provenance),
                notes=VALUES(notes)"
        );
        $insert->execute([
            'company_id' => $companyId,
            'report_date' => (string)$bank['transaction_date'],
            'warehouse_id' => (int)$scope['warehouse_id'],
            'employee_id' => (int)$scope['employee_id'],
            'bank_transaction_id' => (string)$bank['bank_reference'],
            'amount_birr' => number_format((float)$bank['confirmed_amount'], 4, '.', ''),
            'source_reference' => (string)$bank['settlement_number'],
            'idempotency_key' => $idempotency,
            'notes' => 'Automatically captured from an unambiguous ERP settlement bank confirmation.',
        ]);

        $lookup = $connection->prepare(
            'SELECT deposit_event_id FROM bi_powerbi_employee_deposit_events
             WHERE company_id=:company_id AND idempotency_key=:idempotency_key'
        );
        $lookup->execute([
            'company_id' => $companyId,
            'idempotency_key' => $idempotency,
        ]);
        $eventId = (int)$lookup->fetchColumn();

        RepositoryFactory::auditLogs()->record(
            $actorId,
            'powerbi.employee_deposit.auto_captured',
            'sales_settlements',
            'bi_powerbi_employee_deposit_events',
            (string)$eventId,
            null,
            [
                'settlement_id' => $settlementId,
                'confirmation_id' => $confirmationId,
                'warehouse_id' => (int)$scope['warehouse_id'],
                'employee_id' => (int)$scope['employee_id'],
                'bank_reference' => (string)$bank['bank_reference'],
                'amount_birr' => (float)$bank['confirmed_amount'],
            ],
            $companyId
        );

        return ['status' => 'captured', 'deposit_event_id' => $eventId];
    }

    /** @return array<string,mixed> */
    public function bankConfirmationAttribution(int $companyId, int $settlementId): array
    {
        $statement = \db()->prepare(
            'SELECT COUNT(*) AS line_count,
                    COUNT(DISTINCT sl.sales_order_id) AS order_count,
                    SUM(CASE WHEN o.warehouse_id IS NULL OR a.employee_id IS NULL THEN 1 ELSE 0 END) AS unresolved_lines,
                    COUNT(DISTINCT o.warehouse_id) AS warehouse_count,
                    COUNT(DISTINCT a.employee_id) AS employee_count,
                    MIN(o.warehouse_id) AS warehouse_id,
                    MIN(a.employee_id) AS employee_id
             FROM sales_settlement_lines sl
             INNER JOIN sales_orders o
               ON o.company_id=sl.company_id AND o.order_id=sl.sales_order_id
             LEFT JOIN sales_agents a
               ON a.company_id=o.company_id AND a.agent_id=o.agent_id
             WHERE sl.company_id=:company_id AND sl.settlement_id=:settlement_id'
        );
        $statement->execute([
            'company_id' => $companyId,
            'settlement_id' => $settlementId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int)$row['line_count'] < 1) {
            return ['status' => 'unresolved', 'reason' => 'settlement_has_no_lines'];
        }
        if ((int)$row['unresolved_lines'] > 0) {
            return ['status' => 'unresolved', 'reason' => 'missing_order_employee_or_warehouse'];
        }
        if ((int)$row['warehouse_count'] !== 1 || (int)$row['employee_count'] !== 1) {
            return ['status' => 'unresolved', 'reason' => 'multiple_employee_or_shop_attribution'];
        }

        return [
            'status' => 'resolved',
            'warehouse_id' => (int)$row['warehouse_id'],
            'employee_id' => (int)$row['employee_id'],
            'line_count' => (int)$row['line_count'],
            'order_count' => (int)$row['order_count'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function warehousesForActor(int $companyId, int $actorId): array
    {
        $scope = new SalesHierarchyScope();
        if ($scope->hasCompanyWideAccess($companyId, $actorId)) {
            $statement = \db()->prepare(
                'SELECT warehouse_id,code,name
                 FROM inventory_warehouses
                 WHERE company_id=:company_id AND active=TRUE AND deleted_at IS NULL
                 ORDER BY name,warehouse_id'
            );
            $statement->execute(['company_id' => $companyId]);
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        $statement = \db()->prepare(
            'SELECT DISTINCT w.warehouse_id,w.code,w.name
             FROM inventory_user_warehouse_access wa
             INNER JOIN inventory_warehouses w
               ON w.company_id=wa.company_id AND w.warehouse_id=wa.warehouse_id
             WHERE wa.company_id=:company_id
               AND wa.user_id=:actor_id
               AND wa.active=TRUE
               AND w.active=TRUE
               AND w.deleted_at IS NULL
             ORDER BY w.name,w.warehouse_id'
        );
        $statement->execute([
            'company_id' => $companyId,
            'actor_id' => $actorId,
        ]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    private function metric(int $companyId, int $warehouseId, string $date): ?array
    {
        $statement = \db()->prepare(
            'SELECT * FROM bi_powerbi_shop_daily_metrics
             WHERE company_id=:company_id AND warehouse_id=:warehouse_id AND report_date=:report_date'
        );
        $statement->execute([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'report_date' => $date,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function recentMetrics(int $companyId, array $warehouseIds): array
    {
        if ($warehouseIds === []) return [];
        $placeholders = implode(',', array_fill(0, count($warehouseIds), '?'));
        $statement = \db()->prepare(
            "SELECT m.report_date,m.warehouse_id,w.name AS shop_name,
                    m.manager_reported_deposit_birr,m.reward_sim_cards_pieces,
                    m.incentive_sim_cards_birr,m.float_airtime_incentive_birr,
                    m.total_float_returned_birr,m.updated_at
             FROM bi_powerbi_shop_daily_metrics m
             INNER JOIN inventory_warehouses w
               ON w.company_id=m.company_id AND w.warehouse_id=m.warehouse_id
             WHERE m.company_id=? AND m.warehouse_id IN ($placeholders)
             ORDER BY m.report_date DESC,m.updated_at DESC
             LIMIT 30"
        );
        $statement->execute(array_merge([$companyId], $warehouseIds));
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    private function reportingControl(int $companyId): array
    {
        $statement = \db()->prepare(
            'SELECT reporting_mode,live_cutover_date,notes
             FROM bi_powerbi_reporting_control WHERE company_id=:company_id'
        );
        $statement->execute(['company_id' => $companyId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }
}
