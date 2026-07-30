<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$mode = (string) ($data['formMode'] ?? 'create');
$isEdit = $mode === 'edit';
$leaveTypeId = (int) (
    $data['leaveTypeId'] ?? 0
);
$old = is_array($data['old'] ?? null)
    ? $data['old']
    : [];
$errors = is_array($data['errors'] ?? null)
    ? $data['errors']
    : [];
$workflowOptions = is_array(
    $data['workflowOptions'] ?? null
)
    ? $data['workflowOptions']
    : [];
$hrApprovers = is_array(
    $data['hrApprovers'] ?? null
)
    ? $data['hrApprovers']
    : [];
$selectedWorkflow = (string) (
    $old['approval_workflow'] ?? 'manager'
);
$selectedHrApproverId = (int) (
    $old['hr_approver_user_id'] ?? 0
);
$action = $isEdit
    ? '/office_app/public/hr/leave/policies/update'
    : '/office_app/public/hr/leave/policies';
?>

<nav class="workspace-breadcrumb" aria-label="Breadcrumb">
    <a href="/office_app/public/hr">HR</a>
    <span aria-hidden="true">/</span>
    <a href="/office_app/public/hr/leave">Leave</a>
    <span aria-hidden="true">/</span>
    <a href="/office_app/public/hr/leave/policies">
        Policies
    </a>
    <span aria-hidden="true">/</span>
    <strong><?= $isEdit ? 'Edit' : 'Create' ?></strong>
