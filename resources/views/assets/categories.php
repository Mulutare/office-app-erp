<?php declare(strict_types=1); ?>
<section class="card table-card"><h2>Asset categories</h2>
<?php view('assets.list-controls',['entity'=>'categories','workspace'=>$data]); ?>
<div class="table-responsive"><table class="data-table"><thead><tr><th>Code</th><th>Category</th><th>Useful life</th><th>Depreciation</th><th>Status</th></tr></thead><tbody>
<?php if($data['categories']===[]): ?><tr><td colspan="5" class="empty-state">No matching asset categories. Clear filters to see all authorized categories.</td></tr><?php endif; ?>
<?php foreach($data['categories'] as $category): ?><tr><td><?= e($category['category_code']) ?></td><td><?= e($category['category_name']) ?></td><td><?= e($category['useful_life_months']) ?> months</td><td><?= e(ucwords(str_replace('_',' ',$category['depreciation_method']))) ?></td><td><?= $category['active']?'Active':'Inactive' ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
