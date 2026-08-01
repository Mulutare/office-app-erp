<?php

declare(strict_types=1);

$data = is_array($data ?? null) ? $data : [];
$company = is_array($data['company'] ?? null) ? $data['company'] : [];
$targetUser = is_array($data['targetUser'] ?? null)
    ? $data['targetUser'] : [];
$errors = is_array($data['errors'] ?? null) ? $data['errors'] : [];
?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert"><?= e($errors['form']) ?></div>
<?php endif; ?>

<section class="card confirmation-card">
    <div class="confirmation-icon" aria-hidden="true">!</div>
    <div>
        <span class="module-eyebrow">Vendor recovery control</span>
        <h2 class="card-title">Reset password for <?= e($targetUser['display_name'] ?? '') ?>?</h2>
        <p>
            This action applies only to <strong>@<?= e($targetUser['username'] ?? '') ?></strong>
            in <?= e($company['name'] ?? '') ?>.
        </p>
        <ul class="confirmation-list">
            <li>The existing password stops working immediately.</li>
            <li>Failed-login counters and temporary locks are cleared.</li>
            <li>The user must change the one-time password after sign-in.</li>
            <li>The temporary password is displayed once and never audited.</li>
        </ul>
        <form method="post" action="/office_app/public/administration/companies/reset-user-password" class="confirmation-actions">
            <?= csrfField() ?>
            <input type="hidden" name="company_id" value="<?= e($company['company_id'] ?? 0) ?>">
            <input type="hidden" name="user_id" value="<?= e($targetUser['user_id'] ?? 0) ?>">
            <a href="/office_app/public/administration/companies/view?id=<?= e($company['company_id'] ?? 0) ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-danger">Generate temporary password</button>
        </form>
    </div>
</section>
