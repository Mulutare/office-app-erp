<?php
$access = $data['access'] ?? null;
?>
<section class="card enterprise-form">
    <form method="get" action="<?= e(appBasePath()) ?>/administration/access-control">
        <label for="access-user">Select user</label>
        <select id="access-user" name="user_id" required>
            <option value="">Choose a company user</option>
            <?php foreach ($data['users'] as $target): ?>
            <option value="<?= (int)$target['user_id'] ?>" <?= (int)($access['target']['user_id']??0)===(int)$target['user_id']?'selected':'' ?>><?= e($target['display_name'].' — '.$target['username']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-primary" type="submit">Edit function access</button>
        <a class="btn btn-secondary" href="<?= e(appBasePath()) ?>/administration/roles">Role defaults</a>
    </form>
</section>
<?php if (!empty($data['notice'])): ?><p class="alert alert-success" role="status"><?= e($data['notice']) ?></p><?php endif; ?>
<?php if (!empty($data['error'])): ?><p class="alert alert-danger" role="alert"><?= e($data['error']) ?></p><?php endif; ?>
<?php if ($access !== null) \view('administration.user-function-access',['access'=>$access,'returnTo'=>'access_control']); ?>