</nav>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<section class="policy-form-layout">
    <form
        method="post"
        action="<?= e($action) ?>"
        class="card enterprise-form policy-form"
    >
        <?= csrfField() ?>

        <?php if ($isEdit): ?>
            <input
                type="hidden"
                name="leave_type_id"
                value="<?= e($leaveTypeId) ?>"
            >
        <?php endif; ?>

        <section class="form-section">
            <span class="section-kicker">Policy identity</span>
            <h2 class="card-title">
                <?= $isEdit
                    ? 'Update leave policy'
                    : 'Create a leave policy' ?>
            </h2>
            <p class="form-help">
                Use a short stable code for reports and
                integrations, and a clear name for employees.
            </p>

            <div class="form-grid">
                <div class="form-field">
                    <label for="policy-code">
                        Policy code
                    </label>
                    <input
                        id="policy-code"
                        name="code"
                        type="text"
                        value="<?= e($old['code'] ?? '') ?>"
                        maxlength="30"
                        placeholder="ANNUAL"
                        autocomplete="off"
                        required
                    >
                    <?php if (!empty($errors['code'])): ?>
                        <small class="field-error">
                            <?= e($errors['code']) ?>
                        </small>
                    <?php else: ?>
                        <small class="field-hint">
                            Uppercase letters, numbers, hyphens
                            and underscores.
                        </small>
                    <?php endif; ?>
                </div>

                <div class="form-field">
                    <label for="policy-name">
                        Employee-facing name
                    </label>
                    <input
                        id="policy-name"
                        name="name"
                        type="text"
                        value="<?= e($old['name'] ?? '') ?>"
                        maxlength="100"
                        placeholder="Annual Leave"
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
            <span class="section-kicker">Entitlement</span>
            <h2 class="card-title">Annual allowance</h2>

            <div class="policy-entitlement-field">
                <div class="form-field">
                    <label for="policy-entitlement">
                        Days per year
                    </label>
                    <input
                        id="policy-entitlement"
                        name="annual_entitlement"
                        type="number"
                        value="<?= e(
                            $old['annual_entitlement']
                                ?? '0.00'
                        ) ?>"
                        min="0"
                        max="366"
                        step="0.01"
                        inputmode="decimal"
                        required
                    >
                    <?php if (!empty(
                        $errors['annual_entitlement']
                    )): ?>
                        <small class="field-error">
                            <?= e(
                                $errors[
                                    'annual_entitlement'
                                ]
                            ) ?>
                        </small>
                    <?php else: ?>
                        <small class="field-hint">
                            Use 0 for unpaid or discretionary
                            leave without a fixed allowance.
                        </small>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="form-section">
            <span class="section-kicker">Workflow controls</span>
            <h2 class="card-title">
                Availability and approval
            </h2>

            <div
                class="policy-control-grid"
                data-leave-workflow-form
            >
                <div class="form-field">
                    <label for="policy-workflow">
                        Approval workflow
                    </label>
                    <select
                        id="policy-workflow"
                        name="approval_workflow"
                        data-leave-workflow-select
                        required
                    >
                        <?php foreach (
                            $workflowOptions
                            as $value => $label
                        ): ?>
                            <option
                                value="<?= e($value) ?>"
                                <?= $selectedWorkflow
                                    === $value
                                        ? 'selected'
                                        : '' ?>
                            >
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty(
                        $errors['approval_workflow']
                    )): ?>
                        <small class="field-error">
                            <?= e(
                                $errors[
                                    'approval_workflow'
                                ]
                            ) ?>
                        </small>
                    <?php else: ?>
                        <small class="field-hint">
                            Choose automatic, manager, HR, or
                            sequential manager and HR approval.
                        </small>
                    <?php endif; ?>
                </div>

                <div
                    class="form-field"
                    data-leave-hr-approver-field
                >
                    <label for="policy-hr-approver">
                        HR approver
                    </label>
                    <select
                        id="policy-hr-approver"
                        name="hr_approver_user_id"
                        data-leave-hr-approver-select
                    >
                        <option value="">
                            Select HR approver
                        </option>
                        <?php foreach (
                            $hrApprovers as $approver
                        ): ?>
                            <?php
                            $approverId = (int) (
                                $approver['user_id'] ?? 0
                            );
                            ?>
                            <option
                                value="<?= e($approverId) ?>"
                                <?= $selectedHrApproverId
                                    === $approverId
                                        ? 'selected'
                                        : '' ?>
                            >
                                <?= e(
                                    $approver['display_name']
                                    ?? ''
                                ) ?>
                                ·
                                <?= e(
                                    $approver['email'] ?? ''
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty(
                        $errors['hr_approver_user_id']
                    )): ?>
                        <small class="field-error">
                            <?= e(
                                $errors[
                                    'hr_approver_user_id'
                                ]
                            ) ?>
                        </small>
                    <?php else: ?>
                        <small class="field-hint">
                            Required for workflows that include
                            HR approval.
                        </small>
                    <?php endif; ?>
                </div>

                <label class="role-option">
                    <input
                        type="checkbox"
                        name="active"
                        value="1"
                        <?= !empty($old['active'])
                            ? 'checked'
                            : '' ?>
                    >
                    <span>
                        <strong>Available to employees</strong>
                        <small>
                            Active policies appear in the new
                            leave-request form.
                        </small>
                    </span>
                </label>
            </div>

            <?php if (!empty($errors['active'])): ?>
                <div class="field-error policy-control-error">
                    <?= e($errors['active']) ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="form-actions">
            <a
                href="/office_app/public/hr/leave/policies"
                class="btn btn-secondary"
            >
                Cancel
            </a>
            <button type="submit" class="btn btn-primary">
                <?= $isEdit
                    ? 'Save policy changes'
                    : 'Create leave policy' ?>
            </button>
        </div>
    </form>

    <aside class="card policy-form-aside">
        <span class="section-kicker">Operational impact</span>
        <h3>What this policy controls</h3>
        <ul>
            <li>
                The option employees see when requesting leave.
            </li>
            <li>
                The annual entitlement shown in leave balances.
            </li>
            <li>
                Whether requests wait for approval or are
                approved automatically.
            </li>
            <li>
                Availability for future requests without
                deleting historical records.
            </li>
        </ul>
        <p>
            All changes are tenant-scoped and recorded in the
            audit log.
        </p>
    </aside>
</section>
