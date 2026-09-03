<?php
// Controller/view payload is supplied through $data by the ERP view helper.
// Expose it locally for this template while preserving existing variables.
if (is_array($data ?? null)) {
    extract($data, EXTR_SKIP);
}

$permissions=$permissions??($_SESSION['auth']['permissions']??[]);
$can=static fn(string $p):bool=>in_array($p,$permissions,true);
$actorId=(int)($_SESSION['auth']['user_id']??0);
$requests=$stockRequests??[];
$request=$stockRequest??null;
$products=$stockRequestProducts??[];
$section=(string)($_GET['section']??'requests');
$statusClass=static fn(string $s):string=>in_array($s,['closed','issued','ready_to_issue'],true)?'status status-success':(in_array($s,['cancelled'],true)?'status status-danger':'status status-warning');
?>

<?php if(!empty($notice)):?><div class="notice notice-success"><?=e((string)$notice)?></div><?php endif;?>
<?php if(!empty($error)):?><div class="notice notice-error"><?=e((string)$error)?></div><?php endif;?>

<div class="page-actions">
  <a class="btn btn-secondary" href="<?=e(appBasePath())?>/inventory/stock-requests?section=requests">Requests</a>
  <?php if(!empty($canManageReorderThresholds)):?><a class="btn btn-secondary" href="<?=e(appBasePath())?>/inventory/stock-requests?section=reorder">Regional stock notifications</a><?php endif;?>
  <?php if(!empty($canManageStockAuthorities)):?><a class="btn btn-secondary" href="<?=e(appBasePath())?>/inventory/stock-requests?section=authorities">Stock authorities</a><?php endif;?>
</div>

<?php if(is_array($request)):?>
<section class="card">
  <div class="page-heading-row">
    <div><h2><?=e($request['request_number'])?></h2><p><?=e($request['requester_name'])?> · <?=e($request['requester_role_snapshot'])?></p></div>
    <span class="<?=e($statusClass((string)$request['status']))?>"><?=e(str_replace('_',' ',(string)$request['status']))?></span>
  </div>
  <div class="detail-grid">
    <div><strong>Serving stock</strong><br><?=e($request['serving_warehouse_name'].' / '.$request['serving_location_name'])?></div>
    <div><strong>Current handler</strong><br><?=e($request['current_handler_name']??'—')?></div>
    <div><strong>Requested</strong><br><?=e($request['requested_quantity'])?></div>
    <div><strong>Allocated</strong><br><?=e($request['allocated_quantity'])?></div>
    <div><strong>Ready at Shop</strong><br><?=e($request['ready_quantity'])?></div>
    <div><strong>Requested at</strong><br><?=e($request['requested_at'])?></div>
  </div>
  <?php if(!empty($request['notes'])):?><p><strong>Notes:</strong> <?=e($request['notes'])?></p><?php endif;?>
</section>

<section class="card"><h3>Request lines</h3><div class="table-responsive"><table class="data-table"><thead><tr><th>Product</th><th>Requested</th><th>Allocated</th><th>Ready at Shop</th></tr></thead><tbody>
<?php foreach($request['lines']??[] as $line):?><tr><td><?=e($line['sku'].' — '.$line['name'])?></td><td><?=e($line['requested_quantity'].' '.$line['unit_of_measure'])?></td><td><?=e($line['allocated_quantity'])?></td><td><?=e($line['ready_quantity'])?></td></tr><?php endforeach;?>
</tbody></table></div></section>

<?php if(!empty($request['allocations'])):?><section class="card"><h3>Allocation and transfer history</h3><div class="table-responsive"><table class="data-table"><thead><tr><th>Level</th><th>Manager</th><th>Product</th><th>Qty</th><th>Source</th><th>Status</th><th>Transfer</th></tr></thead><tbody>
<?php foreach($request['allocations'] as $a):?><tr><td><?=e(ucfirst($a['authority_level']))?></td><td><?=e($a['authority_name'])?></td><td><?=e($a['product_name'])?></td><td><?=e($a['quantity'])?></td><td><?=e($a['source_warehouse_name'].' / '.$a['source_location_name'])?></td><td><?=e(str_replace('_',' ',$a['status']))?></td><td><?php if(!empty($a['transfer_id'])):?><a href="<?=e(appBasePath())?>/inventory/transfers/<?=$a['transfer_id']?>"><?=e($a['transfer_number']??('TRF #'.$a['transfer_id']))?></a> · <?=e($a['transfer_status']??'')?><?php else:?>Direct Shop allocation<?php endif;?></td></tr><?php endforeach;?>
</tbody></table></div></section><?php endif;?>

