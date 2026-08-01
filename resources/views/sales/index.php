<?php

declare(strict_types=1);

$data = is_array($data ?? null) ? $data : [];
$summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
$orders = is_array($data['orders'] ?? null) ? $data['orders'] : [];
$customers = is_array($data['customers'] ?? null) ? $data['customers'] : [];
$products = is_array($data['products'] ?? null) ? $data['products'] : [];
$agents = is_array($data['agents'] ?? null) ? $data['agents'] : [];
$territories = is_array($data['territories'] ?? null) ? $data['territories'] : [];
$targets = is_array($data['targets'] ?? null) ? $data['targets'] : [];
$commissions = is_array($data['commissions'] ?? null) ? $data['commissions'] : [];
$serialNumbers = is_array($data['serialNumbers'] ?? null) ? $data['serialNumbers'] : [];
$notice = is_array($data['notice'] ?? null) ? $data['notice'] : null;
$errors = is_array($data['errors'] ?? null) ? $data['errors'] : [];
$old = is_array($data['old'] ?? null) ? $data['old'] : [];
$currency = (string) ($data['user']['company']['default_currency'] ?? 'ETB');
$money = static fn (mixed $amount): string => $currency . ' ' . number_format((float) $amount, 2);
$today = date('Y-m-d');
$canOrderActions = !empty($data['canSubmitOrders'])
    || !empty($data['canApproveOrders'])
    || !empty($data['canFulfillOrders'])
    || !empty($data['canCancelOrders']);
?>

<div class="sales-workspace">

<?php if ($notice !== null): ?>
    <div class="alert alert-success" role="status"><?= e($notice['message'] ?? '') ?></div>
<?php endif; ?>
<?php if ($errors !== []): ?>
    <div class="alert alert-danger" role="alert">
        <strong>The request was not saved.</strong>
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="finance-summary-grid sales-summary-grid" aria-label="Sales summary">
    <article class="card finance-summary-card sales-summary-card"><span>Orders</span><strong><?= e($summary['orderCount'] ?? 0) ?></strong></article>
    <article class="card finance-summary-card sales-summary-card"><span>Total sales</span><strong><?= e($money($summary['salesTotal'] ?? 0)) ?></strong></article>
    <article class="card finance-summary-card sales-summary-card"><span>Receivables</span><strong><?= e($money($summary['receivableTotal'] ?? 0)) ?></strong></article>
    <article class="card finance-summary-card sales-summary-card"><span>Overdue</span><strong><?= e($money($summary['overdueTotal'] ?? 0)) ?></strong></article>
    <article class="card finance-summary-card sales-summary-card"><span>Accrued commission</span><strong><?= e($money($summary['commissionTotal'] ?? 0)) ?></strong></article>
</section>

