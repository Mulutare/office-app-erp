<?php
$o=$data['o'];
$orderPermissions=$_SESSION['auth']['permissions']??[];
$orderActor=(int)($_SESSION['auth']['user_id']??0);
$orderOwner=(int)($o['created_by']??0)===$orderActor;
?>
<?php if($o['status']==='submitted' && !$orderOwner && in_array('sales.orders.approve',$orderPermissions,true)): ?>
<section class="card"><h2>Reject at approval</h2>
<form method="post" action="<?=e(appBasePath())?>/sales/orders/action"><?=csrfField()?>
<input type="hidden" name="order_id" value="<?=e($o['order_id'])?>">
<input type="hidden" name="action" value="reject">
<input type="hidden" name="idempotency_key" value="<?=e(bin2hex(random_bytes(16)))?>">
<label>Rejection reason<textarea name="reason" required maxlength="500"></textarea></label>
<button class="btn btn-danger">Reject order</button></form></section>
<?php endif; ?>
<?php if($o['status']==='rejected'): ?>
<section class="card"><h2>Order rejected</h2>
<?php foreach($o['status_history']??[] as $history): if($history['action']==='reject'): ?>
<p class="alert alert-warning"><?=e($history['reason'])?></p>
<?php break; endif; endforeach; ?>
<?php if($orderOwner && in_array('sales.orders.create',$orderPermissions,true) && in_array('sales.orders.submit',$orderPermissions,true)): ?>
<details><summary class="btn btn-primary">Edit and Resubmit</summary>
<form method="post" action="<?=e(appBasePath())?>/sales/orders/<?=e($o['order_id'])?>/resubmit"><?=csrfField()?>
<p>The same order number is retained. Approval is required again.</p>
<?php if(empty($o['warehouse_id']) || empty($o['source_location_id'])): ?>
<label>Warehouse<select name="warehouse_id" required><?php foreach($data['warehouses'] as $w): ?><option value="<?=e($w['warehouse_id'])?>"><?=e($w['name'])?></option><?php endforeach; ?></select></label>
<label>Source location<select name="source_location_id" required><?php foreach($data['locations'] as $l): ?><option value="<?=e($l['location_id'])?>"><?=e($l['code'].' - '.$l['name'])?></option><?php endforeach; ?></select></label>
<?php endif; ?>
<label>Order date<input type="date" name="order_date" required value="<?=e($o['order_date'])?>"></label>
<label>Due date<input type="date" name="due_date" required value="<?=e($o['due_date'])?>"></label>
<label>Notes<textarea name="notes"><?=e($o['notes']??'')?></textarea></label>
<?php foreach($o['lines'] as $index=>$line): ?>
<fieldset><legend><?=e($line['description'])?></legend>
<input type="hidden" name="product_id[]" value="<?=e($line['product_id'])?>">
<label>Quantity<input type="number" name="quantity[]" min="0.001" step="0.001" required value="<?=e($line['quantity'])?>" <?=!empty($o['quotation_id'])?'readonly':''?>></label>
<label>Discount<input type="number" name="discount_amount[]" min="0" step="0.01" required value="<?=e($line['discount_amount'])?>" <?=!empty($o['quotation_id'])?'readonly':''?>></label>
<label>Tax %<input type="number" name="tax_rate[]" min="0" max="100" step="0.0001" required value="<?=e($line['tax_rate'])?>" <?=!empty($o['quotation_id'])?'readonly':''?>></label>
</fieldset>
<?php endforeach; ?>
<button class="btn btn-primary">Resubmit for approval</button></form></details>
<?php endif; ?></section>
<?php endif; ?>
<?php if(!empty($o['status_history'])): ?><section class="card"><h2>Approval history</h2>
<?php foreach($o['status_history'] as $history): ?><p><?=e($history['occurred_at'])?> · <?=e($history['action'])?> · <?=e($history['reason']??'')?></p><?php endforeach; ?>
</section><?php endif; ?>
