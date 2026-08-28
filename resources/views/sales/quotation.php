<?php

declare(strict_types=1);

$data = is_array($data ?? null) ? $data : [];
$mode = (string) ($data['quotationMode'] ?? 'show');
$quotation = is_array($data['quotation'] ?? null) ? $data['quotation'] : [];
$old = is_array($data['old'] ?? null) ? $data['old'] : [];
$errors = is_array($data['errors'] ?? null) ? $data['errors'] : [];
$notice = is_array($data['notice'] ?? null) ? $data['notice'] : null;
$editing = in_array($mode, ['create', 'edit'], true);
$value = static fn (string $key, mixed $default = ''): mixed => $old[$key] ?? $quotation[$key] ?? $default;
$lines = is_array($old['lines'] ?? null) ? $old['lines'] : (is_array($quotation['lines'] ?? null) ? $quotation['lines'] : []);
if ($lines === []) $lines = [[]];
$currency = (string) ($data['user']['company']['default_currency'] ?? 'ETB');
$lineOptions = static function (array $products, int $selected): void {
    echo '<option value="">Select product</option>';
    foreach ($products as $product) {
        $id = (int) $product['product_id'];
        echo '<option value="' . e($id) . '" data-price="' . e($product['unit_price'] ?? 0) . '"'
            . ($id === $selected ? ' selected' : '') . '>' . e($product['sku'] . ' - ' . $product['name']) . '</option>';
    }
};
?>
<div class="sales-workspace" data-section="quotations">
    <?php if (!$editing): ?><?php \view('components.sales-workflow-trace', ['workflowTrace' => $data['workflowTrace'] ?? null]); ?><?php endif; ?>
    <div class="page-actions">
        <a class="btn btn-secondary" href="/office_app/public/sales/quotations">Back to quotations</a>
        <?php if (!$editing): ?><a class="btn btn-secondary" href="/office_app/public/sales/quotations/<?= e($quotation['quotation_id']) ?>/proforma.pdf">Download Proforma PDF</a><?php endif; ?>
        <?php if (!$editing && !empty($data['canEdit']) && ($quotation['status'] ?? '') === 'draft'): ?>
            <a class="btn btn-primary" href="/office_app/public/sales/quotations/<?= e($quotation['quotation_id']) ?>/edit">Edit</a>
        <?php endif; ?>
    </div>

    <?php if ($notice !== null): ?><div class="alert alert-success"><?= e($notice['message'] ?? '') ?></div><?php endif; ?>
    <?php if ($errors !== []): ?><div class="alert alert-danger" role="alert"><strong>The quotation was not saved.</strong></div><?php foreach ($errors as $error): ?><?php if(is_array($error)):?><?php \view('components.app-error',['error'=>$error]);?><?php else:?><div class="alert alert-danger"><?= e((string)$error) ?></div><?php endif;?><?php endforeach; ?><?php endif; ?>

    <header class="section-heading">
        <div><p class="eyebrow">Quotations</p><h2><?= e($mode === 'create' ? 'New quotation' : ($quotation['quotation_number'] ?? 'Quotation')) ?></h2></div>
        <?php if (!$editing): ?><span class="badge badge-neutral"><?= e(strtoupper((string) ($quotation['effective_status'] ?? $quotation['status'] ?? ''))) ?></span><?php endif; ?>
    </header>

    <?php if ($editing): ?>
        <form method="post" action="/office_app/public/sales/quotations<?= $mode === 'edit' ? '/' . e($quotation['quotation_id']) : '' ?>" data-dynamic-lines data-line-limit="50">
            <?= csrfField() ?>
            <section class="card form-section"><h3>Customer &amp; document</h3><div class="finance-filter-form">
                <div class="form-field"><label>Customer</label><select name="customer_id" required><option value="">Select customer</option><?php foreach ($data['customers'] as $customer): ?><option value="<?= e($customer['customer_id']) ?>" <?= (int) $value('customer_id') === (int) $customer['customer_id'] ? 'selected' : '' ?>><?= e($customer['customer_number'] . ' - ' . $customer['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-field"><label>Quotation date</label><input type="date" name="quotation_date" value="<?= e($value('quotation_date', date('Y-m-d'))) ?>" required></div>
                <div class="form-field"><label>Expiration date</label><input type="date" name="expiration_date" value="<?= e($value('expiration_date')) ?>"></div>
                <div class="form-field"><label>DSA / DSP</label><select name="agent_id"><option value="">Unassigned</option><?php foreach ($data['agents'] as $agent): ?><option value="<?= e($agent['agent_id']) ?>" <?= (int) $value('agent_id') === (int) $agent['agent_id'] ? 'selected' : '' ?>><?= e($agent['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-field"><label>Sales team</label><select name="team_id"><option value="">Unassigned</option><?php foreach ($data['salesTeams'] as $team): ?><option value="<?= e($team['team_id']) ?>" <?= (int) $value('team_id') === (int) $team['team_id'] ? 'selected' : '' ?>><?= e($team['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-field"><label>Pricelist</label><select name="pricelist_id"><option value="">Standard price</option><?php foreach ($data['pricelists'] as $price): if (empty($price['active']) && (int) $value('pricelist_id') !== (int) $price['pricelist_id']) continue; ?><option value="<?= e($price['pricelist_id']) ?>" <?= (int) $value('pricelist_id') === (int) $price['pricelist_id'] ? 'selected' : '' ?>><?= e($price['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-field"><label>Payment terms (days)</label><input type="number" min="0" name="payment_terms_days" value="<?= e($value('payment_terms_days', 0)) ?>"></div>
                <div class="form-field"><label>Currency</label><input name="currency" maxlength="3" value="<?= e($value('currency', $currency)) ?>" required></div>
            </div></section>

            <section class="card form-section"><h3>Addresses</h3><div class="finance-summary-grid"><div class="form-field"><label>Billing address/contact</label><textarea name="billing_address" rows="3"><?= e($value('billing_address')) ?></textarea></div><div class="form-field"><label>Delivery address/contact</label><textarea name="delivery_address" rows="3"><?= e($value('delivery_address')) ?></textarea></div></div></section>

            <section class="card form-section"><div class="section-heading"><div><h3>Items</h3><p>Add up to 50 products. Saved prices and totals are recalculated by the server.</p></div><button class="btn btn-secondary" type="button" data-add-line>+ Add item</button></div>
                <div class="table-responsive quotation-lines"><table class="data-table"><thead><tr><th class="quotation-product">Product</th><th>Quantity</th><th>Unit price</th><th>Discount</th><th>Tax %</th><th>Line total</th><th>Action</th></tr></thead><tbody data-line-body>
                <?php foreach ($lines as $index => $line): ?><tr data-line-row>
                    <td><select name="lines[<?= e($index) ?>][product_id]" data-line-product><?php $lineOptions($data['products'], (int) ($line['product_id'] ?? 0)); ?></select></td>
                    <td><input type="number" step="0.001" name="lines[<?= e($index) ?>][quantity]" value="<?= e($line['quantity'] ?? '') ?>" data-line-quantity></td>
                    <td><output data-line-price><?= e(number_format((float) ($line['unit_price'] ?? 0), 2)) ?></output></td>
                    <td><input type="number" min="0" step="0.01" name="lines[<?= e($index) ?>][discount_amount]" value="<?= e($line['discount_amount'] ?? 0) ?>" data-line-discount></td>
                    <td><input type="number" min="0" max="100" step="0.01" name="lines[<?= e($index) ?>][tax_rate]" value="<?= e($line['tax_rate'] ?? 0) ?>" data-line-tax></td>
                    <td><output data-line-total>0.00</output></td><td><button class="btn btn-secondary btn-compact" type="button" data-remove-line>Remove</button></td>
                </tr><?php endforeach; ?>
                </tbody></table></div>
                <template data-line-template><tr data-line-row><td><select data-name="product_id" data-line-product><?php $lineOptions($data['products'], 0); ?></select></td><td><input type="number" step="0.001" data-name="quantity" data-line-quantity></td><td><output data-line-price>0.00</output></td><td><input type="number" min="0" step="0.01" value="0" data-name="discount_amount" data-line-discount></td><td><input type="number" min="0" max="100" step="0.01" value="0" data-name="tax_rate" data-line-tax></td><td><output data-line-total>0.00</output></td><td><button class="btn btn-secondary btn-compact" type="button" data-remove-line>Remove</button></td></tr></template>
                <div class="quotation-total-preview" aria-live="polite"><span>Preview subtotal <strong data-preview-subtotal>0.00</strong></span><span>Discount <strong data-preview-discount>0.00</strong></span><span>Tax <strong data-preview-tax>0.00</strong></span><span>Preview total <strong data-preview-total>0.00</strong></span></div>
            </section>

            <section class="card form-section"><h3>Notes / terms</h3><div class="form-field"><textarea name="notes" rows="4"><?= e($value('notes')) ?></textarea></div></section>
            <div class="page-actions"><button class="btn btn-primary" type="submit"><?= $mode === 'create' ? 'Create quotation' : 'Save quotation' ?></button><a class="btn btn-secondary" href="/office_app/public/sales/quotations">Cancel</a></div>
        </form>
    <?php else: ?>
        <section class="card"><div class="finance-summary-grid"><div><strong>Customer</strong><p><?= e($quotation['customer_name']) ?></p></div><div><strong>Dates</strong><p><?= e($quotation['quotation_date']) ?> · expires <?= e($quotation['expiration_date'] ?? 'never') ?></p></div><div><strong>Sales owner</strong><p><?= e($quotation['agent_name'] ?? 'Unassigned') ?> · <?= e($quotation['team_name'] ?? 'No team') ?></p></div><div><strong>Commercial terms</strong><p><?= e($quotation['pricelist_name'] ?? 'Standard price') ?> · <?= e($quotation['payment_terms_days']) ?> days</p></div></div><p><strong>Billing:</strong> <?= nl2br(e($quotation['billing_address'] ?? 'Not specified')) ?></p><p><strong>Delivery:</strong> <?= nl2br(e($quotation['delivery_address'] ?? 'Not specified')) ?></p></section>
        <section class="card table-card"><div class="table-responsive"><table class="data-table"><thead><tr><th>Product</th><th>Quantity</th><th>UoM</th><th>Unit price</th><th>Discount</th><th>Tax</th><th>Total</th></tr></thead><tbody><?php foreach ($quotation['lines'] as $line): ?><tr><td><?= e($line['sku'] . ' - ' . $line['description']) ?></td><td><?= e($line['quantity']) ?></td><td><?= e($line['unit_of_measure']) ?></td><td><?= e($quotation['currency'] . ' ' . number_format((float) $line['unit_price'], 2)) ?></td><td><?= e(number_format((float) $line['discount_amount'], 2)) ?></td><td><?= e(number_format((float) $line['tax_amount'], 2)) ?></td><td><?= e(number_format((float) $line['line_total'], 2)) ?></td></tr><?php endforeach; ?></tbody></table></div><div class="table-summary"><span>Subtotal <?= e(number_format((float) $quotation['untaxed_amount'], 2)) ?> · Tax <?= e(number_format((float) $quotation['tax_amount'], 2)) ?></span><strong>Total <?= e($quotation['currency'] . ' ' . number_format((float) $quotation['total_amount'], 2)) ?></strong></div></section>
        <?php if (!empty($data['canTransition']) && in_array($quotation['status'], ['draft', 'sent'], true)): ?><section class="card"><div class="page-actions"><?php if ($quotation['status'] === 'draft'): ?><form method="post" action="/office_app/public/sales/quotations/<?= e($quotation['quotation_id']) ?>/send"><?= csrfField() ?><button class="btn btn-secondary">Mark sent</button></form><?php endif; ?><?php if (($quotation['effective_status'] ?? $quotation['status']) !== 'expired'): ?><form method="post" action="/office_app/public/sales/quotations/<?= e($quotation['quotation_id']) ?>/confirm"><?= csrfField() ?><button class="btn btn-primary">Confirm quotation</button></form><?php endif; ?><form method="post" action="/office_app/public/sales/quotations/<?= e($quotation['quotation_id']) ?>/cancel"><?= csrfField() ?><button class="btn btn-secondary">Cancel quotation</button></form></div></section><?php endif; ?>
        <?php if (!empty($quotation['sales_order_id'])): ?><div class="page-actions"><a class="btn btn-primary" href="/office_app/public/sales/orders/<?= e($quotation['sales_order_id']) ?>">Open Sales Order <?= e($quotation['order_number']) ?></a></div><?php endif; ?>
    <?php endif; ?>
</div>
