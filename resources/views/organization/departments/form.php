<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$errors = is_array($data['errors'] ?? null)
    ? $data['errors']
    : [];
$old = is_array($data['old'] ?? null)
    ? $data['old']
    : [];
$parentOptions = is_array(
    $data['parentOptions'] ?? null
)
    ? $data['parentOptions']
    : [];
$isEdit = ($data['formMode'] ?? 'create')
    === 'edit';
$departmentId = (int) (
    $data['departmentId'] ?? 0
);
$selectedParentId = (int) (
    $old['parent_department_id'] ?? 0
);
$formAction = $isEdit
    ? '/office_app/public/organization/departments/update'
    : '/office_app/public/organization/departments';
?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<div class="details-toolbar">
    <a
        href="/office_app/public/organization/departments"
        class="btn btn-secondary"
    >
        Back to departments
    </a>
</div>

<form
    method="post"
    action="<?= e($formAction) ?>"
    class="card enterprise-form department-catalogue-form"
>
    <?= csrfField() ?>
    <?php if ($isEdit): ?>
        <input
            type="hidden"
            name="department_id"
            value="<?= e($departmentId) ?>"
        >
    <?php endif; ?>

    <section class="form-section">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Identity</span>
                <h2 class="card-title">
                    Department information
                </h2>
                <p>
                    Stable codes support reporting,
                    integrations and future module assignments.
                </p>
            </div>
        </div>

        <div class="form-grid">
            <div class="form-field">
                <label for="department-code">
                    Department code
                </label>
                <input
                    id="department-code"
                    name="code"
                    type="text"
                    value="<?= e($old['code'] ?? '') ?>"
                    maxlength="30"
                    placeholder="FIN"
                    required
                    autofocus
                >
                <?php if (!empty($errors['code'])): ?>
                    <small class="field-error">
                        <?= e($errors['code']) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="department-name">
                    Department name
                </label>
                <input
                    id="department-name"
                    name="name"
                    type="text"
                    value="<?= e($old['name'] ?? '') ?>"
                    maxlength="100"
                    placeholder="Finance"
                    required
                >
                <?php if (!empty($errors['name'])): ?>
                    <small class="field-error">
                        <?= e($errors['name']) ?>
                    </small>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="form-section">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Hierarchy</span>
                <h2 class="card-title">
                    Reporting structure
                </h2>
                <p>
                    Leave the parent empty for a top-level
                    department.
                </p>
            </div>
        </div>

        <div class="form-field">
            <label for="parent-department">
                Parent department
            </label>
            <select
                id="parent-department"
                name="parent_department_id"
            >
                <option value="">No parent — top level</option>
                <?php foreach (
                    $parentOptions as $parent
                ): ?>
                    <?php
                    $parentId = (int) (
                        $parent['department_id'] ?? 0
                    );
                    ?>
                    <option
                        value="<?= e($parentId) ?>"
                        <?= $selectedParentId === $parentId
                            ? 'selected'
                            : '' ?>
                    >
                        <?= e(sprintf(
                            '%s — %s',
                            (string) (
                                $parent['code'] ?? ''
                            ),
                            (string) (
                                $parent['name'] ?? ''
                            )
                        )) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="form-help">
                The system prevents self-reference,
                cross-company parents and hierarchy cycles.
            </small>
            <?php if (!empty(
                $errors['parent_department_id']
            )): ?>
                <small class="field-error">
                    <?= e(
                        $errors['parent_department_id']
                    ) ?>
                </small>
            <?php endif; ?>
        </div>
    </section>

    <section class="form-section">
        <div class="form-field">
            <label for="department-description">
                Description
            </label>
            <textarea
                id="department-description"
                name="description"
                rows="5"
                maxlength="500"
                placeholder="Summarize the department's responsibilities and operating scope."
            ><?= e(
                $old['description'] ?? ''
            ) ?></textarea>
            <?php if (!empty(
                $errors['description']
            )): ?>
                <small class="field-error">
                    <?= e($errors['description']) ?>
                </small>
            <?php endif; ?>
        </div>
    </section>

    <section class="form-section">
        <label class="checkbox-option">
            <input
                type="checkbox"
                name="active"
                value="1"
                <?= !array_key_exists('active', $old)
                    || !empty($old['active'])
                        ? 'checked'
                        : '' ?>
            >
            <span>
                <strong>Active department</strong>
                <small>
                    Active departments are available to
                    employee and future ERP workflows.
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
            href="/office_app/public/organization/departments"
            class="btn btn-secondary"
        >
            Cancel
        </a>
        <button type="submit" class="btn btn-primary">
            <?= $isEdit
                ? 'Save department changes'
                : 'Create department' ?>
        </button>
    </div>
</form>
