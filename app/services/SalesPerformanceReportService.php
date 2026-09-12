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
        $userIds = $companyWide ? [] : $scope->userIds($companyId, $actorId);
        $warehouseIds = $companyWide
            ? []
            : (new InventoryReadScope())->warehouseIds($companyId, $actorId);

        $period = in_array(($input['period'] ?? ''), ['daily', 'weekly', 'monthly', 'yearly'], true)
            ? (string) $input['period']
            : 'monthly';
        [$start, $end, $selector] = $this->period($period, (string) ($input['date'] ?? ''));

        $viewBy = in_array(($input['view_by'] ?? ''), ['product', 'employee', 'product_employee'], true)
            ? (string) $input['view_by']
            : 'product';

        $scopeSql = $this->scopeSql($userIds, $warehouseIds, $companyWide);
        $options = $this->options($companyId, $scopeSql);
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

    /** @param list<int> $userIds @param list<int> $warehouseIds */
    private function scopeSql(array $userIds, array $warehouseIds, bool $companyWide): string
    {
        if ($companyWide) {
            return '';
        }
        if ($userIds === [] || $warehouseIds === []) {
            return ' AND 1=0';
        }
        return ' AND quick_sales.user_id IN (' . implode(',', array_map('intval', $userIds)) . ')'
            . ' AND quick_sales.origin_warehouse_id IN (' . implode(',', array_map('intval', $warehouseIds)) . ')';
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
    private function options(int $companyId, string $scopeSql): array
    {
        $connection = \db();
        $from = " FROM sales_quick_sale_reports reports
                  INNER JOIN sales_quick_sales quick_sales
                    ON quick_sales.company_id=reports.company_id
                   AND quick_sales.quick_sale_id=reports.quick_sale_id
                   AND quick_sales.status='closed'
                  INNER JOIN sales_quick_sale_report_lines report_lines
                    ON report_lines.company_id=reports.company_id
                   AND report_lines.report_id=reports.report_id
                  INNER JOIN users employee ON employee.user_id=quick_sales.user_id
                  INNER JOIN inventory_warehouses shops
                    ON shops.company_id=quick_sales.company_id
                   AND shops.warehouse_id=quick_sales.origin_warehouse_id
                  INNER JOIN sales_products products
                    ON products.company_id=report_lines.company_id
                   AND products.product_id=report_lines.product_id
                  WHERE reports.company_id=:company_id
                    AND reports.status='confirmed'
                    AND reports.report_id=(
                        SELECT MAX(latest_report.report_id)
                        FROM sales_quick_sale_reports latest_report
                        WHERE latest_report.company_id=reports.company_id
                          AND latest_report.quick_sale_id=reports.quick_sale_id
                    ) {$scopeSql}";
        $queries = [
            'products' => 'SELECT DISTINCT products.product_id, products.sku, products.name' . $from . ' ORDER BY products.name, products.sku',
            'employees' => 'SELECT DISTINCT quick_sales.user_id, employee.display_name' . $from . ' ORDER BY employee.display_name',
            'shops' => 'SELECT DISTINCT quick_sales.origin_warehouse_id warehouse_id, shops.name' . $from . ' ORDER BY shops.name',
        ];
        $result = [];
        foreach ($queries as $key => $sql) {
            $statement = $connection->prepare($sql);
            $statement->execute(['company_id' => $companyId]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $result[$key] = is_array($rows) ? array_values($rows) : [];
        }
        return $result;
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
