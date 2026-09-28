<?php
$pricingData = is_array($data['pricingData'] ?? null) ? $data['pricingData'] : [];
$rows = $pricingData['changes'] ?? [];
$products = $pricingData['products'] ?? [];
$canManagePricing = !empty($data['canManagePricing']);
$returnTo = ($data['returnTo'] ?? '') === 'pricelists' ? 'pricelists' : '';
$notice = $data['notice'] ?? null;
$error = $data['error'] ?? null;
?>
<div class="module-stack">
<?php if ($notice): ?><div class="notice success"><?= e(is_array($notice) ? ($notice['message'] ?? '') : $notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= e(is_array($error) ? ($error['message'] ?? '') : $error) ?></div><?php endif; ?>
<section class="card">
    <h2>Product and model selling terms</h2>
    <p>Authorized updates take effect immediately. The Products catalogue and DSA/DSP sales use these SKU values. Past sales retain their original amounts.</p>
    <?php if (isset($pricingData['productList'])): ?>
    <?php $path=appBasePath().($returnTo ? '/sales/pricelists' : '/sales/pricing');
        view('components.list-filters',['query'=>$pricingData['productList']['query'],'path'=>$path]+$pricingData['productControls']);
        view('components.list-download',['query'=>$pricingData['productList']['query'],'path'=>$path,'allowed'=>$pricingData['exports']['canExport']??false,'actions'=>['register'=>'pricing']]);
        view('components.list-pagination',['query'=>$pricingData['productList']['query'],'pagination'=>$pricingData['productList']['pagination'],'path'=>$path]); ?>
    <?php endif; ?>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th>SKU / Product</th><th>Variant</th><th>Price</th><th>Discount / unit</th><th>Tax</th><th>Effective</th><th>Updated by</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php if ($products === []): ?><tr><td colspan="9">No matching products. Clear filters to see all authorized products.</td></tr><?php endif; ?>
        <?php foreach ($products as $product): ?>
        <?php
            $id=(int)$product['product_id'];
            $family=strtolower((string)($product['product_family'] ?: 'other'));
            $brand=(string)($product['brand_name'] ?? '');
            $variant=trim(implode(' · ',array_filter([$product['product_family'] ?? $product['product_type'] ?? '',$product['mifi_subtype'] ?? '',$brand,$product['model_name'] ?? ''])));
            $active=!empty($product['active']);
        ?>
        <tr data-pricing-row data-pricing-search-text="<?= e(strtolower($product['sku'].' '.$product['name'].' '.$variant)) ?>" data-pricing-family-value="<?= e($family) ?>" data-pricing-brand-value="<?= e(strtolower($brand)) ?>" data-pricing-status-value="<?= $active?'active':'archived' ?>">
            <td><strong><?= e($product['sku']) ?></strong><br><?= e($product['name']) ?></td>
            <td><?= e($variant ?: '—') ?></td>
            <td><?= e(number_format((float)$product['approved_price'],2)) ?></td>
            <td><?= e(number_format((float)$product['approved_discount_percent'],2)) ?>%</td>
            <td><?= e(number_format((float)$product['approved_tax_percent'],2)) ?>%</td>
            <td><?= e($product['effective_from'] ?? '—') ?></td>
            <td><?= e($product['updated_by'] ?? '—') ?><br><?= e($product['updated_at'] ?? '') ?></td>
            <td><?= $active?'Active':'Archived' ?></td>
            <td><?php if ($canManagePricing && $active): ?><button type="button" class="btn btn-secondary btn-compact" data-pricing-edit="<?= $id ?>" aria-expanded="false">Edit</button><?php else: ?>—<?php endif; ?></td>
        </tr>
        <?php if ($canManagePricing && $active): ?>
        <tr data-pricing-edit-row="<?= $id ?>" hidden><td colspan="9">
            <form method="post" action="<?= e(appBasePath()) ?>/sales/pricing">
                <?= csrfField() ?>
                <input type="hidden" name="product_id" value="<?= $id ?>">
                <?php if ($returnTo): ?><input type="hidden" name="return_to" value="pricelists"><?php endif; ?>
                <strong>Update <?= e($product['sku'].' — '.$product['name']) ?></strong>
                <div class="finance-filter-form">
                    <label>Selling price<input name="proposed_price" type="number" min="0.01" step="0.01" value="<?= e($product['approved_price'] ?: '') ?>" required></label>
                    <label>Discount per unit (%)<input name="approved_discount_percent" type="number" min="0" max="99.99" step="0.01" value="<?= e($product['approved_discount_percent']) ?>" required></label>
                    <label>Tax (%)<input name="approved_tax_percent" type="number" min="0" max="100" step="0.01" value="<?= e($product['approved_tax_percent']) ?>" required></label>
                    <label>Reason or reference (optional)<input name="reason" maxlength="1000"></label>
                </div>
                <button class="btn btn-primary" type="submit">Save pricing</button>
                <button class="btn btn-secondary" type="button" data-pricing-cancel="<?= $id ?>">Cancel</button>
            </form>
        </td></tr>
        <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<section class="card table-card">
    <h3>Pricing change history</h3>
    <?php if (isset($pricingData['historyList'])):
        view('components.list-filters',['query'=>$pricingData['historyList']['query'],'path'=>$path,'sorts'=>['date'=>'Changed at','sku'=>'SKU','product'=>'Product','status'=>'Status'],
            'filters'=>['status'=>['label'=>'Status','options'=>['approved'=>'Saved','submitted'=>'Legacy pending','rejected'=>'Rejected']], 'from'=>['label'=>'From','type'=>'date'],'to'=>['label'=>'To','type'=>'date']]]);
        view('components.list-download',['query'=>$pricingData['historyList']['query'],'path'=>$path,'allowed'=>$pricingData['exports']['canExport']??false,'actions'=>['register'=>'price-history']]);
        view('components.list-pagination',['query'=>$pricingData['historyList']['query'],'pagination'=>$pricingData['historyList']['pagination'],'path'=>$path]);
    endif; ?>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th>SKU</th><th>Price, before → after</th><th>Discount, before → after</th><th>Tax, before → after</th><th>Changed by</th><th>Changed at</th><th>Reason</th><th>Record</th></tr></thead>
        <tbody>
        <?php if ($rows === []): ?><tr><td colspan="8">No pricing changes recorded.</td></tr><?php endif; ?>
        <?php foreach ($rows as $row): ?><tr>
            <td><?= e($row['sku']) ?></td>
            <td><?= e($row['old_price'].' → '.$row['proposed_price']) ?></td>
            <td><?= e($row['old_discount_percent'] === null ? '—' : $row['old_discount_percent'].'%') ?> → <?= e($row['approved_discount_percent']) ?>%</td>
            <td><?= e($row['old_tax_percent'] === null ? '—' : $row['old_tax_percent'].'%') ?> → <?= e($row['approved_tax_percent']) ?>%</td>
            <td><?= e($row['changed_by_name'] ?? 'Unavailable') ?></td>
            <td><?= e($row['requested_at']) ?></td>
            <td><?= e($row['reason'] ?: '—') ?></td>
            <td><?= e($row['status'] === 'submitted' ? 'Legacy pending record' : ($row['status'] === 'approved' ? 'Saved' : 'Superseded / rejected')) ?></td>
        </tr><?php endforeach; ?>
        </tbody>
    </table></div>
</section>
</div>
<script src="<?= e(appBasePath()) ?>/assets/js/sales-pricing.js?v=091" defer></script>
