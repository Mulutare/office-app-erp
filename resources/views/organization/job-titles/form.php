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
$jobTitleId = (int) (
    $data['jobTitleId'] ?? 0
);
$formAction = $isEdit
    ? '/office_app/public/organization/job-titles/update'
    : '/office_app/public/organization/job-titles';
?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<div class="details-toolbar">
    <a
        href="/office_app/public/organization/job-titles"
        class="btn btn-secondary"
    >
        Back to job titles
    </a>
</div>

<form
    method="post"
    action="<?= e($formAction) ?>"
    class="card enterprise-form job-title-form"
>
    <?= csrfField() ?>
    <?php if ($isEdit): ?>
        <input
            type="hidden"
            name="job_title_id"
            value="<?= e($jobTitleId) ?>"
        >
    <?php endif; ?>

    <section class="form-section">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Identity</span>
                <h2 class="card-title">
                    Job-title information
                </h2>
                <p>
                    Use a stable code so future integrations
                    do not depend on display text.
                </p>
            </div>
        </div>

        <div class="form-grid">
            <div class="form-field">
                <label for="job-title-code">
                    Job-title code
                </label>
                <input
                    id="job-title-code"
                    name="code"
                    type="text"
                    value="<?= e($old['code'] ?? '') ?>"
                    maxlength="30"
                    placeholder="FIN-MGR"
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
                <label for="job-title-name">
                    Display name
                </label>
                <input
                    id="job-title-name"
                    name="name"
                    type="text"
                    value="<?= e($old['name'] ?? '') ?>"
                    maxlength="120"
                    placeholder="Finance Manager"
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
                <span class="eyebrow">Classification</span>
                <h2 class="card-title">
                    Family and grade reference
                </h2>
                <p>
                    Classification supports future salary,
                    reporting and workforce structures without
                    storing compensation here.
                </p>
            </div>
        </div>

        <div class="form-grid">
            <div class="form-field">
                <label for="job-family">Job family</label>
                <input
                    id="job-family"
                    name="job_family"
                    type="text"
                    value="<?= e(
                        $old['job_family'] ?? ''
                    ) ?>"
                    maxlength="100"
                    placeholder="Finance and Accounting"
                >
                <?php if (!empty(
                    $errors['job_family']
                )): ?>
                    <small class="field-error">
                        <?= e($errors['job_family']) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="grade-level">
                    Grade level
                </label>
                <input
                    id="grade-level"
                    name="grade_level"
                    type="text"
                    value="<?= e(
                        $old['grade_level'] ?? ''
                    ) ?>"
                    maxlength="40"
                    placeholder="M2"
                >
                <small class="form-help">
                    A reference only; compensation remains
                    outside this catalogue.
                </small>
                <?php if (!empty(
                    $errors['grade_level']
                )): ?>
                    <small class="field-error">
                        <?= e($errors['grade_level']) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field form-field-wide">
                <label for="job-title-description">
                    Description
                </label>
                <textarea
                    id="job-title-description"
                    name="description"
                    rows="5"
                    maxlength="500"
                    placeholder="Summarize the purpose and scope of this job title."
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
                <strong>Active job title</strong>
                <small>
                    Active titles will be available to future
                    position and employee workflows.
                </small>
            </span>
        </label>
    </section>

    <div class="form-actions">
        <a
            href="/office_app/public/organization/job-titles"
            class="btn btn-secondary"
        >
            Cancel
        </a>
        <button type="submit" class="btn btn-primary">
            <?= $isEdit
                ? 'Save job-title changes'
                : 'Create job title' ?>
        </button>
    </div>
</form>
