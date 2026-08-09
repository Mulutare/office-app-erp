<?php

declare(strict_types=1);

$invoices = is_array($data['invoices'] ?? null) ? $data['invoices'] : [];
$money = static fn (mixed $amount, string $currency): string =>
    $currency . ' ' . number_format((float) $amount, 2);
?>
<div class="module-stack">
    <nav class="card details-toolbar">
        <a class="btn btn-secondary" href="/office_app/public/finance">Finance</a>
        <a class="btn btn-primary" href="/office_app/public/finance/customer-invoices">Customer Invoices</a>
    </nav>
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
                    <td><strong><a href="/office_app/public/finance/customer-invoices/<?= e((string) $invoice['invoice_id']) ?>"><?= e((string) $invoice['invoice_number']) ?></a></strong></td>
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
