<?php

declare(strict_types=1);

$picking = $data['delivery'];
$isReturn = (string) $picking['picking_type'] === 'customer_return';
$open = in_array((string) $picking['status'], ['ready', 'partially_done'], true);
$waiting = (string) $picking['status'] === 'waiting_stock';
$completedDelivery = !$isReturn && in_array((string) $picking['status'], ['done', 'partially_done'], true);
$returnable = array_sum(array_map(
    static fn (array $line): float => (float) ($line['returnable_quantity'] ?? 0),
    $picking['lines']
));
?>
<div class="module-stack">
    <?php if (!empty($data['notice'])): ?><div class="alert alert-success" role="status"><?= e((string) ($data['notice']['message'] ?? 'Completed.')) ?></div><?php endif; ?>
    <?php foreach ((array) ($data['errors'] ?? []) as $error): ?><div class="alert alert-danger" role="alert"><strong>Inventory operation not completed.</strong> <?= e((string) $error) ?></div><?php endforeach; ?>
    <nav class="card details-toolbar">
        <a class="btn btn-secondary" href="/office_app/public/sales/orders/<?= e((string) $picking['sales_order_id']) ?>">Sales Order <?= e((string) $picking['order_number']) ?></a>
        <a class="btn btn-secondary" href="/office_app/public/sales/deliveries">Deliveries</a>
        <?php if (is_array($picking['original'] ?? null)): ?><a class="btn btn-secondary" href="/office_app/public/sales/deliveries/<?= e((string) $picking['original']['picking_id']) ?>">Original Delivery <?= e((string) $picking['original']['picking_number']) ?></a><?php endif; ?>
    </nav>
    <section class="card">
        <p><strong>Document:</strong> <?= e($isReturn ? 'Customer Return' : 'Delivery') ?> · <strong>Customer:</strong> <?= e((string) $picking['customer_name']) ?> · <strong>State:</strong> <?= e(str_replace('_', ' ', (string) $picking['status'])) ?></p>
        <p><strong>Warehouse:</strong> <?= e((string) $picking['warehouse_name']) ?> · <?= e((string) $picking['source_location_name']) ?> → <?= e((string) $picking['destination_location_name']) ?></p>
        <?php if ($waiting): ?><div class="alert alert-warning"><strong>Waiting for stock.</strong> Nothing is reserved, so this delivery cannot be validated yet.</div><?php endif; ?>
    </section>
    <?php if ($waiting && !empty($data['canComplete'])): ?><form method="post" action="/office_app/public/sales/deliveries/<?= e((string) $picking['picking_id']) ?>/reserve"><?= csrfField() ?><button class="btn btn-primary">Check availability and reserve stock</button></form><?php endif; ?>
    <form method="post" action="/office_app/public/sales/deliveries/<?= e((string) $picking['picking_id']) ?>/complete">
        <?= csrfField() ?><input type="hidden" name="idempotency_key" value="<?= e(bin2hex(random_bytes(16))) ?>">
        <section class="card table-card">
            <h2><?= e($isReturn ? 'Return quantities' : 'Delivery quantities') ?></h2>
            <div class="table-responsive"><table class="data-table"><thead><tr><th>Product</th><th>Requested</th><th>Reserved</th><th>Done</th><th>Remaining</th><?php if ($open && !empty($data['canComplete'])): ?><th>Complete now</th><?php endif; ?></tr></thead><tbody>
            <?php foreach ($picking['lines'] as $line): ?><tr><td><?= e((string) $line['sku'] . ' - ' . (string) $line['product_name']) ?></td><td><?= e((string) $line['requested_quantity']) ?></td><td><?= e((string) $line['reserved_quantity']) ?></td><td><?= e((string) $line['completed_quantity']) ?></td><td><?= e((string) $line['remaining_quantity']) ?></td><?php if ($open && !empty($data['canComplete'])): ?><td><input type="number" name="completed_quantity[<?= e((string) $line['picking_line_id']) ?>]" min="0" max="<?= e((string) $line['remaining_quantity']) ?>" step="0.001" value="<?= e((string) $line['remaining_quantity']) ?>"></td><?php endif; ?></tr><?php endforeach; ?>
            </tbody></table></div>
            <?php if ($open && !empty($data['canComplete'])): ?><label><input type="checkbox" name="create_backorder" value="1" checked> Create a backorder for any remaining quantity</label><button class="btn btn-primary"><?= e($isReturn ? 'Validate Return' : 'Validate Delivery') ?></button><?php endif; ?>
        </section>
    </form>
    <?php if ($completedDelivery && $returnable > 0.0005 && !empty($data['canReturn'])): ?>
    <form method="post" action="/office_app/public/sales/deliveries/<?= e((string) $picking['picking_id']) ?>/returns">
        <?= csrfField() ?>
        <section class="card table-card"><h2>Create Return</h2><p>Create a separate Inventory return document. The original delivery remains unchanged.</p><table class="data-table"><thead><tr><th>Product</th><th>Delivered</th><th>Previously returned</th><th>Returnable</th><th>Return now</th></tr></thead><tbody>
        <?php foreach ($picking['lines'] as $line): ?><tr><td><?= e((string) $line['sku'] . ' - ' . (string) $line['product_name']) ?></td><td><?= e((string) $line['completed_quantity']) ?></td><td><?= e((string) $line['returned_quantity']) ?></td><td><?= e((string) $line['returnable_quantity']) ?></td><td><input type="number" name="return_quantity[<?= e((string) $line['picking_line_id']) ?>]" min="0" max="<?= e((string) $line['returnable_quantity']) ?>" step="0.001" value="0"></td></tr><?php endforeach; ?>
        </tbody></table><button class="btn btn-primary">Create Return</button></section>
    </form>
    <?php endif; ?>
    <?php if (!$isReturn): ?><section class="card table-card"><h2>Return history</h2><table class="data-table"><thead><tr><th>Return</th><th>State</th><th>Created</th><th>Completed</th></tr></thead><tbody><?php if (($picking['returns'] ?? []) === []): ?><tr><td colspan="4" class="empty-state">No returns created.</td></tr><?php endif; ?><?php foreach ((array) ($picking['returns'] ?? []) as $return): ?><tr><td><a href="/office_app/public/sales/deliveries/<?= e((string) $return['picking_id']) ?>"><?= e((string) $return['picking_number']) ?></a></td><td><?= e((string) $return['status']) ?></td><td><?= e((string) $return['created_at']) ?></td><td><?= e((string) ($return['completed_at'] ?? '')) ?></td></tr><?php endforeach; ?></tbody></table></section><?php endif; ?>
</div>
