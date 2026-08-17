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
?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<section class="card confirmation-card">
    <div class="confirmation-icon" aria-hidden="true">
        !
    </div>

    <div>
        <h2 class="card-title">
            Reset password for
            <?= e($profile['display_name'] ?? '') ?>?
        </h2>

        <p>
            A secure temporary password will replace the
            current password for
            <strong>
                @<?= e($profile['username'] ?? '') ?>
            </strong>.
        </p>

        <ul class="confirmation-list">
            <li>
                The existing password will stop working
                immediately.
            </li>
            <li>
                Failed-login counters and any temporary
                account lock will be cleared.
            </li>
            <li>
                The user must choose a new password at
                the next sign-in.
            </li>
            <li>
                The temporary password will be displayed
                only once.
            </li>
        </ul>

        <form
            method="post"
            action="<?= e(appBasePath()) ?>/administration/users/reset-password"
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
                class="btn btn-danger"
            >
                Generate temporary password
            </button>
        </form>
    </div>
</section>
