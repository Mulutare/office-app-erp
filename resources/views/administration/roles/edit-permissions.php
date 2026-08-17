<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$role = is_array($data['role'] ?? null)
    ? $data['role']
    : [];
$permissions = is_array(
    $data['permissions'] ?? null
)
    ? $data['permissions']
    : [];
$selectedPermissionIds = is_array(
    $data['selectedPermissionIds'] ?? null
)
    ? array_map(
        'intval',
        $data['selectedPermissionIds']
    )
    : [];
$errors = is_array($data['errors'] ?? null)
    ? $data['errors']
    : [];
$permissionGroups = [];

foreach ($permissions as $permission) {
    if (!is_array($permission)) {
        continue;
    }

    $module = (string) (
        $permission['module'] ?? 'Other'
    );
    $permissionGroups[$module][] = $permission;
}
?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<form
    method="post"
    action="<?= e(appBasePath()) ?>/administration/roles/update-permissions"
    class="card enterprise-form role-permission-form"
>
    <?= csrfField() ?>

    <input
        type="hidden"
        name="role_id"
        value="<?= e($role['role_id'] ?? '') ?>"
    >

    <section class="form-section">
        <div class="section-heading">
            <div>
                <h2 class="card-title">
                    <?= e($role['name'] ?? '') ?>
                </h2>
                <code><?= e($role['code'] ?? '') ?></code>
            </div>

            <span
                class="count-pill"
                aria-label="<?= e(
                    count($selectedPermissionIds)
                    . ' permissions selected'
                ) ?>"
                title="<?= e(
                    count($selectedPermissionIds)
                    . ' permissions selected'
                ) ?>"
            >
                <?= e(count($selectedPermissionIds)) ?>
            </span>
        </div>

        <p class="form-help">
            Select only the capabilities required by this
            role. View access and management access are
            deliberately separate.
        </p>

        <?php if (!empty(
            $errors['permissions']
        )): ?>
            <div class="field-error">
                <?= e($errors['permissions']) ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="permission-editor">
        <?php foreach (
            $permissionGroups as $module => $items
        ): ?>
            <fieldset class="permission-editor-group">
                <legend><?= e(ucwords($module)) ?></legend>

                <?php foreach ($items as $permission): ?>
                    <?php
                    $permissionId = (int) (
                        $permission['permission_id'] ?? 0
                    );
                    ?>

                    <label class="permission-option">
                        <input
                            type="checkbox"
                            name="permission_ids[]"
                            value="<?= e($permissionId) ?>"
                            <?= in_array(
                                $permissionId,
                                $selectedPermissionIds,
                                true
                            )
                                ? 'checked'
                                : '' ?>
                        >

                        <span>
                            <strong>
                                <?= e(
                                    $permission['name'] ?? ''
                                ) ?>
                            </strong>
                            <code>
                                <?= e(
                                    $permission['code'] ?? ''
                                ) ?>
                            </code>
                            <small>
                                <?= e(
                                    $permission[
                                        'description'
                                    ] ?? ''
                                ) ?>
                            </small>
                        </span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>
    </div>

    <div class="form-actions">
        <a
            href="<?= e(appBasePath()) ?>/administration/roles/view?id=<?= e(
                $role['role_id'] ?? ''
            ) ?>"
            class="btn btn-secondary"
        >
            Cancel
        </a>

        <button type="submit" class="btn btn-primary">
            Save permissions
        </button>
    </div>
</form>
