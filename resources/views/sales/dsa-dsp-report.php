<?php

declare(strict_types=1);

$report = is_array($data['report'] ?? null) ? $data['report'] : [];
$period = (string) ($report['period'] ?? 'monthly');
$viewBy = (string) ($report['viewBy'] ?? 'product');
$rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
$summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
$summarySold = array_sum(array_map(static fn (array $row): float => (float) ($row['sold_quantity'] ?? 0), $summary));
$summaryReturned = array_sum(array_map(static fn (array $row): float => (float) ($row['returned_quantity'] ?? 0), $summary));
$summaryReports = array_sum(array_map(static fn (array $row): int => (int) ($row['report_count'] ?? 0), $summary));
$amounts = $summary === []
    ? '—'
    : implode(' · ', array_map(static fn (array $row): string => (string) ($row['currency'] ?? 'ETB') . ' ' . number_format((float) ($row['sales_amount'] ?? 0), 2), $summary));
$selectorType = $period === 'monthly' ? 'month' : ($period === 'yearly' ? 'number' : 'date');
$selectorLabel = match ($period) {
    'weekly' => 'Week containing',
    'monthly' => 'Month',
    'yearly' => 'Year',
    default => 'Date',
};
?>

<div class="sales-workspace">
    <section class="card">
        <form method="get" action="<?= e(appBasePath()) ?>/sales/dsa-dsp-report" class="filters-form">
            <div class="form-grid">
                <fieldset class="form-field">
                    <legend>Period</legend>
                    <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $key => $label): ?>
                        <label><input type="radio" name="period" value="<?= e($key) ?>"<?= $period === $key ? ' checked' : '' ?>> <?= e($label) ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <div class="form-field">
                    <label for="report-date"><?= e($selectorLabel) ?></label>
                    <input id="report-date" type="<?= e($selectorType) ?>" name="date" value="<?= e($report['selector'] ?? '') ?>"<?= $period === 'yearly' ? ' min="2000" max="2099"' : '' ?>>
                </div>
                <div class="form-field">
                    <label for="report-product">Product</label>
                    <select id="report-product" name="product_id">
                        <option value="">All products</option>
                        <?php foreach (($report['products'] ?? []) as $product): ?>
                            <option value="<?= e($product['product_id']) ?>"<?= (int) ($report['productId'] ?? 0) === (int) $product['product_id'] ? ' selected' : '' ?>><?= e(trim(($product['sku'] ?? '') . ' - ' . ($product['name'] ?? ''))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label for="report-employee">Employee</label>
                    <select id="report-employee" name="employee_id">
                        <option value="">All employees</option>
                        <?php foreach (($report['employees'] ?? []) as $employee): ?>
                            <option value="<?= e($employee['user_id']) ?>"<?= (int) ($report['employeeId'] ?? 0) === (int) $employee['user_id'] ? ' selected' : '' ?>><?= e($employee['display_name'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!empty($report['showShopFilter'])): ?>
                    <div class="form-field">
                        <label for="report-shop">Shop</label>
                        <select id="report-shop" name="shop_id">
                            <option value="">All authorized shops</option>
                            <?php foreach (($report['shops'] ?? []) as $shop): ?>
                                <option value="<?= e($shop['warehouse_id']) ?>"<?= (int) ($report['shopId'] ?? 0) === (int) $shop['warehouse_id'] ? ' selected' : '' ?>><?= e($shop['name'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="form-field">
                    <label for="report-view">View by</label>
                    <select id="report-view" name="view_by">
                        <option value="product"<?= $viewBy === 'product' ? ' selected' : '' ?>>Product</option>
                        <option value="employee"<?= $viewBy === 'employee' ? ' selected' : '' ?>>Employee</option>
                        <option value="product_employee"<?= $viewBy === 'product_employee' ? ' selected' : '' ?>>Product + Employee</option>
                    </select>
                </div>
            </div>
            <div class="page-actions"><button class="btn btn-primary" type="submit">Apply filters</button></div>
        </form>
    </section>

    <section class="finance-summary-grid" aria-label="Sales report summary">
        <article class="card finance-summary-card"><span>Total Sales Amount</span><strong class="erp-money"><?= e($amounts) ?></strong></article>
        <article class="card finance-summary-card"><span>Sold Quantity</span><strong><?= e(number_format($summarySold, 3, '.', '')) ?></strong></article>
        <article class="card finance-summary-card"><span>Returned Quantity</span><strong><?= e(number_format($summaryReturned, 3, '.', '')) ?></strong></article>
        <article class="card finance-summary-card"><span>Finalized Reports</span><strong><?= e($summaryReports) ?></strong></article>
    </section>

    <section class="card">
        <div class="section-heading"><div><h2>Finalized sales</h2><p><?= e(substr((string) ($report['dateStart'] ?? ''), 0, 10)) ?> to <?= e(date('Y-m-d', strtotime((string) ($report['dateEnd'] ?? '')) - 86400)) ?></p></div></div>
        <?php if ($rows === []): ?>
            <p>No finalized sales found for the selected period and filters.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead><tr>
                        <?php if ($viewBy !== 'product'): ?><th>Employee</th><th>Shop</th><?php endif; ?>
                        <?php if ($viewBy !== 'employee'): ?><th>Product</th><?php endif; ?>
                        <th>Sold Qty</th><th>Returned Qty</th><th>Sales Amount</th><th>Reports</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php if ($viewBy !== 'product'): ?><td><?= e($row['employee_name'] ?? '') ?></td><td><?= e($row['shop_name'] ?? '') ?></td><?php endif; ?>
                            <?php if ($viewBy !== 'employee'): ?><td><?= e(trim(($row['sku'] ?? '') . ' - ' . ($row['product_name'] ?? ''))) ?></td><?php endif; ?>
                            <td><?= e(number_format((float) ($row['sold_quantity'] ?? 0), 3, '.', '')) ?></td>
                            <td><?= e(number_format((float) ($row['returned_quantity'] ?? 0), 3, '.', '')) ?></td>
                            <td class="erp-money erp-money-column"><?= e(($row['currency'] ?? 'ETB') . ' ' . number_format((float) ($row['sales_amount'] ?? 0), 2)) ?></td>
                            <td><?= e((int) ($row['report_count'] ?? 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
