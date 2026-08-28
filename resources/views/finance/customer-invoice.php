<?php

declare(strict_types=1);

$invoice = $data['invoice'];
$lines = is_array($invoice['lines'] ?? null) ? $invoice['lines'] : [];
$payments = is_array($invoice['payments'] ?? null) ? $invoice['payments'] : [];
$journals = is_array($data['paymentJournals'] ?? null) ? $data['paymentJournals'] : [];
$money = static fn (mixed $amount): string =>
    (string) $invoice['currency'] . ' ' . number_format((float) $amount, 2);
$canPay = !empty($data['canRegisterPayment'])
    && (string) $invoice['document_type'] === 'customer_invoice'
    && (string) $invoice['status'] === 'posted'
    && (string) $invoice['payment_status'] !== 'paid'
    && (float) $invoice['residual_amount'] > 0;
$canPost = !empty($data['canPostInvoice']) && (string) $invoice['status'] === 'draft';
?>
<div class="module-stack">
    <?php \view('components.sales-workflow-trace', ['workflowTrace' => $data['workflowTrace'] ?? null]); ?>
    <?php if (is_array($data['notice'] ?? null)): ?><div class="alert alert-success" role="status"><?= e((string) ($data['notice']['message'] ?? '')) ?></div><?php endif; ?>
    <?php foreach ((array) ($data['errors'] ?? []) as $error): ?><?php if(is_array($error)):?><?php \view('components.app-error',['error'=>$error]);?><?php else:?><div class="alert alert-danger" role="alert"><?= e((string) $error) ?></div><?php endif;?><?php endforeach; ?>
    <nav class="card details-toolbar">
        <a class="btn btn-secondary" href="/office_app/public/finance">Finance</a>
        <a class="btn btn-secondary" href="/office_app/public/finance/customer-invoices">Customer Invoices</a>
        <?php if (!empty($invoice['sales_order_id'])): ?><a class="btn btn-secondary" href="/office_app/public/sales/orders/<?= e((string) $invoice['sales_order_id']) ?>">Sales Order <?= e((string) $invoice['order_number']) ?></a><?php endif; ?>
        <a class="btn btn-secondary" href="/office_app/public/finance/customer-invoices/<?= e((string) $invoice['invoice_id']) ?>/invoice.pdf">Download Invoice PDF</a>
    </nav>
    <section class="finance-summary-grid">
        <article class="card"><span>Invoice state</span><strong><?= e(strtoupper((string) $invoice['status'])) ?></strong></article>
        <article class="card"><span>Payment state</span><strong><?= e(strtoupper(str_replace('_', ' ', (string) $invoice['payment_status']))) ?></strong></article>
        <article class="card"><span>Total</span><strong><?= e($money($invoice['total_amount'])) ?></strong></article>
        <article class="card"><span>Residual</span><strong><?= e($money($invoice['residual_amount'])) ?></strong></article>
    </section>
    <section class="card">
        <h2><?= e((string) $invoice['invoice_number']) ?></h2>
        <p><strong>Customer:</strong> <?= e((string) $invoice['customer_name']) ?> · <strong>Sales Order:</strong> <?= e((string) ($invoice['order_number'] ?? '—')) ?></p>
        <p><strong>Invoice date:</strong> <?= e((string) $invoice['invoice_date']) ?> · <strong>Due date:</strong> <?= e((string) $invoice['due_date']) ?></p>
        <p><strong>Journal:</strong> <?= e((string) $invoice['journal_code']) ?> — <?= e((string) $invoice['journal_name']) ?> · <strong>Posting reference:</strong> <?= e((string) ($invoice['posting_reference'] ?? 'Not posted')) ?></p>
        <?php if ((string) $invoice['status'] === 'posted'): ?><p class="page-description">This posted invoice is accounting-locked. Its quantities, prices, discounts, taxes and totals cannot be edited.</p><?php endif; ?>
    </section>
    <section class="card table-card">
        <h2>Invoice lines</h2>
        <table class="data-table">
            <thead><tr><th>Product</th><th>Description</th><th>Quantity</th><th>Unit price</th><th>Discount</th><th>Tax</th><th>Untaxed</th><th>Tax amount</th><th>Total</th></tr></thead>
            <tbody><?php foreach ($lines as $line): ?><tr>
                <td><?= e(trim((string) ($line['sku'] ?? '') . ' ' . (string) ($line['product_name'] ?? ''))) ?></td>
                <td><?= e((string) $line['description']) ?></td>
                <td><?= e((string) $line['quantity']) ?></td>
                <td><?= e($money($line['unit_price'])) ?></td>
                <td><?= e($money($line['discount_amount'])) ?></td>
                <td><?= e(number_format((float) $line['tax_rate'], 2) . '%') ?></td>
                <td><?= e($money($line['untaxed_amount'])) ?></td>
                <td><?= e($money($line['tax_amount'])) ?></td>
                <td><?= e($money($line['total_amount'])) ?></td>
            </tr><?php endforeach; ?></tbody>
        </table>
        <p><strong>Untaxed amount:</strong> <?= e($money($invoice['untaxed_amount'])) ?> · <strong>Discount:</strong> <?= e($money($invoice['discount_amount'])) ?> · <strong>Tax amount:</strong> <?= e($money($invoice['tax_amount'])) ?> · <strong>Total:</strong> <?= e($money($invoice['total_amount'])) ?> · <strong>Residual:</strong> <?= e($money($invoice['residual_amount'])) ?></p>
    </section>
    <?php if ($canPost): ?><section class="card"><h2>Post Invoice</h2><p>Posting locks the commercial amounts and creates the receivable, revenue and tax journal entry.</p><form method="post" action="/office_app/public/finance/customer-invoices/<?= e((string) $invoice['invoice_id']) ?>/post"><?= csrfField() ?><button class="btn btn-primary">Post Invoice</button></form></section><?php endif; ?>
    <?php if ($canPay): ?>
    <section class="card">
        <h2>Register Payment</h2>
        <form method="post" action="/office_app/public/finance/customer-invoices/<?= e((string) $invoice['invoice_id']) ?>/payments">
            <?= csrfField() ?>
            <div class="finance-filter-form">
                <label>Payment journal<select name="journal_id" required><option value="">Select bank or cash</option><?php foreach ($journals as $journal): ?><option value="<?= e((string) $journal['journal_id']) ?>"><?= e((string) $journal['journal_code'] . ' — ' . (string) $journal['journal_name']) ?></option><?php endforeach; ?></select></label>
                <label>Payment date<input type="date" name="payment_date" value="<?= e(date('Y-m-d')) ?>" required></label>
                <label>Amount<input type="number" name="amount" min="0.01" max="<?= e((string) $invoice['residual_amount']) ?>" step="0.01" value="<?= e(number_format((float) $invoice['residual_amount'], 2, '.', '')) ?>" required></label>
                <label>Method<select name="method"><option value="bank_transfer">Bank transfer</option><option value="cash">Cash</option><option value="check">Check</option><option value="card">Card</option></select></label>
                <label>Reference<input name="reference_number" maxlength="120"></label>
            </div>
            <button class="btn btn-primary">Register Payment</button>
        </form>
    </section>
    <?php endif; ?>
    <section class="card table-card" id="payments">
        <h2>Payments and allocations</h2>
        <table class="data-table"><thead><tr><th>Payment</th><th>Date</th><th>Amount</th><th>Allocated</th><th>Method</th><th>Reference</th><th>Posting reference</th><th>Status</th></tr></thead><tbody>
        <?php if ($payments === []): ?><tr><td colspan="8" class="empty-state">No payments have been allocated.</td></tr><?php endif; ?>
        <?php foreach ($payments as $payment): ?><tr><td><?= e((string) $payment['payment_number']) ?></td><td><?= e((string) $payment['payment_date']) ?></td><td><?= e($money($payment['amount'])) ?></td><td><?= e($money($payment['allocated_amount'])) ?></td><td><?= e(str_replace('_', ' ', (string) $payment['method'])) ?></td><td><?= e((string) ($payment['reference_number'] ?? '')) ?></td><td><?= e((string) ($payment['posting_reference'] ?? '')) ?></td><td><?= e((string) $payment['status']) ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php if ($payments !== []): ?><div class="details-toolbar"><?php foreach ($payments as $payment): ?><a class="btn btn-secondary" href="/office_app/public/finance/payments/<?= e((string) $payment['payment_id']) ?>/receipt.pdf">Download Payment Receipt PDF — <?= e((string) $payment['payment_number']) ?></a><?php endforeach; ?></div><?php endif; ?>
    </section>
</div>
