<?php declare(strict_types=1); ?>
<?php if ($isManager && $status === 'submitted' && empty($detail['stockCheck']['sufficient_locations'])): ?>
<section class="card qs-routing">
    <h2>Stock unavailable</h2>
    <p>No authorized source in this warehouse can supply the full request. Forward this same request to your direct parent manager. At the highest manager it stays open until stock is available.</p>
    <form method="post" action="<?= e(appBasePath()) ?>/sales/quick-sale/<?= e($sale['quick_sale_id']) ?>/escalate">
        <?= csrfField() ?>
        <label>Reason<input name="reason" required maxlength="2000" value="Insufficient available stock at assigned sources."></label>
        <button class="btn btn-primary" type="submit">Escalate / record unavailable stock</button>
    </form>
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
                / Quick Sale ID <?= e($sale['quick_sale_id']) ?>
                <?php if (!empty($sale['order_number'])): ?>
                    / <?= e($sale['order_number']) ?>
                    / Order ID <?= e($sale['sales_order_id']) ?>
                <?php endif; ?>
                <?php if (!empty($values['report_id'])): ?>
                    / Report #<?= e($values['report_id']) ?>
                <?php endif; ?>
            </strong>
            - <?= e($event['created_at']) ?> - <?= e($event['actor_name']) ?>:
            <?= e(ucfirst(str_replace('_', ' ', str_replace('quick_sale.', '', $event['action'])))) ?>
            <?php if (!empty($values['reason'])): ?> - <?= e($values['reason']) ?><?php endif; ?>
            <?php if (!$isOwner && !empty($values['from_manager_id'])): ?>
                (manager <?= e($values['from_manager_id']) ?> to <?= e($values['to_manager_id'] ?? 'no parent') ?>)
            <?php endif; ?>
            <?php if (!$isOwner && !empty($values['checked'])): ?>
                <ul>
                <?php foreach ($values['checked'] as $stock): ?>
                    <li>Warehouse <?= e($stock['warehouse_id']) ?> / location <?= e($stock['location_id']) ?>,
                        product <?= e($stock['product_id']) ?>: required <?= e($stock['required']) ?>,
                        available <?= e($stock['available']) ?></li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
