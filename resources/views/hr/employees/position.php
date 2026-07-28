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
$employee = is_array(
    $data['employee'] ?? null
)
    ? $data['employee']
    : [];
$current = is_array($data['current'] ?? null)
    ? $data['current']
    : null;
$positions = is_array(
    $data['positions'] ?? null
)
    ? $data['positions']
    : [];
$employeeId = (int) (
    $employee['employee_id'] ?? 0
);
$selectedPosition = (string) (
    $old['position_id'] ?? ''
);
?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<div class="details-toolbar">
    <a
        href="/office_app/public/hr/employees/view?id=<?= e(
            $employeeId
        ) ?>"
        class="btn btn-secondary"
    >
        Back to employee
    </a>
</div>

<section class="card assignment-context">
    <div>
        <span class="eyebrow">Employee</span>
        <h2 class="card-title">
            <?= e($employee['display_name'] ?? '') ?>
        </h2>
        <p>
            <?= e(
                $employee['employee_number'] ?? ''
            ) ?>
            &middot;
            Hired <?= e(
                substr(
                    (string) (
                        $employee['hire_date'] ?? ''
                    ),
                    0,
                    10
                )
            ) ?>
        </p>
    </div>

    <div class="assignment-current">
        <span>Current position</span>
        <strong>
            <?= $current === null
                ? 'Not assigned'
                : e(
                    $current[
                        'position_name_snapshot'
                    ] ?? ''
                ) ?>
        </strong>
        <?php if ($current !== null): ?>
            <small>
                Since <?= e(substr(
                    (string) (
                        $current['effective_from']
                        ?? ''
                    ),
                    0,
                    10
                )) ?>
            </small>
        <?php endif; ?>
    </div>
</section>

<form
    method="post"
    action="/office_app/public/hr/employees/position"
    class="card enterprise-form assignment-form"
>
    <?= csrfField() ?>
    <input
        type="hidden"
        name="employee_id"
        value="<?= e($employeeId) ?>"
    >

    <section class="form-section">
        <div class="section-heading">
            <div>
                <span class="eyebrow">
                    Approved headcount
                </span>
                <h2 class="card-title">
                    <?= $current === null
                        ? 'Create position assignment'
                        : 'Transfer employee' ?>
                </h2>
                <p>
                    Only open positions belonging to this
                    company are available. A transfer ends
                    the current assignment on the new
                    effective date.
                </p>
            </div>
        </div>

        <div class="form-grid">
            <div class="form-field form-field-wide">
                <label for="assignment-position">
                    New position
                </label>
                <select
                    id="assignment-position"
                    name="position_id"
                    required
                    autofocus
                >
                    <option value="">
                        Select an open position
                    </option>
                    <?php foreach (
                        $positions as $position
                    ): ?>
                        <?php
                        $positionId = (string) (
                            $position['position_id']
                            ?? ''
                        );
                        $available = !empty(
                            $position['available']
                        );
                        ?>
                        <option
                            value="<?= e($positionId) ?>"
                            <?= $selectedPosition
                                === $positionId
                                    ? 'selected'
                                    : '' ?>
                            <?= $available
                                ? ''
                                : 'disabled' ?>
                        >
                            <?= e(
                                $position['name'] ?? ''
                            ) ?>
                            — <?= e(
                                $position[
                                    'department_name'
                                ] ?? ''
                            ) ?>
                            — <?= e(
                                $position[
                                    'filled_headcount'
                                ] ?? 0
                            ) ?>/<?= e(
                                $position[
                                    'approved_headcount'
                                ] ?? 0
                            ) ?> filled
                            <?= $available
                                ? ''
                                : ' (full)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="form-help">
                    Capacity is checked again securely when
                    the assignment is saved.
                </small>
                <?php if (!empty(
                    $errors['position_id']
                )): ?>
                    <small class="field-error">
                        <?= e(
                            $errors['position_id']
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="assignment-effective">
                    Effective date
                </label>
                <input
                    id="assignment-effective"
                    name="effective_from"
                    type="date"
                    value="<?= e(
                        $old['effective_from']
                        ?? date('Y-m-d')
                    ) ?>"
                    min="<?= e(substr(
                        (string) (
                            $employee['hire_date'] ?? ''
                        ),
                        0,
                        10
                    )) ?>"
                    max="<?= e(date('Y-m-d')) ?>"
                    required
                >
                <?php if (!empty(
                    $errors['effective_from']
                )): ?>
                    <small class="field-error">
                        <?= e(
                            $errors['effective_from']
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field form-field-wide">
                <label for="assignment-notes">
                    Assignment note
                </label>
                <textarea
                    id="assignment-notes"
                    name="notes"
                    rows="4"
                    maxlength="500"
                    placeholder="Optional business reason or approval reference"
                ><?= e($old['notes'] ?? '') ?></textarea>
                <?php if (!empty(
                    $errors['notes']
                )): ?>
                    <small class="field-error">
                        <?= e($errors['notes']) ?>
                    </small>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="form-actions">
        <a
            href="/office_app/public/hr/employees/view?id=<?= e(
                $employeeId
            ) ?>"
            class="btn btn-secondary"
        >
            Cancel
        </a>
        <button type="submit" class="btn btn-primary">
            <?= $current === null
                ? 'Assign position'
                : 'Confirm transfer' ?>
        </button>
    </div>
</form>
