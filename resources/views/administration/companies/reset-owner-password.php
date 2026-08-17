<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null)
    ? $data
    : [];
$company = is_array(
    $data['company'] ?? null
)
    ? $data['company']
    : [];
$owner = is_array($data['owner'] ?? null)
    ? $data['owner']
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
        <span class="module-eyebrow">
            Vendor recovery control
        </span>
        <h2 class="card-title">
            Reset the owner password for
            <?= e($company['name'] ?? '') ?>?
        </h2>

        <p>
            A secure temporary password will replace the
            current password for primary owner
            <strong>
                <?= e($owner['display_name'] ?? '') ?>
                (@<?= e($owner['username'] ?? '') ?>)
            </strong>.
        </p>

        <dl class="unlock-summary company-owner-recovery-summary">
            <div>
                <dt>Company</dt>
                <dd>
                    <?= e($company['name'] ?? '') ?>
                    <small>
                        <?= e($company['code'] ?? '') ?>
                    </small>
                </dd>
            </div>
            <div>
                <dt>Owner email</dt>
                <dd>
                    <?= e($owner['email'] ?? '') ?>
                </dd>
            </div>
            <div>
                <dt>Last login</dt>
                <dd>
                    <?= e(
                        $owner['last_login_at']
                        ?? 'Never'
                    ) ?>
                </dd>
            </div>
            <div>
                <dt>Account state</dt>
                <dd>
                    <?= !empty($owner['active'])
                        ? 'Active'
                        : 'Inactive' ?>
                </dd>
            </div>
        </dl>

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
                The owner must choose a new password at
                the next successful sign-in.
            </li>
            <li>
                Company approval, suspension and
                subscription controls remain unchanged.
            </li>
            <li>
                The temporary password is displayed once
                and is never written to the audit log.
            </li>
        </ul>

        <form
            method="post"
            action="<?= e(appBasePath()) ?>/administration/companies/reset-owner-password"
            class="confirmation-actions"
        >
            <?= csrfField() ?>

            <input
                type="hidden"
                name="company_id"
                value="<?= e(
                    $company['company_id'] ?? 0
                ) ?>"
            >

            <a
                href="<?= e(appBasePath()) ?>/administration/companies/view?id=<?= e(
                    $company['company_id'] ?? 0
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
