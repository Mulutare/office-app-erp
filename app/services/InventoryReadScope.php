<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/** Reporting visibility never grants operational stock access. */
final class InventoryReadScope
{
    private array $warehouseCache = [];
    public function isAdministrator(int $company, int $actor): bool
    {
        $s = \db()->prepare("SELECT 1 FROM company_users cu
            JOIN users u ON u.user_id=cu.user_id AND u.active=TRUE AND u.deleted_at IS NULL
            JOIN company_user_roles ur ON ur.company_id=cu.company_id AND ur.user_id=cu.user_id
            JOIN roles r ON r.role_id=ur.role_id AND r.active=TRUE
            WHERE cu.company_id=? AND cu.user_id=? AND cu.active=TRUE
              AND r.code IN ('company_owner','system_administrator') LIMIT 1");
        $s->execute([$company, $actor]);
        return (bool) $s->fetchColumn();
    }

    /** @return list<int> */
    public function warehouseIds(int $company, int $actor): array
    {
        $key = $company . ':' . $actor;
        if (isset($this->warehouseCache[$key])) return $this->warehouseCache[$key];

        $s = \db()->prepare('SELECT warehouse_id,code,warehouse_type,branch_id,manager_user_id,is_default FROM inventory_warehouses
            WHERE company_id=? AND active=TRUE AND deleted_at IS NULL');
        $s->execute([$company]);
        $warehouses = $s->fetchAll(PDO::FETCH_ASSOC);
        if ($this->isAdministrator($company, $actor)) {
            return $this->warehouseCache[$key] = array_values(array_map('intval', array_column($warehouses, 'warehouse_id')));
        }

        $s = \db()->prepare('SELECT user_id,warehouse_id,authority_level FROM inventory_stock_authorities WHERE company_id=? AND active=TRUE');
        $s->execute([$company]);
        $authorities = $s->fetchAll(PDO::FETCH_ASSOC);
        $authorityByUser = [];
        foreach ($authorities as $authority) $authorityByUser[(int) $authority['user_id']] = $authority;

        $ids = [];
        $hierarchy = new SalesHierarchyScope();
        $ownAuthority = $authorityByUser[$actor] ?? null;

        if (is_array($ownAuthority)) {
            // Manager read scope is broader than mutation scope. Always include the
            // represented warehouse, then recursively include warehouses represented
            // by reporting descendants. No access rows are created by this read scope.
            $ids[(int) $ownAuthority['warehouse_id']] = true;
            $visibleUsers = $hierarchy->userIds($company, $actor);
            foreach ($authorities as $authority) {
                if (in_array((int) $authority['user_id'], $visibleUsers, true)) {
                    $ids[(int) $authority['warehouse_id']] = true;
                }
            }
            foreach ($warehouses as $warehouse) {
                $manager = (int) ($warehouse['manager_user_id'] ?? 0);
                if ($manager > 0 && in_array($manager, $visibleUsers, true) && !$this->isCompanyWarehouse($warehouse)) {
                    $ids[(int) $warehouse['warehouse_id']] = true;
                }
            }

            // Regional managers own the complete branch inventory view. In production,
            // many Shop warehouses pre-date manager/reporting links, so manager_user_id
            // alone is not an authoritative way to discover every warehouse underneath
            // a Regional warehouse. Branch membership is authoritative for the Regional
            // read boundary; the company/Central warehouse remains excluded.
            $level = strtolower(trim((string) ($ownAuthority['authority_level'] ?? '')));
            $ownWarehouse = $this->warehouseRow($warehouses, (int) $ownAuthority['warehouse_id']);
            $branchId = (int) ($ownWarehouse['branch_id'] ?? 0);
            if ($level === 'regional' && $branchId > 0) {
                foreach ($warehouses as $warehouse) {
                    if ((int) ($warehouse['branch_id'] ?? 0) === $branchId && !$this->isCompanyWarehouse($warehouse)) {
                        $ids[(int) $warehouse['warehouse_id']] = true;
                    }
                }
            }

            // District fallback for legacy Shop warehouses: if this branch has exactly
            // one active District stock authority, Shop/retail warehouses in that branch
            // necessarily belong below that District even when old manager links are
            // incomplete. With multiple District authorities we do NOT broaden scope;
            // reporting relationships remain the discriminator and sibling privacy wins.
            if ($level === 'district' && $branchId > 0 && $this->districtAuthorityCountForBranch($company, $branchId) === 1) {
                foreach ($warehouses as $warehouse) {
                    $type = strtolower(trim((string) ($warehouse['warehouse_type'] ?? '')));
                    if ((int) ($warehouse['branch_id'] ?? 0) === $branchId
                        && in_array($type, ['standard', 'retail'], true)
                        && !$this->isCompanyWarehouse($warehouse)) {
                        $ids[(int) $warehouse['warehouse_id']] = true;
                    }
                }
            }
        } else {
            // Members/agents may read the warehouse of their own reporting team only.
            // Walk upward to the nearest active stock authority; do not expose siblings.
            $current = $hierarchy->parentId($company, $actor);
            $seen = [];
            while ($current !== null && $current > 0) {
                if (isset($seen[$current])) break;
                $seen[$current] = true;
                if (isset($authorityByUser[$current])) {
                    $ids[(int) $authorityByUser[$current]['warehouse_id']] = true;
                    break;
                }
                $current = $hierarchy->parentId($company, $current);
            }
        }

        // PT-CENTRAL is a company warehouse and remains hidden unless the user has
        // explicit operational Central access. Manager hierarchy alone never exposes it.
        $access = new InventoryOperationalAccessService();
        foreach ($warehouses as $warehouse) {
            $id = (int) $warehouse['warehouse_id'];
            if ($this->isCompanyWarehouse($warehouse)) {
                unset($ids[$id]);
                if ($access->canAccessWarehouse($company, $actor, $id)) $ids[$id] = true;
            }
        }

        return $this->warehouseCache[$key] = array_values(array_map('intval', array_keys($ids)));
    }

    /** @param list<array<string,mixed>> $warehouses */
    private function warehouseRow(array $warehouses, int $warehouseId): array
    {
        foreach ($warehouses as $warehouse) {
            if ((int) ($warehouse['warehouse_id'] ?? 0) === $warehouseId) return $warehouse;
        }
        return [];
    }

    /** @param array<string,mixed> $warehouse */
    private function isCompanyWarehouse(array $warehouse): bool
    {
        return (string) ($warehouse['code'] ?? '') === 'PT-CENTRAL'
            || (!empty($warehouse['is_default']) && (int) ($warehouse['branch_id'] ?? 0) === 0);
    }

    private function districtAuthorityCountForBranch(int $company, int $branchId): int
    {
        $s = \db()->prepare("SELECT COUNT(*)
            FROM inventory_stock_authorities a
            INNER JOIN inventory_warehouses w
              ON w.company_id=a.company_id AND w.warehouse_id=a.warehouse_id
            WHERE a.company_id=? AND a.active=TRUE AND a.authority_level='district'
              AND w.active=TRUE AND w.deleted_at IS NULL AND w.branch_id=?");
        $s->execute([$company, $branchId]);
        return (int) $s->fetchColumn();
    }

    public function warehouse(int $company, int $actor, int $warehouse): bool
    {
        return in_array($warehouse, $this->warehouseIds($company, $actor), true);
    }

    public function location(int $company, int $actor, int $warehouse, int $location): bool
    {
        if (!$this->warehouse($company, $actor, $warehouse)) return false;
        $s = \db()->prepare('SELECT 1 FROM inventory_warehouse_locations WHERE company_id=? AND warehouse_id=? AND location_id=? AND deleted_at IS NULL');
        $s->execute([$company, $warehouse, $location]);
        return (bool) $s->fetchColumn();
    }

    /** Only pass developer-controlled SQL column names. */
    public function predicate(int $company, int $actor, string $column): string
    {
        $ids = $this->warehouseIds($company, $actor);
        return $ids === [] ? '1=0' : $column . ' IN (' . implode(',', $ids) . ')';
    }
}