<?php if(!empty($request['procurements'])):?><section class="card"><h3>Linked company procurement</h3><div class="table-responsive"><table class="data-table"><thead><tr><th>Requisition</th><th>Status</th><th>Purchase order</th><th>PO status</th></tr></thead><tbody>
<?php foreach($request['procurements'] as $p):?><tr><td><?=e($p['requisition_number'])?></td><td><?=e($p['requisition_status'])?></td><td><?php if(!empty($p['purchase_order_id'])):?><a href="<?=e(appBasePath())?>/procurement/<?=$p['purchase_order_id']?>"><?=e($p['po_number'])?></a><?php else:?>—<?php endif;?></td><td><?=e($p['purchase_order_status']??'—')?></td></tr><?php endforeach;?>
</tbody></table></div></section><?php endif;?>

<section class="card"><h3>Available action</h3><div class="page-actions">
<?php if((int)($request['current_handler_user_id']??0)===$actorId && in_array($request['status'],['pending_review','awaiting_procurement'],true) && $can('inventory.stock_requests.process')):?><form method="post" action="<?=e(appBasePath())?>/inventory/stock-requests/<?=$request['request_id']?>/process"><?=csrfField()?><button class="btn btn-primary">Check my represented stock & route remaining</button></form><?php endif;?>
<?php if($request['status']==='ready_to_issue' && (int)($stockRequestAuthority['authority_id']??0)===(int)$request['serving_authority_id'] && $can('inventory.stock_requests.issue')):?><form method="post" action="<?=e(appBasePath())?>/inventory/stock-requests/<?=$request['request_id']?>/issue"><?=csrfField()?><button class="btn btn-primary">Issue full request to DSA/DSP</button></form><?php endif;?>
<?php if($request['status']==='issued' && (int)$request['requester_user_id']===$actorId && $can('inventory.stock_requests.receive')):?><form method="post" action="<?=e(appBasePath())?>/inventory/stock-requests/<?=$request['request_id']?>/receive"><?=csrfField()?><button class="btn btn-primary">Confirm I received the stock</button></form><?php endif;?>
<a class="btn btn-secondary" href="<?=e(appBasePath())?>/inventory/stock-requests">Back to requests</a>
</div></section>

<?php elseif($section==='authorities' && !empty($canManageStockAuthorities)):?>
<section class="card"><h2>Manager → represented stock</h2><p>Hierarchy comes from each company user's reporting manager. This screen only assigns the stock represented by each Shop, District and Regional Manager.</p>
<form method="post" action="<?=e(appBasePath())?>/inventory/stock-requests/authorities"><?=csrfField()?>
<div class="enterprise-form">
<label>Manager<select name="user_id" required><option value="">Select manager</option><?php foreach($stockAuthorityCandidates??[] as $c):?><option value="<?=$c['user_id']?>"><?=e($c['display_name'].' — '.$c['job_title'])?></option><?php endforeach;?></select></label>
<label>Level<select name="authority_level" required><option value="shop">Shop Manager</option><option value="district">District Manager</option><option value="regional">Regional Manager / company stock</option></select></label>
<label>Warehouse<select name="warehouse_id" id="authority-warehouse" required><option value="">Select warehouse</option><?php foreach(($stockAuthorityWarehouses['warehouses']??[]) as $w):?><option value="<?=$w['warehouse_id']?>"><?=e($w['code'].' — '.$w['name'])?></option><?php endforeach;?></select></label>
<label>Stock location<select name="location_id" id="authority-location" required><option value="">Select location</option><?php foreach(($stockAuthorityWarehouses['locations']??[]) as $l):?><option value="<?=$l['location_id']?>" data-warehouse="<?=$l['warehouse_id']?>"><?=e($l['code'].' — '.$l['name'])?></option><?php endforeach;?></select></label>
<label><input type="checkbox" name="active" value="1" checked> Active</label>
</div><button class="btn btn-primary">Save stock authority</button></form></section>
<section class="card"><h3>Configured authorities</h3><div class="table-responsive"><table class="data-table"><thead><tr><th>Manager</th><th>HR Job Title</th><th>Level</th><th>Represented stock</th><th>Reports to</th><th>Active</th></tr></thead><tbody><?php foreach($stockAuthorities??[] as $a):?><tr><td><?=e($a['display_name'])?></td><td><?=e($a['job_title'])?></td><td><?=e(ucfirst($a['authority_level']))?></td><td><?=e($a['warehouse_name'].' / '.$a['location_name'])?></td><td><?=e($a['manager_name']??'—')?></td><td><?=!empty($a['active'])?'Yes':'No'?></td></tr><?php endforeach;?></tbody></table></div></section>

