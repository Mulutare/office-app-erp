<?php

declare(strict_types=1);

$invoices = is_array($data['invoices'] ?? null) ? $data['invoices'] : [];
$filters = is_array($data['invoiceFilters'] ?? null) ? $data['invoiceFilters'] : [];
$customers = is_array($data['invoiceCustomers'] ?? null) ? $data['invoiceCustomers'] : [];
$exportQuery = http_build_query(array_filter(['format'=>'xlsx'] + $filters, static fn(mixed $value): bool => $value !== ''));
$money = static fn (mixed $amount, string $currency): string =>
    $currency . ' ' . number_format((float) $amount, 2);
?>
<div class="module-stack">
    <?php require __DIR__ . '/quick-sale-queue.php'; ?>
    <div class="page-actions"><a class="btn btn-secondary" href="<?= e(appBasePath()) ?>/data-exchange/invoices/export?<?=e($exportQuery)?>">Export Excel</a></div>
    <form method="get" action="<?= e(appBasePath()) ?>/finance/customer-invoices" class="finance-filter-form" aria-label="Customer invoice filters">
        <label>Search<input type="search" name="search" value="<?=e((string)($filters['search']??''))?>" placeholder="Invoice, customer or order"></label>
        <label>Payment<select name="payment"><option value="">All payment states</option><?php foreach(['not_paid'=>'Outstanding','partial'=>'Partial','paid'=>'Paid','credit'=>'Credit'] as $value=>$label):?><option value="<?=e($value)?>" <?=($filters['payment']??'')===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label>
        <label>Date from<input type="date" name="date_from" value="<?=e((string)($filters['date_from']??''))?>"></label>
        <label>Date to<input type="date" name="date_to" value="<?=e((string)($filters['date_to']??''))?>"></label>
        <label>Customer<select name="customer"><option value="">All customers</option><?php foreach($customers as $customer):?><option value="<?=e((string)$customer)?>" <?=($filters['customer']??'')===(string)$customer?'selected':''?>><?=e((string)$customer)?></option><?php endforeach;?></select></label>
        <div class="filter-actions"><button class="btn btn-primary" type="submit">Apply filters</button><a class="btn btn-secondary" href="<?= e(appBasePath()) ?>/finance/customer-invoices">Clear</a></div>
    </form>
    <section class="card table-card">
        <h2>Customer invoices</h2>
        <p>Open posted invoices to register and allocate customer payments.</p>
        <table class="data-table">
            <thead><tr><th>Invoice</th><th>Customer</th><th>Sales Order</th><th>Date</th><th>Due</th><th>Total</th><th>Residual</th><th>State</th><th>Payment</th></tr></thead>
            <tbody>
            <?php if ($invoices === []): ?>
                <tr><td colspan="9" class="empty-state">No customer invoices.</td></tr>
            <?php endif; ?>
            <?php foreach ($invoices as $invoice): ?>
                <tr>
                    <td><strong><a href="<?= e(appBasePath()) ?>/finance/customer-invoices/<?= e((string) $invoice['invoice_id']) ?>"><?= e((string) $invoice['invoice_number']) ?></a></strong></td>
                    <td><?= e((string) $invoice['customer_name']) ?></td>
                    <td><?= e((string) ($invoice['order_number'] ?? '—')) ?></td>
                    <td><?= e((string) $invoice['invoice_date']) ?></td>
                    <td><?= e((string) $invoice['due_date']) ?></td>
                    <td><?= e($money($invoice['total_amount'], (string) $invoice['currency'])) ?></td>
                    <td><?= e($money($invoice['residual_amount'], (string) $invoice['currency'])) ?></td>
                    <td><?= e(strtoupper((string) $invoice['status'])) ?></td>
                    <td><?= e(strtoupper(str_replace('_', ' ', (string) $invoice['payment_status']))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>
