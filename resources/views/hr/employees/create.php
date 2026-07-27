<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$departments = is_array(
    $data['departments'] ?? null
)
    ? $data['departments']
    : [];
$managers = is_array($data['managers'] ?? null)
    ? $data['managers']
    : [];
$users = is_array($data['users'] ?? null)
    ? $data['users']
    : [];
$employmentTypes = is_array(
    $data['employmentTypes'] ?? null
)
    ? $data['employmentTypes']
    : [];
$employmentStatuses = is_array(
    $data['employmentStatuses'] ?? null
)
    ? $data['employmentStatuses']
    : [];
$errors = is_array($data['errors'] ?? null)
    ? $data['errors']
    : [];
$old = is_array($data['old'] ?? null)
    ? $data['old']
    : [];
$isEdit = ($data['formMode'] ?? 'create')
    === 'edit';
$employeeId = (int) (
    $data['employeeId'] ?? 0
);
$formAction = $isEdit
    ? '/office_app/public/hr/employees/update'
    : '/office_app/public/hr/employees';
$cancelUrl = $isEdit
    ? '/office_app/public/hr/employees/view?id='
        . $employeeId
    : '/office_app/public/hr';

$oldValue = static function (
    string $key,
    string $default = ''
) use ($old): string {
    return isset($old[$key])
        ? (string) $old[$key]
        : $default;
};
?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<?php if ($departments === []): ?>
    <div class="alert alert-warning" role="alert">
        Create an active department before registering an
        employee.
        <a
            href="/office_app/public/hr/departments/create"
            class="table-link"
        >
            Create department
        </a>
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
            name="employee_id"
            value="<?= e($employeeId) ?>"
        >
    <?php endif; ?>

    <section class="form-section">
        <div class="section-heading">
            <div>
                <h2 class="card-title">
                    Employee identity
                </h2>
                <p>
                    Use the employee's official work identity.
                </p>
            </div>
        </div>

        <div class="form-grid">
            <div class="form-field">
                <label for="employee-number">
                    Employee number
                </label>
                <input
                    id="employee-number"
                    name="employee_number"
                    type="text"
                    value="<?= e($oldValue(
                        'employee_number'
                    )) ?>"
                    maxlength="50"
                    placeholder="EMP-0001"
                    autocomplete="off"
                    required
                    autofocus
                >
                <?php if (!empty(
                    $errors['employee_number']
                )): ?>
                    <small class="field-error">
                        <?= e(
                            $errors['employee_number']
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="preferred-name">
                    Preferred name
                </label>
                <input
                    id="preferred-name"
                    name="preferred_name"
                    type="text"
                    value="<?= e($oldValue(
                        'preferred_name'
                    )) ?>"
                    maxlength="80"
                    autocomplete="off"
                >
                <?php if (!empty(
                    $errors['preferred_name']
                )): ?>
                    <small class="field-error">
                        <?= e(
                            $errors['preferred_name']
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>

            <?php
            $nameFields = [
                'first_name' => 'First name',
                'middle_name' => 'Middle name',
                'last_name' => 'Last name',
            ];
            ?>
            <?php foreach (
                $nameFields as $field => $label
            ): ?>
                <div class="form-field">
                    <label for="<?= e($field) ?>">
                        <?= e($label) ?>
                    </label>
                    <input
                        id="<?= e($field) ?>"
                        name="<?= e($field) ?>"
                        type="text"
                        value="<?= e($oldValue($field)) ?>"
                        maxlength="80"
                        autocomplete="off"
                        <?= $field !== 'middle_name'
                            ? 'required'
                            : '' ?>
                    >
                    <?php if (!empty(
                        $errors[$field]
                    )): ?>
                        <small class="field-error">
                            <?= e($errors[$field]) ?>
                        </small>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="form-section">
        <h2 class="card-title">
            Work contact
        </h2>
        <div class="form-grid">
            <div class="form-field">
                <label for="work-email">
                    Work email
                </label>
                <input
                    id="work-email"
                    name="work_email"
                    type="email"
                    value="<?= e($oldValue(
                        'work_email'
                    )) ?>"
                    maxlength="190"
                    autocomplete="off"
                    required
                >
                <?php if (!empty(
                    $errors['work_email']
                )): ?>
                    <small class="field-error">
                        <?= e($errors['work_email']) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="work-phone">
                    Work phone
                </label>
                <input
                    id="work-phone"
                    name="work_phone"
                    type="tel"
                    value="<?= e($oldValue(
                        'work_phone'
                    )) ?>"
                    maxlength="40"
                    autocomplete="off"
                >
                <?php if (!empty(
                    $errors['work_phone']
                )): ?>
                    <small class="field-error">
                        <?= e($errors['work_phone']) ?>
                    </small>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="form-section">
        <div class="section-heading">
            <div>
                <h2 class="card-title">
                    Employment and organization
                </h2>
                <p>
                    Define the employee's position and
                    reporting relationship.
                </p>
            </div>
            <a
                href="/office_app/public/hr/departments/create"
                class="table-link"
            >
                Create department
            </a>
        </div>

        <div class="form-grid">
            <div class="form-field">
                <label for="department">
                    Department
                </label>
                <select
                    id="department"
                    name="department_id"
                    required
                >
                    <option value="">
                        Select department
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
                            value="<?= e(
                                $departmentId
                            ) ?>"
                            <?= $oldValue(
                                'department_id'
                            ) === $departmentId
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e(
                                $department['name']
                                ?? ''
                            ) ?>
                            (<?= e(
                                $department['code']
                                ?? ''
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
                <label for="job-title">
                    Job title
                </label>
                <input
                    id="job-title"
                    name="job_title"
                    type="text"
                    value="<?= e($oldValue(
                        'job_title'
                    )) ?>"
                    maxlength="120"
                    required
                >
                <?php if (!empty(
                    $errors['job_title']
                )): ?>
                    <small class="field-error">
                        <?= e($errors['job_title']) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="employment-type">
                    Employment type
                </label>
                <select
                    id="employment-type"
                    name="employment_type"
                    required
                >
                    <?php foreach (
                        $employmentTypes
                        as $value => $label
                    ): ?>
                        <option
                            value="<?= e($value) ?>"
                            <?= $oldValue(
                                'employment_type',
                                'full_time'
                            ) === $value
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty(
                    $errors['employment_type']
                )): ?>
                    <small class="field-error">
                        <?= e(
                            $errors['employment_type']
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="employment-status">
                    Employment status
                </label>
                <select
                    id="employment-status"
                    name="employment_status"
                    required
                >
                    <?php foreach (
                        $employmentStatuses
                        as $value => $label
                    ): ?>
                        <option
                            value="<?= e($value) ?>"
                            <?= $oldValue(
                                'employment_status',
                                'active'
                            ) === $value
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty(
                    $errors['employment_status']
                )): ?>
                    <small class="field-error">
                        <?= e(
                            $errors[
                                'employment_status'
                            ]
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="hire-date">
                    Hire date
                </label>
                <input
                    id="hire-date"
                    name="hire_date"
                    type="date"
                    value="<?= e($oldValue(
                        'hire_date',
                        date('Y-m-d')
                    )) ?>"
                    required
                >
                <?php if (!empty(
                    $errors['hire_date']
                )): ?>
                    <small class="field-error">
                        <?= e($errors['hire_date']) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="termination-date">
                    Termination date
                </label>
                <input
                    id="termination-date"
                    name="termination_date"
                    type="date"
                    value="<?= e($oldValue(
                        'termination_date'
                    )) ?>"
                >
                <small class="form-help">
                    Required only when status is Terminated.
                </small>
                <?php if (!empty(
                    $errors['termination_date']
                )): ?>
                    <small class="field-error">
                        <?= e(
                            $errors['termination_date']
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field form-field-wide">
                <label for="manager">
                    Manager
                </label>
                <select
                    id="manager"
                    name="manager_employee_id"
                >
                    <option value="">
                        No manager assigned
                    </option>
                    <?php foreach (
                        $managers as $manager
                    ): ?>
                        <?php
                        $managerId = (string) (
                            $manager['employee_id']
                            ?? ''
                        );
                        ?>
                        <option
                            value="<?= e($managerId) ?>"
                            <?= $oldValue(
                                'manager_employee_id'
                            ) === $managerId
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e(
                                $manager['display_name']
                                ?? ''
                            ) ?>
                            &mdash;
                            <?= e(
                                $manager[
                                    'employee_number'
                                ] ?? ''
                            ) ?>
                            / <?= e(
                                $manager['job_title']
                                ?? ''
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty(
                    $errors['manager_employee_id']
                )): ?>
                    <small class="field-error">
                        <?= e(
                            $errors[
                                'manager_employee_id'
                            ]
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="form-section">
        <h2 class="card-title">
            ERP account link
        </h2>
        <p class="form-help">
            Optional. Linking an account does not change its
            roles or permissions.
        </p>

        <div class="form-field">
            <label for="user-account">
                Available ERP account
            </label>
            <select
                id="user-account"
                name="user_id"
            >
                <option value="">
                    No ERP account linked
                </option>
                <?php foreach ($users as $user): ?>
                    <?php
                    $userId = (string) (
                        $user['user_id'] ?? ''
                    );
                    ?>
                    <option
                        value="<?= e($userId) ?>"
                        <?= $oldValue('user_id')
                            === $userId
                                ? 'selected'
                                : '' ?>
                    >
                        <?= e(
                            $user['display_name'] ?? ''
                        ) ?>
                        (@<?= e(
                            $user['username'] ?? ''
                        ) ?>)
                        <?= empty($user['active'])
                            ? ' (Inactive)'
                            : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty(
                $errors['user_id']
            )): ?>
                <small class="field-error">
                    <?= e($errors['user_id']) ?>
                </small>
            <?php endif; ?>
        </div>
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
            <?= $departments === []
                ? 'disabled'
                : '' ?>
        >
            <?= $isEdit
                ? 'Save employee changes'
                : 'Create employee' ?>
        </button>
    </div>
</form>
