<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RepositoryFactory;
use PDO;
use RuntimeException;
use Throwable;

final class StockRequestService
{
    private TenantContext $tenant;

    public function __construct(?TenantContext $tenant = null)
    {
        $this->tenant = $tenant ?? new TenantContext();
    }

    /** @return array<string,mixed> */
    public function workspace(int $actorId, ?int $requestId = null): array
    {
        $companyId = $this->tenant->companyId();
        try {
            $actor = $this->employeeContext($companyId, $actorId);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'The signed-in company user is not linked to an active HR employee record.') {
                throw $exception;
            }

            // Workspace viewing does not require an HR employee link.
            // Employee workflow actions remain protected by employeeContext().
            $actor = [
                'user_id' => $actorId,
                'manager_user_id' => null,
                'employee_id' => null,
                'department_id' => null,
                'employee_number' => null,
                'first_name' => null,
                'middle_name' => null,
                'last_name' => null,
                'legacy_job_title' => null,
                'job_title' => null,
            ];
        }
        $visibleRequesterIds = $this->visibleRequesterIds($companyId, $actorId);
        $manageAuthorities = $this->actorCan($companyId, $actorId, 'inventory.stock_authorities.manage');

        $where = ['r.company_id=:company_id'];
        $parameters = ['company_id' => $companyId];
        if (!$manageAuthorities) {
            $scope = $visibleRequesterIds;
            if ($scope === []) $scope = [$actorId];
            $placeholders = [];
            foreach (array_values(array_unique($scope)) as $i => $userId) {
                $key = 'scope_' . $i;
                $placeholders[] = ':' . $key;
                $parameters[$key] = $userId;
            }
            $where[] = '(r.requester_user_id IN(' . implode(',', $placeholders) . ')
                OR r.current_handler_user_id=:scope_actor
                OR EXISTS(
                    SELECT 1 FROM inventory_stock_request_allocations sa
                    INNER JOIN inventory_stock_authorities au
                      ON au.company_id=sa.company_id AND au.authority_id=sa.authority_id
                    WHERE sa.company_id=r.company_id AND sa.request_id=r.request_id
                      AND au.user_id=:scope_actor_two
                ))';
            $parameters['scope_actor'] = $actorId;
            $parameters['scope_actor_two'] = $actorId;
        }
        if ($requestId !== null) {
            $where[] = 'r.request_id=:request_id';
            $parameters['request_id'] = $requestId;
        }

        $sql = "SELECT r.*,
                       COALESCE(NULLIF(TRIM(CONCAT(e.first_name,' ',COALESCE(e.middle_name,''),' ',e.last_name)),''),u.display_name) requester_name,
                       h.display_name current_handler_name,
                       sa.authority_level serving_level,
                       sw.name serving_warehouse_name,sl.name serving_location_name,
                       COALESCE((SELECT SUM(l.requested_quantity) FROM inventory_stock_request_lines l
                                 WHERE l.company_id=r.company_id AND l.request_id=r.request_id),0) requested_quantity,
                       COALESCE((SELECT SUM(a.quantity) FROM inventory_stock_request_allocations a
                                 WHERE a.company_id=r.company_id AND a.request_id=r.request_id AND a.status<>'released'),0) allocated_quantity,
                       COALESCE((SELECT SUM(a.quantity) FROM inventory_stock_request_allocations a
                                 WHERE a.company_id=r.company_id AND a.request_id=r.request_id AND a.status='shop_reserved'),0) ready_quantity
                FROM inventory_stock_requests r
                INNER JOIN users u ON u.user_id=r.requester_user_id
                LEFT JOIN hr_employees e ON e.company_id=r.company_id AND e.employee_id=r.requester_employee_id
                LEFT JOIN users h ON h.user_id=r.current_handler_user_id
                INNER JOIN inventory_stock_authorities sa ON sa.company_id=r.company_id AND sa.authority_id=r.serving_authority_id
                INNER JOIN inventory_warehouses sw ON sw.company_id=sa.company_id AND sw.warehouse_id=sa.warehouse_id
                INNER JOIN inventory_warehouse_locations sl ON sl.company_id=sa.company_id AND sl.warehouse_id=sa.warehouse_id AND sl.location_id=sa.location_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY r.request_id DESC";
        $statement = \db()->prepare($sql);
        $statement->execute($parameters);
        $requests = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($requests)) $requests = [];

        $request = null;
        if ($requestId !== null) {
            $request = $requests[0] ?? null;
            if (is_array($request)) {
                $request['lines'] = $this->requestLines($companyId, $requestId);
                $request['allocations'] = $this->requestAllocations($companyId, $requestId);
                $request['procurements'] = $this->requestProcurements($companyId, $requestId);
            }
        }

        $authority = $this->authorityForUser($companyId, $actorId);
        $role = $this->roleFromTitle((string) ($actor['job_title'] ?? ''));
        $canCreate = in_array($role, ['dsa', 'dsp'], true);
        $canProcess = is_array($authority) && in_array($role, ['shop', 'district', 'regional'], true);
        $canManageReorder = is_array($authority)
            && $role === 'regional'
            && ($authority['authority_level'] ?? '') === 'regional';

        return [
            'stockRequests' => $requestId === null ? $requests : $this->listVisibleRequests($companyId, $actorId, $manageAuthorities, $visibleRequesterIds),
            'stockRequest' => $request,
            'stockRequestProducts' => $this->stockableProducts($companyId),
            'stockRequestActor' => $actor,
            'stockRequestActorRole' => $role,
            'stockRequestAuthority' => $authority,
            'canCreateStockRequest' => $canCreate,
            'canProcessStockRequest' => $canProcess,
            'canManageStockAuthorities' => $manageAuthorities,
            'canManageReorderThresholds' => $canManageReorder,
            'stockAuthorities' => $manageAuthorities ? $this->authorities($companyId) : [],
            'stockAuthorityCandidates' => $manageAuthorities ? $this->authorityCandidates($companyId) : [],
            'stockAuthorityWarehouses' => $manageAuthorities ? $this->warehousesAndLocations($companyId) : [],
            'regionalReorder' => $canManageReorder ? $this->regionalReorderWorkspace($companyId, $authority) : [],
        ];
    }

    public function createRequest(array $input, int $actorId): int
    {
        $companyId = $this->tenant->companyId();
        $actor = $this->employeeContext($companyId, $actorId);
        $role = $this->roleFromTitle((string) ($actor['job_title'] ?? ''));
        if (!in_array($role, ['dsa', 'dsp'], true)) {
            throw new RuntimeException('Only employees whose current HR Job Title is DSA or DSP can create a stock request.');
        }
        $managerId = (int) ($actor['manager_user_id'] ?? 0);
        if ($managerId < 1) {
            throw new RuntimeException('Your direct reporting manager is not configured.');
        }
        $servingAuthority = $this->authorityForUser($companyId, $managerId);
        if (!is_array($servingAuthority) || ($servingAuthority['authority_level'] ?? '') !== 'shop') {
            throw new RuntimeException('Your direct Shop Manager does not have an active represented stock location.');
        }
        $lines = $this->normalizeRequestLines($input);
        if ($lines === []) {
            throw new RuntimeException('Add at least one product with a positive requested quantity.');
        }
        $this->assertStockableProducts($companyId, array_keys($lines));
        $notes = trim((string) ($input['notes'] ?? '')) ?: null;

        $connection = \db();
        $connection->beginTransaction();
        try {
            $number = $this->nextRequestNumber($connection, $companyId);
            $insert = $connection->prepare(
                "INSERT INTO inventory_stock_requests(
                    company_id,request_number,requester_user_id,requester_employee_id,
                    requester_role_snapshot,serving_authority_id,current_handler_user_id,status,notes,requested_at
                 ) VALUES(
                    :company_id,:request_number,:requester_user_id,:requester_employee_id,
                    :requester_role_snapshot,:serving_authority_id,:current_handler_user_id,'pending_review',:notes,NOW()
                 )"
            );
            $insert->execute([
                'company_id' => $companyId,
                'request_number' => $number,
                'requester_user_id' => $actorId,
                'requester_employee_id' => (int) $actor['employee_id'],
                'requester_role_snapshot' => (string) $actor['job_title'],
                'serving_authority_id' => (int) $servingAuthority['authority_id'],
                'current_handler_user_id' => $managerId,
                'notes' => $notes,
            ]);
            $requestId = (int) $connection->lastInsertId();
            $lineInsert = $connection->prepare(
                "INSERT INTO inventory_stock_request_lines(
                    company_id,request_id,product_id,requested_quantity,notes
                 ) VALUES(:company_id,:request_id,:product_id,:quantity,:notes)"
            );
            foreach ($lines as $productId => $quantity) {
                $lineInsert->execute([
                    'company_id' => $companyId,
                    'request_id' => $requestId,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'notes' => null,
                ]);
            }
            $connection->commit();
            $this->audit($actorId, 'stock_request.created', 'inventory_stock_requests', $requestId, [
                'request_number' => $number,
                'handler_user_id' => $managerId,
            ]);
            return $requestId;
        } catch (Throwable $e) {
            if ($connection->inTransaction()) $connection->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function processRequest(int $requestId, int $actorId): array
    {
        $companyId = $this->tenant->companyId();
        $connection = \db();
        $connection->beginTransaction();
        try {
            $request = $this->requestForUpdate($connection, $companyId, $requestId);
            $requestStatus = (string) $request['status'];
            if (!in_array($requestStatus, ['pending_review','awaiting_procurement'], true)) {
                throw new RuntimeException('Only a stock request waiting for manager review or procurement replenishment can be processed.');
            }
            if ((int) $request['current_handler_user_id'] !== $actorId) {
                throw new RuntimeException('This stock request is assigned to another manager.');
            }
            $authority = $this->authorityForUser($companyId, $actorId, true);
            if (!is_array($authority)) {
                throw new RuntimeException('Your represented stock location is not configured.');
            }
            $this->assertAuthorityMatchesJobTitle($companyId, $actorId, (string) $authority['authority_level']);
            if ($requestStatus === 'awaiting_procurement' && (string) $authority['authority_level'] !== 'regional') {
                throw new RuntimeException('Only the Regional Manager can recheck a stock request that is waiting for company procurement.');
            }
            $serving = $this->authorityById($companyId, (int) $request['serving_authority_id'], true);
            if (!is_array($serving)) throw new RuntimeException('The serving Shop stock authority is no longer active.');

            $allocated = $this->allocateRemainingLocked($connection, $request, $authority, $serving, $actorId);
            if ($allocated !== []) {
                $this->createTransferForAllocationsLocked($connection, $request, $authority, $serving, $allocated, $actorId);
            }
            $remaining = $this->remainingLinesLocked($connection, $companyId, $requestId);

            if ($remaining !== []) {
                if ((string) $authority['authority_level'] === 'regional') {
                    $requisitionId = $this->ensureShortageRequisitionLocked(
                        $connection,
                        $request,
                        $authority,
                        $remaining,
                        $actorId
                    );
                    $connection->prepare(
                        "UPDATE inventory_stock_requests
                         SET status='awaiting_procurement',current_handler_user_id=:handler
                         WHERE company_id=:company_id AND request_id=:request_id"
                    )->execute([
                        'handler' => $actorId,
                        'company_id' => $companyId,
                        'request_id' => $requestId,
                    ]);
                    $connection->commit();
                    $this->audit($actorId, 'stock_request.procurement_requested', 'inventory_stock_requests', $requestId, [
                        'requisition_id' => $requisitionId,
                    ]);
                    return ['status' => 'awaiting_procurement', 'requisition_id' => $requisitionId];
                }

                $nextManager = $this->nextManagerAuthority($companyId, $actorId, (string) $authority['authority_level']);
                if (!is_array($nextManager)) {
                    throw new RuntimeException('The next stock authority in the reporting hierarchy is not configured.');
                }
                $connection->prepare(
                    "UPDATE inventory_stock_requests
                     SET status='pending_review',current_handler_user_id=:handler
                     WHERE company_id=:company_id AND request_id=:request_id"
                )->execute([
                    'handler' => (int) $nextManager['user_id'],
                    'company_id' => $companyId,
                    'request_id' => $requestId,
                ]);
                $connection->commit();
                $this->audit($actorId, 'stock_request.escalated', 'inventory_stock_requests', $requestId, [
                    'from_level' => $authority['authority_level'],
                    'to_level' => $nextManager['authority_level'],
                    'to_user_id' => (int) $nextManager['user_id'],
                ]);
                return ['status' => 'pending_review', 'handler_user_id' => (int) $nextManager['user_id']];
            }

            $status = $this->refreshRequestStatusLocked($connection, $companyId, $requestId);
            $connection->commit();
            $this->audit($actorId, 'stock_request.allocated', 'inventory_stock_requests', $requestId, ['status' => $status]);
            return ['status' => $status];
        } catch (Throwable $e) {
            if ($connection->inTransaction()) $connection->rollBack();
            throw $e;
        }
    }

    public function issueRequest(int $requestId, int $actorId): void
    {
        $companyId = $this->tenant->companyId();
        $connection = \db();
        $connection->beginTransaction();
        try {
            $request = $this->requestForUpdate($connection, $companyId, $requestId);
            if ((string) $request['status'] !== 'ready_to_issue') {
                throw new RuntimeException('The request is not fully assembled at the Shop stock location yet.');
            }
            $serving = $this->authorityById($companyId, (int) $request['serving_authority_id'], true);
            if (!is_array($serving) || (int) $serving['user_id'] !== $actorId || (string) $serving['authority_level'] !== 'shop') {
                throw new RuntimeException('Only the serving Shop Manager can issue this stock request.');
            }
            $this->assertAuthorityMatchesJobTitle($companyId, $actorId, 'shop');

            $lines = $this->requestLines($companyId, $requestId, true);
            foreach ($lines as $line) {
                $ready = $connection->prepare(
                    "SELECT COALESCE(SUM(quantity),0) FROM inventory_stock_request_allocations
                     WHERE company_id=:company_id AND request_id=:request_id AND request_line_id=:line_id
                       AND status='shop_reserved'"
                );
                $ready->execute([
                    'company_id' => $companyId,
                    'request_id' => $requestId,
                    'line_id' => (int) $line['request_line_id'],
                ]);
                $readyQuantity = round((float) $ready->fetchColumn(), 3);
                $requestedQuantity = round((float) $line['requested_quantity'], 3);
                if (abs($readyQuantity - $requestedQuantity) > 0.0005) {
                    throw new RuntimeException('The request reservation no longer matches the requested quantity.');
                }

                $release = $connection->prepare(
                    "UPDATE inventory_stock_balances
                     SET quantity_reserved=quantity_reserved-:quantity,version_number=version_number+1
                     WHERE company_id=:company_id AND warehouse_id=:warehouse_id AND location_id=:location_id
                       AND product_id=:product_id AND quantity_reserved>=:required"
                );
                $release->execute([
                    'quantity' => $requestedQuantity,
                    'company_id' => $companyId,
                    'warehouse_id' => (int) $serving['warehouse_id'],
                    'location_id' => (int) $serving['location_id'],
                    'product_id' => (int) $line['product_id'],
                    'required' => $requestedQuantity,
                ]);
                if ($release->rowCount() !== 1) {
                    throw new RuntimeException('Reserved Shop stock could not be released safely for issue.');
                }

                RepositoryFactory::inventory()->completeStockMovement([
                    'companyId' => $companyId,
                    'productId' => (int) $line['product_id'],
                    'sourceWarehouseId' => (int) $serving['warehouse_id'],
                    'sourceLocationId' => (int) $serving['location_id'],
                    'destinationWarehouseId' => null,
                    'destinationLocationId' => null,
                    'quantity' => $requestedQuantity,
                    'movementType' => 'issue',
                    'currency' => (string) ($_SESSION['auth']['company']['default_currency'] ?? 'ETB'),
                    'referenceType' => 'inventory_stock_request',
                    'referenceId' => $requestId,
                    'referenceNumber' => (string) $request['request_number'],
                    'idempotencyKey' => sprintf('stock-request:%d:line:%d:issue', $requestId, (int) $line['request_line_id']),
                    'notes' => 'Issued to ' . (string) $request['requester_role_snapshot'],
                    'occurredAt' => date('Y-m-d H:i:s'),
                    'actorId' => $actorId,
                ]);
            }

            $connection->prepare(
                "UPDATE inventory_stock_request_allocations
                 SET status='issued',issued_at=NOW()
                 WHERE company_id=:company_id AND request_id=:request_id AND status='shop_reserved'"
            )->execute(['company_id' => $companyId, 'request_id' => $requestId]);
            $connection->prepare(
                "UPDATE inventory_stock_requests
                 SET status='issued',issued_by=:actor,issued_at=NOW(),current_handler_user_id=NULL
                 WHERE company_id=:company_id AND request_id=:request_id AND status='ready_to_issue'"
            )->execute(['actor' => $actorId, 'company_id' => $companyId, 'request_id' => $requestId]);
            $connection->commit();
            $this->audit($actorId, 'stock_request.issued', 'inventory_stock_requests', $requestId, ['status' => 'issued']);
        } catch (Throwable $e) {
            if ($connection->inTransaction()) $connection->rollBack();
            throw $e;
        }
    }

    public function confirmReceipt(int $requestId, int $actorId): void
    {
        $companyId = $this->tenant->companyId();
        $statement = \db()->prepare(
            "UPDATE inventory_stock_requests
             SET status='closed',received_by=:actor,received_at=NOW()
             WHERE company_id=:company_id AND request_id=:request_id
               AND requester_user_id=:requester AND status='issued'"
        );
        $statement->execute([
            'actor' => $actorId,
            'company_id' => $companyId,
            'request_id' => $requestId,
            'requester' => $actorId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Only the requesting employee can confirm receipt after the stock has been issued.');
        }
        $this->audit($actorId, 'stock_request.received', 'inventory_stock_requests', $requestId, ['status' => 'closed']);
    }

    public function saveAuthority(array $input, int $actorId): void
    {
        $companyId = $this->tenant->companyId();
        $userId = (int) ($input['user_id'] ?? 0);
        $level = strtolower(trim((string) ($input['authority_level'] ?? '')));
        $warehouseId = (int) ($input['warehouse_id'] ?? 0);
        $locationId = (int) ($input['location_id'] ?? 0);
        $active = !empty($input['active']);
        if ($userId < 1 || $warehouseId < 1 || $locationId < 1 || !in_array($level, ['shop', 'district', 'regional'], true)) {
            throw new RuntimeException('Manager, authority level, warehouse and stock location are required.');
        }
        $this->assertAuthorityMatchesJobTitle($companyId, $userId, $level);
        $location = \db()->prepare(
            "SELECT w.warehouse_id,w.name warehouse_name,l.location_id,l.name location_name,l.receiving_allowed
             FROM inventory_warehouses w
             INNER JOIN inventory_warehouse_locations l ON l.company_id=w.company_id AND l.warehouse_id=w.warehouse_id
             WHERE w.company_id=:company_id AND w.warehouse_id=:warehouse_id AND l.location_id=:location_id
               AND w.active=TRUE AND w.deleted_at IS NULL AND l.active=TRUE AND l.deleted_at IS NULL
               AND l.location_usage='internal' AND l.is_virtual=FALSE AND l.picking_allowed=TRUE"
        );
        $location->execute(['company_id' => $companyId, 'warehouse_id' => $warehouseId, 'location_id' => $locationId]);
        $row = $location->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('Select an active internal stock location.');
        if ($level === 'regional' && empty($row['receiving_allowed'])) {
            throw new RuntimeException('The Regional / Passion Technologies company stock location must also allow Procurement receiving.');
        }

        $connection = \db();
        $connection->beginTransaction();
        try {
            $existing = $connection->prepare(
                'SELECT * FROM inventory_stock_authorities WHERE company_id=:company_id AND user_id=:user_id FOR UPDATE'
            );
            $existing->execute(['company_id' => $companyId, 'user_id' => $userId]);
            $current = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($current)) {
                $changedRoute = (int) $current['warehouse_id'] !== $warehouseId
                    || (int) $current['location_id'] !== $locationId
                    || (string) $current['authority_level'] !== $level
                    || ((bool) $current['active'] !== $active);
                if ($changedRoute && $this->authorityHasOpenWork($connection, $companyId, (int) $current['authority_id'])) {
                    throw new RuntimeException('This stock authority has open stock requests or reservations. Finish them before changing its level or stock location.');
                }
                $update = $connection->prepare(
                    "UPDATE inventory_stock_authorities
                     SET authority_level=:level,warehouse_id=:warehouse_id,location_id=:location_id,
                         active=:active,updated_by=:actor
                     WHERE company_id=:company_id AND authority_id=:authority_id"
                );
                $update->execute([
                    'level' => $level,
                    'warehouse_id' => $warehouseId,
                    'location_id' => $locationId,
                    'active' => $active ? 1 : 0,
                    'actor' => $actorId,
                    'company_id' => $companyId,
                    'authority_id' => (int) $current['authority_id'],
                ]);
                $authorityId = (int) $current['authority_id'];
            } else {
                $insert = $connection->prepare(
                    "INSERT INTO inventory_stock_authorities(
                        company_id,user_id,authority_level,warehouse_id,location_id,active,created_by,updated_by
                     ) VALUES(:company_id,:user_id,:level,:warehouse_id,:location_id,:active,:actor,:actor_two)"
                );
                $insert->execute([
                    'company_id' => $companyId,
                    'user_id' => $userId,
                    'level' => $level,
                    'warehouse_id' => $warehouseId,
                    'location_id' => $locationId,
                    'active' => $active ? 1 : 0,
                    'actor' => $actorId,
                    'actor_two' => $actorId,
                ]);
                $authorityId = (int) $connection->lastInsertId();
            }
            $connection->commit();
            $this->audit($actorId, 'stock_authority.saved', 'inventory_stock_authorities', $authorityId, [
                'user_id' => $userId,
                'authority_level' => $level,
                'warehouse_id' => $warehouseId,
                'location_id' => $locationId,
                'active' => $active,
            ]);
        } catch (Throwable $e) {
            if ($connection->inTransaction()) $connection->rollBack();
            throw $e;
        }
    }

    public function saveReorderThreshold(array $input, int $actorId): void
    {
        $companyId = $this->tenant->companyId();
        $authority = $this->authorityForUser($companyId, $actorId);
        $role = $this->roleFromTitle((string) ($this->employeeContext($companyId, $actorId)['job_title'] ?? ''));
        if (!is_array($authority) || $role !== 'regional' || (string) $authority['authority_level'] !== 'regional') {
            throw new RuntimeException('Only the Regional Manager can edit company-stock notification quantities.');
        }
        $productId = (int) ($input['product_id'] ?? 0);
        $quantityRaw = $input['notification_quantity'] ?? null;
        if ($productId < 1 || !is_numeric($quantityRaw) || (float) $quantityRaw < 0) {
            throw new RuntimeException('Select a product and enter a non-negative notification quantity.');
        }
        $this->assertStockableProducts($companyId, [$productId]);
        $active = !empty($input['active']);
        $statement = \db()->prepare(
            "INSERT INTO inventory_reorder_thresholds(
                company_id,warehouse_id,location_id,product_id,notification_quantity,active,updated_by
             ) VALUES(:company_id,:warehouse_id,:location_id,:product_id,:quantity,:active,:actor)
             ON DUPLICATE KEY UPDATE notification_quantity=VALUES(notification_quantity),active=VALUES(active),updated_by=VALUES(updated_by)"
        );
        $statement->execute([
            'company_id' => $companyId,
            'warehouse_id' => (int) $authority['warehouse_id'],
            'location_id' => (int) $authority['location_id'],
            'product_id' => $productId,
            'quantity' => round((float) $quantityRaw, 3),
            'active' => $active ? 1 : 0,
            'actor' => $actorId,
        ]);
        $this->audit($actorId, 'reorder_threshold.saved', 'inventory_reorder_thresholds', $productId, [
            'notification_quantity' => round((float) $quantityRaw, 3),
            'active' => $active,
        ]);
    }

    /** Called inside the Inventory transfer transaction before stock leaves the source. */
    public function beforeTransferDispatch(int $companyId, int $transferId): void
    {
        $connection = \db();
        $allocations = $connection->prepare(
            "SELECT a.allocation_id,a.quantity,a.source_warehouse_id,a.source_location_id,l.product_id
             FROM inventory_stock_request_allocations a
             INNER JOIN inventory_stock_request_lines l
               ON l.company_id=a.company_id AND l.request_id=a.request_id AND l.request_line_id=a.request_line_id
             WHERE a.company_id=:company_id AND a.transfer_id=:transfer_id AND a.status='source_reserved'
             FOR UPDATE"
        );
        $allocations->execute(['company_id' => $companyId, 'transfer_id' => $transferId]);
        foreach ($allocations->fetchAll(PDO::FETCH_ASSOC) as $allocation) {
            $release = $connection->prepare(
                "UPDATE inventory_stock_balances
                 SET quantity_reserved=quantity_reserved-:quantity,version_number=version_number+1
                 WHERE company_id=:company_id AND warehouse_id=:warehouse_id AND location_id=:location_id
                   AND product_id=:product_id AND quantity_reserved>=:required"
            );
            $release->execute([
                'quantity' => (float) $allocation['quantity'],
                'company_id' => $companyId,
                'warehouse_id' => (int) $allocation['source_warehouse_id'],
                'location_id' => (int) $allocation['source_location_id'],
                'product_id' => (int) $allocation['product_id'],
                'required' => (float) $allocation['quantity'],
            ]);
            if ($release->rowCount() !== 1) {
                throw new RuntimeException('Stock-request reservation could not be released safely for transfer dispatch.');
            }
        }
    }

    /** Called inside the Inventory transfer transaction after all dispatch movements succeed. */
    public function afterTransferDispatch(int $companyId, int $transferId): void
    {
        \db()->prepare(
            "UPDATE inventory_stock_request_allocations
             SET status='in_transit',dispatched_at=NOW()
             WHERE company_id=:company_id AND transfer_id=:transfer_id AND status='source_reserved'"
        )->execute(['company_id' => $companyId, 'transfer_id' => $transferId]);
    }

    /** Called inside the Inventory transfer transaction after destination stock is received. */
    public function afterTransferReceive(int $companyId, int $transferId): void
    {
        $connection = \db();
        $allocations = $connection->prepare(
            "SELECT a.*,l.product_id
             FROM inventory_stock_request_allocations a
             INNER JOIN inventory_stock_request_lines l
               ON l.company_id=a.company_id AND l.request_id=a.request_id AND l.request_line_id=a.request_line_id
             WHERE a.company_id=:company_id AND a.transfer_id=:transfer_id AND a.status='in_transit'
             FOR UPDATE"
        );
        $allocations->execute(['company_id' => $companyId, 'transfer_id' => $transferId]);
        $requestIds = [];
        foreach ($allocations->fetchAll(PDO::FETCH_ASSOC) as $allocation) {
            $reserve = $connection->prepare(
                "UPDATE inventory_stock_balances
                 SET quantity_reserved=quantity_reserved+:quantity,version_number=version_number+1
                 WHERE company_id=:company_id AND warehouse_id=:warehouse_id AND location_id=:location_id
                   AND product_id=:product_id AND quantity_available>=:required"
            );
            $reserve->execute([
                'quantity' => (float) $allocation['quantity'],
                'company_id' => $companyId,
                'warehouse_id' => (int) $allocation['destination_warehouse_id'],
                'location_id' => (int) $allocation['destination_location_id'],
                'product_id' => (int) $allocation['product_id'],
                'required' => (float) $allocation['quantity'],
            ]);
            if ($reserve->rowCount() !== 1) {
                throw new RuntimeException('Received stock could not be reserved for the originating stock request.');
            }
            $connection->prepare(
                "UPDATE inventory_stock_request_allocations
                 SET status='shop_reserved',received_at=NOW()
                 WHERE company_id=:company_id AND allocation_id=:allocation_id AND status='in_transit'"
            )->execute(['company_id' => $companyId, 'allocation_id' => (int) $allocation['allocation_id']]);
            $requestIds[(int) $allocation['request_id']] = true;
        }
        foreach (array_keys($requestIds) as $requestId) {
            $this->refreshRequestStatusLocked($connection, $companyId, (int) $requestId);
        }
    }

    /** Must be called in the same transaction as cancelling an approved linked transfer. */
    public function onTransferCancelled(int $companyId, int $transferId): void
    {
        $connection = \db();
        $allocations = $connection->prepare(
            "SELECT a.*,l.product_id,au.user_id authority_user_id
             FROM inventory_stock_request_allocations a
             INNER JOIN inventory_stock_request_lines l
               ON l.company_id=a.company_id AND l.request_id=a.request_id AND l.request_line_id=a.request_line_id
             INNER JOIN inventory_stock_authorities au
               ON au.company_id=a.company_id AND au.authority_id=a.authority_id
             WHERE a.company_id=:company_id AND a.transfer_id=:transfer_id AND a.status='source_reserved'
             FOR UPDATE"
        );
        $allocations->execute(['company_id' => $companyId, 'transfer_id' => $transferId]);
        $requestHandlers = [];
        foreach ($allocations->fetchAll(PDO::FETCH_ASSOC) as $allocation) {
            $release = $connection->prepare(
                "UPDATE inventory_stock_balances
                 SET quantity_reserved=quantity_reserved-:quantity,version_number=version_number+1
                 WHERE company_id=:company_id AND warehouse_id=:warehouse_id AND location_id=:location_id
                   AND product_id=:product_id AND quantity_reserved>=:required"
            );
            $release->execute([
                'quantity' => (float) $allocation['quantity'],
                'company_id' => $companyId,
                'warehouse_id' => (int) $allocation['source_warehouse_id'],
                'location_id' => (int) $allocation['source_location_id'],
                'product_id' => (int) $allocation['product_id'],
                'required' => (float) $allocation['quantity'],
            ]);
            if ($release->rowCount() !== 1) {
                throw new RuntimeException('Cancelled transfer reservation could not be released safely.');
            }
            $connection->prepare(
                "UPDATE inventory_stock_request_allocations SET status='released',released_at=NOW()
                 WHERE company_id=:company_id AND allocation_id=:allocation_id AND status='source_reserved'"
            )->execute(['company_id' => $companyId, 'allocation_id' => (int) $allocation['allocation_id']]);
            $requestHandlers[(int) $allocation['request_id']] = (int) $allocation['authority_user_id'];
        }
        foreach ($requestHandlers as $requestId => $handlerId) {
            $connection->prepare(
                "UPDATE inventory_stock_requests
                 SET status='pending_review',current_handler_user_id=:handler
                 WHERE company_id=:company_id AND request_id=:request_id
                   AND status IN('awaiting_transfer','ready_to_issue')"
            )->execute(['handler' => $handlerId, 'company_id' => $companyId, 'request_id' => $requestId]);
        }
    }

    /**
     * Resume a reactive SR after a linked PO receipt is posted into the Regional/company stock.
     * Receipt posting remains authoritative even if no linked SR exists.
     */
    public function resumeFromGoodsReceipt(int $companyId, int $goodsReceiptId, int $actorId): void
    {
        $connection = \db();
        $link = $connection->prepare(
            "SELECT srp.request_id
             FROM inventory_goods_receipts gr
             INNER JOIN purchase_orders po ON po.company_id=gr.company_id AND po.purchase_order_id=gr.purchase_order_id
             INNER JOIN inventory_stock_request_procurements srp
               ON srp.company_id=po.company_id AND srp.requisition_id=po.requisition_id
             WHERE gr.company_id=:company_id AND gr.goods_receipt_id=:receipt_id
             LIMIT 1"
        );
        $link->execute(['company_id' => $companyId, 'receipt_id' => $goodsReceiptId]);
        $requestId = (int) $link->fetchColumn();
        if ($requestId < 1) return;

        $connection->beginTransaction();
        try {
            $request = $this->requestForUpdate($connection, $companyId, $requestId);
            if (!in_array((string) $request['status'], ['awaiting_procurement','pending_review'], true)) {
                $connection->commit();
                return;
            }
            $regional = $this->regionalAuthority($companyId, true);
            if (!is_array($regional)) throw new RuntimeException('The active Regional company-stock authority is not configured.');
            $serving = $this->authorityById($companyId, (int) $request['serving_authority_id'], true);
            if (!is_array($serving)) throw new RuntimeException('The serving Shop stock authority is no longer active.');
            $allocated = $this->allocateRemainingLocked(
                $connection,
                $request,
                $regional,
                $serving,
                (int) $regional['user_id']
            );
            if ($allocated !== []) {
                $this->createTransferForAllocationsLocked(
                    $connection,
                    $request,
                    $regional,
                    $serving,
                    $allocated,
                    (int) $regional['user_id']
                );
            }
            $remaining = $this->remainingLinesLocked($connection, $companyId, $requestId);
            if ($remaining === []) {
                $this->refreshRequestStatusLocked($connection, $companyId, $requestId);
            } else {
                $connection->prepare(
                    "UPDATE inventory_stock_requests
                     SET status='awaiting_procurement',current_handler_user_id=:regional
                     WHERE company_id=:company_id AND request_id=:request_id"
                )->execute(['regional' => (int) $regional['user_id'], 'company_id' => $companyId, 'request_id' => $requestId]);
            }
            $connection->commit();
            $this->audit($actorId, 'stock_request.procurement_receipt_resumed', 'inventory_stock_requests', $requestId, [
                'goods_receipt_id' => $goodsReceiptId,
            ]);
        } catch (Throwable $e) {
            if ($connection->inTransaction()) $connection->rollBack();
            throw $e;
        }
    }

    /** @return array<int,float> keyed by request line id */
    private function allocateRemainingLocked(PDO $connection, array $request, array $authority, array $serving, int $actorId): array
    {
        $companyId = (int) $request['company_id'];
        $requestId = (int) $request['request_id'];
        $remainingLines = $this->remainingLinesLocked($connection, $companyId, $requestId);
        $allocated = [];
        foreach ($remainingLines as $line) {
            $remaining = (float) $line['remaining_quantity'];
            if ($remaining <= 0.0005) continue;
            $balance = $connection->prepare(
                "SELECT stock_balance_id,quantity_available
                 FROM inventory_stock_balances
                 WHERE company_id=:company_id AND warehouse_id=:warehouse_id AND location_id=:location_id
                   AND product_id=:product_id FOR UPDATE"
            );
            $balance->execute([
                'company_id' => $companyId,
                'warehouse_id' => (int) $authority['warehouse_id'],
                'location_id' => (int) $authority['location_id'],
                'product_id' => (int) $line['product_id'],
            ]);
            $stock = $balance->fetch(PDO::FETCH_ASSOC);
            $available = is_array($stock) ? max(0.0, (float) $stock['quantity_available']) : 0.0;
            $quantity = round(min($remaining, $available), 3);
            if ($quantity <= 0.0005) continue;
            $reserve = $connection->prepare(
                "UPDATE inventory_stock_balances
                 SET quantity_reserved=quantity_reserved+:quantity,version_number=version_number+1
                 WHERE company_id=:company_id AND stock_balance_id=:balance_id AND quantity_available>=:required"
            );
            $reserve->execute([
                'quantity' => $quantity,
                'company_id' => $companyId,
                'balance_id' => (int) $stock['stock_balance_id'],
                'required' => $quantity,
            ]);
            if ($reserve->rowCount() !== 1) {
                throw new RuntimeException('Available stock changed while the request was being allocated. Try again.');
            }
            $isServing = (int) $authority['authority_id'] === (int) $serving['authority_id'];
            $status = $isServing ? 'shop_reserved' : 'source_reserved';
            $insert = $connection->prepare(
                "INSERT INTO inventory_stock_request_allocations(
                    company_id,request_id,request_line_id,authority_id,
                    source_warehouse_id,source_location_id,destination_warehouse_id,destination_location_id,
                    quantity,status,reserved_at,created_by
                 ) VALUES(
                    :company_id,:request_id,:request_line_id,:authority_id,
                    :source_warehouse_id,:source_location_id,:destination_warehouse_id,:destination_location_id,
                    :quantity,:status,NOW(),:created_by
                 )"
            );
            $insert->execute([
                'company_id' => $companyId,
                'request_id' => $requestId,
                'request_line_id' => (int) $line['request_line_id'],
                'authority_id' => (int) $authority['authority_id'],
                'source_warehouse_id' => (int) $authority['warehouse_id'],
                'source_location_id' => (int) $authority['location_id'],
                'destination_warehouse_id' => (int) $serving['warehouse_id'],
                'destination_location_id' => (int) $serving['location_id'],
                'quantity' => $quantity,
                'status' => $status,
                'created_by' => $actorId,
            ]);
            $allocated[(int) $line['request_line_id']] = $quantity;
        }
        return $allocated;
    }

    private function createTransferForAllocationsLocked(
        PDO $connection,
        array $request,
        array $authority,
        array $serving,
        array $allocated,
        int $actorId
    ): void {
        if ($allocated === [] || (int) $authority['authority_id'] === (int) $serving['authority_id']) return;
        $companyId = (int) $request['company_id'];
        $operation = $connection->prepare(
            "SELECT operation_type_id FROM inventory_operation_types
             WHERE company_id=:company_id AND warehouse_id=:warehouse_id
               AND operation_kind='internal_transfer' AND active=TRUE AND is_default=TRUE LIMIT 1"
        );
        $operation->execute(['company_id' => $companyId, 'warehouse_id' => (int) $authority['warehouse_id']]);
        $operationId = (int) $operation->fetchColumn();
        if ($operationId < 1) throw new RuntimeException('The source authority warehouse has no default internal-transfer operation.');

        $transferNumber = 'TRF-SR-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $reason = 'Stock request ' . (string) $request['request_number'];
        $header = $connection->prepare(
            "INSERT INTO inventory_transfers(
                company_id,source_warehouse_id,destination_warehouse_id,operation_type_id,
                transfer_number,transfer_date,status,notes,reason,created_by,submitted_by,submitted_at,approved_by,approved_at
             ) VALUES(
                :company_id,:source_warehouse_id,:destination_warehouse_id,:operation_type_id,
                :transfer_number,CURRENT_DATE,'approved',:notes,:reason,:created_by,:submitted_by,NOW(),:approved_by,NOW()
             )"
        );
        $header->execute([
            'company_id' => $companyId,
            'source_warehouse_id' => (int) $authority['warehouse_id'],
            'destination_warehouse_id' => (int) $serving['warehouse_id'],
            'operation_type_id' => $operationId,
            'transfer_number' => $transferNumber,
            'notes' => $reason,
            'reason' => $reason,
            'created_by' => (int) $request['requester_user_id'],
            'submitted_by' => (int) $request['requester_user_id'],
            'approved_by' => $actorId,
        ]);
        $transferId = (int) $connection->lastInsertId();

        $lines = $connection->prepare(
            "SELECT l.request_line_id,l.product_id,a.allocation_id,a.quantity,b.average_unit_cost
             FROM inventory_stock_request_lines l
             INNER JOIN inventory_stock_request_allocations a
               ON a.company_id=l.company_id AND a.request_id=l.request_id AND a.request_line_id=l.request_line_id
             LEFT JOIN inventory_stock_balances b
               ON b.company_id=a.company_id AND b.warehouse_id=a.source_warehouse_id
              AND b.location_id=a.source_location_id AND b.product_id=l.product_id
             WHERE l.company_id=:company_id AND l.request_id=:request_id
               AND a.authority_id=:authority_id AND a.status='source_reserved'
               AND a.transfer_id IS NULL
             ORDER BY l.request_line_id"
        );
        $lines->execute([
            'company_id' => $companyId,
            'request_id' => (int) $request['request_id'],
            'authority_id' => (int) $authority['authority_id'],
        ]);
        $insertLine = $connection->prepare(
            "INSERT INTO inventory_transfer_lines(
                company_id,transfer_id,source_warehouse_id,source_location_id,
                destination_warehouse_id,destination_location_id,product_id,quantity,unit_cost,notes
             ) VALUES(
                :company_id,:transfer_id,:source_warehouse_id,:source_location_id,
                :destination_warehouse_id,:destination_location_id,:product_id,:quantity,:unit_cost,:notes
             )"
        );
        foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $line) {
            if (!array_key_exists((int) $line['request_line_id'], $allocated)) continue;
            $insertLine->execute([
                'company_id' => $companyId,
                'transfer_id' => $transferId,
                'source_warehouse_id' => (int) $authority['warehouse_id'],
                'source_location_id' => (int) $authority['location_id'],
                'destination_warehouse_id' => (int) $serving['warehouse_id'],
                'destination_location_id' => (int) $serving['location_id'],
                'product_id' => (int) $line['product_id'],
                'quantity' => (float) $line['quantity'],
                'unit_cost' => (float) ($line['average_unit_cost'] ?? 0),
                'notes' => $reason,
            ]);
            $transferLineId = (int) $connection->lastInsertId();
            $connection->prepare(
                "UPDATE inventory_stock_request_allocations
                 SET transfer_id=:transfer_id,transfer_line_id=:transfer_line_id
                 WHERE company_id=:company_id AND allocation_id=:allocation_id AND transfer_id IS NULL"
            )->execute([
                'transfer_id' => $transferId,
                'transfer_line_id' => $transferLineId,
                'company_id' => $companyId,
                'allocation_id' => (int) $line['allocation_id'],
            ]);
        }
    }

    /** @return list<array<string,mixed>> */
    private function remainingLinesLocked(PDO $connection, int $companyId, int $requestId): array
    {
        $statement = $connection->prepare(
            "SELECT l.request_line_id,l.product_id,l.requested_quantity,p.sku,p.name,
                    GREATEST(l.requested_quantity-COALESCE(SUM(CASE WHEN a.status<>'released' THEN a.quantity ELSE 0 END),0),0) remaining_quantity
             FROM inventory_stock_request_lines l
             INNER JOIN sales_products p ON p.company_id=l.company_id AND p.product_id=l.product_id
             LEFT JOIN inventory_stock_request_allocations a
               ON a.company_id=l.company_id AND a.request_id=l.request_id AND a.request_line_id=l.request_line_id
             WHERE l.company_id=:company_id AND l.request_id=:request_id
             GROUP BY l.request_line_id,l.product_id,l.requested_quantity,p.sku,p.name
             HAVING remaining_quantity>0.0005
             ORDER BY l.request_line_id"
        );
        $statement->execute(['company_id' => $companyId, 'request_id' => $requestId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    private function ensureShortageRequisitionLocked(
        PDO $connection,
        array $request,
        array $regional,
        array $remaining,
        int $actorId
    ): int {
        $companyId = (int) $request['company_id'];
        $existing = $connection->prepare(
            "SELECT p.requisition_id
             FROM inventory_stock_request_procurements p
             INNER JOIN purchase_requisitions r ON r.company_id=p.company_id AND r.requisition_id=p.requisition_id
             WHERE p.company_id=:company_id AND p.request_id=:request_id
               AND r.status IN('draft','submitted','approved','converted')
             ORDER BY p.link_id DESC LIMIT 1 FOR UPDATE"
        );
        $existing->execute(['company_id' => $companyId, 'request_id' => (int) $request['request_id']]);
        $existingId = (int) $existing->fetchColumn();
        if ($existingId > 0) return $existingId;

        $employee = $this->employeeContext($companyId, $actorId);
        $departmentId = (int) ($employee['department_id'] ?? 0);
        if ($departmentId < 1) {
            throw new RuntimeException('Regional Manager must have an active HR department before a company purchase requisition can be created.');
        }
        $number = 'PR-SR-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $header = $connection->prepare(
            "INSERT INTO purchase_requisitions(
                company_id,requisition_number,requester_user_id,department_id,requested_date,
                justification,status
             ) VALUES(:company_id,:number,:requester,:department,CURRENT_DATE,:justification,'submitted')"
        );
        $header->execute([
            'company_id' => $companyId,
            'number' => $number,
            'requester' => $actorId,
            'department' => $departmentId,
            'justification' => 'Company stock shortage for ' . (string) $request['request_number'] . '. Replenish Passion Technologies company stock and resume the same SR.',
        ]);
        $requisitionId = (int) $connection->lastInsertId();
        $line = $connection->prepare(
            "INSERT INTO purchase_requisition_lines(
                company_id,requisition_id,product_id,description,quantity,estimated_unit_price,preferred_supplier_id,warehouse_id
             ) VALUES(:company_id,:requisition_id,:product_id,:description,:quantity,:estimated_unit_price,NULL,:warehouse_id)"
        );
        $price = $connection->prepare(
            "SELECT COALESCE(
                 (SELECT pol.unit_price
                    FROM purchase_order_lines pol
                    INNER JOIN purchase_orders po ON po.company_id=pol.company_id AND po.purchase_order_id=pol.purchase_order_id
                   WHERE pol.company_id=:po_company AND pol.product_id=:po_product
                     AND po.status NOT IN('rejected','cancelled')
                   ORDER BY po.order_date DESC,pol.purchase_order_line_id DESC LIMIT 1),
                 (SELECT b.average_unit_cost FROM inventory_stock_balances b
                   WHERE b.company_id=:stock_company AND b.warehouse_id=:stock_warehouse
                     AND b.location_id=:stock_location AND b.product_id=:stock_product LIMIT 1),
                 0
             )"
        );
        foreach ($remaining as $item) {
            $price->execute([
                'po_company' => $companyId,
                'po_product' => (int) $item['product_id'],
                'stock_company' => $companyId,
                'stock_warehouse' => (int) $regional['warehouse_id'],
                'stock_location' => (int) $regional['location_id'],
                'stock_product' => (int) $item['product_id'],
            ]);
            $estimatedUnitPrice = max(0.0, (float) $price->fetchColumn());
            $line->execute([
                'company_id' => $companyId,
                'requisition_id' => $requisitionId,
                'product_id' => (int) $item['product_id'],
                'description' => 'Stock shortage for ' . (string) $request['request_number'] . ' — ' . (string) $item['name'],
                'quantity' => round((float) $item['remaining_quantity'], 3),
                'warehouse_id' => (int) $regional['warehouse_id'],
                'estimated_unit_price' => $estimatedUnitPrice,
            ]);
        }
        $connection->prepare(
            "INSERT INTO inventory_stock_request_procurements(
                company_id,request_id,requisition_id,receiving_warehouse_id,receiving_location_id,created_by
             ) VALUES(
                :company_id,:request_id,:requisition_id,:receiving_warehouse_id,:receiving_location_id,:actor
             )"
        )->execute([
            'company_id' => $companyId,
            'request_id' => (int) $request['request_id'],
            'requisition_id' => $requisitionId,
            'receiving_warehouse_id' => (int) $regional['warehouse_id'],
            'receiving_location_id' => (int) $regional['location_id'],
            'actor' => $actorId,
        ]);
        return $requisitionId;
    }

    private function refreshRequestStatusLocked(PDO $connection, int $companyId, int $requestId): string
    {
        $request = $this->requestForUpdate($connection, $companyId, $requestId);
        if (in_array((string) $request['status'], ['issued','closed','cancelled'], true)) return (string) $request['status'];
        $remaining = $this->remainingLinesLocked($connection, $companyId, $requestId);
        if ($remaining !== []) {
            $status = $this->hasOpenProcurement($connection, $companyId, $requestId)
                ? 'awaiting_procurement'
                : 'pending_review';
        } else {
            $pendingTransfer = $connection->prepare(
                "SELECT COUNT(*) FROM inventory_stock_request_allocations
                 WHERE company_id=:company_id AND request_id=:request_id
                   AND status IN('source_reserved','in_transit')"
            );
            $pendingTransfer->execute(['company_id' => $companyId, 'request_id' => $requestId]);
            $status = (int) $pendingTransfer->fetchColumn() > 0 ? 'awaiting_transfer' : 'ready_to_issue';
        }
        $connection->prepare(
            "UPDATE inventory_stock_requests SET status=:status
             WHERE company_id=:company_id AND request_id=:request_id"
        )->execute(['status' => $status, 'company_id' => $companyId, 'request_id' => $requestId]);
        return $status;
    }

    private function hasOpenProcurement(PDO $connection, int $companyId, int $requestId): bool
    {
        $statement = $connection->prepare(
            "SELECT COUNT(*)
             FROM inventory_stock_request_procurements p
             INNER JOIN purchase_requisitions r ON r.company_id=p.company_id AND r.requisition_id=p.requisition_id
             WHERE p.company_id=:company_id AND p.request_id=:request_id
               AND r.status IN('draft','submitted','approved','converted')"
        );
        $statement->execute(['company_id' => $companyId, 'request_id' => $requestId]);
        return (int) $statement->fetchColumn() > 0;
    }

    /** @return array<string,mixed> */
    private function requestForUpdate(PDO $connection, int $companyId, int $requestId): array
    {
        $statement = $connection->prepare(
            'SELECT * FROM inventory_stock_requests WHERE company_id=:company_id AND request_id=:request_id FOR UPDATE'
        );
        $statement->execute(['company_id' => $companyId, 'request_id' => $requestId]);
        $request = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($request)) throw new RuntimeException('Stock request was not found.');
        return $request;
    }

    /** @return array<string,mixed> */
    private function employeeContext(int $companyId, int $userId): array
    {
        $statement = \db()->prepare(
            "SELECT cu.user_id,cu.manager_user_id,e.employee_id,e.department_id,e.employee_number,
                    e.first_name,e.middle_name,e.last_name,e.job_title legacy_job_title,
                    COALESCE(a.job_title_name_snapshot,e.job_title) job_title
             FROM company_users cu
             INNER JOIN hr_employees e ON e.company_id=cu.company_id AND e.user_id=cu.user_id
               AND e.deleted_at IS NULL AND e.employment_status='active'
             LEFT JOIN hr_employee_position_assignments a ON a.company_id=e.company_id AND a.employee_id=e.employee_id
               AND a.assignment_status='current' AND a.current_marker=1
             WHERE cu.company_id=:company_id AND cu.user_id=:user_id AND cu.active=TRUE
             LIMIT 1"
        );
        $statement->execute(['company_id' => $companyId, 'user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('The signed-in company user is not linked to an active HR employee record.');
        return $row;
    }

    private function roleFromTitle(string $title): ?string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $title) ?? $title));
        if (preg_match('/(^|[^a-z])dsa([^a-z]|$)/', $normalized)) return 'dsa';
        if (preg_match('/(^|[^a-z])dsp([^a-z]|$)/', $normalized)) return 'dsp';
        if (str_contains($normalized, 'shop manager')) return 'shop';
        if (str_contains($normalized, 'district manager')) return 'district';
        if (str_contains($normalized, 'regional manager')) return 'regional';
        return null;
    }

    private function assertAuthorityMatchesJobTitle(int $companyId, int $userId, string $level): void
    {
        $context = $this->employeeContext($companyId, $userId);
        $role = $this->roleFromTitle((string) ($context['job_title'] ?? ''));
        if ($role !== $level) {
            throw new RuntimeException(sprintf('The employee HR Job Title does not match the %s stock-authority level.', ucfirst($level)));
        }
    }

    /** @return array<string,mixed>|null */
    private function authorityForUser(int $companyId, int $userId, bool $forUpdate = false): ?array
    {
        $sql = "SELECT a.*,w.code warehouse_code,w.name warehouse_name,l.code location_code,l.name location_name
                FROM inventory_stock_authorities a
                INNER JOIN inventory_warehouses w ON w.company_id=a.company_id AND w.warehouse_id=a.warehouse_id
                INNER JOIN inventory_warehouse_locations l
                  ON l.company_id=a.company_id AND l.warehouse_id=a.warehouse_id AND l.location_id=a.location_id
                WHERE a.company_id=:company_id AND a.user_id=:user_id AND a.active=TRUE
                  AND w.active=TRUE AND w.deleted_at IS NULL AND l.active=TRUE AND l.deleted_at IS NULL
                LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
        $statement = \db()->prepare($sql);
        $statement->execute(['company_id' => $companyId, 'user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function authorityById(int $companyId, int $authorityId, bool $forUpdate = false): ?array
    {
        $sql = "SELECT a.*,w.code warehouse_code,w.name warehouse_name,l.code location_code,l.name location_name
                FROM inventory_stock_authorities a
                INNER JOIN inventory_warehouses w ON w.company_id=a.company_id AND w.warehouse_id=a.warehouse_id
                INNER JOIN inventory_warehouse_locations l
                  ON l.company_id=a.company_id AND l.warehouse_id=a.warehouse_id AND l.location_id=a.location_id
                WHERE a.company_id=:company_id AND a.authority_id=:authority_id AND a.active=TRUE
                LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
        $statement = \db()->prepare($sql);
        $statement->execute(['company_id' => $companyId, 'authority_id' => $authorityId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function regionalAuthority(int $companyId, bool $forUpdate = false): ?array
    {
        $sql = "SELECT a.*,w.code warehouse_code,w.name warehouse_name,l.code location_code,l.name location_name
                FROM inventory_stock_authorities a
                INNER JOIN inventory_warehouses w ON w.company_id=a.company_id AND w.warehouse_id=a.warehouse_id
                INNER JOIN inventory_warehouse_locations l
                  ON l.company_id=a.company_id AND l.warehouse_id=a.warehouse_id AND l.location_id=a.location_id
                WHERE a.company_id=:company_id AND a.authority_level='regional' AND a.active=TRUE LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
        $statement = \db()->prepare($sql);
        $statement->execute(['company_id' => $companyId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function nextManagerAuthority(int $companyId, int $userId, string $currentLevel): ?array
    {
        $manager = \db()->prepare(
            'SELECT manager_user_id FROM company_users WHERE company_id=:company_id AND user_id=:user_id AND active=TRUE'
        );
        $manager->execute(['company_id' => $companyId, 'user_id' => $userId]);
        $managerId = (int) $manager->fetchColumn();
        if ($managerId < 1) return null;
        $authority = $this->authorityForUser($companyId, $managerId);
        if (!is_array($authority)) return null;
        $expected = ['shop' => 'district', 'district' => 'regional'][$currentLevel] ?? null;
        if ($expected === null || (string) $authority['authority_level'] !== $expected) return null;
        $this->assertAuthorityMatchesJobTitle($companyId, $managerId, $expected);
        return $authority;
    }

    /** @return list<array<string,mixed>> */
    private function requestLines(int $companyId, int $requestId, bool $forUpdate = false): array
    {
        $statement = \db()->prepare(
            "SELECT l.*,p.sku,p.name,p.unit_of_measure,
                    COALESCE(SUM(CASE WHEN a.status<>'released' THEN a.quantity ELSE 0 END),0) allocated_quantity,
                    COALESCE(SUM(CASE WHEN a.status='shop_reserved' THEN a.quantity ELSE 0 END),0) ready_quantity
             FROM inventory_stock_request_lines l
             INNER JOIN sales_products p ON p.company_id=l.company_id AND p.product_id=l.product_id
             LEFT JOIN inventory_stock_request_allocations a
               ON a.company_id=l.company_id AND a.request_id=l.request_id AND a.request_line_id=l.request_line_id
             WHERE l.company_id=:company_id AND l.request_id=:request_id
             GROUP BY l.request_line_id,l.company_id,l.request_id,l.product_id,l.requested_quantity,l.notes,l.created_at,p.sku,p.name,p.unit_of_measure
             ORDER BY l.request_line_id"
        );
        $statement->execute(['company_id' => $companyId, 'request_id' => $requestId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /** @return list<array<string,mixed>> */
    private function requestAllocations(int $companyId, int $requestId): array
    {
        $statement = \db()->prepare(
            "SELECT a.*,p.sku,p.name product_name,au.authority_level,u.display_name authority_name,
                    sw.name source_warehouse_name,sl.name source_location_name,
                    dw.name destination_warehouse_name,dl.name destination_location_name,t.transfer_number,t.status transfer_status
             FROM inventory_stock_request_allocations a
             INNER JOIN inventory_stock_request_lines l
               ON l.company_id=a.company_id AND l.request_id=a.request_id AND l.request_line_id=a.request_line_id
             INNER JOIN sales_products p ON p.company_id=l.company_id AND p.product_id=l.product_id
             INNER JOIN inventory_stock_authorities au ON au.company_id=a.company_id AND au.authority_id=a.authority_id
             INNER JOIN users u ON u.user_id=au.user_id
             INNER JOIN inventory_warehouses sw ON sw.company_id=a.company_id AND sw.warehouse_id=a.source_warehouse_id
             INNER JOIN inventory_warehouse_locations sl ON sl.company_id=a.company_id AND sl.warehouse_id=a.source_warehouse_id AND sl.location_id=a.source_location_id
             INNER JOIN inventory_warehouses dw ON dw.company_id=a.company_id AND dw.warehouse_id=a.destination_warehouse_id
             INNER JOIN inventory_warehouse_locations dl ON dl.company_id=a.company_id AND dl.warehouse_id=a.destination_warehouse_id AND dl.location_id=a.destination_location_id
             LEFT JOIN inventory_transfers t ON t.company_id=a.company_id AND t.transfer_id=a.transfer_id
             WHERE a.company_id=:company_id AND a.request_id=:request_id
             ORDER BY a.allocation_id"
        );
        $statement->execute(['company_id' => $companyId, 'request_id' => $requestId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /** @return list<array<string,mixed>> */
    private function requestProcurements(int $companyId, int $requestId): array
    {
        $statement = \db()->prepare(
            "SELECT l.link_id,r.requisition_id,r.requisition_number,r.status requisition_status,
                    po.purchase_order_id,po.po_number,po.status purchase_order_status
             FROM inventory_stock_request_procurements l
             INNER JOIN purchase_requisitions r ON r.company_id=l.company_id AND r.requisition_id=l.requisition_id
             LEFT JOIN purchase_orders po ON po.company_id=r.company_id AND po.requisition_id=r.requisition_id
             WHERE l.company_id=:company_id AND l.request_id=:request_id
             ORDER BY l.link_id DESC"
        );
        $statement->execute(['company_id' => $companyId, 'request_id' => $requestId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /** @return list<int> */
    private function visibleRequesterIds(int $companyId, int $actorId): array
    {
        $statement = \db()->prepare(
            'SELECT user_id,manager_user_id FROM company_users WHERE company_id=:company_id AND active=TRUE'
        );
        $statement->execute(['company_id' => $companyId]);
        $children = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $manager = (int) ($row['manager_user_id'] ?? 0);
            if ($manager > 0) $children[$manager][] = (int) $row['user_id'];
        }
        $visible = [$actorId => true];
        $queue = [$actorId];
        while ($queue !== []) {
            $manager = array_shift($queue);
            foreach ($children[$manager] ?? [] as $child) {
                if (isset($visible[$child])) continue;
                $visible[$child] = true;
                $queue[] = $child;
            }
        }
        return array_map('intval', array_keys($visible));
    }

    /** @return list<array<string,mixed>> */
    private function listVisibleRequests(int $companyId, int $actorId, bool $all, array $visibleRequesterIds): array
    {
        $where = ['r.company_id=:company_id'];
        $params = ['company_id' => $companyId];
        if (!$all) {
            $ids = $visibleRequesterIds === [] ? [$actorId] : $visibleRequesterIds;
            $ph = [];
            foreach (array_values(array_unique($ids)) as $i => $id) {
                $key = 'v' . $i;
                $ph[] = ':' . $key;
                $params[$key] = $id;
            }
            $where[] = '(r.requester_user_id IN(' . implode(',', $ph) . ') OR r.current_handler_user_id=:actor)';
            $params['actor'] = $actorId;
        }
        $statement = \db()->prepare(
            "SELECT r.*,u.display_name requester_name,h.display_name current_handler_name,
                    COALESCE((SELECT SUM(l.requested_quantity) FROM inventory_stock_request_lines l WHERE l.company_id=r.company_id AND l.request_id=r.request_id),0) requested_quantity,
                    COALESCE((SELECT SUM(a.quantity) FROM inventory_stock_request_allocations a WHERE a.company_id=r.company_id AND a.request_id=r.request_id AND a.status<>'released'),0) allocated_quantity
             FROM inventory_stock_requests r
             INNER JOIN users u ON u.user_id=r.requester_user_id
             LEFT JOIN users h ON h.user_id=r.current_handler_user_id
             WHERE " . implode(' AND ', $where) . " ORDER BY r.request_id DESC"
        );
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /** @return list<array<string,mixed>> */
    private function stockableProducts(int $companyId): array
    {
        $statement = \db()->prepare(
            "SELECT product_id,sku,name,unit_of_measure FROM sales_products
             WHERE company_id=:company_id AND active=TRUE AND deleted_at IS NULL
               AND product_type NOT IN('service','fixed_asset') ORDER BY name,product_id"
        );
        $statement->execute(['company_id' => $companyId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /** @return array<int,float> */
    private function normalizeRequestLines(array $input): array
    {
        $productIds = (array) ($input['product_id'] ?? []);
        $quantities = (array) ($input['quantity'] ?? []);
        $lines = [];
        foreach ($productIds as $i => $rawProductId) {
            $productId = (int) $rawProductId;
            $rawQuantity = $quantities[$i] ?? null;
            if ($productId < 1 || !is_numeric($rawQuantity)) continue;
            $quantity = round((float) $rawQuantity, 3);
            if ($quantity <= 0) continue;
            $lines[$productId] = round(($lines[$productId] ?? 0) + $quantity, 3);
        }
        return $lines;
    }

    /** @param list<int> $productIds */
    private function assertStockableProducts(int $companyId, array $productIds): void
    {
        if ($productIds === []) throw new RuntimeException('No products were selected.');
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $statement = \db()->prepare(
            "SELECT product_id FROM sales_products
             WHERE company_id=? AND active=TRUE AND deleted_at IS NULL
               AND product_type NOT IN('service','fixed_asset') AND product_id IN($placeholders)"
        );
        $statement->execute(array_merge([$companyId], array_map('intval', $productIds)));
        $found = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        sort($found);
        $expected = array_values(array_unique(array_map('intval', $productIds)));
        sort($expected);
        if ($found !== $expected) throw new RuntimeException('Every requested item must be an active inventory-stocked product in this company.');
    }

    private function nextRequestNumber(PDO $connection, int $companyId): string
    {
        $year = (int) date('Y');
        $connection->prepare(
            'INSERT IGNORE INTO inventory_stock_request_sequences(company_id,request_year,last_number) VALUES(:company_id,:year,0)'
        )->execute(['company_id' => $companyId, 'year' => $year]);
        $sequence = $connection->prepare(
            'SELECT last_number FROM inventory_stock_request_sequences WHERE company_id=:company_id AND request_year=:year FOR UPDATE'
        );
        $sequence->execute(['company_id' => $companyId, 'year' => $year]);
        $next = (int) $sequence->fetchColumn() + 1;
        $connection->prepare(
            'UPDATE inventory_stock_request_sequences SET last_number=:next WHERE company_id=:company_id AND request_year=:year'
        )->execute(['next' => $next, 'company_id' => $companyId, 'year' => $year]);
        return sprintf('SR-%04d-%06d', $year, $next);
    }

    private function authorityHasOpenWork(PDO $connection, int $companyId, int $authorityId): bool
    {
        $statement = $connection->prepare(
            "SELECT
               (SELECT COUNT(*) FROM inventory_stock_requests r
                 WHERE r.company_id=:company_one AND r.serving_authority_id=:authority_one
                   AND r.status NOT IN('closed','cancelled'))
               +
               (SELECT COUNT(*) FROM inventory_stock_request_allocations a
                 WHERE a.company_id=:company_two AND a.authority_id=:authority_two
                   AND a.status IN('source_reserved','in_transit','shop_reserved'))
               +
               (SELECT COUNT(*) FROM inventory_stock_requests r
                  INNER JOIN inventory_stock_authorities x
                    ON x.company_id=r.company_id AND x.user_id=r.current_handler_user_id
                 WHERE r.company_id=:company_three AND x.authority_id=:authority_three
                   AND r.status NOT IN('closed','cancelled'))"
        );
        $statement->execute([
            'company_one' => $companyId,
            'authority_one' => $authorityId,
            'company_two' => $companyId,
            'authority_two' => $authorityId,
            'company_three' => $companyId,
            'authority_three' => $authorityId,
        ]);
        return (int) $statement->fetchColumn() > 0;
    }

    /** @return list<array<string,mixed>> */
    private function authorities(int $companyId): array
    {
        $statement = \db()->prepare(
            "SELECT a.*,u.display_name,COALESCE(pa.job_title_name_snapshot,e.job_title) job_title,
                    w.code warehouse_code,w.name warehouse_name,l.code location_code,l.name location_name,
                    cu.manager_user_id,mu.display_name manager_name
             FROM inventory_stock_authorities a
             INNER JOIN company_users cu ON cu.company_id=a.company_id AND cu.user_id=a.user_id
             INNER JOIN users u ON u.user_id=a.user_id
             INNER JOIN hr_employees e ON e.company_id=a.company_id AND e.user_id=a.user_id AND e.deleted_at IS NULL
             LEFT JOIN hr_employee_position_assignments pa ON pa.company_id=e.company_id AND pa.employee_id=e.employee_id AND pa.assignment_status='current' AND pa.current_marker=1
             INNER JOIN inventory_warehouses w ON w.company_id=a.company_id AND w.warehouse_id=a.warehouse_id
             INNER JOIN inventory_warehouse_locations l ON l.company_id=a.company_id AND l.warehouse_id=a.warehouse_id AND l.location_id=a.location_id
             LEFT JOIN users mu ON mu.user_id=cu.manager_user_id
             WHERE a.company_id=:company_id ORDER BY FIELD(a.authority_level,'shop','district','regional'),u.display_name"
        );
        $statement->execute(['company_id' => $companyId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /** @return list<array<string,mixed>> */
    private function authorityCandidates(int $companyId): array
    {
        $statement = \db()->prepare(
            "SELECT cu.user_id,u.display_name,e.employee_number,
                    COALESCE(pa.job_title_name_snapshot,e.job_title) job_title
             FROM company_users cu
             INNER JOIN users u ON u.user_id=cu.user_id AND u.active=TRUE AND u.deleted_at IS NULL
             INNER JOIN hr_employees e ON e.company_id=cu.company_id AND e.user_id=cu.user_id
               AND e.deleted_at IS NULL AND e.employment_status='active'
             LEFT JOIN hr_employee_position_assignments pa ON pa.company_id=e.company_id AND pa.employee_id=e.employee_id
               AND pa.assignment_status='current' AND pa.current_marker=1
             WHERE cu.company_id=:company_id AND cu.active=TRUE
             ORDER BY u.display_name"
        );
        $statement->execute(['company_id' => $companyId]);
        $out = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (in_array($this->roleFromTitle((string) $row['job_title']), ['shop','district','regional'], true)) $out[] = $row;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function warehousesAndLocations(int $companyId): array
    {
        $warehouses = \db()->prepare(
            "SELECT warehouse_id,code,name FROM inventory_warehouses
             WHERE company_id=:company_id AND active=TRUE AND deleted_at IS NULL ORDER BY name"
        );
        $warehouses->execute(['company_id' => $companyId]);
        $locations = \db()->prepare(
            "SELECT location_id,warehouse_id,code,name,receiving_allowed FROM inventory_warehouse_locations
             WHERE company_id=:company_id AND active=TRUE AND deleted_at IS NULL
               AND location_usage='internal' AND is_virtual=FALSE AND picking_allowed=TRUE
             ORDER BY warehouse_id,pick_priority,name"
        );
        $locations->execute(['company_id' => $companyId]);
        return [
            'warehouses' => $warehouses->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'locations' => $locations->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    /** @return array<string,mixed> */
    private function regionalReorderWorkspace(int $companyId, array $authority): array
    {
        $statement = \db()->prepare(
            "SELECT p.product_id,p.sku,p.name,p.unit_of_measure,
                    COALESCE(b.quantity_on_hand,0) quantity_on_hand,
                    COALESCE(b.quantity_reserved,0) quantity_reserved,
                    COALESCE(b.quantity_available,0) quantity_available,
                    t.notification_quantity,t.active threshold_active,
                    CASE WHEN t.active=TRUE AND COALESCE(b.quantity_available,0)<=t.notification_quantity THEN 1 ELSE 0 END low_stock
             FROM sales_products p
             LEFT JOIN inventory_stock_balances b
               ON b.company_id=p.company_id AND b.product_id=p.product_id
              AND b.warehouse_id=:warehouse_id AND b.location_id=:location_id
             LEFT JOIN inventory_reorder_thresholds t
               ON t.company_id=p.company_id AND t.product_id=p.product_id
              AND t.warehouse_id=:threshold_warehouse AND t.location_id=:threshold_location
             WHERE p.company_id=:company_id AND p.active=TRUE AND p.deleted_at IS NULL
               AND p.product_type NOT IN('service','fixed_asset')
             ORDER BY low_stock DESC,p.name,p.product_id"
        );
        $statement->execute([
            'warehouse_id' => (int) $authority['warehouse_id'],
            'location_id' => (int) $authority['location_id'],
            'threshold_warehouse' => (int) $authority['warehouse_id'],
            'threshold_location' => (int) $authority['location_id'],
            'company_id' => $companyId,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return [
            'warehouse_id' => (int) $authority['warehouse_id'],
            'location_id' => (int) $authority['location_id'],
            'warehouse_name' => (string) $authority['warehouse_name'],
            'location_name' => (string) $authority['location_name'],
            'products' => is_array($rows) ? $rows : [],
        ];
    }

    private function actorCan(
        int $companyId,
        int $userId,
        string $permission
    ): bool {
        if ($companyId < 1 || $userId < 1 || $permission === '') {
            return false;
        }

        $statement = \db()->prepare(
            "SELECT 1
             FROM company_user_roles cur
             INNER JOIN roles r
               ON r.role_id=cur.role_id
              AND r.active=TRUE
             INNER JOIN company_role_permissions crp
               ON crp.company_id=cur.company_id
              AND crp.role_id=cur.role_id
             INNER JOIN permissions p
               ON p.permission_id=crp.permission_id
              AND p.active=TRUE
             WHERE cur.company_id=:company_id
               AND cur.user_id=:user_id
               AND p.code=:permission
             LIMIT 1"
        );

        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
            'permission' => $permission,
        ]);

        return $statement->fetchColumn() !== false;
    }
    private function audit(int $actorId, string $action, string $table, int $id, ?array $values): void
    {
        RepositoryFactory::auditLogs()->record(
            $actorId,
            $action,
            'inventory',
            $table,
            (string) $id,
            null,
            $values,
            $this->tenant->companyId()
        );
    }
}
