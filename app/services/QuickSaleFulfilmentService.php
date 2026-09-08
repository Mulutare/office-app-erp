<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RepositoryFactory;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Stages Quick Sale stock back down the physical hierarchy.
 *
 * Escalation may move responsibility upward, but final fulfilment must return
 * through Regional -> District -> origin Shop. Only the origin Shop may create
 * the customer delivery used by the DSA/DSP sales report.
 */
final class QuickSaleFulfilmentService
{
    /** @return array<string,mixed>|null */
    public function stageIfAncestor(
        int $companyId,
        int $quickSaleId,
        int $actorId,
        int $sourceLocationId
    ): ?array {
        $connection = \db();
        $owns = !$connection->inTransaction();

        try {
            if ($owns) {
                $connection->beginTransaction();
            }

            $sale = $this->saleForUpdate($connection, $companyId, $quickSaleId);
            if ((string) $sale['status'] !== 'submitted' || !empty($sale['sales_order_id'])) {
                if ($owns) {
                    $connection->commit();
                }
                return null;
            }

            if ((int) $sale['manager_user_id'] !== $actorId) {
                throw new RuntimeException('Only the current responsible manager can stage this Quick Sale stock.');
            }

            $originManager = (int) ($sale['origin_manager_user_id'] ?? 0);
            $originWarehouse = (int) ($sale['origin_warehouse_id'] ?? 0);
            if ($originManager < 1 || $originWarehouse < 1) {
                throw new RuntimeException('Quick Sale origin Shop metadata is not configured.');
            }

            $source = $this->authorityForUser($connection, $companyId, $actorId, true);
            if (!is_array($source) || (int) $source['warehouse_id'] !== (int) $sale['warehouse_id']) {
                throw new RuntimeException('The current Quick Sale warehouse does not match the manager stock authority.');
            }

            if ((int) $source['location_id'] !== $sourceLocationId) {
                throw new RuntimeException('Use the stock-authority source location for staged Quick Sale fulfilment.');
            }

            if ((string) $source['authority_level'] === 'shop') {
                if ((int) $source['warehouse_id'] !== $originWarehouse || $actorId !== $originManager) {
                    throw new RuntimeException('Quick Sale fulfilment reached a Shop that is not the originating Shop.');
                }
                if ($owns) {
                    $connection->commit();
                }
                return null;
            }

            if (!in_array((string) $source['authority_level'], ['regional', 'district'], true)) {
                throw new RuntimeException('Only Regional, District or origin Shop stock can fulfil a Quick Sale.');
            }

            $existing = $this->openTransfer($connection, $companyId, $quickSaleId);
            if (is_array($existing)) {
                if ($owns) {
                    $connection->commit();
                }
                return [
                    'staged' => true,
                    'replayed' => true,
                    'transferId' => (int) $existing['transfer_id'],
                    'transferNumber' => (string) $existing['transfer_number'],
                    'status' => (string) $existing['status'],
                ];
            }

            $destination = $this->nextDownstreamAuthority(
                $connection,
                $companyId,
                $actorId,
                $originManager,
                (string) $source['authority_level']
            );
            if (!is_array($destination)) {
                throw new RuntimeException('The next downstream stock authority toward the originating Shop is not configured.');
            }

            $required = $this->requiredLines($connection, $companyId, (int) $sale['quotation_id']);
            if ($required === []) {
                throw new RuntimeException('The Quick Sale has no stockable quantity to fulfil.');
            }

            $balances = [];
            foreach ($required as $line) {
                $balance = RepositoryFactory::inventory()->stockBalanceForUpdate(
                    $companyId,
                    (int) $source['warehouse_id'],
                    $sourceLocationId,
                    (int) $line['product_id']
                );
                $available = max(
                    0.0,
                    (float) ($balance['quantity_on_hand'] ?? 0)
                    - (float) ($balance['quantity_reserved'] ?? 0)
                );
                if ($available + 0.0005 < (float) $line['quantity']) {
                    throw new RuntimeException('Insufficient available stock at this source. Escalate the same request instead.');
                }
                $balances[(int) $line['product_id']] = $balance;
            }

            $operation = $connection->prepare(
                "SELECT operation_type_id
                 FROM inventory_operation_types
                 WHERE company_id=:company_id
                   AND warehouse_id=:warehouse_id
                   AND operation_kind='internal_transfer'
                   AND active=TRUE AND is_default=TRUE
                 LIMIT 1"
            );
            $operation->execute([
                'company_id' => $companyId,
                'warehouse_id' => (int) $source['warehouse_id'],
            ]);
            $operationId = (int) $operation->fetchColumn();
            if ($operationId < 1) {
                throw new RuntimeException('The source warehouse internal-transfer operation is not configured.');
            }

            $number = 'TRF-QS-' . date('Ymd') . '-'
                . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
            $reason = 'Quick Sale staged fulfilment #' . $quickSaleId;

            /*
             * System-generated replenishment follows the same pattern as the
             * existing Stock Request flow: the DSA/DSP is the originating maker
             * and the supplying manager is the independent approver. This leaves
             * the transfer ready for the source owner to dispatch, without
             * bypassing dispatch/receipt custody.
             */
            $header = $connection->prepare(
                "INSERT INTO inventory_transfers(
                    company_id,source_warehouse_id,destination_warehouse_id,
                    operation_type_id,transfer_number,transfer_date,status,
                    notes,reason,created_by,submitted_by,submitted_at,
                    approved_by,approved_at
                 ) VALUES(
                    :company_id,:source_warehouse_id,:destination_warehouse_id,
                    :operation_type_id,:transfer_number,CURRENT_DATE,'approved',
                    :notes,:reason,:created_by,:submitted_by,NOW(),
                    :approved_by,NOW()
                 )"
            );
            $header->execute([
                'company_id' => $companyId,
                'source_warehouse_id' => (int) $source['warehouse_id'],
                'destination_warehouse_id' => (int) $destination['warehouse_id'],
                'operation_type_id' => $operationId,
                'transfer_number' => $number,
                'notes' => $reason,
                'reason' => $reason,
                'created_by' => (int) $sale['user_id'],
                'submitted_by' => (int) $sale['user_id'],
                'approved_by' => $actorId,
            ]);
            $transferId = (int) $connection->lastInsertId();

            $insert = $connection->prepare(
                "INSERT INTO inventory_transfer_lines(
                    company_id,transfer_id,source_warehouse_id,source_location_id,
                    destination_warehouse_id,destination_location_id,product_id,
                    quantity,unit_cost,notes
                 ) VALUES(
                    :company_id,:transfer_id,:source_warehouse_id,:source_location_id,
                    :destination_warehouse_id,:destination_location_id,:product_id,
                    :quantity,:unit_cost,:notes
                 )"
            );
            $link = $connection->prepare(
                "INSERT INTO sales_quick_sale_transfer_links(
                    company_id,quick_sale_id,transfer_line_id,state
                 ) VALUES(:company_id,:quick_sale_id,:transfer_line_id,'reserved')"
            );

            foreach ($required as $line) {
                $productId = (int) $line['product_id'];
                $quantity = round((float) $line['quantity'], 3);
                $balance = $balances[$productId];

                $insert->execute([
                    'company_id' => $companyId,
                    'transfer_id' => $transferId,
                    'source_warehouse_id' => (int) $source['warehouse_id'],
                    'source_location_id' => $sourceLocationId,
                    'destination_warehouse_id' => (int) $destination['warehouse_id'],
                    'destination_location_id' => (int) $destination['location_id'],
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_cost' => (float) ($balance['average_unit_cost'] ?? 0),
                    'notes' => $reason,
                ]);
                $transferLineId = (int) $connection->lastInsertId();

                RepositoryFactory::inventory()->changeReplenishmentReservation(
                    $companyId,
                    (int) $source['warehouse_id'],
                    $sourceLocationId,
                    $productId,
                    $quantity
                );

                $link->execute([
                    'company_id' => $companyId,
                    'quick_sale_id' => $quickSaleId,
                    'transfer_line_id' => $transferLineId,
                ]);
            }

            $connection->prepare(
                "UPDATE sales_quick_sales
                 SET fulfilment_state='awaiting_transfer'
                 WHERE company_id=:company_id AND quick_sale_id=:quick_sale_id
                   AND status='submitted'"
            )->execute([
                'company_id' => $companyId,
                'quick_sale_id' => $quickSaleId,
            ]);

            RepositoryFactory::auditLogs()->record(
                $actorId,
                'quick_sale.downstream_transfer_created',
                'sales',
                'sales_quick_sales',
                (string) $quickSaleId,
                null,
                [
                    'transfer_id' => $transferId,
                    'transfer_number' => $number,
                    'from_warehouse_id' => (int) $source['warehouse_id'],
                    'to_warehouse_id' => (int) $destination['warehouse_id'],
                    'to_manager_user_id' => (int) $destination['user_id'],
                ],
                $companyId
            );

            if ($owns) {
                $connection->commit();
            }

            return [
                'staged' => true,
                'replayed' => false,
                'transferId' => $transferId,
                'transferNumber' => $number,
                'status' => 'approved',
                'destinationManagerId' => (int) $destination['user_id'],
                'destinationWarehouseId' => (int) $destination['warehouse_id'],
            ];
        } catch (Throwable $exception) {
            if ($owns && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    /** Must be called inside the Inventory transfer transaction. */
    public function transferTransition(int $companyId, int $transferId, string $action): void
    {
        $connection = \db();
        if (!$connection->inTransaction()) {
            throw new RuntimeException('Quick Sale transfer callbacks require the Inventory transaction.');
        }

        $statement = $connection->prepare(
            "SELECT x.quick_sale_id,x.state,l.*,t.destination_warehouse_id
             FROM sales_quick_sale_transfer_links x
             INNER JOIN inventory_transfer_lines l
               ON l.company_id=x.company_id
              AND l.transfer_line_id=x.transfer_line_id
             INNER JOIN inventory_transfers t
               ON t.company_id=l.company_id
              AND t.transfer_id=l.transfer_id
             WHERE x.company_id=:company_id AND l.transfer_id=:transfer_id
             ORDER BY l.transfer_line_id
             FOR UPDATE"
        );
        $statement->execute([
            'company_id' => $companyId,
            'transfer_id' => $transferId,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return;
        }

        $saleIds = [];
        foreach ($rows as $line) {
            $saleIds[(int) $line['quick_sale_id']] = true;
            $state = (string) $line['state'];

            if (($action === 'dispatch' || $action === 'cancel') && $state === 'reserved') {
                RepositoryFactory::inventory()->changeReplenishmentReservation(
                    $companyId,
                    (int) $line['source_warehouse_id'],
                    (int) $line['source_location_id'],
                    (int) $line['product_id'],
                    -(float) $line['quantity']
                );
                $state = $action === 'dispatch' ? 'in_transit' : 'released';
            } elseif ($action === 'receive' && $state === 'in_transit') {
                $state = 'received';
            }

            $connection->prepare(
                'UPDATE sales_quick_sale_transfer_links
                 SET state=:state
                 WHERE company_id=:company_id AND transfer_line_id=:transfer_line_id'
            )->execute([
                'state' => $state,
                'company_id' => $companyId,
                'transfer_line_id' => (int) $line['transfer_line_id'],
            ]);
        }

        foreach (array_keys($saleIds) as $quickSaleId) {
            if ($action === 'cancel') {
                $connection->prepare(
                    "UPDATE sales_quick_sales
                     SET fulfilment_state='at_authority'
                     WHERE company_id=:company_id AND quick_sale_id=:quick_sale_id
                       AND status='submitted'"
                )->execute([
                    'company_id' => $companyId,
                    'quick_sale_id' => $quickSaleId,
                ]);
                continue;
            }

            if ($action !== 'receive') {
                continue;
            }

            $open = $connection->prepare(
                "SELECT COUNT(*)
                 FROM sales_quick_sale_transfer_links x
                 INNER JOIN inventory_transfer_lines l
                   ON l.company_id=x.company_id
                  AND l.transfer_line_id=x.transfer_line_id
                 WHERE x.company_id=:company_id
                   AND x.quick_sale_id=:quick_sale_id
                   AND l.transfer_id=:transfer_id
                   AND x.state<>'received'"
            );
            $open->execute([
                'company_id' => $companyId,
                'quick_sale_id' => $quickSaleId,
                'transfer_id' => $transferId,
            ]);
            if ((int) $open->fetchColumn() > 0) {
                continue;
            }

            $destinationWarehouse = (int) $rows[0]['destination_warehouse_id'];
            $destination = $this->authorityForWarehouse(
                $connection,
                $companyId,
                $destinationWarehouse
            );
            if (!is_array($destination)) {
                throw new RuntimeException('The received Quick Sale destination warehouse has no active stock authority.');
            }

            $sale = $this->saleForUpdate($connection, $companyId, $quickSaleId);
            $originWarehouse = (int) ($sale['origin_warehouse_id'] ?? 0);
            $originManager = (int) ($sale['origin_manager_user_id'] ?? 0);
            $atOrigin = $destinationWarehouse === $originWarehouse
                && (int) $destination['user_id'] === $originManager;

            $connection->prepare(
                "UPDATE sales_quick_sales
                 SET manager_user_id=:manager_user_id,
                     warehouse_id=:warehouse_id,
                     fulfilment_state=:fulfilment_state
                 WHERE company_id=:company_id AND quick_sale_id=:quick_sale_id
                   AND status='submitted'"
            )->execute([
                'manager_user_id' => (int) $destination['user_id'],
                'warehouse_id' => $destinationWarehouse,
                'fulfilment_state' => $atOrigin ? 'at_origin' : 'at_authority',
                'company_id' => $companyId,
                'quick_sale_id' => $quickSaleId,
            ]);

            RepositoryFactory::auditLogs()->record(
                (int) $destination['user_id'],
                'quick_sale.downstream_transfer_received',
                'sales',
                'sales_quick_sales',
                (string) $quickSaleId,
                null,
                [
                    'transfer_id' => $transferId,
                    'manager_user_id' => (int) $destination['user_id'],
                    'warehouse_id' => $destinationWarehouse,
                    'at_origin_shop' => $atOrigin,
                ],
                $companyId
            );
        }
    }

    /** @return array<string,mixed>|null */
    public function openTransferForSale(int $companyId, int $quickSaleId): ?array
    {
        return $this->openTransfer(\db(), $companyId, $quickSaleId);
    }

    /** @return array<string,mixed> */
    private function saleForUpdate(PDO $connection, int $companyId, int $quickSaleId): array
    {
        $statement = $connection->prepare(
            "SELECT qs.*,q.sales_order_id
             FROM sales_quick_sales qs
             INNER JOIN sales_quotations q
               ON q.company_id=qs.company_id AND q.quotation_id=qs.quotation_id
             WHERE qs.company_id=:company_id AND qs.quick_sale_id=:quick_sale_id
             FOR UPDATE"
        );
        $statement->execute([
            'company_id' => $companyId,
            'quick_sale_id' => $quickSaleId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Quick Sale was not found.');
        }
        return $row;
    }

    /** @return list<array{product_id:int,quantity:float}> */
    private function requiredLines(PDO $connection, int $companyId, int $quotationId): array
    {
        $statement = $connection->prepare(
            'SELECT product_id,SUM(quantity) quantity
             FROM sales_quotation_lines
             WHERE company_id=:company_id AND quotation_id=:quotation_id
             GROUP BY product_id
             HAVING SUM(quantity)>0.0005
             ORDER BY product_id'
        );
        $statement->execute([
            'company_id' => $companyId,
            'quotation_id' => $quotationId,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return array_map(
            static fn (array $row): array => [
                'product_id' => (int) $row['product_id'],
                'quantity' => (float) $row['quantity'],
            ],
            is_array($rows) ? $rows : []
        );
    }

    /** @return array<string,mixed>|null */
    private function openTransfer(PDO $connection, int $companyId, int $quickSaleId): ?array
    {
        $statement = $connection->prepare(
            "SELECT DISTINCT t.transfer_id,t.transfer_number,t.status
             FROM sales_quick_sale_transfer_links x
             INNER JOIN inventory_transfer_lines l
               ON l.company_id=x.company_id AND l.transfer_line_id=x.transfer_line_id
             INNER JOIN inventory_transfers t
               ON t.company_id=l.company_id AND t.transfer_id=l.transfer_id
             WHERE x.company_id=:company_id AND x.quick_sale_id=:quick_sale_id
               AND x.state IN('reserved','in_transit')
               AND t.status IN('draft','submitted','approved','in_transit')
             ORDER BY t.transfer_id DESC
             LIMIT 1"
        );
        $statement->execute([
            'company_id' => $companyId,
            'quick_sale_id' => $quickSaleId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function authorityForUser(PDO $connection, int $companyId, int $userId, bool $forUpdate = false): ?array
    {
        $sql = "SELECT a.*
                FROM inventory_stock_authorities a
                INNER JOIN inventory_warehouses w
                  ON w.company_id=a.company_id AND w.warehouse_id=a.warehouse_id
                INNER JOIN inventory_warehouse_locations l
                  ON l.company_id=a.company_id AND l.warehouse_id=a.warehouse_id
                 AND l.location_id=a.location_id
                WHERE a.company_id=:company_id AND a.user_id=:user_id
                  AND a.active=TRUE AND w.active=TRUE AND w.deleted_at IS NULL
                  AND l.active=TRUE AND l.deleted_at IS NULL
                LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
        $statement = $connection->prepare($sql);
        $statement->execute(['company_id' => $companyId, 'user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function authorityForWarehouse(PDO $connection, int $companyId, int $warehouseId): ?array
    {
        $statement = $connection->prepare(
            "SELECT a.*
             FROM inventory_stock_authorities a
             INNER JOIN company_users cu
               ON cu.company_id=a.company_id AND cu.user_id=a.user_id AND cu.active=TRUE
             WHERE a.company_id=:company_id AND a.warehouse_id=:warehouse_id
               AND a.active=TRUE
             ORDER BY a.authority_id
             LIMIT 2"
        );
        $statement->execute([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            return null;
        }
        return $rows[0];
    }

    /** @return array<string,mixed>|null */
    private function nextDownstreamAuthority(
        PDO $connection,
        int $companyId,
        int $currentUserId,
        int $originManagerUserId,
        string $currentLevel
    ): ?array {
        $expected = [
            'regional' => 'district',
            'district' => 'shop',
        ][$currentLevel] ?? null;
        if ($expected === null) {
            return null;
        }

        $parents = $connection->prepare(
            'SELECT user_id,manager_user_id
             FROM company_users
             WHERE company_id=:company_id AND active=TRUE'
        );
        $parents->execute(['company_id' => $companyId]);
        $map = array_column(
            $parents->fetchAll(PDO::FETCH_ASSOC),
            'manager_user_id',
            'user_id'
        );

        $cursor = $originManagerUserId;
        $child = 0;
        $seen = [];
        while ($cursor > 0 && $cursor !== $currentUserId) {
            if (isset($seen[$cursor]) || !array_key_exists($cursor, $map)) {
                throw new RuntimeException('The Quick Sale reporting hierarchy contains a cycle or inactive manager.');
            }
            $seen[$cursor] = true;
            $child = $cursor;
            $cursor = (int) $map[$cursor];
        }

        if ($cursor !== $currentUserId || $child < 1) {
            return null;
        }

        $authority = $this->authorityForUser($connection, $companyId, $child);
        if (!is_array($authority) || (string) $authority['authority_level'] !== $expected) {
            return null;
        }
        return $authority;
    }
}
