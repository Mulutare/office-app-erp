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
$branchId = (int) ($data['branchId'] ?? 0);
$formAction = $isEdit
    ? '/office_app/public/organization/branches/update'
    : '/office_app/public/organization/branches';
?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<div class="details-toolbar">
    <a
        href="/office_app/public/organization/branches"
        class="btn btn-secondary"
    >
        Back to branches
    </a>
</div>

<form
    method="post"
    action="<?= e($formAction) ?>"
    class="card enterprise-form branch-form"
>
    <?= csrfField() ?>
    <?php if ($isEdit): ?>
        <input
            type="hidden"
            name="branch_id"
            value="<?= e($branchId) ?>"
        >
    <?php endif; ?>

    <section class="form-section">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Identity</span>
                <h2 class="card-title">
                    Branch information
                </h2>
                <p>
                    Use a stable code for reports,
                    integrations and future assignments.
                </p>
            </div>
        </div>

        <div class="form-grid">
            <div class="form-field">
                <label for="branch-code">Branch code</label>
                <input
                    id="branch-code"
                    name="code"
                    type="text"
                    value="<?= e($old['code'] ?? '') ?>"
                    maxlength="30"
                    placeholder="NBO-HQ"
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
                <label for="branch-name">Branch name</label>
                <input
                    id="branch-name"
                    name="name"
                    type="text"
                    value="<?= e($old['name'] ?? '') ?>"
                    maxlength="120"
                    placeholder="Nairobi Headquarters"
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
                <span class="eyebrow">Location</span>
                <h2 class="card-title">
                    Address and operating timezone
                </h2>
            </div>
        </div>

        <div class="form-grid">
            <div class="form-field form-field-wide">
                <label for="branch-address">Address</label>
                <input
                    id="branch-address"
                    name="address_line"
                    type="text"
                    value="<?= e(
                        $old['address_line'] ?? ''
                    ) ?>"
                    maxlength="190"
                    placeholder="Building, street or area"
                >
                <?php if (!empty(
                    $errors['address_line']
                )): ?>
                    <small class="field-error">
                        <?= e($errors['address_line']) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="branch-city">City</label>
                <input
                    id="branch-city"
                    name="city"
                    type="text"
                    value="<?= e($old['city'] ?? '') ?>"
                    maxlength="100"
                    placeholder="Nairobi"
                >
                <?php if (!empty($errors['city'])): ?>
                    <small class="field-error">
                        <?= e($errors['city']) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="branch-country">
                    Country code
                </label>
                <input
                    id="branch-country"
                    name="country_code"
                    type="text"
                    value="<?= e(
                        $old['country_code'] ?? 'KE'
                    ) ?>"
                    maxlength="2"
                    placeholder="KE"
                    required
                >
                <?php if (!empty(
                    $errors['country_code']
                )): ?>
                    <small class="field-error">
                        <?= e($errors['country_code']) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field form-field-wide">
                <label for="branch-timezone">
                    IANA timezone
                </label>
                <input
                    id="branch-timezone"
                    name="timezone"
                    type="text"
                    value="<?= e(
                        $old['timezone']
                        ?? 'Africa/Nairobi'
                    ) ?>"
                    maxlength="80"
                    placeholder="Africa/Nairobi"
                    required
                >
                <small class="form-help">
                    Example: Africa/Nairobi or
                    Europe/London.
                </small>
                <?php if (!empty(
                    $errors['timezone']
                )): ?>
                    <small class="field-error">
                        <?= e($errors['timezone']) ?>
                    </small>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="form-section">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Contact</span>
                <h2 class="card-title">
                    Branch contact
                </h2>
            </div>
        </div>

        <div class="form-grid">
            <div class="form-field">
                <label for="branch-email">Email</label>
                <input
                    id="branch-email"
                    name="contact_email"
                    type="email"
                    value="<?= e(
                        $old['contact_email'] ?? ''
                    ) ?>"
                    maxlength="190"
                    placeholder="branch@example.com"
                >
                <?php if (!empty(
                    $errors['contact_email']
                )): ?>
                    <small class="field-error">
                        <?= e($errors['contact_email']) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="branch-phone">Phone</label>
                <input
                    id="branch-phone"
                    name="contact_phone"
                    type="tel"
                    value="<?= e(
                        $old['contact_phone'] ?? ''
                    ) ?>"
                    maxlength="40"
                    placeholder="+254 700 000 000"
                >
                <?php if (!empty(
                    $errors['contact_phone']
                )): ?>
                    <small class="field-error">
                        <?= e($errors['contact_phone']) ?>
                    </small>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="form-section branch-options">
        <div>
            <label class="checkbox-option">
                <input
                    type="checkbox"
                    name="is_head_office"
                    value="1"
                    <?= !empty($old['is_head_office'])
                        ? 'checked'
                        : '' ?>
                >
                <span>
                    <strong>Head office</strong>
                    <small>
                        Only one branch can be the company
                        head office.
                    </small>
                </span>
            </label>
            <?php if (!empty(
                $errors['is_head_office']
            )): ?>
                <small class="field-error">
                    <?= e($errors['is_head_office']) ?>
                </small>
            <?php endif; ?>
        </div>

        <div>
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
                    <strong>Active branch</strong>
                    <small>
                        Active locations will be available to
                        future ERP modules.
                    </small>
                </span>
            </label>
            <?php if (!empty($errors['active'])): ?>
                <small class="field-error">
                    <?= e($errors['active']) ?>
                </small>
            <?php endif; ?>
        </div>
    </section>

    <div class="form-actions">
        <a
            href="/office_app/public/organization/branches"
            class="btn btn-secondary"
        >
            Cancel
        </a>
        <button type="submit" class="btn btn-primary">
            <?= $isEdit
                ? 'Save branch changes'
                : 'Create branch' ?>
        </button>
    </div>
</form>
