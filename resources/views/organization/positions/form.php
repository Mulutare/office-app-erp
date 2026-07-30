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
$branches = is_array($data['branches'] ?? null)
    ? $data['branches']
    : [];
$departments = is_array(
    $data['departments'] ?? null
)
    ? $data['departments']
    : [];
$jobTitles = is_array($data['jobTitles'] ?? null)
    ? $data['jobTitles']
    : [];
$statuses = is_array($data['statuses'] ?? null)
    ? $data['statuses']
    : [];
$isEdit = ($data['formMode'] ?? 'create')
    === 'edit';
$positionId = (int) (
    $data['positionId'] ?? 0
);
$formAction = $isEdit
    ? '/office_app/public/organization/positions/update'
    : '/office_app/public/organization/positions';
$selectedBranch = (string) (
    $old['branch_id'] ?? ''
);
$selectedDepartment = (string) (
    $old['department_id'] ?? ''
);
$selectedJobTitle = (string) (
    $old['job_title_id'] ?? ''
);
$selectedStatus = (string) (
    $old['status'] ?? 'planned'
);
$assignEmployeeId = (int) (
    $data['assignEmployeeId'] ?? 0
);
$missingPrerequisites = [];

if (!$isEdit && $departments === []) {
    $missingPrerequisites['departments'] =
        'Create an active department';
}

if (!$isEdit && $jobTitles === []) {
    $missingPrerequisites['job_titles'] =
        'Create an active job title';
}
?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<?php if ($assignEmployeeId > 0): ?>
    <div class="alert alert-information" role="status">
        <strong>Employee assignment workflow</strong>
        This position will default to
        <strong>Open</strong>. After creation, you will
        return to the employee and complete the assignment.
    </div>
<?php endif; ?>

<?php if ($missingPrerequisites !== []): ?>
    <section class="card prerequisite-panel">
        <div>
            <span class="eyebrow">
                Setup required
            </span>
            <h2>Complete organization prerequisites</h2>
            <p>
                A position must reference controlled company
                records. Complete the missing catalogues,
                then return here.
            </p>
        </div>
        <div class="prerequisite-grid">
            <?php if (isset(
                $missingPrerequisites['departments']
            )): ?>
                <a
                    href="/office_app/public/organization/departments/create"
                    class="prerequisite-item"
                >
                    <span>1</span>
                    <strong>Create department</strong>
                    <small>
                        Define the organization unit.
                    </small>
                </a>
            <?php endif; ?>

            <?php if (isset(
                $missingPrerequisites['job_titles']
            )): ?>
                <a
                    href="/office_app/public/organization/job-titles/create"
                    class="prerequisite-item"
                >
                    <span>2</span>
                    <strong>Create job title</strong>
                    <small>
                        Define the job architecture record.
                    </small>
                </a>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<div class="details-toolbar">
    <a
        href="/office_app/public/organization/positions"
        class="btn btn-secondary"
    >
        Back to positions
    </a>
</div>

<form
    method="post"
    action="<?= e($formAction) ?>"
    class="card enterprise-form position-form"