<?php if (!empty($data['canCreateOrders'])): ?>
<section class="card finance-filter-panel sales-order-composer">
    <h2>Create sales order</h2>
    <p class="page-description">Add up to three product lines per order. Prices and commissions are taken from the controlled product catalogue.</p>
    <form method="post" action="/office_app/public/sales/orders" class="finance-filter-form">
        <?= csrfField() ?>
        <div class="form-field"><label for="customer_id">Customer</label><select id="customer_id" name="customer_id" required><option value="">Select customer</option><?php foreach ($customers as $customer): ?><option value="<?= e($customer['customer_id']) ?>"><?= e($customer['customer_number'] . ' - ' . $customer['name']) ?></option><?php endforeach; ?></select></div>
        <?php for ($line = 1; $line <= 3; $line++): ?>
            <div class="form-field"><label>Line <?= e($line) ?> product</label><select name="product_id[]" <?= $line === 1 ? 'required' : '' ?>><option value="">Select product</option><?php foreach ($products as $product): ?><option value="<?= e($product['product_id']) ?>"><?= e($product['sku'] . ' - ' . $product['name'] . ' (' . $money($product['unit_price']) . ')') ?></option><?php endforeach; ?></select></div>
            <div class="form-field"><label>Line <?= e($line) ?> quantity</label><input name="quantity[]" type="number" min="0.001" step="0.001" value="<?= $line === 1 ? '1' : '' ?>"></div>
            <div class="form-field"><label>Line <?= e($line) ?> discount</label><input name="discount_amount[]" type="number" min="0" step="0.01" value="0"></div>
            <div class="form-field"><label>Line <?= e($line) ?> tax (%)</label><input name="tax_rate[]" type="number" min="0" max="100" step="0.01" value="0"></div>
        <?php endfor; ?>
        <div class="form-field"><label for="order_date">Order date</label><input id="order_date" name="order_date" type="date" value="<?= e($today) ?>" required></div>
        <div class="form-field"><label for="due_date">Due date</label><input id="due_date" name="due_date" type="date" value="<?= e($today) ?>" required></div>
        <div class="form-field"><label for="currency">Currency</label><input id="currency" name="currency" maxlength="3" value="<?= e($currency) ?>" required></div>
        <div class="form-field"><label for="territory_id">Territory</label><select id="territory_id" name="territory_id"><option value="">Not assigned</option><?php foreach ($territories as $territory): ?><option value="<?= e($territory['territory_id']) ?>"><?= e($territory['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-field"><label for="agent_id">DSA / DSP</label><select id="agent_id" name="agent_id"><option value="">Not assigned</option><?php foreach ($agents as $agent): ?><option value="<?= e($agent['agent_id']) ?>"><?= e($agent['agent_code'] . ' - ' . $agent['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-field"><label for="order_notes">Notes</label><input id="order_notes" name="notes" maxlength="500"></div>
        <div class="filter-actions"><button class="btn btn-primary" type="submit" name="confirm" value="1">Submit for approval</button><button class="btn btn-secondary" type="submit">Save draft</button></div>
    </form>
</section>
<?php endif; ?>

<section class="card table-card">
    <div class="table-summary"><strong>Recent orders and receivables</strong><span><?php if (!empty($data['canExportReports'])): ?><a class="btn btn-secondary btn-compact" href="/office_app/public/sales/export">Export CSV</a><?php endif; ?> <?= e(count($orders)) ?> orders</span></div>
    <div class="table-responsive"><table class="data-table"><thead><tr><th>Order</th><th>Customer</th><th>Date / due</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><?php if ($canOrderActions): ?><th>Order action</th><?php endif; ?><?php if (!empty($data['canRecordPayments'])): ?><th>Receipt</th><?php endif; ?></tr></thead><tbody>
    <?php if ($orders === []): ?><tr><td colspan="8" class="empty-state">No sales orders have been created.</td></tr><?php endif; ?>
    <?php foreach ($orders as $order): ?><tr>
        <td><strong><?= e($order['order_number']) ?></strong><small><?= e($order['agent_name'] ?? 'No DSA/DSP') ?></small></td>
        <td><?= e($order['customer_name']) ?></td>
        <td><?= e($order['order_date']) ?><small>Due <?= e($order['due_date']) ?></small></td>
        <td><?= e($money($order['total_amount'])) ?></td><td><?= e($money($order['paid_amount'])) ?></td><td><strong><?= e($money($order['balance_due'])) ?></strong></td>
        <td><span class="badge badge-<?= e($order['status'] === 'paid' ? 'success' : 'muted') ?>"><?= e(ucwords(str_replace('_', ' ', $order['status']))) ?></span></td>
        <?php if ($canOrderActions): ?><td><form method="post" action="/office_app/public/sales/orders/action"><?= csrfField() ?><input type="hidden" name="order_id" value="<?= e($order['order_id']) ?>"><input type="hidden" name="idempotency_key" value="<?= e(bin2hex(random_bytes(16))) ?>"><?php if ($order['status'] === 'draft' && !empty($data['canSubmitOrders'])): ?><button class="btn btn-secondary btn-compact" name="action" value="submit">Submit</button><?php elseif ($order['status'] === 'submitted' && !empty($data['canApproveOrders'])): ?><button class="btn btn-primary btn-compact" name="action" value="approve">Approve</button><?php elseif (in_array($order['status'], ['approved', 'confirmed'], true) && !empty($data['canFulfillOrders'])): ?><button class="btn btn-secondary btn-compact" name="action" value="fulfill">Fulfill</button><?php endif; ?><?php if (in_array($order['status'], ['draft', 'submitted', 'approved', 'confirmed'], true) && !empty($data['canCancelOrders'])): ?><input name="reason" placeholder="Cancellation reason"><button class="btn btn-secondary btn-compact" name="action" value="cancel">Cancel</button><?php endif; ?></form></td><?php endif; ?>
        <?php if (!empty($data['canRecordPayments'])): ?><td><?php if (in_array($order['status'], ['approved', 'confirmed', 'fulfilled', 'partially_paid'], true)): ?><form method="post" action="/office_app/public/sales/payments"><?= csrfField() ?><input type="hidden" name="order_id" value="<?= e($order['order_id']) ?>"><input name="receipt_number" placeholder="Receipt #" maxlength="50" required><input name="payment_date" type="date" value="<?= e($today) ?>" required><input name="amount" type="number" min="0.01" max="<?= e($order['balance_due']) ?>" step="0.01" placeholder="Amount" required><select name="payment_method"><option value="bank_transfer">Bank transfer</option><option value="cash">Cash</option><option value="mobile_money">Mobile money</option><option value="card">Card</option></select><input name="reference_number" placeholder="Reference"><button class="btn btn-secondary btn-compact" type="submit">Record</button></form><?php else: ?>-<?php endif; ?></td><?php endif; ?>
    </tr><?php endforeach; ?></tbody></table></div>
</section>

<?php if (!empty($data['canManageCatalogue'])): ?>
<section class="finance-summary-grid sales-management-grid">
    <article class="card"><h2>Add territory</h2><form method="post" action="/office_app/public/sales/territories"><?= csrfField() ?><div class="form-field"><label>Code</label><input name="code" maxlength="40" required></div><div class="form-field"><label>Name</label><input name="name" maxlength="120" required></div><button class="btn btn-primary" type="submit">Add territory</button></form></article>
    <article class="card"><h2>Add DSA / DSP</h2><form method="post" action="/office_app/public/sales/agents"><?= csrfField() ?><div class="form-field"><label>Code</label><input name="agent_code" maxlength="40" required></div><div class="form-field"><label>Name</label><input name="name" maxlength="160" required></div><div class="form-field"><label>Type</label><select name="agent_type"><option value="DSA">DSA</option><option value="DSP">DSP</option></select></div><div class="form-field"><label>Territory</label><select name="territory_id"><option value="">Not assigned</option><?php foreach ($territories as $territory): ?><option value="<?= e($territory['territory_id']) ?>"><?= e($territory['name']) ?></option><?php endforeach; ?></select></div><div class="form-field"><label>Phone</label><input name="phone" maxlength="40"></div><button class="btn btn-primary" type="submit">Add DSA/DSP</button></form></article>
    <article class="card"><h2>Add customer</h2><form method="post" action="/office_app/public/sales/customers"><?= csrfField() ?><div class="form-field"><label>Customer number</label><input name="customer_number" maxlength="40" required></div><div class="form-field"><label>Name</label><input name="name" maxlength="160" required></div><div class="form-field"><label>Type</label><select name="customer_type"><option value="business">Business</option><option value="individual">Individual</option><option value="agent">Agent</option><option value="government">Government</option></select></div><div class="form-field"><label>Email</label><input name="email" type="email"></div><div class="form-field"><label>Phone</label><input name="phone"></div><div class="form-field"><label>Preferred currency</label><input name="preferred_currency" maxlength="3" value="<?= e($currency) ?>" required></div><div class="form-field"><label>Credit policy</label><select name="credit_mode"><option value="no_credit">No credit allowed</option><option value="fixed">Fixed credit limit</option><option value="unlimited">Unlimited credit</option></select></div><div class="form-field"><label>Credit limit</label><input name="credit_limit" type="number" min="0" step="0.01" value="0"></div><div class="form-field"><label>Payment terms (days)</label><input name="payment_terms_days" type="number" min="0" value="0"></div><button class="btn btn-primary" type="submit">Add customer</button></form></article>
    <article class="card"><h2>Add telecom product</h2><form method="post" action="/office_app/public/sales/products"><?= csrfField() ?><div class="form-field"><label>SKU</label><input name="sku" maxlength="60" required></div><div class="form-field"><label>Name</label><input name="name" maxlength="160" required></div><div class="form-field"><label>Category</label><input name="category" maxlength="80"></div><input type="hidden" name="product_type" value="telecom_product"><div class="form-field"><label>Unit</label><input name="unit_of_measure" value="unit" maxlength="20"></div><div class="form-field"><label>Unit price</label><input name="unit_price" type="number" min="0" step="0.01" required></div><div class="form-field"><label>Commission rate (%)</label><input name="commission_rate" type="number" min="0" max="100" step="0.01" value="0"></div><label><input name="serial_tracking" type="checkbox" value="1"> Track serial numbers</label><div><button class="btn btn-primary" type="submit">Add product</button></div></form></article>
</section>
<?php endif; ?>

<?php if (!empty($data['canManageSerials'])): ?>
<section class="card finance-filter-panel"><h2>Register telecom serial numbers</h2><p class="page-description">Enter IMEI, ICCID, device or voucher serials separated by a new line or comma.</p><form method="post" action="/office_app/public/sales/serials" class="finance-filter-form"><?= csrfField() ?><div class="form-field"><label>Serial-tracked product</label><select name="product_id" required><option value="">Select product</option><?php foreach ($products as $product): ?><?php if (!empty($product['serial_tracking'])): ?><option value="<?= e($product['product_id']) ?>"><?= e($product['sku'] . ' - ' . $product['name']) ?></option><?php endif; ?><?php endforeach; ?></select></div><div class="form-field"><label>Serial numbers</label><textarea name="serial_numbers" rows="5" required></textarea></div><button class="btn btn-primary" type="submit">Register serials</button></form></section>
<?php endif; ?>

<section class="card table-card"><div class="table-summary"><strong>Serial-number registry</strong><span><?= e(count($serialNumbers)) ?> serials</span></div><div class="table-responsive"><table class="data-table"><thead><tr><th>Serial</th><th>Product</th><th>Status</th><th>Registered</th></tr></thead><tbody><?php if ($serialNumbers === []): ?><tr><td colspan="4" class="empty-state">No serial numbers registered.</td></tr><?php endif; ?><?php foreach ($serialNumbers as $serial): ?><tr><td><strong><?= e($serial['serial_number']) ?></strong></td><td><?= e($serial['sku'] . ' - ' . $serial['product_name']) ?></td><td><?= e(ucwords(str_replace('_', ' ', $serial['status']))) ?></td><td><?= e($serial['registered_at']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>

<section class="card table-card"><div class="table-summary"><strong>Commission control</strong><span><?= e(count($commissions)) ?> commissions</span></div><div class="table-responsive"><table class="data-table"><thead><tr><th>Order</th><th>DSA/DSP</th><th>Amount</th><th>Status</th><?php if (!empty($data['canManageCommissions'])): ?><th>Action</th><?php endif; ?></tr></thead><tbody><?php if ($commissions === []): ?><tr><td colspan="5" class="empty-state">No commissions accrued.</td></tr><?php endif; ?><?php foreach ($commissions as $commission): ?><tr><td><?= e($commission['order_number']) ?></td><td><?= e($commission['agent_code'] . ' - ' . $commission['agent_name']) ?></td><td><?= e($money($commission['commission_amount'])) ?></td><td><?= e(ucfirst($commission['status'])) ?></td><?php if (!empty($data['canManageCommissions'])): ?><td><?php if (in_array($commission['status'], ['accrued', 'approved'], true)): ?><form method="post" action="/office_app/public/sales/commissions/action"><?= csrfField() ?><input type="hidden" name="commission_id" value="<?= e($commission['commission_id']) ?>"><button class="btn btn-secondary btn-compact" name="action" value="<?= e($commission['status'] === 'accrued' ? 'approve' : 'pay') ?>"><?= e($commission['status'] === 'accrued' ? 'Approve' : 'Mark paid') ?></button></form><?php else: ?>-<?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div></section>

<?php if (!empty($data['canManageTargets'])): ?>
<section class="card finance-filter-panel"><h2>Set sales target</h2><form method="post" action="/office_app/public/sales/targets" class="finance-filter-form"><?= csrfField() ?><div class="form-field"><label>Territory</label><select name="territory_id"><option value="">All / agent only</option><?php foreach ($territories as $territory): ?><option value="<?= e($territory['territory_id']) ?>"><?= e($territory['name']) ?></option><?php endforeach; ?></select></div><div class="form-field"><label>DSA / DSP</label><select name="agent_id"><option value="">All / territory only</option><?php foreach ($agents as $agent): ?><option value="<?= e($agent['agent_id']) ?>"><?= e($agent['agent_code'] . ' - ' . $agent['name']) ?></option><?php endforeach; ?></select></div><div class="form-field"><label>Period start</label><input name="period_start" type="date" required></div><div class="form-field"><label>Period end</label><input name="period_end" type="date" required></div><div class="form-field"><label>Amount target</label><input name="target_amount" type="number" min="0" step="0.01" value="0"></div><div class="form-field"><label>Quantity target</label><input name="target_quantity" type="number" min="0" step="0.001" value="0"></div><button class="btn btn-primary" type="submit">Set target</button></form></section>
<?php endif; ?>

<section class="card table-card"><div class="table-summary"><strong>Target performance</strong><span><?= e(count($targets)) ?> targets</span></div><div class="table-responsive"><table class="data-table"><thead><tr><th>Scope</th><th>Period</th><th>Target</th><th>Achieved</th><th>Completion</th></tr></thead><tbody><?php if ($targets === []): ?><tr><td colspan="5" class="empty-state">No sales targets have been set.</td></tr><?php endif; ?><?php foreach ($targets as $target): ?><?php $targetAmount = (float) $target['target_amount']; $achieved = (float) $target['achieved_amount']; $completion = $targetAmount > 0 ? min(999.9, $achieved * 100 / $targetAmount) : 0; ?><tr><td><strong><?= e($target['territory_name'] ?? 'All territories') ?></strong><small><?= e($target['agent_name'] ?? 'All DSA/DSP') ?></small></td><td><?= e($target['period_start']) ?> to <?= e($target['period_end']) ?></td><td><?= e($money($targetAmount)) ?><small><?= e($target['target_quantity']) ?> units</small></td><td><?= e($money($achieved)) ?></td><td><strong><?= e(number_format($completion, 1)) ?>%</strong></td></tr><?php endforeach; ?></tbody></table></div></section>
</div>