<?php elseif($section==='reorder' && !empty($canManageReorderThresholds)):?>
<section class="card"><h2>Regional company-stock notifications</h2><p><strong><?=e(($regionalReorder['warehouse_name']??'').' / '.($regionalReorder['location_name']??''))?></strong>. Thresholds create a visible low-stock warning only; they never create a requisition or PO automatically.</p></section>
<section class="card"><div class="table-responsive"><table class="data-table"><thead><tr><th>Product</th><th>On hand</th><th>Reserved</th><th>Available</th><th>Notification at/below</th><th>State</th><th>Action</th></tr></thead><tbody>
<?php foreach($regionalReorder['products']??[] as $p):?><tr><td><?=e($p['sku'].' — '.$p['name'])?></td><td><?=e($p['quantity_on_hand'])?></td><td><?=e($p['quantity_reserved'])?></td><td><?=e($p['quantity_available'])?></td><td><form method="post" action="<?=e(appBasePath())?>/inventory/stock-requests/reorder-thresholds" class="proc-actions"><?=csrfField()?><input type="hidden" name="product_id" value="<?=$p['product_id']?>"><input name="notification_quantity" type="number" min="0" step="0.001" value="<?=e($p['notification_quantity']??0)?>" required><label><input type="checkbox" name="active" value="1" <?=!isset($p['threshold_active'])||!empty($p['threshold_active'])?'checked':''?>> active</label><button class="btn btn-secondary">Save</button></form></td><td><?php if(!empty($p['low_stock'])):?><strong>⚠ Low stock</strong><?php else:?>OK<?php endif;?></td><td><?php if(!empty($p['low_stock'])):?><a class="btn btn-primary" href="<?=e(appBasePath())?>/procurement?section=requisitions&source=regional-low-stock&product_id=<?=$p['product_id']?>&warehouse_id=<?=e($regionalReorder['warehouse_id'])?>">Create purchase requisition</a><?php else:?>—<?php endif;?></td></tr><?php endforeach;?>
</tbody></table></div></section>

<?php else:?>
<div class="grid-2">
<?php if(!empty($canCreateStockRequest)):?><section class="card"><h2>New stock request</h2><p>Your HR Job Title is <?=e($stockRequestActor['job_title']??'')?>. The request will go to your direct Shop Manager and keep one SR number through all escalation.</p><form method="post" action="<?=e(appBasePath())?>/inventory/stock-requests"><?=csrfField()?>
<div id="sr-lines"><div class="proc-line sr-line"><label>Product<select name="product_id[]" required><option value="">Select product</option><?php foreach($products as $p):?><option value="<?=$p['product_id']?>"><?=e($p['sku'].' — '.$p['name'])?></option><?php endforeach;?></select></label><label>Quantity<input name="quantity[]" type="number" min="0.001" step="0.001" required></label></div></div>
<div class="page-actions"><button class="btn btn-secondary" type="button" id="add-sr-line">Add another item</button></div><label>Notes<textarea name="notes" maxlength="1000"></textarea></label><button class="btn btn-primary">Submit stock request</button></form></section><?php endif;?>
<section class="card"><h2>Stock requests</h2><?php if(!$requests):?><div class="proc-empty">No stock requests in your reporting scope.</div><?php else:?><div class="table-responsive"><table class="data-table"><thead><tr><th>SR</th><th>Requester</th><th>Requested</th><th>Allocated</th><th>Handler</th><th>Status</th></tr></thead><tbody><?php foreach($requests as $r):?><tr><td><a href="<?=e(appBasePath())?>/inventory/stock-requests/<?=$r['request_id']?>"><?=e($r['request_number'])?></a></td><td><?=e($r['requester_name'])?></td><td><?=e($r['requested_quantity'])?></td><td><?=e($r['allocated_quantity'])?></td><td><?=e($r['current_handler_name']??'—')?></td><td><span class="<?=e($statusClass((string)$r['status']))?>"><?=e(str_replace('_',' ',$r['status']))?></span></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
</div>
<?php endif;?>

<script>
(function(){
 var add=document.getElementById('add-sr-line'),box=document.getElementById('sr-lines');if(add&&box){add.addEventListener('click',function(){var first=box.querySelector('.sr-line');if(!first)return;var clone=first.cloneNode(true);clone.querySelectorAll('select,input').forEach(function(el){el.value='';});box.appendChild(clone);});}
 var w=document.getElementById('authority-warehouse'),l=document.getElementById('authority-location');if(w&&l){var filter=function(){Array.from(l.options).forEach(function(o,i){if(i===0)return;o.hidden=o.dataset.warehouse!==w.value;});if(l.selectedOptions[0]&&l.selectedOptions[0].hidden)l.value='';};w.addEventListener('change',filter);filter();}
})();
</script>
