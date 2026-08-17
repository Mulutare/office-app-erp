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
$failedLoginCount = (int) (
    $profile['failed_login_count'] ?? 0
);
$lockedUntil = is_string(
    $profile['locked_until'] ?? null
)
    ? $profile['locked_until']
    : '';
?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<section class="card confirmation-card">
    <div
        class="confirmation-icon confirmation-icon-information"
        aria-hidden="true"
    >
        🔓
    </div>

    <div>
        <h2 class="card-title">
            Unlock <?= e(
                $profile['display_name'] ?? ''
            ) ?>?
        </h2>

        <p>
            This will clear the temporary account lock
            and failed-login counter for
            <strong>
                @<?= e($profile['username'] ?? '') ?>
            </strong>.
        </p>

        <dl class="unlock-summary">
            <div>
                <dt>Failed login count</dt>
                <dd><?= e($failedLoginCount) ?></dd>
            </div>
            <div>
                <dt>Locked until</dt>
                <dd>
                    <?= $lockedUntil !== ''
                        ? e($lockedUntil)
                        : 'No active lock' ?>
                </dd>
            </div>
        </dl>

        <ul class="confirmation-list">
            <li>The existing password will not change.</li>
            <li>
                Roles, permissions, and activation status
                will not change.
            </li>
            <li>
                The unlock action will be recorded in the
                audit log.
            </li>
        </ul>

        <form
            method="post"
            action="<?= e(appBasePath()) ?>/administration/users/unlock"
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

            <a
                href="<?= e(appBasePath()) ?>/administration/users/view?id=<?= e(
                    $profile['user_id'] ?? ''
                ) ?>"
                class="btn btn-secondary"
            >
                Cancel
            </a>

            <button
                type="submit"
                class="btn btn-primary"
            >
                Unlock account
            </button>
        </form>
    </div>
</section>
