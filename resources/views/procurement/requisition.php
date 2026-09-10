<?php
$r=$data['requisition'];$owner=(int)$r['requester_user_id']===(int)($_SESSION['auth']['user_id']??0);
$canEdit=$owner && $r['status']==='rejected' && in_array('procurement.requisitions.create',$_SESSION['auth']['permissions']??[],true);
?>
<section class="card">
<a href="<?=e(appBasePath())?>/procurement?section=requisitions">Back to requisitions</a>
<h1><?=e($r['requisition_number'])?></h1><p>Status: <?=e($r['status'])?></p>
<?php if(!empty($data['notice'])): ?><p class="alert alert-success"><?=e($data['notice'])?></p><?php endif; ?>
<?php if(!empty($data['error'])): ?><p class="alert alert-danger"><?=e($data['error'])?></p><?php endif; ?>
<?php if(!empty($r['rejection_reason'])): ?><p class="alert alert-warning">Previous rejection: <?=e($r['rejection_reason'])?></p><?php endif; ?>
<form method="post" action="<?=e(appBasePath())?>/procurement/requisitions/<?=e($r['requisition_id'])?>/resubmit">
<?=csrfField()?>
<label>Justification<textarea name="justification" required maxlength="1000" <?=$canEdit?'':'readonly'?>><?=e($r['justification'])?></textarea></label>
<label>Required by<input type="date" name="required_by_date" value="<?=e($r['required_by_date']??'')?>" <?=$canEdit?'':'readonly'?>></label>
<?php foreach($r['lines'] as $line): ?>
<fieldset><legend>Item <?=e($line['product_id'])?></legend>
<label>Description<input name="lines[<?=e($line['requisition_line_id'])?>][description]" required maxlength="255" value="<?=e($line['description'])?>" <?=$canEdit?'':'readonly'?>></label>
<label>Quantity<input name="lines[<?=e($line['requisition_line_id'])?>][quantity]" type="number" min="0.001" step="0.001" required value="<?=e($line['quantity'])?>" <?=$canEdit?'':'readonly'?>></label>
<label>Estimated unit price<input name="lines[<?=e($line['requisition_line_id'])?>][unit_price]" type="number" min="0.0001" step="0.0001" required value="<?=e($line['estimated_unit_price'])?>" <?=$canEdit?'':'readonly'?>></label>
</fieldset><?php endforeach; ?>
<?php if($canEdit): ?><p>Product and destination stay unchanged. Linked replenishment quantities must stay unchanged.</p><button class="btn btn-primary">Edit and Resubmit</button><?php endif; ?>
</form></section>

<section class="card"><h2>Approval history</h2>
<?php foreach($r['history']??[] as $history): ?><p><?=e($history['occurred_at'])?> · <?=e($history['action'])?> · <?=e($history['reason']??'')?></p><?php endforeach; ?>
</section>
