<?php declare(strict_types=1);$data=is_array($data??null)?$data:[];$type=$data['commercialType'];$r=$data['record'];$can=!empty($data['canManage']);$notice=$data['notice']??null;$errors=$data['errors']??[];?>
<div class="sales-workspace"><nav class="card"><a class="btn btn-secondary btn-compact" href="/office_app/public/sales/<?=e($type==='pricelist'?'pricelists':'teams')?>">Back to list</a></nav><?php if(is_array($notice)):?><div class="alert alert-success"><?=e($notice['message']??'')?></div><?php endif;?><?php if($errors!==[]):?><?php foreach($errors as $error):?><?php if(is_array($error)):?><?php \view('components.app-error',['error'=>$error]);?><?php else:?><div class="alert alert-danger"><div><?=e((string)$error)?></div></div><?php endif;?><?php endforeach;?><?php endif;?><header class="page-header"><div><p class="eyebrow"><?=e($type==='pricelist'?'Pricelist':'Sales team')?></p><h1><?=e($r['name'])?></h1><p><?=!empty($r['active'])?'Active':'Archived'?></p></div></header>
<?php if($type==='pricelist'):?><section class="card"><form method="post" action="/office_app/public/sales/pricelists/<?=e($r['pricelist_id'])?>"><?=csrfField()?><div class="finance-filter-form"><div class="form-field"><label>Name</label><input name="name" value="<?=e($r['name'])?>" required></div><div class="form-field"><label>Currency</label><input name="currency" value="<?=e($r['currency'])?>" maxlength="3" required></div><div class="form-field"><label>Valid from</label><input name="valid_from" type="date" value="<?=e($r['valid_from']??'')?>"></div><div class="form-field"><label>Valid to</label><input name="valid_to" type="date" value="<?=e($r['valid_to']??'')?>"></div></div><?php if($can):?><button class="btn btn-primary">Save</button><?php endif;?></form><?php if($can):?><form method="post" action="/office_app/public/sales/pricelists/<?=e($r['pricelist_id'])?>/active"><?=csrfField()?><input type="hidden" name="active" value="<?=!empty($r['active'])?'0':'1'?>"><button class="btn btn-secondary"><?=!empty($r['active'])?'Archive':'Activate'?></button></form><?php endif;?></section>
<section class="card table-card"><h2>Pricing rules</h2><div class="table-responsive"><table class="data-table"><thead><tr><th>Scope</th><th>Minimum</th><th>Calculation</th><th>Validity</th><th>Priority</th><th>Status</th></tr></thead><tbody><?php foreach($r['rules'] as $rule):?><tr><td><?=e($rule['product_name']??$rule['category']??'All products')?></td><td><?=e($rule['minimum_quantity'])?></td><td><?=e($rule['calculation']==='fixed'?'Fixed '.$rule['fixed_price']:'Adjustment '.$rule['percentage_adjustment'].'%')?></td><td><?=e(($rule['valid_from']??'Any').' – '.($rule['valid_to']??'Any'))?></td><td><?=e($rule['priority'])?></td><td><?=!empty($rule['active'])?'Active':'Inactive'?></td></tr><?php endforeach;?></tbody></table></div></section><?php if($can):?><section class="card"><h2>Add rule</h2><form method="post" action="/office_app/public/sales/pricelists/<?=e($r['pricelist_id'])?>/rules"><?=csrfField()?><div class="finance-filter-form"><div class="form-field"><label>Product</label><select name="product_id"><option value="">All / category</option><?php foreach($data['products'] as $p):?><option value="<?=e($p['product_id'])?>"><?=e($p['sku'].' - '.$p['name'])?></option><?php endforeach;?></select></div><div class="form-field"><label>Category</label><input name="category"></div><div class="form-field"><label>Minimum quantity</label><input type="number" min="0.001" step="0.001" name="minimum_quantity" value="1"></div><div class="form-field"><label>Calculation</label><select name="calculation"><option value="fixed">Fixed price</option><option value="percentage">Percentage adjustment</option></select></div><div class="form-field"><label>Fixed price</label><input type="number" min="0" step="0.01" name="fixed_price" value="0"></div><div class="form-field"><label>Percentage adjustment</label><input type="number" step="0.01" name="percentage_adjustment" value="0"></div><div class="form-field"><label>From</label><input type="date" name="rule_from"></div><div class="form-field"><label>To</label><input type="date" name="rule_to"></div><div class="form-field"><label>Priority</label><input type="number" name="priority" value="100"></div></div><button class="btn btn-primary">Add rule</button></form></section><section class="card"><h2>Edit rules</h2><?php foreach($r['rules'] as $rule):?><details><summary><?=e(($rule['product_name']??$rule['category']??'All products').' · priority '.$rule['priority'])?></summary><form method="post" action="/office_app/public/sales/pricelists/<?=e($r['pricelist_id'])?>/rules/<?=e($rule['rule_id'])?>"><?=csrfField()?><div class="finance-filter-form"><div class="form-field"><label>Product</label><select name="product_id"><option value="">All / category</option><?php foreach($data['products'] as $p):?><option value="<?=e($p['product_id'])?>" <?=(int)$rule['product_id']===(int)$p['product_id']?'selected':''?>><?=e($p['sku'].' - '.$p['name'])?></option><?php endforeach;?></select></div><div class="form-field"><label>Category</label><input name="category" value="<?=e($rule['category']??'')?>"></div><div class="form-field"><label>Minimum quantity</label><input type="number" min="0.001" step="0.001" name="minimum_quantity" value="<?=e($rule['minimum_quantity'])?>"></div><div class="form-field"><label>Calculation</label><select name="calculation"><option value="fixed" <?=$rule['calculation']==='fixed'?'selected':''?>>Fixed price</option><option value="percentage" <?=$rule['calculation']==='percentage'?'selected':''?>>Percentage adjustment</option></select></div><div class="form-field"><label>Fixed price</label><input type="number" min="0" step="0.01" name="fixed_price" value="<?=e($rule['fixed_price']??0)?>"></div><div class="form-field"><label>Percentage adjustment</label><input type="number" step="0.01" name="percentage_adjustment" value="<?=e($rule['percentage_adjustment']??0)?>"></div><div class="form-field"><label>From</label><input type="date" name="rule_from" value="<?=e($rule['valid_from']??'')?>"></div><div class="form-field"><label>To</label><input type="date" name="rule_to" value="<?=e($rule['valid_to']??'')?>"></div><div class="form-field"><label>Priority</label><input type="number" name="priority" value="<?=e($rule['priority'])?>"></div></div><button class="btn btn-primary">Save rule</button></form><form method="post" action="/office_app/public/sales/pricelists/<?=e($r['pricelist_id'])?>/rules/<?=e($rule['rule_id'])?>/active"><?=csrfField()?><input type="hidden" name="active" value="<?=!empty($rule['active'])?'0':'1'?>"><button class="btn btn-secondary"><?=!empty($rule['active'])?'Deactivate':'Activate'?></button></form></details><?php endforeach;?></section><?php endif;?>
<?php else:?>

