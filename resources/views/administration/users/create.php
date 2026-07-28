<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null)
    ? $data
    : [];

$roles = is_array($data['roles'] ?? null)
    ? $data['roles']
    : [];

$managers = is_array($data['managers'] ?? null)
    ? $data['managers']
    : [];

$company = is_array($data['company'] ?? null)
    ? $data['company']
    : [];

$errors = is_array($data['errors'] ?? null)
    ? $data['errors']
    : [];

$old = is_array($data['old'] ?? null)
    ? $data['old']
    : [];

$selectedRoles = is_array(
    $old['role_ids'] ?? null
)
    ? array_map(
        'intval',
        $old['role_ids']
    )
    : [];
?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<form
    method="post"
    action="/office_app/public/administration/users"
    class="card enterprise-form"
>
    <?= csrfField() ?>

    <section class="form-section">
        <h2 class="card-title">
            Account information
        </h2>

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
                        $old['display_name']
                        ?? ''
                    ) ?>"
                    maxlength="120"
                    required
                    autofocus
                >

                <?php if (
                    !empty(
                        $errors['display_name']
                    )
                ): ?>
                    <small class="field-error">
                        <?= e(
                            $errors['display_name']
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="username">
                    Username
                </label>

                <input
                    id="username"
                    name="username"
                    type="text"
                    value="<?= e(
                        $old['username']
                        ?? ''
                    ) ?>"
                    maxlength="50"
                    autocomplete="off"
                    required
                >

                <?php if (
                    !empty($errors['username'])
                ): ?>
                    <small class="field-error">
                        <?= e(
                            $errors['username']
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field form-field-wide">
                <label for="email">
                    Email address
                </label>

                <input
                    id="email"
                    name="email"
                    type="email"
                    value="<?= e(
                        $old['email'] ?? ''
                    ) ?>"
                    maxlength="190"
                    autocomplete="off"
                    required
                >

                <?php if (
                    !empty($errors['email'])
                ): ?>
                    <small class="field-error">
                        <?= e($errors['email']) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field form-field-wide">
                <label for="manager_user_id">
                    Reporting manager
                </label>

                <select
                    id="manager_user_id"
                    name="manager_user_id"
                    required
                >
                    <option value="">
                        Select the employee's manager
                    </option>
                    <?php foreach (
                        $managers as $manager
                    ): ?>
                        <?php
                        $managerUserId = (int) (
                            $manager['user_id'] ?? 0
                        );
                        ?>
                        <option
                            value="<?= e($managerUserId) ?>"
                            <?= (int) (
                                $old['manager_user_id']
                                ?? 0
                            ) === $managerUserId
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e(
                                $manager['display_name']
                                ?? $manager['username']
                                ?? ''
                            ) ?>
                            ·
                            <?= e(
                                $manager['email'] ?? ''
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <small class="form-help">
                    Required for approvals and reporting
                    inside
                    <?= e(
                        $company['name']
                        ?? 'this company'
                    ) ?>.
                </small>

                <?php if (
                    !empty(
                        $errors['manager_user_id']
                    )
                ): ?>
                    <small class="field-error">
                        <?= e(
                            $errors['manager_user_id']
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="form-section">
        <h2 class="card-title">
            Access roles
        </h2>

        <p class="form-help">
            Assign only the roles required for this
            employee’s responsibilities.
        </p>

        <?php if (
            !empty($errors['roles'])
        ): ?>
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
                ?>

                <label class="role-option">
                    <input
                        type="checkbox"
                        name="role_ids[]"
                        value="<?= e($roleId) ?>"
                        <?= in_array(
                            $roleId,
                            $selectedRoles,
                            true
                        )
                            ? 'checked'
                            : '' ?>
                    >

                    <span>
                        <strong>
                            <?= e(
                                $role['name'] ?? ''
                            ) ?>
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
                <?= !array_key_exists(
                    'active',
                    $old
                ) || !empty($old['active'])
                    ? 'checked'
                    : '' ?>
            >

            <span>
                <strong>Activate account</strong>
                <small>
                    Inactive accounts cannot sign in.
                </small>
            </span>
        </label>
    </section>

    <div class="form-actions">
        <a
            href="/office_app/public/administration/users"
            class="btn btn-secondary"
        >
            Cancel
        </a>

        <button
            type="submit"
            class="btn btn-primary"
        >
            Create user
        </button>
    </div>
</form>
