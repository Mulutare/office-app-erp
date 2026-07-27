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
$isEdit = ($data['formMode'] ?? 'create')
    === 'edit';
$departmentId = (int) (
    $data['departmentId'] ?? 0
);
$formAction = $isEdit
    ? '/office_app/public/hr/departments/update'
    : '/office_app/public/hr/departments';
$cancelUrl = $isEdit
    ? '/office_app/public/hr/departments'
    : '/office_app/public/hr';
?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<form
    method="post"
    action="<?= e($formAction) ?>"
    class="card enterprise-form hr-record-form"
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
                <h2 class="card-title">
                    Department information
                </h2>
                <p>
                    Use a stable code that can be referenced
                    in reports and integrations.
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
                    placeholder="HR"
                    autocomplete="off"
                    required
                    autofocus
                >
                <small class="form-help">
                    Letters, numbers, hyphens and
                    underscores only.
                </small>
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
                    placeholder="Human Resources"
                    required
                >
                <?php if (!empty($errors['name'])): ?>
                    <small class="field-error">
                        <?= e($errors['name']) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field form-field-wide">
                <label for="department-description">
                    Description
                </label>
                <textarea
                    id="department-description"
                    name="description"
                    rows="4"
                    maxlength="255"
                    placeholder="Briefly describe this department's responsibilities."
                ><?= e(
                    $old['description'] ?? ''
                ) ?></textarea>
                <?php if (!empty(
                    $errors['description']
                )): ?>
                    <small class="field-error">
                        <?= e(
                            $errors['description']
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>
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
                    Active departments are available when
                    assigning employees.
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
            href="<?= e($cancelUrl) ?>"
            class="btn btn-secondary"
        >
            Cancel
        </a>
        <button
            type="submit"
            class="btn btn-primary"
        >
            <?= $isEdit
                ? 'Save department changes'
                : 'Create department' ?>
        </button>
    </div>
</form>