<?php
$members=(array)($r['members']??[]);
$memberCount=count($members);
$managerName=trim((string)($r['manager_name']??''));
if($managerName===''){
    $managerName='Unassigned';
}
?>

<section class="sales-team-overview" aria-label="Sales team overview">

    <article class="card sales-team-overview-card">
        <span class="sales-team-overview-label">Manager</span>
        <strong><?=e($managerName)?></strong>
        <small>Reporting manager from Human Resources</small>
    </article>

    <article class="card sales-team-overview-card">
        <span class="sales-team-overview-label">Members</span>
        <strong><?=e($memberCount)?></strong>
        <small><?= $memberCount===1 ? 'DSA / DSP member' : 'DSA / DSP members' ?></small>
    </article>

    <article class="card sales-team-overview-card">
        <span class="sales-team-overview-label">Status</span>
        <strong><?=!empty($r['active'])?'Active':'Archived'?></strong>
        <small>Sales team status</small>
    </article>

</section>


<section class="card table-card sales-team-members-card">

    <div class="table-summary">
        <strong>Team members</strong>
        <span><?=e($memberCount)?> members</span>
    </div>

    <div class="table-responsive">
        <table class="data-table sales-team-member-table">
            <thead>
                <tr>
                    <th>Member</th>
                    <th>Role</th>
                    <th>Agent code</th>
                </tr>
            </thead>

            <tbody>

            <?php if($members===[]): ?>
                <tr>
                    <td colspan="3" class="empty-state">
                        No DSA / DSP members assigned.
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach($members as $member): ?>
                <tr>
                    <td>
                        <strong><?=e($member['name']??'Unknown')?></strong>
                    </td>

                    <td>
                        <span class="sales-team-role-badge">
                            <?=e(strtoupper((string)($member['agent_type']??'DSA/DSP')))?>
                        </span>
                    </td>

                    <td>
                        <?=e($member['agent_code']??'-')?>
                    </td>
                </tr>
            <?php endforeach; ?>

            </tbody>
        </table>
    </div>

</section>


<?php if($can): ?>

<details class="card sales-team-admin-panel">
    <summary>Administration - edit team</summary>

    <form method="post"
          action="/office_app/public/sales/teams/<?=e($r['team_id'])?>">

        <?=csrfField()?>

        <div class="sales-team-admin-grid">

            <div class="form-field">
                <label>Team name</label>
                <input
                    name="name"
                    value="<?=e($r['name'])?>"
                    required
                >
            </div>

            <div class="form-field">
                <label>Territory</label>
                <select name="territory_id">
                    <option value="">No territory</option>

                    <?php foreach($data['territories'] as $territory): ?>
                        <option
                            value="<?=e($territory['territory_id'])?>"
                            <?=
                                (int)($r['territory_id']??0)
                                ===(int)$territory['territory_id']
                                    ? 'selected'
                                    : ''
                            ?>
                        >
                            <?=e($territory['name'])?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-field sales-team-member-editor">
                <label>Members</label>

                <?php
                $ids=array_map(
                    static fn(array $member)=>
                        (int)$member['agent_id'],
                    $members
                );
                ?>

                <select
                    multiple
                    size="10"
                    name="member_ids[]"
                >
                    <?php foreach($data['agents'] as $agent): ?>
                        <option
                            value="<?=e($agent['agent_id'])?>"
                            <?=in_array((int)$agent['agent_id'],$ids,true)?'selected':''?>
                        >
                            <?=e($agent['name'])?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

        </div>

        <div class="sales-team-admin-actions">
            <button class="btn btn-primary">
                Save team
            </button>
        </div>

    </form>

    <form method="post"
          action="/office_app/public/sales/teams/<?=e($r['team_id'])?>/active"
          class="sales-team-status-form">

        <?=csrfField()?>

        <input
            type="hidden"
            name="active"
            value="<?=!empty($r['active'])?'0':'1'?>"
        >

        <button class="btn btn-secondary">
            <?=!empty($r['active'])?'Archive team':'Activate team'?>
        </button>
    </form>

</details>

<?php endif; ?>

<?php endif; ?>
</div>