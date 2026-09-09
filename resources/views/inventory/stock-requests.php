<style id="stock-request-detail-polish-v1">

/* ===============================
   Stock Request detail polish
   =============================== */

.page-heading-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 16px;
}

.page-heading-row h2 {
    margin: 0 0 4px;
    font-size: 20px;
}

.page-heading-row p {
    margin: 0;
    color: #64748b;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-top: 12px;
}

.detail-grid > div {
    min-width: 0;
    padding: 12px 14px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #f8fafc;
}

.detail-grid strong {
    display: block;
    margin-bottom: 4px;
    color: #64748b;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .03em;
    text-transform: uppercase;
}

/* Request tables */

.stock-request-lines-card,
.stock-request-peer-card,
.stock-request-action-card {
    margin-top: 14px;
}

.stock-request-lines-card h3,
.stock-request-peer-card h3,
.stock-request-action-card h3 {
    margin: 0 0 12px;
}

.stock-request-lines-card .data-table th {
    white-space: nowrap;
}

.stock-request-lines-card .data-table td {
    vertical-align: middle;
}

/* Peer transfer form */

.stock-request-peer-form {
    display: grid;
    grid-template-columns:
        minmax(240px, 1.5fr)
        minmax(180px, 1fr)
        minmax(130px, .55fr)
        auto;
    gap: 12px;
    align-items: end;
    margin-top: 14px;
}

.stock-request-peer-form label {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}

.stock-request-peer-form select,
.stock-request-peer-form input {
    width: 100%;
}

.stock-request-peer-form .btn {
    white-space: nowrap;
}

/* Available actions */

.stock-request-action-card .page-actions {
    display: flex;
    justify-content: flex-start;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}

.stock-request-action-card .page-actions form {
    margin: 0;
}

/* Keep detail cards compact instead of huge empty blocks */

.stock-request-detail-card {
    padding: 18px;
}

.stock-request-peer-card p {
    margin: 0;
    color: #64748b;
}

/* Responsive */

@media (max-width: 1100px) {
    .detail-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .stock-request-peer-form {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 700px) {
    .page-heading-row {
        flex-direction: column;
    }

    .detail-grid,
    .stock-request-peer-form {
        grid-template-columns: 1fr;
    }

    .stock-request-peer-form .btn {
        width: 100%;
    }
}

</style>
<style id="stock-request-extra-line-style">
.stock-request-extra-line {
    margin-top: 10px;
}

.stock-request-extra-line > summary {
    display: inline-flex;
    width: auto;
    cursor: pointer;
    list-style: none;
    user-select: none;
}

.stock-request-extra-line > summary::-webkit-details-marker {
    display: none;
}

.stock-request-extra-line > summary::before {
    content: "+";
    margin-right: 6px;
    font-weight: 700;
}

.stock-request-extra-line[open] > summary::before {
    content: "−";
}

.stock-request-extra-line > .stock-request-line {
    width: 100%;
    margin-top: 12px;
}

#stock-request-lines,
#stock-request-create-form .page-actions {
    width: 100%;
}

#stock-request-create-form .page-actions {
    display: block;
}

#stock-request-create-form .stock-request-line {
    align-items: end;
}

#stock-request-create-form .stock-request-extra-line select,
#stock-request-create-form .stock-request-extra-line input {
    width: 100%;
}
</style>
<?php

/*
 * The application view helper passes view data as $data.
 * Import those keys into the local content-view scope.
 */
if (isset($data) && is_array($data)) {
    extract($data, EXTR_SKIP);
}

$permissions=$permissions??($_SESSION['auth']['permissions']??[]);
$can=static fn(string $p):bool=>in_array($p,$permissions,true);
$actorId=(int)($_SESSION['auth']['user_id']??0);
$requests=$stockRequests??[];

/*
 * STOCK_REQUEST_LIST_FALLBACK_V1
 *
 * The controller remains the primary reporting-scope source.
 * If the nested content view receives no rows, never hide a request
 * that the signed-in user created or is currently responsible for.
 *
 * Read-only fallback; authorization is still enforced by controller.
 */
