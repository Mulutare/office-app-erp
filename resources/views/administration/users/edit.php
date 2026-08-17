<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$profile = is_array($data['profile'] ?? null)
    ? $data['profile']
    : [];
$roles = is_array($data['roles'] ?? null)
    ? $data['roles']
    : [];
$managers = is_array(
    $data['managers'] ?? null
)
    ? $data['managers']
    : [];
$errors = is_array($data['errors'] ?? null)
    ? $data['errors']
    : [];
$selectedRoles = is_array(
    $profile['role_ids'] ?? null
)
    ? array_map('intval', $profile['role_ids'])
    : [];
$isSelf = !empty($data['isSelf']);
$isAccessProtected = !empty(
    $data['isAccessProtected']
);
$managerRequired = !empty(
    $data['managerRequired']
);
$managerUserId = (int) (
    $profile['manager_user_id'] ?? 0
);
?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<form
    method="post"
    action="<?= e(appBasePath()) ?>/administration/users/update"
    class="card enterprise-form"
>
    <?= csrfField() ?>

    <input
        type="hidden"
        name="user_id"
        value="<?= e($profile['user_id'] ?? '') ?>"
    >

    <section class="form-section">
        <h2 class="card-title">Account information</h2>

        <div class="form-grid">
            <div class="form-field">
                <label for="display_name">
                    Display name
                </label>
                <input
                    id="display_name"
                    name="display_name"
                    type="text"
                    value="<?= e(
                        $profile['display_name'] ?? ''
                    ) ?>"
                    maxlength="120"
                    required
                    autofocus
                >
                <?php if (!empty(
                    $errors['display_name']
                )): ?>
                    <small class="field-error">
                        <?= e(
                            $errors['display_name']
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="username">Username</label>
                <input
                    id="username"
                    name="username"
                    type="text"
                    value="<?= e(
                        $profile['username'] ?? ''
                    ) ?>"
                    maxlength="50"
                    required
                >
                <?php if (!empty(
                    $errors['username']
                )): ?>
                    <small class="field-error">
                        <?= e($errors['username']) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field form-field-wide">
                <label for="email">Email address</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    value="<?= e(
                        $profile['email'] ?? ''
                    ) ?>"
                    maxlength="190"
                    required
                >
                <?php if (!empty(
                    $errors['email']
                )): ?>
                    <small class="field-error">
                        <?= e($errors['email']) ?>
                    </small>
                <?php endif; ?>
            </div>

            <?php if (empty(
                $profile['is_platform_admin']
            )): ?>
                <div class="form-field form-field-wide">
                    <label for="manager_user_id">
                        Reporting manager
                    </label>

                    <select
                        id="manager_user_id"
                        name="manager_user_id"
                        <?= $managerRequired
                            ? 'required'
                            : '' ?>
                        <?= $isSelf
                            ? 'disabled'
                            : '' ?>
                    >
                        <option value="">
                            <?= $managerRequired
                                ? 'Select the employee manager'
                                : 'No reporting manager' ?>
                        </option>

                        <?php foreach (
                            $managers as $manager
                        ): ?>
                            <?php
                            $optionUserId = (int) (
                                $manager['user_id']
                                ?? 0
                            );
                            ?>
                            <option
                                value="<?= e(
                                    $optionUserId
                                ) ?>"
                                <?= $managerUserId
                                    === $optionUserId
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e(
                                    $manager[
                                        'display_name'
                                    ]
                                    ?? $manager[
                                        'username'
                                    ]
                                    ?? ''
                                ) ?>
                                — <?= e(
                                    $manager['email']
                                    ?? ''
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <?php if ($isSelf): ?>
                        <input
                            type="hidden"
                            name="manager_user_id"
                            value="<?= e(
                                $managerUserId > 0
                                    ? $managerUserId
                                    : ''
                            ) ?>"
                        >
                        <small class="form-help">
                            Your reporting manager is maintained
                            by another company administrator.
                        </small>
                    <?php endif; ?>

                    <?php if (!empty(
                        $errors['manager_user_id']
                    )): ?>
                        <small class="field-error">
                            <?= e(
                                $errors[
                                    'manager_user_id'
                                ]
                            ) ?>
                        </small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="form-section">
        <h2 class="card-title">Access roles</h2>

        <p class="form-help">
            Assign only the roles required for this
            employee's responsibilities.
        </p>

        <?php if ($isSelf): ?>
            <div class="alert alert-information">
                Your own role assignments are protected
                from self-modification.
            </div>
        <?php elseif (
            !empty($profile['is_platform_admin'])
        ): ?>
            <div class="alert alert-information">
                Platform administrator role assignments
                are protected from company-level changes.
            </div>
        <?php endif; ?>

        <?php if (!empty($errors['roles'])): ?>
            <div class="field-error">
                <?= e($errors['roles']) ?>
            </div>
        <?php endif; ?>

        <div class="role-grid">
            <?php foreach ($roles as $role): ?>
                <?php
                $roleId = (int) (
                    $role['role_id'] ?? 0
                );
                $selected = in_array(
                    $roleId,
                    $selectedRoles,
                    true
                );
                ?>

                <label class="role-option">
                    <input
                        type="checkbox"
                        name="role_ids[]"
                        value="<?= e($roleId) ?>"
                        <?= $selected ? 'checked' : '' ?>
                        <?= $isAccessProtected
                            ? 'disabled'
                            : '' ?>
                    >

                    <?php if (
                        $isAccessProtected
                        && $selected
                    ): ?>
                        <input
                            type="hidden"
                            name="role_ids[]"
                            value="<?= e($roleId) ?>"
                        >
                    <?php endif; ?>

                    <span>
                        <strong>
                            <?= e($role['name'] ?? '') ?>
                        </strong>
                        <small>
                            <?= e(
                                $role['description']
                                ?? $role['code']
                                ?? ''
                            ) ?>
                        </small>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="form-section">
        <label class="checkbox-option">
            <input
                type="checkbox"
                name="active"
                value="1"
                <?= !empty($profile['active'])
                    ? 'checked'
                    : '' ?>
                <?= $isAccessProtected
                    ? 'disabled'
                    : '' ?>
            >

            <?php if (
                $isAccessProtected
                && !empty($profile['active'])
            ): ?>
                <input
                    type="hidden"
                    name="active"
                    value="1"
                >
            <?php endif; ?>

            <span>
                <strong>Active account</strong>
                <small>
                    Inactive accounts cannot sign in.
                    Protected access changes use the
                    dedicated account-status workflow.
                </small>
            </span>
        </label>

        <?php if (!empty($errors['active'])): ?>
            <small class="field-error">
                <?= e($errors['active']) ?>
            </small>
        <?php endif; ?>
    </section>

    <div class="form-actions">
        <a
            href="<?= e(appBasePath()) ?>/administration/users/view?id=<?= e(
                $profile['user_id'] ?? ''
            ) ?>"
            class="btn btn-secondary"
        >
            Cancel
        </a>

        <button type="submit" class="btn btn-primary">
            Save changes
        </button>
    </div>
</form>
