<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$profile = is_array($data['profile'] ?? null)
    ? $data['profile']
    : [];
$errors = is_array($data['errors'] ?? null)
    ? $data['errors']
    : [];
$currentlyActive = !empty($profile['active']);
$activate = !$currentlyActive;
?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<section class="card confirmation-card">
    <div
        class="confirmation-icon <?= $activate
            ? 'confirmation-icon-success'
            : '' ?>"
        aria-hidden="true"
    >
        <?= $activate ? '✓' : '!' ?>
    </div>

    <div>
        <h2 class="card-title">
            <?= $activate ? 'Activate' : 'Deactivate' ?>
            <?= e($profile['display_name'] ?? '') ?>?
        </h2>

        <?php if ($activate): ?>
            <p>
                Activating
                <strong>
                    @<?= e($profile['username'] ?? '') ?>
                </strong>
                will allow the account to sign in again,
                subject to its current password and lock status.
            </p>

            <ul class="confirmation-list">
                <li>
                    Existing roles and permissions will
                    become available again.
                </li>
                <li>
                    A separate unlock may still be required
                    if the account is temporarily locked.
                </li>
                <li>
                    This change will be recorded in the
                    audit log.
                </li>
            </ul>
        <?php else: ?>
            <p>
                Deactivating
                <strong>
                    @<?= e($profile['username'] ?? '') ?>
                </strong>
                will block the account from OfficeApp.
            </p>

            <ul class="confirmation-list">
                <li>
                    New sign-in attempts will be rejected.
                </li>
                <li>
                    Existing access will end on the next
                    protected request.
                </li>
                <li>
                    Roles, history and audit records will
                    be preserved.
                </li>
                <li>
                    The account can be activated again later.
                </li>
            </ul>
        <?php endif; ?>

        <form
            method="post"
            action="/office_app/public/administration/users/account-status"
            class="confirmation-actions"
        >
            <?= csrfField() ?>

            <input
                type="hidden"
                name="user_id"
                value="<?= e(
                    $profile['user_id'] ?? ''
                ) ?>"
            >

            <input
                type="hidden"
                name="active"
                value="<?= $activate ? '1' : '0' ?>"
            >

            <a
                href="/office_app/public/administration/users/view?id=<?= e(
                    $profile['user_id'] ?? ''
                ) ?>"
                class="btn btn-secondary"
            >
                Cancel
            </a>

            <button
                type="submit"
                class="btn <?= $activate
                    ? 'btn-primary'
                    : 'btn-danger' ?>"
            >
                <?= $activate
                    ? 'Activate account'
                    : 'Deactivate account' ?>
            </button>
        </form>
    </div>
</section>