if ($requests === [] && $actorId > 0) {
    $requestListCompanyId =
        (new \App\Services\TenantContext())->companyId();

    $fallbackStatement = \db()->prepare(
        "SELECT
            r.request_id,
            r.request_number,
            r.requester_user_id,
            r.current_handler_user_id,
            r.status,
            r.request_kind,
            r.notes,
            r.requested_at,

            requester.display_name AS requester_name,
            handler.display_name AS handler_name,

            a.authority_level AS serving_level,
            w.name AS serving_warehouse_name,
            l.name AS serving_location_name,

            (
                SELECT COUNT(*)
                FROM inventory_stock_request_lines rl
                WHERE rl.company_id=r.company_id
                  AND rl.request_id=r.request_id
            ) AS line_count

         FROM inventory_stock_requests r

         LEFT JOIN users requester
           ON requester.user_id=r.requester_user_id

         LEFT JOIN users handler
           ON handler.user_id=r.current_handler_user_id

         LEFT JOIN inventory_stock_authorities a
           ON a.company_id=r.company_id
          AND a.authority_id=r.serving_authority_id

         LEFT JOIN inventory_warehouses w
           ON w.company_id=r.company_id
          AND w.warehouse_id=a.warehouse_id

         LEFT JOIN inventory_warehouse_locations l
           ON l.company_id=r.company_id
          AND l.warehouse_id=a.warehouse_id
          AND l.location_id=a.location_id

         WHERE r.company_id=:company_id
           AND (
                r.requester_user_id=:requester_id
                OR
                r.current_handler_user_id=:handler_id
           )

         ORDER BY
            CASE
                WHEN r.current_handler_user_id=:priority_id
                 AND r.status='pending_review'
                THEN 0
                ELSE 1
            END,
            r.requested_at DESC,
            r.request_id DESC"
    );

    $fallbackStatement->execute([
        'company_id' => $requestListCompanyId,
        'requester_id' => $actorId,
        'handler_id' => $actorId,
        'priority_id' => $actorId,
    ]);

    $requests =
        $fallbackStatement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

$detailWorkspace =
    is_array($stockRequestDetailWorkspace ?? null)
        ? $stockRequestDetailWorkspace
        : [];

$request =
    $detailWorkspace['request']
    ?? ($stockRequest ?? null);

$peerProposals =
    $detailWorkspace['peerProposals']
    ?? ($peerProposals ?? []);

$peerCandidates =
    $detailWorkspace['peerCandidates']
    ?? ($peerCandidates ?? []);
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
<section class="card stock-request-detail-card">
  <div class="page-heading-row">
    <div><h2><?=e($request['request_number'])?></h2><p><?=e($request['requester_name'])?> · <?=e($request['requester_role_snapshot'])?></p></div>
    <span class="<?=e($statusClass((string)$request['status']))?>"><?=e(str_replace('_',' ',(string)$request['status']))?></span>
  </div>
  <div class="detail-grid">
    <div><strong><?=($request['request_kind']??'employee_issue')==='manager_replenishment'?'Receiving stock':'Serving stock'?></strong><br><?=e($request['serving_warehouse_name'].' / '.$request['serving_location_name'])?></div>
    <div><strong>Current handler</strong><br><?=e($request['current_handler_name']??'—')?></div>
    <div><strong>Requested</strong><br><?=e($request['requested_quantity'])?></div>
    <div><strong>Allocated</strong><br><?=e($request['allocated_quantity'])?></div>
    <div><strong><?=($request['request_kind']??'employee_issue')==='manager_replenishment'?'Received / fulfilled':'Ready at Shop'?></strong><br><?=e($request['ready_quantity'])?></div>
    <div><strong>Requested at</strong><br><?=e($request['requested_at'])?></div>
  </div>
  <?php if(!empty($request['notes'])):?><p><strong>Notes:</strong> <?=e($request['notes'])?></p><?php endif;?>
</section>

<section class="card stock-request-lines-card"><h3>Request lines</h3><div class="table-responsive"><table class="data-table"><thead><tr><th>Product</th><th>Requested</th><th>Allocated</th><th>Pending proposals</th><th>Uncommitted</th><th>Remaining to receive / issue</th><th><?=($request['request_kind']??'employee_issue')==='manager_replenishment'?'Received':'Ready at Shop'?></th></tr></thead><tbody>
<?php foreach($request['lines']??[] as $line):?><tr><td><?=e($line['sku'].' — '.$line['name'])?></td><td><?=e($line['requested_quantity'].' '.$line['unit_of_measure'])?></td><td><?=e($line['allocated_quantity'])?></td><td><?=e($line['proposed_quantity']??0)?></td><td><?=e(max(0,(float)$line['requested_quantity']-(float)$line['allocated_quantity']-(float)($line['proposed_quantity']??0)))?></td><td><?=e(max(0,(float)$line['requested_quantity']-(float)$line['ready_quantity']))?></td><td><?=e($line['ready_quantity'])?></td></tr><?php endforeach;?>
</tbody></table></div></section>

<?php if(!empty($request['allocations'])):?><section class="card"><h3>Allocation and transfer history</h3><div class="table-responsive"><table class="data-table"><thead><tr><th>Level</th><th>Manager</th><th>Product</th><th>Qty</th><th>Source</th><th>Status</th><th>Transfer</th></tr></thead><tbody>
<?php foreach($request['allocations'] as $a):?><tr><td><?=e(ucfirst($a['authority_level']))?></td><td><?=e($a['authority_name'])?></td><td><?=e($a['product_name'])?></td><td><?=e($a['quantity'])?></td><td><?=e($a['source_warehouse_name'].' / '.$a['source_location_name'])?></td><td><?=e(str_replace('_',' ',$a['status']))?></td><td><?php if(!empty($a['transfer_id'])):?><a href="<?=e(appBasePath())?>/inventory/transfers/<?=$a['transfer_id']?>"><?=e($a['transfer_number']??('TRF #'.$a['transfer_id']))?></a> · <?=e($a['transfer_status']??'')?><?php else:?>Direct Shop allocation<?php endif;?></td></tr><?php endforeach;?>
</tbody></table></div></section><?php endif;?>

<?php if(!empty($request['procurements'])):?><section class="card"><h3>Linked company procurement</h3><div class="table-responsive"><table class="data-table"><thead><tr><th>Requisition</th><th>Status</th><th>Purchase order</th><th>PO status</th></tr></thead><tbody>
<?php foreach($request['procurements'] as $p):?><tr><td><?=e($p['requisition_number'])?></td><td><?=e($p['requisition_status'])?></td><td><?php if(!empty($p['purchase_order_id'])):?><a href="<?=e(appBasePath())?>/procurement/<?=$p['purchase_order_id']?>"><?=e($p['po_number'])?></a><?php else:?>—<?php endif;?></td><td><?=e($p['purchase_order_status']??'—')?></td></tr><?php endforeach;?>
</tbody></table></div></section><?php endif;?>

<?php if (!empty($peerCandidates)): ?>
<section class="card stock-request-peer-card"><h3>Propose Peer Transfer</h3><p>The source manager must approve before stock is reserved. Pending proposals count as commitments against this request.</p>
<form class="stock-request-peer-form" method="post" action="<?=e(appBasePath())?>/inventory/stock-requests/<?=(int)$request['request_id']?>/peer-proposals"><?=csrfField()?>
<label>Source sibling<select name="source_authority_id" required><?php foreach($peerCandidates as $candidate):?><option value="<?=(int)$candidate['authority_id']?>"><?=e($candidate['warehouse_name'].' — '.$candidate['display_name'])?></option><?php endforeach;?></select></label>
<label>Request product<select name="request_line_id" required><?php foreach($request['lines'] as $line):?><option value="<?=(int)$line['request_line_id']?>"><?=e($line['name'])?></option><?php endforeach;?></select></label>
<label>Quantity<input type="number" name="quantity" min="0.001" step="0.001" required></label><button class="btn btn-primary">Send proposal</button></form></section>
<?php endif; ?>
<section class="card stock-request-action-card"><h3>Available action</h3><div class="page-actions">
<?php if((int)($request['current_handler_user_id']??0)===$actorId && in_array($request['status'],['pending_review','awaiting_transfer'],true) && $can('inventory.stock_requests.process')):?><form method="post" action="<?=e(appBasePath())?>/inventory/stock-requests/<?=$request['request_id']?>/process"><?=csrfField()?><button class="btn btn-primary"><?=(($request['request_kind']??'')==='manager_replenishment' && (int)$request['requester_user_id']===$actorId)?'Recheck Central replenishment':'Fulfil From My Stock'?></button></form><?php endif;?>
<?php if(($request['request_kind']??'employee_issue')==='employee_issue' && $request['status']==='ready_to_issue' && (int)($stockRequestAuthority['authority_id']??0)===(int)$request['serving_authority_id'] && $can('inventory.stock_requests.issue')):?><form method="post" action="<?=e(appBasePath())?>/inventory/stock-requests/<?=$request['request_id']?>/issue"><?=csrfField()?><button class="btn btn-primary">Issue full request to DSA/DSP</button></form><?php endif;?>
<?php if($request['status']==='issued' && (int)$request['requester_user_id']===$actorId && $can('inventory.stock_requests.receive')):?><form method="post" action="<?=e(appBasePath())?>/inventory/stock-requests/<?=$request['request_id']?>/receive"><?=csrfField()?><button class="btn btn-primary">Confirm I received the stock</button></form><?php endif;?>
<a class="btn btn-secondary" href="<?=e(appBasePath())?>/inventory/stock-requests">Back to requests</a>
</div></section>

<?php elseif($section==='authorities' && !empty($canManageStockAuthorities)):?>
<section class="card"><h2>Manager → represented stock</h2><p>Configure the represented stock and its parent warehouse. Explicit warehouse parents take precedence over legacy reporting links. Ambiguous branches require an explicit parent.</p>
<form method="post" action="<?=e(appBasePath())?>/inventory/stock-requests/authorities"><?=csrfField()?>
<div class="enterprise-form">
<label>Manager<select name="user_id" required><option value="">Select manager</option><?php foreach($stockAuthorityCandidates??[] as $c):?><option value="<?=$c['user_id']?>"><?=e($c['display_name'].' — '.$c['job_title'])?></option><?php endforeach;?></select></label>
<label>Level<select name="authority_level" required><option value="shop">Shop Manager</option><option value="district">District Manager</option><option value="regional">Regional Manager / company stock</option></select></label>
<label>Warehouse<select name="warehouse_id" id="authority-warehouse" required><option value="">Select warehouse</option><?php foreach(($stockAuthorityWarehouses['warehouses']??[]) as $w):?><option value="<?=$w['warehouse_id']?>"><?=e($w['code'].' — '.$w['name'])?></option><?php endforeach;?></select></label>
<label>Stock location<select name="location_id" id="authority-location" required><option value="">Select location</option><?php foreach(($stockAuthorityWarehouses['locations']??[]) as $l):?><option value="<?=$l['location_id']?>" data-warehouse="<?=$l['warehouse_id']?>"><?=e($l['code'].' — '.$l['name'])?></option><?php endforeach;?></select></label>
<label>Parent warehouse (Shop → District, District → Regional)<select name="parent_warehouse_id"><option value="">Keep existing / infer only an unambiguous parent</option><?php foreach(($stockAuthorityWarehouses['warehouses']??[]) as $w):?><option value="<?=$w['warehouse_id']?>"><?=e($w['code'].' — '.$w['name'])?></option><?php endforeach;?></select></label>
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
<?php if(!empty($canCreateStockRequest) || $can('inventory.stock_requests.create')):?><?php
$companyId = (new \App\Services\TenantContext())->companyId();
$actorUserId = (int) ($_SESSION['auth']['user_id'] ?? 0);

$requestProducts = is_array($stockRequestProducts ?? null)
    ? $stockRequestProducts
    : [];

if ($requestProducts === []) {
    $productStatement = \db()->prepare(
        "SELECT
            product_id,
            sku,
            name,
            unit_of_measure,
            product_type
         FROM sales_products
         WHERE company_id=:company_id
           AND active=TRUE
           AND deleted_at IS NULL
           AND (
                product_type IS NULL
                OR product_type NOT IN('service','fixed_asset')
           )
         ORDER BY name,product_id"
    );

    $productStatement->execute([
        'company_id' => $companyId,
    ]);

    $requestProducts =
        $productStatement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

$resolvedAuthority =
    is_array($stockRequestAuthority ?? null)
        ? $stockRequestAuthority
        : null;

if ($resolvedAuthority === null && $actorUserId > 0) {
    $authorityStatement = \db()->prepare(
        "SELECT a.*
         FROM inventory_stock_authorities a
         INNER JOIN inventory_warehouses w
           ON w.company_id=a.company_id
          AND w.warehouse_id=a.warehouse_id
         INNER JOIN inventory_warehouse_locations l
           ON l.company_id=a.company_id
          AND l.warehouse_id=a.warehouse_id
          AND l.location_id=a.location_id
         WHERE a.company_id=:company_id
           AND a.user_id=:user_id
           AND a.active=TRUE
           AND w.active=TRUE
           AND w.deleted_at IS NULL
           AND l.active=TRUE
           AND l.deleted_at IS NULL
         LIMIT 1"
    );

    $authorityStatement->execute([
        'company_id' => $companyId,
        'user_id' => $actorUserId,
    ]);

    $candidate =
        $authorityStatement->fetch(\PDO::FETCH_ASSOC);

    if (is_array($candidate)) {
        $resolvedAuthority = $candidate;
    }
}

$stockManagerLevel = (string) (
    $resolvedAuthority['authority_level'] ?? ''
);

$isStockHierarchyManager = in_array(
    $stockManagerLevel,
    ['shop', 'district', 'regional'],
    true
);
?>

<section class="card">
    <h2>
        <?= $isStockHierarchyManager
            ? 'Request Stock'
            : 'New stock request' ?>
    </h2>

    <p>
        <?php if ($isStockHierarchyManager): ?>
            Request replenishment for your
            <?= e(ucfirst($stockManagerLevel)) ?>
            stock location.
        <?php else: ?>
            Submit products and quantities to your
            responsible Shop Manager.
        <?php endif; ?>
    </p>

    <form
        id="stock-request-create-form"
        method="post"
        action="<?= e(appBasePath()) ?>/inventory/stock-requests"
    >
        <?= csrfField() ?>

        <div id="stock-request-lines">
            <div class="form-grid stock-request-line">
                <label>
                    Product
                    <select
                        name="product_id[]"
                        class="stock-request-product"
                        required
                    >
                        <option value="">Select product</option>

                        <?php foreach ($requestProducts as $product): ?>
                            <option
                                value="<?= (int) $product['product_id'] ?>"
                            >
                                <?= e(
                                    (string) $product['sku'] . ' - ' . (string) $product['name']
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Quantity
                    <input
                        name="quantity[]"
                        class="stock-request-quantity"
                        type="number"
                        min="0.001"
                        step="0.001"
                        required
                    >
                </label>
            </div>
        </div>

        <div class="page-actions">
    <?php if ($requestProducts !== []): ?>

        <?php for ($extraLine = 2; $extraLine <= 10; $extraLine++): ?>
            <details class="stock-request-extra-line">
                <summary class="btn btn-secondary">
                    Add another item
                </summary>

                <div class="form-grid stock-request-line">
                    <label>
                        Product
                        <select
                            name="product_id[]"
                            class="stock-request-product"
                        >
                            <option value="">Select product</option>

                            <?php foreach ($requestProducts as $product): ?>
                                <option
                                    value="<?= (int) $product['product_id'] ?>"
                                >
                                    <?= e(
                                        (string) $product['sku'] . ' - ' . (string) $product['name']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        Quantity
                        <input
                            name="quantity[]"
                            class="stock-request-quantity"
                            type="number"
                            min="0.001"
                            step="0.001"
                        >
                    </label>
                </div>

        <?php endfor; ?>

        <?php for ($extraLine = 2; $extraLine <= 10; $extraLine++): ?>
            </details>
        <?php endfor; ?>

    <?php endif; ?>
</div>

        <?php if ($requestProducts === []): ?>
            <p class="form-help">
                No requestable stock products are configured.
            </p>
        <?php endif; ?>

        <label>
            Notes
            <textarea
                name="notes"
                maxlength="1000"
            ></textarea>
        </label>

        <button
            type="submit"
            class="btn btn-primary"
            <?= $requestProducts === []
                ? 'disabled'
                : '' ?>
        >
            <?= $isStockHierarchyManager
                ? 'Request replenishment'
                : 'Submit stock request' ?>
        </button>
    </form>
</section>



<?php endif;?>
<section class="card" id="stock-request-status-table">
    <h2>Stock requests</h2>

    <?php
    $listActorId = (int) ($_SESSION['auth']['user_id'] ?? 0);

    $statusLabel = static function (array $row, int $actorId): string {
        $status = (string) ($row['status'] ?? '');

        $assignedToMe =
            (int) ($row['current_handler_user_id'] ?? 0)
            === $actorId;

        if (
            $assignedToMe
            && in_array(
                $status,
                [
                    'pending_review',
                    'awaiting_transfer',
                    'awaiting_procurement'
                ],
                true
            )
        ) {
            return 'Action required';
        }

        return match ($status) {
            'pending_review' => 'Waiting for manager',
            'awaiting_transfer' => 'Waiting for stock transfer',
            'awaiting_procurement' => 'Waiting for procurement',
            'issued' => 'Stock sent',
            'completed', 'closed' => 'Completed',
            'cancelled', 'rejected' => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    };
    ?>

    <?php if (!empty($requests)): ?>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Request</th>
                        <th>Type</th>
                        <th>Requested by</th>
                        <th>Stock location</th>
                        <th>Items</th>
                        <th>Status</th>
                        <th>Responsible</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($requests as $row): ?>
                        <?php
                        $assignedToMe =
                            (int) (
                                $row['current_handler_user_id'] ?? 0
                            ) === $listActorId;

                        $actionRequired =
                            $assignedToMe
                            && in_array(
                                (string) ($row['status'] ?? ''),
                                [
                                    'pending_review',
                                    'awaiting_transfer',
                                    'awaiting_procurement'
                                ],
                                true
                            );
                        ?>

                        <tr>
                            <td>
                                <strong>
                                    <?= e(
                                        (string) (
                                            $row['request_number']
                                            ?? ('#' . ($row['request_id'] ?? ''))
                                        )
                                    ) ?>
                                </strong>
                            </td>

                            <td>
                                <?= e(
                                    ($row['request_kind'] ?? '')
                                    === 'manager_replenishment'
                                        ? 'Replenishment'
                                        : 'Stock request'
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    (string) (
                                        $row['requester_name'] ?? ''
                                    )
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    trim(
                                        (string) (
                                            $row['serving_warehouse_name']
                                            ?? ''
                                        )
                                        . (
                                            !empty(
                                                $row['serving_location_name']
                                            )
                                                ? ' / '
                                                    . $row[
                                                        'serving_location_name'
                                                    ]
                                                : ''
                                        )
                                    )
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    (string) (
                                        $row['line_count'] ?? ''
                                    )
                                ) ?>
                            </td>

                            <td>
                                <strong>
                                    <?= e(
                                        $statusLabel(
                                            $row,
                                            $listActorId
                                        )
                                    ) ?>
                                </strong>
                            </td>

                            <td>
                                <?= $assignedToMe
                                    ? 'You'
                                    : e(
                                        (string) (
                                            $row['handler_name'] ?? ''
                                        )
                                    ) ?>
                            </td>

                            <td>
                                <form
                                    method="get"
                                    action="<?= e(appBasePath()) ?>/inventory/stock-requests/<?= (int) $row['request_id'] ?>"
                                    style="margin:0"
                                >
                                    <button
                                        type="submit"
                                        class="<?= $actionRequired
                                            ? 'btn btn-primary btn-compact'
                                            : 'btn btn-secondary btn-compact' ?>"
                                    >
                                        <?= $actionRequired
                                            ? 'Review'
                                            : 'Open' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>

                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php else: ?>

        <p class="empty-state">
            No stock requests in your reporting scope.
        </p>

    <?php endif; ?>
</section>
</div>
<?php endif;?>

<script>
(function(){
 var add=document.getElementById('add-sr-line'),box=document.getElementById('sr-lines');if(add&&box){add.addEventListener('click',function(){var first=box.querySelector('.sr-line');if(!first)return;var clone=first.cloneNode(true);clone.querySelectorAll('select,input').forEach(function(el){el.value='';});box.appendChild(clone);});}
 var w=document.getElementById('authority-warehouse'),l=document.getElementById('authority-location');if(w&&l){var filter=function(){Array.from(l.options).forEach(function(o,i){if(i===0)return;o.hidden=o.dataset.warehouse!==w.value;});if(l.selectedOptions[0]&&l.selectedOptions[0].hidden)l.value='';};w.addEventListener('change',filter);filter();}
})();
</script>

<?php if(!empty($peerProposals)): ?>
<section class="card"><h2>Peer transfer decisions and history</h2>
<?php foreach($peerProposals as $peer): ?>
<article class="card"><h3><?=e($peer['proposal_number'])?> · <?=e(str_replace('_',' ',$peer['state']))?></h3>
<p><?=e($peer['request_number'])?> · <?=e($peer['product_name'])?> · <?=e($peer['quantity'])?> units<br>
<?=e($peer['source_name'].' ('.$peer['source_owner_name'].') → '.$peer['destination_name'].' ('.$peer['destination_owner_name'].')')?><br>
Proposed by <?=e($peer['proposer_name'])?> at <?=e($peer['created_at'])?></p>
<?php if($peer['quantity_available']!==null):?><p>Source available stock: <?=e($peer['quantity_available'])?></p><?php endif;?>
<?php if($peer['source_decided_at']):?><p>Source decision by user #<?=(int)$peer['source_decided_by']?> at <?=e($peer['source_decided_at'])?> <?=e($peer['rejection_reason']??'')?></p><?php endif;?>
<?php if($peer['dispatched_at']):?><p>Dispatched <?=e($peer['quantity'])?> by user #<?=(int)$peer['dispatched_by']?> at <?=e($peer['dispatched_at'])?></p><?php endif;?>
<?php if($peer['posted_at']):?><p>Received <?=e($peer['quantity'])?> by user #<?=(int)$peer['posted_by']?> at <?=e($peer['posted_at'])?></p><?php endif;?>
<?php if($peer['state']==='proposed' && $can('inventory.stock_requests.process')): ?>
<?php if((int)$peer['source_owner_user_id']===$actorId && (int)($stockRequestAuthority['authority_id']??0)===(int)$peer['source_authority_id']): ?>
<form method="post" action="<?=e(appBasePath())?>/inventory/peer-proposals/<?=(int)$peer['proposal_id']?>/decision"><?=csrfField()?><button name="decision" value="approve" class="btn btn-primary">Approve</button><label>Rejection reason<input name="reason" maxlength="1000"></label><button name="decision" value="reject" class="btn btn-secondary">Reject</button></form>
<?php elseif((int)$peer['proposed_by']===$actorId): ?>
<form method="post" action="<?=e(appBasePath())?>/inventory/peer-proposals/<?=(int)$peer['proposal_id']?>/decision"><?=csrfField()?><button name="decision" value="cancel" class="btn btn-secondary">Cancel proposal</button></form>
<?php endif; endif; ?>
<?php if(!empty($peer['transfer_id'])): ?>
<a href="<?=e(appBasePath())?>/inventory/transfers/<?=(int)$peer['transfer_id']?>"><?=e($peer['transfer_number'])?></a>
<?php if($peer['state']==='source_approved' && (int)$peer['source_owner_user_id']===$actorId && $can('inventory.transfers.dispatch')): ?><p>Dispatch required from your source warehouse.</p><?php endif;?>
<?php if($peer['state']==='dispatched' && (int)$peer['destination_owner_user_id']===$actorId && $can('inventory.transfers.receive')): ?><p>Receipt required at your destination warehouse.</p><?php endif;?>
<?php endif;?></article>
<?php endforeach;?></section>
<?php endif;?>
