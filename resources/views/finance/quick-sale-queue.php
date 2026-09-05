<?php declare(strict_types=1); $queue = $data['quickSaleQueue'] ?? []; ?>
<?php if ($queue !== []): ?>
<section class="card">
    <h2>Quick Sales handed to Finance</h2>
    <p>Open the invoice to post it and record payment. Use Sales settlement for the eligible payment, then add separate bank confirmation and reconcile.</p>
    <div class="qs-finance-grid">
    <?php foreach ($queue as $task): ?>
        <article class="qs-finance-card">
            <h3><?= e($task['order_number']) ?> / <?= e($task['invoice_number']) ?></h3>
            <p><?= e($task['customer_name']) ?> - <?= e($task['currency']) ?> <?= e(number_format((float) $task['total_amount'], 2)) ?></p>
            <p>DSA/DSP: <?= e($task['agent_name']) ?><br>Manager: <?= e($task['manager_name']) ?></p>
            <p>
                DSA receipt: <?= e(trim((string) ($task['invoice_reference'] ?? '')) !== '' ? $task['invoice_reference'] : 'Not provided') ?><br>
                DSA payment: <?= e(trim((string) ($task['payment_method'] ?? '')) !== '' ? ucwords(str_replace('_', ' ', (string) $task['payment_method'])) : 'Not provided') ?><br>
                DSA payment ref: <?= e(trim((string) ($task['payment_reference'] ?? '')) !== '' ? $task['payment_reference'] : 'Not provided') ?>
            </p>
            <p>Handed off <?= e($task['finance_handoff_at']) ?><br>Invoice: <?= e($task['invoice_status']) ?> / Payment: <?= e($task['payment_status']) ?></p>
            <a class="btn btn-primary" href="<?= e(appBasePath()) ?>/finance/customer-invoices/<?= e($task['invoice_id']) ?>">Process invoice / payment</a>
            <?php if (!empty($task['has_evidence'])): ?>
                <a href="<?= e(appBasePath()) ?>/sales/quick-sale/<?= e($task['quick_sale_id']) ?>/reports/<?= e($task['report_id']) ?>/evidence" target="_blank" rel="noopener">View DSA Receipt</a>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