>
    <?= csrfField() ?>
    <?php if (
        !$isEdit
        && $assignEmployeeId > 0
    ): ?>
        <input
            type="hidden"
            name="assign_employee_id"
            value="<?= e($assignEmployeeId) ?>"
        >
    <?php endif; ?>
    <?php if ($isEdit): ?>
        <input
            type="hidden"
            name="position_id"
            value="<?= e($positionId) ?>"
        >
    <?php endif; ?>

    <section class="form-section">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Identity</span>
                <h2 class="card-title">
                    Position definition
                </h2>
                <p>
                    A position is approved workforce capacity,
                    not a person or recruitment application.
                </p>
            </div>
        </div>

        <div class="form-grid">
            <div class="form-field">
                <label for="position-code">
                    Position code
                </label>
                <input
                    id="position-code"
                    name="code"
                    type="text"
                    value="<?= e($old['code'] ?? '') ?>"
                    maxlength="40"
                    placeholder="FIN-ANL-NBO"
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
                <label for="position-name">
                    Position name
                </label>
                <input
                    id="position-name"
                    name="name"
                    type="text"
                    value="<?= e($old['name'] ?? '') ?>"
                    maxlength="140"
                    placeholder="Finance Analyst - Nairobi"
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
                <span class="eyebrow">Organization</span>
                <h2 class="card-title">
                    Placement and job architecture
                </h2>
                <p>
                    Every selection is restricted to active
                    records in the current company.
                </p>
            </div>
        </div>

        <div class="form-grid position-organization-grid">
            <div class="form-field">
                <label for="position-department">
                    Department
                </label>
                <select
                    id="position-department"
                    name="department_id"
                    required
                >
                    <option value="">
                        Select a department
                    </option>
                    <?php foreach (
                        $departments as $department
                    ): ?>
                        <?php
                        $departmentId = (string) (
                            $department[
                                'department_id'
                            ] ?? ''
                        );
                        ?>
                        <option
                            value="<?= e($departmentId) ?>"
                            <?= $selectedDepartment
                                === $departmentId
                                    ? 'selected'
                                    : '' ?>
                        >
                            <?= e(
                                $department['name'] ?? ''
                            ) ?>
                            (<?= e(
                                $department['code'] ?? ''
                            ) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty(
                    $errors['department_id']
                )): ?>
                    <small class="field-error">
                        <?= e(
                            $errors['department_id']
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="position-job-title">
                    Job title
                </label>
                <select
                    id="position-job-title"
                    name="job_title_id"
                    required
                >
                    <option value="">
                        Select a job title
                    </option>
                    <?php foreach (
                        $jobTitles as $jobTitle
                    ): ?>
                        <?php
                        $jobTitleId = (string) (
                            $jobTitle['job_title_id']
                            ?? ''
                        );
                        ?>
                        <option
                            value="<?= e($jobTitleId) ?>"
                            <?= $selectedJobTitle
                                === $jobTitleId
                                    ? 'selected'
                                    : '' ?>
                        >
                            <?= e(
                                $jobTitle['name'] ?? ''
                            ) ?>
                            <?php if (!empty(
                                $jobTitle['grade_level']
                            )): ?>
                                - <?= e(
                                    $jobTitle['grade_level']
                                ) ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty(
                    $errors['job_title_id']
                )): ?>
                    <small class="field-error">
                        <?= e(
                            $errors['job_title_id']
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field form-field-wide">
                <label for="position-branch">
                    Branch or location
                </label>
                <select
                    id="position-branch"
                    name="branch_id"
                >
                    <option value="">
                        Company-wide / location flexible
                    </option>
                    <?php foreach (
                        $branches as $branch
                    ): ?>
                        <?php
                        $branchId = (string) (
                            $branch['branch_id'] ?? ''
                        );
                        ?>
                        <option
                            value="<?= e($branchId) ?>"
                            <?= $selectedBranch === $branchId
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($branch['name'] ?? '') ?>
                            (<?= e($branch['code'] ?? '') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="form-help">
                    Leave blank when the position is not tied
                    to a single company location.
                </small>
                <?php if (!empty(
                    $errors['branch_id']
                )): ?>
                    <small class="field-error">
                        <?= e($errors['branch_id']) ?>
                    </small>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="form-section">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Planning</span>
                <h2 class="card-title">
                    Capacity and lifecycle
                </h2>
                <p>
                    Headcount records approved capacity.
                    Occupancy will be handled by employee
                    assignment in a later phase.
                </p>
            </div>
        </div>

        <div class="form-grid">
            <div class="form-field">
                <label for="approved-headcount">
                    Approved headcount
                </label>
                <input
                    id="approved-headcount"
                    name="approved_headcount"
                    type="number"
                    value="<?= e(
                        $old['approved_headcount'] ?? 1
                    ) ?>"
                    min="1"
                    max="10000"
                    step="1"
                    required
                >
                <?php if (!empty(
                    $errors['approved_headcount']
                )): ?>
                    <small class="field-error">
                        <?= e(
                            $errors[
                                'approved_headcount'
                            ]
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="position-status">
                    Position status
                </label>
                <select
                    id="position-status"
                    name="status"
                    required
                >
                    <?php foreach (
                        $statuses as $value => $label
                    ): ?>
                        <option
                            value="<?= e($value) ?>"
                            <?= $selectedStatus === $value
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty(
                    $errors['status']
                )): ?>
                    <small class="field-error">
                        <?= e($errors['status']) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field form-field-wide">
                <label for="position-description">
                    Planning notes
                </label>
                <textarea
                    id="position-description"
                    name="description"
                    rows="5"
                    maxlength="500"
                    placeholder="Describe the approved purpose, scope or planning assumptions."
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

    <div class="form-actions">
        <a
            href="/office_app/public/organization/positions"
            class="btn btn-secondary"
        >
            Cancel
        </a>
        <button
            type="submit"
            class="btn btn-primary"
            <?= $missingPrerequisites === []
                ? ''
                : 'disabled' ?>
        >
            <?= $isEdit
                ? 'Save position changes'
                : (
                    $missingPrerequisites === []
                        ? 'Create position'
                        : 'Complete setup first'
                ) ?>
        </button>
    </div>
</form>
