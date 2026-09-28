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
        <?php view('components.list-filters',['query'=>$report['list']['query'],'path'=>appBasePath().'/sales/dsa-dsp-report',
            'sorts'=>$report['listSorts'],
            'filters'=>['period'=>['label'=>'Period','options'=>['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','yearly'=>'Yearly']],
                'date'=>['label'=>$selectorLabel,'type'=>$selectorType==='number'?'text':$selectorType],
                'from'=>['label'=>'From date (override period)','type'=>'date'],'to'=>['label'=>'To date (override period)','type'=>'date'],
                'product_id'=>['label'=>'Product','options'=>array_column($report['products'],'name','product_id')],
                'employee_id'=>['label'=>'Employee','options'=>array_column($report['employees'],'display_name','user_id')],
                'shop_id'=>['label'=>'Authorized shop','options'=>array_column($report['shops'],'name','warehouse_id')],
                'view_by'=>['label'=>'View by','options'=>['product'=>'Product','employee'=>'Employee','product_employee'=>'Product + employee']]],
        ]); ?>
        <?php view('components.list-download',['query'=>$report['list']['query'],'path'=>appBasePath().'/sales/dsa-dsp-report','allowed'=>$data['canExportList']??false]); ?>
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

<?php view('components.list-pagination',['query'=>$report['list']['query'],'pagination'=>$report['list']['pagination'],'path'=>appBasePath().'/sales/dsa-dsp-report']); ?>
