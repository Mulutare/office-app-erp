<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class InventoryOperationalAccessService
{
    /** @return list<array<string,mixed>> */
    public function warehousesForUser(int $companyId, int $userId): array
    {
        if (!$this->hasPermission($companyId,$userId,'inventory.warehouses.use')) { return []; }
        $implicit = $this->hasImplicitAllAccess($companyId, $userId);
        $sql = "SELECT w.warehouse_id,w.code,w.name,w.allow_negative_stock,w.branch_id
                FROM inventory_warehouses w
                WHERE w.company_id=:company_id AND w.active=TRUE AND w.deleted_at IS NULL";
        if (!$implicit) {
            $sql .= " AND EXISTS(SELECT 1 FROM inventory_user_warehouse_access a
                        WHERE a.company_id=w.company_id AND a.warehouse_id=w.warehouse_id
                          AND a.user_id=:user_id AND a.active=TRUE)";
        }
        $sql .= ' ORDER BY w.name,w.warehouse_id';
        $statement = \db()->prepare($sql);
        $parameters = ['company_id' => $companyId];
        if (!$implicit) {
            $parameters['user_id'] = $userId;
        }
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function locationsForUser(int $companyId, int $userId, ?int $warehouseId = null): array
    {
        if (!$this->hasPermission($companyId,$userId,'inventory.warehouses.use')) { return []; }
        $implicit = $this->hasImplicitAllAccess($companyId, $userId);
        $sql = "SELECT l.location_id,l.warehouse_id,l.code,l.name,l.pick_priority
                FROM inventory_warehouse_locations l
                INNER JOIN inventory_warehouses w
                  ON w.company_id=l.company_id AND w.warehouse_id=l.warehouse_id
                WHERE l.company_id=:company_id AND l.active=TRUE AND l.deleted_at IS NULL
                  AND l.picking_allowed=TRUE AND l.location_usage='internal' AND l.is_virtual=FALSE
                  AND w.active=TRUE AND w.deleted_at IS NULL";
        $parameters = ['company_id' => $companyId];
        if ($warehouseId !== null) {
            $sql .= ' AND l.warehouse_id=:warehouse_id';
            $parameters['warehouse_id'] = $warehouseId;
        }
        if (!$implicit) {
            $sql .= " AND EXISTS(SELECT 1 FROM inventory_user_warehouse_access wa
                         WHERE wa.company_id=l.company_id AND wa.user_id=:warehouse_user
                           AND wa.warehouse_id=l.warehouse_id AND wa.active=TRUE)
                      AND EXISTS(SELECT 1 FROM inventory_user_location_access la
                         WHERE la.company_id=l.company_id AND la.user_id=:location_user
                           AND la.warehouse_id=l.warehouse_id AND la.location_id=l.location_id
                           AND la.active=TRUE)";
            $parameters['warehouse_user'] = $userId;
            $parameters['location_user'] = $userId;
        }
        $sql .= ' ORDER BY l.warehouse_id,l.pick_priority,l.name,l.location_id';
        $statement = \db()->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function receivingLocationsForUser(int $companyId, int $userId, ?int $warehouseId = null): array
    {
        if (!$this->hasPermission($companyId,$userId,'inventory.warehouses.use') || !$this->hasPermission($companyId,$userId,'procurement.receiving_destinations.use')) return [];
        $implicit=$this->hasImplicitAllAccess($companyId,$userId);
        $sql="SELECT l.location_id,l.warehouse_id,l.code,l.name FROM inventory_warehouse_locations l INNER JOIN inventory_warehouses w ON w.company_id=l.company_id AND w.warehouse_id=l.warehouse_id WHERE l.company_id=:company_id AND w.active=TRUE AND w.deleted_at IS NULL AND l.active=TRUE AND l.deleted_at IS NULL AND l.receiving_allowed=TRUE AND l.location_usage='internal' AND l.is_virtual=FALSE";
        $parameters=['company_id'=>$companyId];
        if($warehouseId!==null){$sql.=' AND l.warehouse_id=:warehouse_id';$parameters['warehouse_id']=$warehouseId;}
        if(!$implicit){$sql.=" AND EXISTS(SELECT 1 FROM inventory_user_warehouse_access wa WHERE wa.company_id=l.company_id AND wa.user_id=:warehouse_user AND wa.warehouse_id=l.warehouse_id AND wa.active=TRUE) AND EXISTS(SELECT 1 FROM inventory_user_location_access la WHERE la.company_id=l.company_id AND la.user_id=:location_user AND la.warehouse_id=l.warehouse_id AND la.location_id=l.location_id AND la.active=TRUE)";$parameters['warehouse_user']=$userId;$parameters['location_user']=$userId;}
        $sql.=' ORDER BY l.warehouse_id,l.name,l.location_id';$statement=\db()->prepare($sql);$statement->execute($parameters);return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    public function assertAuthorizedDestination(int $companyId,int $userId,int $warehouseId,int $locationId): array
    {
        if(!$this->hasPermission($companyId,$userId,'inventory.warehouses.use')||!$this->hasPermission($companyId,$userId,'procurement.receiving_destinations.use'))throw new RuntimeException('You are not authorized to select Procurement receiving destinations.');
        foreach($this->receivingLocationsForUser($companyId,$userId,$warehouseId) as $location)if((int)$location['location_id']===$locationId)return $location;
        throw new RuntimeException('Select an active, assigned receiving location in the selected warehouse.');
    }

    /** @return array<string,mixed> */
    public function assertAuthorizedTransferDestination(int $companyId,int $userId,int $warehouseId,int $locationId): array
    {
        if(!$this->hasPermission($companyId,$userId,'inventory.warehouses.use'))throw new RuntimeException('You are not authorized to use warehouse stock operationally.');
        $sql="SELECT w.warehouse_id,w.name warehouse_name,l.location_id,l.name location_name FROM inventory_warehouses w INNER JOIN inventory_warehouse_locations l ON l.company_id=w.company_id AND l.warehouse_id=w.warehouse_id WHERE w.company_id=:company_id AND w.warehouse_id=:warehouse_id AND l.location_id=:location_id AND w.active=TRUE AND w.deleted_at IS NULL AND l.active=TRUE AND l.deleted_at IS NULL AND l.receiving_allowed=TRUE AND l.location_usage='internal' AND l.is_virtual=FALSE";$statement=\db()->prepare($sql);$statement->execute(['company_id'=>$companyId,'warehouse_id'=>$warehouseId,'location_id'=>$locationId]);$destination=$statement->fetch(PDO::FETCH_ASSOC);if(!is_array($destination))throw new RuntimeException('Select an active internal receiving destination in the selected warehouse.');
        if(!$this->hasImplicitAllAccess($companyId,$userId)){$access=\db()->prepare("SELECT COUNT(*) FROM inventory_user_warehouse_access wa INNER JOIN inventory_user_location_access la ON la.company_id=wa.company_id AND la.user_id=wa.user_id AND la.warehouse_id=wa.warehouse_id WHERE wa.company_id=:company_id AND wa.user_id=:user_id AND wa.warehouse_id=:warehouse_id AND la.location_id=:location_id AND wa.active=TRUE AND la.active=TRUE");$access->execute(['company_id'=>$companyId,'user_id'=>$userId,'warehouse_id'=>$warehouseId,'location_id'=>$locationId]);if((int)$access->fetchColumn()!==1)throw new RuntimeException('You are not assigned to use the selected destination warehouse and location.');}
        return $destination;
    }

    /** @return array<string,mixed> */
    public function assertAuthorizedSource(
        int $companyId,
        int $userId,
        int $warehouseId,
        int $locationId
    ): array {
        if (!$this->hasPermission($companyId, $userId, 'inventory.warehouses.use')) {
            throw new RuntimeException('You are not authorized to use warehouse stock operationally.');
        }
        $statement = \db()->prepare(
            "SELECT w.warehouse_id,w.name warehouse_name,w.allow_negative_stock,
                    l.location_id,l.name location_name
             FROM inventory_warehouses w
             INNER JOIN inventory_warehouse_locations l
               ON l.company_id=w.company_id AND l.warehouse_id=w.warehouse_id
             WHERE w.company_id=:company_id AND w.warehouse_id=:warehouse_id
               AND l.location_id=:location_id
               AND w.active=TRUE AND w.deleted_at IS NULL
               AND l.active=TRUE AND l.deleted_at IS NULL
               AND l.picking_allowed=TRUE AND l.location_usage='internal' AND l.is_virtual=FALSE"
        );
        $statement->execute([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
        ]);
        $source = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($source)) {
            throw new RuntimeException('Select an active operational stock source location in the selected warehouse.');
        }
        if (!$this->hasImplicitAllAccess($companyId, $userId)) {
            $access = \db()->prepare(
                "SELECT COUNT(*) FROM inventory_user_warehouse_access wa
                 INNER JOIN inventory_user_location_access la
                   ON la.company_id=wa.company_id AND la.user_id=wa.user_id
                  AND la.warehouse_id=wa.warehouse_id
                 WHERE wa.company_id=:company_id AND wa.user_id=:user_id
                   AND wa.warehouse_id=:warehouse_id AND la.location_id=:location_id
                   AND wa.active=TRUE AND la.active=TRUE"
            );
            $access->execute([
                'company_id' => $companyId,
                'user_id' => $userId,
                'warehouse_id' => $warehouseId,
                'location_id' => $locationId,
            ]);
            if ((int) $access->fetchColumn() !== 1) {
                throw new RuntimeException('You are not assigned to use the selected warehouse and source location.');
            }
        }
        return $source;
    }

    /** @param list<int> $productIds @return array<int,array<string,mixed>> */
    public function availability(
        int $companyId,
        int $userId,
        int $warehouseId,
        int $locationId,
        array $productIds
    ): array {
        $source = $this->assertAuthorizedSource($companyId, $userId, $warehouseId, $locationId);
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        if ($productIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $statement = \db()->prepare(
            "SELECT p.product_id,p.sku,p.name,
                    COALESCE(b.quantity_on_hand,0) quantity_on_hand,
                    COALESCE(b.quantity_reserved,0) quantity_reserved,
                    COALESCE(b.quantity_available,0) quantity_available
             FROM sales_products p
             LEFT JOIN inventory_stock_balances b
               ON b.company_id=p.company_id AND b.product_id=p.product_id
              AND b.warehouse_id=? AND b.location_id=?
             WHERE p.company_id=? AND p.product_id IN ($placeholders) AND p.deleted_at IS NULL"
        );
        $statement->execute(array_merge([$warehouseId, $locationId, $companyId], $productIds));
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['warehouse_name'] = $source['warehouse_name'];
            $row['location_name'] = $source['location_name'];
            $row['allow_negative_stock'] = !empty($source['allow_negative_stock']);
            $result[(int) $row['product_id']] = $row;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public function assignmentForm(int $companyId, int $userId): array
    {
        $member = \db()->prepare(
            'SELECT u.user_id,u.username,u.display_name FROM company_users cu
             INNER JOIN users u ON u.user_id=cu.user_id
             WHERE cu.company_id=:company_id AND cu.user_id=:user_id AND cu.active=TRUE'
        );
        $member->execute(['company_id' => $companyId, 'user_id' => $userId]);
        $user = $member->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            throw new RuntimeException('The company user was not found.');
        }
        $warehouses = \db()->prepare(
            "SELECT warehouse_id,code,name,active FROM inventory_warehouses
             WHERE company_id=:company_id AND deleted_at IS NULL ORDER BY active DESC,name"
        );
        $warehouses->execute(['company_id' => $companyId]);
        $locations = \db()->prepare(
            "SELECT location_id,warehouse_id,code,name,active,picking_allowed,location_usage,is_virtual
             FROM inventory_warehouse_locations
             WHERE company_id=:company_id AND deleted_at IS NULL
             ORDER BY warehouse_id,pick_priority,name"
        );
        $locations->execute(['company_id' => $companyId]);
        $assignedWarehouses = \db()->prepare(
            'SELECT warehouse_id FROM inventory_user_warehouse_access
             WHERE company_id=:company_id AND user_id=:user_id AND active=TRUE'
        );
        $assignedWarehouses->execute(['company_id' => $companyId, 'user_id' => $userId]);
        $assignedLocations = \db()->prepare(
            'SELECT location_id FROM inventory_user_location_access
             WHERE company_id=:company_id AND user_id=:user_id AND active=TRUE'
        );
        $assignedLocations->execute(['company_id' => $companyId, 'user_id' => $userId]);
        return [
            'profile' => $user,
            'warehouses' => $warehouses->fetchAll(PDO::FETCH_ASSOC),
            'locations' => $locations->fetchAll(PDO::FETCH_ASSOC),
            'assignedWarehouseIds' => array_map('intval', $assignedWarehouses->fetchAll(PDO::FETCH_COLUMN)),
            'assignedLocationIds' => array_map('intval', $assignedLocations->fetchAll(PDO::FETCH_COLUMN)),
            'implicitAllAccess' => $this->hasImplicitAllAccess($companyId, $userId),
        ];
    }

    /** @param list<int|string> $warehouseIds @param list<int|string> $locationIds */
    public function saveAssignments(
        int $companyId,
        int $userId,
        array $warehouseIds,
        array $locationIds,
        int $actorId
    ): void {
        $warehouseIds = array_values(array_unique(array_filter(array_map('intval', $warehouseIds), static fn (int $id): bool => $id > 0)));
        $locationIds = array_values(array_unique(array_filter(array_map('intval', $locationIds), static fn (int $id): bool => $id > 0)));
        $form = $this->assignmentForm($companyId, $userId);
        $validWarehouses = [];
        foreach ($form['warehouses'] as $warehouse) {
            if (!empty($warehouse['active'])) {
                $validWarehouses[(int) $warehouse['warehouse_id']] = true;
            }
        }
        $locationWarehouse = [];
        foreach ($form['locations'] as $location) {
            if (!empty($location['active']) && !empty($location['picking_allowed'])
                && ($location['location_usage'] ?? '') === 'internal' && empty($location['is_virtual'])) {
                $locationWarehouse[(int) $location['location_id']] = (int) $location['warehouse_id'];
            }
        }
        foreach ($warehouseIds as $warehouseId) {
            if (!isset($validWarehouses[$warehouseId])) {
                throw new RuntimeException('An assigned warehouse is inactive or outside the company.');
            }
        }
        foreach ($locationIds as $locationId) {
            $warehouseId = $locationWarehouse[$locationId] ?? 0;
            if ($warehouseId < 1 || !in_array($warehouseId, $warehouseIds, true)) {
                throw new RuntimeException('Every assigned location must be an active operational source under an assigned warehouse.');
            }
        }
        $connection = \db();
        $connection->beginTransaction();
        try {
            $connection->prepare('DELETE FROM inventory_user_location_access WHERE company_id=:company_id AND user_id=:user_id')
                ->execute(['company_id' => $companyId, 'user_id' => $userId]);
            $connection->prepare('DELETE FROM inventory_user_warehouse_access WHERE company_id=:company_id AND user_id=:user_id')
                ->execute(['company_id' => $companyId, 'user_id' => $userId]);
            $warehouseInsert = $connection->prepare(
                'INSERT INTO inventory_user_warehouse_access(company_id,user_id,warehouse_id,active,granted_by)
                 VALUES(:company_id,:user_id,:warehouse_id,TRUE,:granted_by)'
            );
            foreach ($warehouseIds as $warehouseId) {
                $warehouseInsert->execute(['company_id'=>$companyId,'user_id'=>$userId,'warehouse_id'=>$warehouseId,'granted_by'=>$actorId]);
            }
            $locationInsert = $connection->prepare(
                'INSERT INTO inventory_user_location_access(company_id,user_id,warehouse_id,location_id,active,granted_by)
                 VALUES(:company_id,:user_id,:warehouse_id,:location_id,TRUE,:granted_by)'
            );
            foreach ($locationIds as $locationId) {
                $locationInsert->execute(['company_id'=>$companyId,'user_id'=>$userId,'warehouse_id'=>$locationWarehouse[$locationId],'location_id'=>$locationId,'granted_by'=>$actorId]);
            }
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    private function hasImplicitAllAccess(int $companyId, int $userId): bool
    {
        $statement = \db()->prepare(
            "SELECT COUNT(*) FROM company_user_roles ur
             INNER JOIN roles r ON r.role_id=ur.role_id AND r.active=TRUE
             WHERE ur.company_id=:company_id AND ur.user_id=:user_id
               AND r.code IN('company_owner','system_administrator')"
        );
        $statement->execute(['company_id' => $companyId, 'user_id' => $userId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function hasPermission(int $companyId, int $userId, string $permission): bool
    {
        $statement = \db()->prepare(
            'SELECT COUNT(*) FROM company_user_roles ur
             INNER JOIN company_role_permissions rp ON rp.company_id=ur.company_id AND rp.role_id=ur.role_id
             INNER JOIN permissions p ON p.permission_id=rp.permission_id AND p.active=TRUE
             WHERE ur.company_id=:company_id AND ur.user_id=:user_id AND p.code=:permission'
        );
        $statement->execute(['company_id'=>$companyId,'user_id'=>$userId,'permission'=>$permission]);
        return (int) $statement->fetchColumn() > 0;
    }
}
