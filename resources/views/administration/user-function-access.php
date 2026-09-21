<?php
$access = $data['access'];
$target = $access['target'];
$groups = []; $gates = []; $landingNames = []; $landingItems = [];
foreach (\App\Services\WorkspaceAccessService::definitions() as $module=>$items) {
    foreach ($items as $item) if (is_string($item[2])) { $landingNames[$item[2]]=isset($landingNames[$item[2]])?$landingNames[$item[2]].' / '.$item[0]:$item[0]; $landingItems[$item[2]][]=$item; }
}
foreach ($access['permissions'] as $permission) {
    $module = ['organization'=>'administration','audit'=>'administration'][$permission['module']] ?? $permission['module'];
    if (str_ends_with($permission['code'],'.module.enabled')) { $gates[$module]=$permission; continue; }
    $parts=explode('.',$permission['code']);
    $area=count($parts)>2?ucwords(str_replace('_',' ',$parts[1])):'General';
    $groups[$module][$area][]=$permission;
}
if (isset($groups['sales'])) $groups = ['sales'=>$groups['sales']] + $groups;
$choice = static function(array $permission) use ($access,$landingItems): void {
    $id=(int)$permission['permission_id']; $code=$permission['code'];
    $selected=$access['overrides'][$id]??'inherit';
    $default=in_array($code,$access['defaults'],true)?'Allowed':'Denied';
    $effective=in_array($code,$access['effective'],true)?'Allowed':'Denied';
    ?>
    <div><small>Role default: <strong><?= e($default) ?></strong></small><label>User override
    <select name="access[<?= $id ?>]" aria-label="<?= e($permission['name']) ?>">
        <option value="inherit" <?= $selected==='inherit'?'selected':'' ?>>Use role setting (<?= e($default) ?>)</option>
        <option value="allow" <?= $selected==='allow'?'selected':'' ?>>Allow for this user</option>
        <option value="deny" <?= $selected==='deny'?'selected':'' ?>>Deny for this user</option>
    </select></label><small>Effective access: <strong><?= e($effective) ?></strong> (saved setting)</small>
        <?php foreach ($landingItems[$code]??[] as $landing): ?>
        <small><?= e($landing[0]) ?> page: <strong><?= \App\Services\WorkspaceAccessService::allowed($landing,$access['effective'])?'Allowed':'Denied' ?></strong></small>
        <?php endforeach; ?>
    </div>
    <?php
};
?>
<section class="card enterprise-form" id="user-function-access">
    <h2>Function Access Overrides — <?= e($target['display_name']) ?></h2>
    <p>Company: <strong><?= e($access['company']['name']??$access['company']['company_name']??'Current company') ?></strong> · Assigned roles: <strong><?= e(implode(', ', $access['roles'])) ?></strong></p>
    <p>Add or deny functions for this user. Their company and role settings remain unchanged. A user deny takes priority over a role grant. Company module availability, record scope, and workflow rules still apply.</p>
    <?php if ((int)$target['user_id']===(int)($_SESSION['auth']['user_id']??0)): ?>
        <p>Another access administrator must edit your access.</p>
    <?php else: ?>
    <form method="post" action="<?= e(appBasePath()) ?>/administration/access-control">
        <?= csrfField() ?>
        <input type="hidden" name="user_id" value="<?= (int)$target['user_id'] ?>">
        <input type="hidden" name="version" value="<?= e($access['version']) ?>">
        <input type="hidden" name="return_to" value="<?= e($data['returnTo']??'access_control') ?>">
        <div class="permission-editor">
        <?php foreach ($groups as $module=>$areas): ?>
            <fieldset class="permission-editor-group">
                <legend><?= e(['hr'=>'Human Resources','it'=>'IT'][$module]??ucwords($module)) ?></legend>
                <?php if (isset($gates[$module])): ?>
                    <div class="permission-option"><strong>Module access</strong><?php $choice($gates[$module]); ?></div>
                    <p class="form-help">Denying the module hides all its functions. Function choices are kept.</p>
                <?php endif; ?>
                <details <?= $module==='sales'?'open':'' ?>>
                    <summary>Functions</summary>
                    <?php foreach ($areas as $area=>$permissions): ?>
                        <h3><?= e($area) ?></h3>
                        <?php foreach ($permissions as $permission): ?>
                            <div class="permission-option">
                                <span><strong><?= e($landingNames[$permission['code']]??$permission['name']) ?></strong><small><?= e($permission['description']??'') ?></small></span>
                                <?php $choice($permission); ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </details>
            </fieldset>
        <?php endforeach; ?>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save function access</button></div>
    </form>
    <?php endif; ?>
</section>
