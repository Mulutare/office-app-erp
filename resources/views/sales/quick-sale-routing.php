<?php declare(strict_types=1); ?>
<?php if ($isManager && $status === 'submitted' && empty($detail['stockCheck']['sufficient_locations'])): ?>
<section class="card qs-routing">
    <h2>Stock unavailable</h2>
    <p>This sale stays pending. The responsible manager can request replenishment for their own warehouse, then allocate stock after receipt.</p>
    <?php foreach (($detail['replenishment']['transfers'] ?? []) as $link): ?>
        <p>Company stock transfer requested: <?= e($link['transfer_number']) ?> — <?= e($link['status']) ?></p>
    <?php endforeach; ?>
    <?php foreach (($detail['replenishment']['requisitions'] ?? []) as $link): ?>
        <p>Company procurement: <?= e($link['requisition_number']) ?> — <?= e($link['status']) ?></p>
    <?php endforeach; ?>
    <a class="btn btn-primary" href="<?=e(appBasePath())?>/inventory/stock-requests">Request Stock</a>
</section>
<?php endif; ?>
<?php if ($isManager && $status === 'closed' && $managerReport && !empty($managerReport['finance_invoice_id'])): ?>
<section class="card qs-routing">
    <h2>Finance handoff</h2>
    <?php if (!empty($managerReport['finance_handoff_at'])): ?>
        <p>Sent to Finance: <?= e($managerReport['finance_handoff_at']) ?></p>
    <?php else: ?>
        <p>The report is confirmed and its invoice is ready for Finance.</p>
        <form method="post" action="<?= e(appBasePath()) ?>/sales/quick-sale/<?= e($sale['quick_sale_id']) ?>/reports/<?= e($managerReport['report_id']) ?>/handoff">
            <?= csrfField() ?>
            <button class="btn btn-primary" type="submit">Send to Finance</button>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php if (!empty($detail['routingHistory'])): ?>
<section class="card qs-routing">
    <h2>Activity</h2>
    <ul>
    <?php foreach ($detail['routingHistory'] as $event): ?>
        <?php if ($isOwner && $event['action'] === 'quick_sale.finance_handoff') continue; ?>
        <?php $values = json_decode((string) $event['new_values'], true) ?: []; ?>
        <li>
            <strong>
                <?= e($sale['quotation_number'] ?? ('Quick Sale #' . $sale['quick_sale_id'])) ?>
                <?php if (!empty($sale['order_number'])): ?> / <?= e($sale['order_number']) ?><?php endif; ?>
            </strong>
            - <?= e($event['created_at']) ?> - <?= e($event['actor_name']) ?>:
            <?= e(ucfirst(str_replace('_', ' ', str_replace('quick_sale.', '', $event['action'])))) ?>
            <?php if (!empty($values['reason'])): ?> - <?= e($values['reason']) ?><?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
