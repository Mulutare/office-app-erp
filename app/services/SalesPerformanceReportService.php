<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use PDO;

final class SalesPerformanceReportService
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function report(int $actorId, array $input): array
    {
        $companyId = (new TenantContext())->companyId();
        $scope = new SalesHierarchyScope();
        $isAgent = $scope->isAgent($companyId, $actorId);
        $companyWide = $scope->hasCompanyWideAccess($companyId, $actorId);
        $warehouseIds = $this->visibleWarehouseIds(
            $companyId,
            $actorId,
            $companyWide,
            $isAgent
        );

        $period = in_array(($input['period'] ?? ''), ['daily', 'weekly', 'monthly', 'yearly'], true)
            ? (string) $input['period']
            : 'monthly';
        [$start, $end, $selector] = $this->period($period, (string) ($input['date'] ?? ''));

        $viewBy = in_array(($input['view_by'] ?? ''), ['product', 'employee', 'product_employee'], true)
            ? (string) $input['view_by']
            : 'product';

        $scopeSql = $this->scopeSql(
            $warehouseIds,
            $companyWide,
            $isAgent,
            $actorId
        );
        $options = $this->options(
            $companyId,
            $actorId,
            $warehouseIds,
            $companyWide,
            $isAgent
        );
        $productId = $this->scopedId($input['product_id'] ?? null, $options['products'], 'product_id');
        $employeeId = $this->scopedId($input['employee_id'] ?? null, $options['employees'], 'user_id');
        $shopId = $this->scopedId($input['shop_id'] ?? null, $options['shops'], 'warehouse_id');

        $parameters = [
            'company_id' => $companyId,
            'date_start' => $start,
            'date_end' => $end,
        ];
        $filters = '';
        if ($productId === -1 || $employeeId === -1 || $shopId === -1) {
            $filters .= ' AND 1=0';
        }
        if (($productId ?? 0) > 0) {
            $filters .= ' AND report_lines.product_id = :product_id';
            $parameters['product_id'] = $productId;
        }
        if (($employeeId ?? 0) > 0) {
            $filters .= ' AND quick_sales.user_id = :employee_id';
            $parameters['employee_id'] = $employeeId;
        }
        if (($shopId ?? 0) > 0) {
            $filters .= ' AND quick_sales.origin_warehouse_id = :shop_id';
            $parameters['shop_id'] = $shopId;
        }

        $base = $this->baseSql($scopeSql, $filters);
        [$select, $group, $order] = match ($viewBy) {
            'employee' => [
                'employee_user_id, employee_name, shop_id, shop_name, currency',
                'employee_user_id, employee_name, shop_id, shop_name, currency',
                'employee_name, shop_name, currency',
            ],
            'product_employee' => [
                'employee_user_id, employee_name, shop_id, shop_name, product_id, sku, product_name, currency',
                'employee_user_id, employee_name, shop_id, shop_name, product_id, sku, product_name, currency',
                'employee_name, shop_name, product_name, currency',
            ],
            default => [
                'product_id, sku, product_name, currency',
                'product_id, sku, product_name, currency',
                'product_name, currency',
            ],
        };

        $connection = \db();
        $summary = $connection->prepare(
            "SELECT currency, SUM(sold_quantity) sold_quantity,
                    SUM(returned_quantity) returned_quantity,
                    SUM(sales_amount) sales_amount,
                    COUNT(DISTINCT report_id) report_count
             FROM ({$base}) scoped_sales
             GROUP BY currency ORDER BY currency"
        );
        $summary->execute($parameters);
        $summaryRows = $summary->fetchAll(PDO::FETCH_ASSOC);

        $rows = $connection->prepare(
            "SELECT {$select}, SUM(sold_quantity) sold_quantity,
                    SUM(returned_quantity) returned_quantity,
                    SUM(sales_amount) sales_amount,
                    COUNT(DISTINCT report_id) report_count
             FROM ({$base}) scoped_sales
             GROUP BY {$group}
             ORDER BY {$order}"
        );
        $rows->execute($parameters);

        return [
            'period' => $period,
            'selector' => $selector,
            'dateStart' => $start,
            'dateEnd' => $end,
            'viewBy' => $viewBy,
            'productId' => $productId,
            'employeeId' => $employeeId,
            'shopId' => $shopId,
            'products' => $options['products'],
            'employees' => $options['employees'],
            'shops' => $options['shops'],
            'showShopFilter' => count($options['shops']) > 1,
            'summary' => is_array($summaryRows) ? $summaryRows : [],
            'rows' => $rows->fetchAll(PDO::FETCH_ASSOC),
            'isAgent' => $isAgent,
        ];
    }

    /** @param list<int> $warehouseIds */
    private function scopeSql(
        array $warehouseIds,
        bool $companyWide,
        bool $isAgent,
        int $actorId
    ): string {
        if ($companyWide) {
            return '';
        }
        if ($isAgent) {
            return ' AND quick_sales.user_id = ' . (int) $actorId;
        }
        if ($warehouseIds === []) {
            return ' AND 1=0';
        }
        return ' AND quick_sales.origin_warehouse_id IN ('
            . implode(',', array_map('intval', $warehouseIds))
            . ')';
    }

    /** @return list<int> */
    private function visibleWarehouseIds(
        int $companyId,
        int $actorId,
        bool $companyWide,
        bool $isAgent
    ): array {
        if ($companyWide) {
            return [];
        }

        $connection = \db();
        if ($isAgent) {
            $statement = $connection->prepare(
                "SELECT DISTINCT access.warehouse_id
                 FROM inventory_user_warehouse_access access
                 INNER JOIN inventory_warehouses warehouses
                   ON warehouses.company_id=access.company_id
                  AND warehouses.warehouse_id=access.warehouse_id
                  AND warehouses.active=TRUE
                  AND warehouses.deleted_at IS NULL
                 WHERE access.company_id=:company_id
                   AND access.user_id=:user_id
                   AND access.active=TRUE"
            );
            $statement->execute([
                'company_id' => $companyId,
                'user_id' => $actorId,
            ]);
            $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
            if ($ids !== []) {
                sort($ids);
                return array_values(array_unique($ids));
            }

            $fallback = (new InventoryReadScope())->warehouseIds($companyId, $actorId);
            sort($fallback);
            return array_values(array_unique(array_map('intval', $fallback)));
        }

        $statement = $connection->prepare(
            "SELECT DISTINCT authority.warehouse_id
             FROM inventory_stock_authorities authority
             INNER JOIN inventory_warehouses warehouses
               ON warehouses.company_id=authority.company_id
              AND warehouses.warehouse_id=authority.warehouse_id
              AND warehouses.active=TRUE
              AND warehouses.deleted_at IS NULL
             WHERE authority.company_id=:company_id
               AND authority.user_id=:user_id
               AND authority.active=TRUE"
        );
        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $actorId,
        ]);
        $roots = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));

        if ($roots === []) {
            $roots = array_map(
                'intval',
                (new InventoryReadScope())->warehouseIds($companyId, $actorId)
            );
        }

        return $this->descendantWarehouseIds($companyId, $roots);
    }

    /** @param list<int> $rootIds @return list<int> */
    private function descendantWarehouseIds(int $companyId, array $rootIds): array
    {
        $rootIds = array_values(array_unique(array_filter(
            array_map('intval', $rootIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($rootIds === []) {
            return [];
        }

        $statement = \db()->prepare(
            "SELECT warehouse_id,parent_warehouse_id
             FROM inventory_warehouses
             WHERE company_id=:company_id
               AND active=TRUE
               AND deleted_at IS NULL"
        );
        $statement->execute(['company_id' => $companyId]);

        $children = [];
        $active = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $warehouse) {
            $warehouseId = (int) ($warehouse['warehouse_id'] ?? 0);
            if ($warehouseId < 1) {
                continue;
            }
            $active[$warehouseId] = true;
            $parentId = (int) ($warehouse['parent_warehouse_id'] ?? 0);
            if ($parentId > 0) {
                $children[$parentId][] = $warehouseId;
            }
        }

        $visible = [];
        $queue = [];
        foreach ($rootIds as $rootId) {
            if (isset($active[$rootId]) && !isset($visible[$rootId])) {
                $visible[$rootId] = true;
                $queue[] = $rootId;
            }
        }

        for ($i = 0; $i < count($queue); $i++) {
            foreach ($children[$queue[$i]] ?? [] as $childId) {
                if (!isset($visible[$childId])) {
                    $visible[$childId] = true;
                    $queue[] = $childId;
                }
            }
        }

        $ids = array_map('intval', array_keys($visible));
        sort($ids);
        return $ids;
    }

    private function baseSql(string $scopeSql, string $filters): string
    {
        return "SELECT reports.report_id, quick_sales.user_id employee_user_id,
                       employee.display_name employee_name,
                       quick_sales.origin_warehouse_id shop_id,
                       shops.name shop_name, report_lines.product_id,
                       products.sku, products.name product_name,
                       COALESCE(invoices.currency, quotations.currency) currency,
                       SUM(report_lines.sold_quantity) sold_quantity,
                       SUM(report_lines.returned_quantity) returned_quantity,
                       COALESCE(invoice_amounts.sales_amount, 0) sales_amount
                FROM sales_quick_sale_reports reports
                INNER JOIN sales_quick_sales quick_sales
                  ON quick_sales.company_id=reports.company_id
                 AND quick_sales.quick_sale_id=reports.quick_sale_id
                 AND quick_sales.status='closed'
                INNER JOIN sales_quick_sale_report_lines report_lines
                  ON report_lines.company_id=reports.company_id
                 AND report_lines.report_id=reports.report_id
                INNER JOIN (
                    SELECT company_id, quick_sale_id, MIN(created_at) business_date
                    FROM sales_quick_sale_reports
                    GROUP BY company_id, quick_sale_id
                ) business_dates
                  ON business_dates.company_id=reports.company_id
                 AND business_dates.quick_sale_id=reports.quick_sale_id
                INNER JOIN sales_products products
                  ON products.company_id=report_lines.company_id
                 AND products.product_id=report_lines.product_id
                INNER JOIN users employee ON employee.user_id=quick_sales.user_id
                INNER JOIN inventory_warehouses shops
                  ON shops.company_id=quick_sales.company_id
                 AND shops.warehouse_id=quick_sales.origin_warehouse_id
                INNER JOIN sales_quotations quotations
                  ON quotations.company_id=quick_sales.company_id
                 AND quotations.quotation_id=quick_sales.quotation_id
                LEFT JOIN finance_invoices invoices
                  ON invoices.company_id=reports.company_id
                 AND invoices.invoice_id=reports.finance_invoice_id
                LEFT JOIN (
                    SELECT company_id, invoice_id, product_id,
                           SUM(total_amount) sales_amount
                    FROM finance_invoice_lines
                    GROUP BY company_id, invoice_id, product_id
                ) invoice_amounts
                  ON invoice_amounts.company_id=reports.company_id
                 AND invoice_amounts.invoice_id=reports.finance_invoice_id
                 AND invoice_amounts.product_id=report_lines.product_id
                WHERE reports.company_id=:company_id
                  AND reports.status='confirmed'
                  AND reports.report_id=(
                      SELECT MAX(latest_report.report_id)
                      FROM sales_quick_sale_reports latest_report
                      WHERE latest_report.company_id=reports.company_id
                        AND latest_report.quick_sale_id=reports.quick_sale_id
                  )
                  AND business_dates.business_date>=:date_start
                  AND business_dates.business_date<:date_end
                  {$scopeSql}{$filters}
                GROUP BY reports.report_id, quick_sales.user_id,
                         employee.display_name, quick_sales.origin_warehouse_id,
                         shops.name, report_lines.product_id, products.sku,
                         products.name,
                         COALESCE(invoices.currency, quotations.currency),
                         invoice_amounts.sales_amount";
    }

    /** @return array{products:list<array<string,mixed>>,employees:list<array<string,mixed>>,shops:list<array<string,mixed>>} */
    private function options(
        int $companyId,
        int $actorId,
        array $warehouseIds,
        bool $companyWide,
        bool $isAgent
    ): array {
        $connection = \db();

        $productStatement = $connection->prepare(
            "SELECT product_id,sku,name
             FROM sales_products
             WHERE company_id=:company_id
               AND active=TRUE
               AND deleted_at IS NULL
             ORDER BY name,sku"
        );
        $productStatement->execute(['company_id' => $companyId]);
        $products = $productStatement->fetchAll(PDO::FETCH_ASSOC);

        $employeeParams = ['company_id' => $companyId];
        $employeeJoin = '';
        $employeeScope = '';
        if ($isAgent) {
            $employeeScope = ' AND users.user_id=:actor_id';
            $employeeParams['actor_id'] = $actorId;
        } elseif (!$companyWide) {
            if ($warehouseIds === []) {
                $employeeScope = ' AND 1=0';
            } else {
                $employeeJoin = "\n             INNER JOIN inventory_user_warehouse_access access\n"
                    . "               ON access.company_id=employees.company_id\n"
                    . "              AND access.user_id=employees.user_id\n"
                    . "              AND access.active=TRUE";
                $employeeScope = ' AND access.warehouse_id IN ('
                    . implode(',', array_map('intval', $warehouseIds))
                    . ')';
            }
        }

        $employeeStatement = $connection->prepare(
            "SELECT DISTINCT users.user_id,users.display_name
             FROM sales_agents agents
             INNER JOIN hr_employees employees
               ON employees.company_id=agents.company_id
              AND employees.employee_id=agents.employee_id
              AND employees.user_id IS NOT NULL
              AND employees.deleted_at IS NULL
             INNER JOIN users users
               ON users.user_id=employees.user_id
              AND users.active=TRUE
              AND users.deleted_at IS NULL
             INNER JOIN company_users memberships
               ON memberships.company_id=employees.company_id
              AND memberships.user_id=employees.user_id
              AND memberships.active=TRUE{$employeeJoin}
             WHERE agents.company_id=:company_id
               AND agents.active=TRUE
               AND agents.deleted_at IS NULL
               AND UPPER(TRIM(agents.agent_type)) IN ('DSA','DSP'){$employeeScope}
             ORDER BY users.display_name"
        );
        $employeeStatement->execute($employeeParams);
        $employees = $employeeStatement->fetchAll(PDO::FETCH_ASSOC);

        $shopParams = ['company_id' => $companyId];
        $shopScope = '';
        if (!$companyWide) {
            if ($warehouseIds === []) {
                $shopScope = ' AND 1=0';
            } else {
                $shopScope = ' AND warehouses.warehouse_id IN ('
                    . implode(',', array_map('intval', $warehouseIds))
                    . ')';
            }
        }

        $shopLevelFilter = $isAgent
            ? ''
            : " AND EXISTS (
                    SELECT 1
                    FROM inventory_stock_authorities authority
                    WHERE authority.company_id=warehouses.company_id
                      AND authority.warehouse_id=warehouses.warehouse_id
                      AND authority.authority_level='shop'
                      AND authority.active=TRUE
                )";

        $shopStatement = $connection->prepare(
            "SELECT warehouses.warehouse_id,warehouses.name
             FROM inventory_warehouses warehouses
             WHERE warehouses.company_id=:company_id
               AND warehouses.active=TRUE
               AND warehouses.deleted_at IS NULL{$shopScope}{$shopLevelFilter}
             ORDER BY warehouses.name"
        );
        $shopStatement->execute($shopParams);
        $shops = $shopStatement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'products' => is_array($products) ? array_values($products) : [],
            'employees' => is_array($employees) ? array_values($employees) : [],
            'shops' => is_array($shops) ? array_values($shops) : [],
        ];
    }

    /** @param list<array<string,mixed>> $options */
    private function scopedId(mixed $value, array $options, string $key): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return -1;
        }
        foreach ($options as $option) {
            if ((int) ($option[$key] ?? 0) === (int) $id) {
                return (int) $id;
            }
        }
        return -1;
    }

    /** @return array{string,string,string} */
    private function period(string $period, string $raw): array
    {
        $today = new DateTimeImmutable('today');
        if ($period === 'yearly') {
            $year = preg_match('/^(20\d{2})$/', $raw, $match) ? (int) $match[1] : (int) $today->format('Y');
            $start = new DateTimeImmutable(sprintf('%04d-01-01', $year));
            return [$start->format('Y-m-d 00:00:00'), $start->modify('+1 year')->format('Y-m-d 00:00:00'), (string) $year];
        }
        if ($period === 'monthly') {
            $selector = preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $raw) ? $raw : $today->format('Y-m');
            $start = new DateTimeImmutable($selector . '-01');
            return [$start->format('Y-m-d 00:00:00'), $start->modify('+1 month')->format('Y-m-d 00:00:00'), $selector];
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if (!$date || $date->format('Y-m-d') !== $raw) {
            $date = $today;
        }
        if ($period === 'weekly') {
            $start = $date->modify('monday this week');
            return [$start->format('Y-m-d 00:00:00'), $start->modify('+7 days')->format('Y-m-d 00:00:00'), $date->format('Y-m-d')];
        }
        return [$date->format('Y-m-d 00:00:00'), $date->modify('+1 day')->format('Y-m-d 00:00:00'), $date->format('Y-m-d')];
    }
}
