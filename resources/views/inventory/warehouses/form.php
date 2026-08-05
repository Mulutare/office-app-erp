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
$managers = is_array($data['managers'] ?? null)
    ? $data['managers']
    : [];
$warehouseTypes = is_array($data['warehouseTypes'] ?? null)
    ? $data['warehouseTypes']
    : [];
?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<div class="details-toolbar">
    <a
        href="/office_app/public/inventory/warehouses"
        class="btn btn-secondary"
    >
        Back to warehouses
    </a>
</div>

<form
    method="post"
    action="/office_app/public/inventory/warehouses"
    class="card enterprise-form"
>
    <?= csrfField() ?>

    <section class="form-section">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Identity</span>
                <h2 class="card-title">Warehouse information</h2>
                <p>
                    The warehouse and RCPT, INT, DLV and ADJ
                    operation types will be committed together.
                </p>
            </div>
        </div>

        <div class="form-grid">
            <div class="form-field">
                <label for="warehouse-code">Warehouse code</label>
                <input
                    id="warehouse-code"
                    name="code"
                    type="text"
                    value="<?= e($old['code'] ?? '') ?>"
                    maxlength="40"
                    placeholder="MAIN-WH"
                    required
                    autofocus
                >
                <?php if (!empty($errors['code'])): ?>
                    <small class="field-error"><?= e($errors['code']) ?></small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="warehouse-name">Warehouse name</label>
                <input
                    id="warehouse-name"
                    name="name"
                    type="text"
                    value="<?= e($old['name'] ?? '') ?>"
                    maxlength="160"
                    placeholder="Main Distribution Warehouse"
                    required
                >
                <?php if (!empty($errors['name'])): ?>
                    <small class="field-error"><?= e($errors['name']) ?></small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="warehouse-type">Warehouse type</label>
                <select id="warehouse-type" name="warehouse_type" required>
                    <?php foreach ($warehouseTypes as $value => $label): ?>
                        <option
                            value="<?= e($value) ?>"
                            <?= ($old['warehouse_type'] ?? 'standard') === $value
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['warehouse_type'])): ?>
                    <small class="field-error"><?= e($errors['warehouse_type']) ?></small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="warehouse-branch">Branch</label>
                <select id="warehouse-branch" name="branch_id">
                    <option value="">No branch assignment</option>
                    <?php foreach ($branches as $branch): ?>
                        <option
                            value="<?= e($branch['branch_id'] ?? '') ?>"
                            <?= (string) ($old['branch_id'] ?? '')
                                === (string) ($branch['branch_id'] ?? '')
                                    ? 'selected'
                                    : '' ?>
                        >
                            <?= e(($branch['code'] ?? '') . ' — ' . ($branch['name'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['branch_id'])): ?>
                    <small class="field-error"><?= e($errors['branch_id']) ?></small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="warehouse-manager">Manager</label>
                <select id="warehouse-manager" name="manager_user_id">
                    <option value="">No manager assignment</option>
                    <?php foreach ($managers as $manager): ?>
                        <option
                            value="<?= e($manager['user_id'] ?? '') ?>"
                            <?= (string) ($old['manager_user_id'] ?? '')
                                === (string) ($manager['user_id'] ?? '')
                                    ? 'selected'
                                    : '' ?>
                        >
                            <?= e(($manager['display_name'] ?? '') . ' (' . ($manager['username'] ?? '') . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['manager_user_id'])): ?>
                    <small class="field-error"><?= e($errors['manager_user_id']) ?></small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="warehouse-email">Email</label>
                <input
                    id="warehouse-email"
                    name="email"
                    type="email"
                    value="<?= e($old['email'] ?? '') ?>"
                    maxlength="190"
                    placeholder="warehouse@example.com"
                >
                <?php if (!empty($errors['email'])): ?>
                    <small class="field-error"><?= e($errors['email']) ?></small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="warehouse-phone">Phone</label>
                <input
                    id="warehouse-phone"
                    name="phone"
                    type="text"
                    value="<?= e($old['phone'] ?? '') ?>"
                    maxlength="40"
                    placeholder="+251..."
                >
                <?php if (!empty($errors['phone'])): ?>
                    <small class="field-error"><?= e($errors['phone']) ?></small>
                <?php endif; ?>
            </div>

            <div class="form-field form-field-wide">
                <label for="warehouse-address">Address</label>
                <input
                    id="warehouse-address"
                    name="address"
                    type="text"
                    value="<?= e($old['address'] ?? '') ?>"
                    maxlength="255"
                    placeholder="Warehouse street and site details"
                >
                <?php if (!empty($errors['address'])): ?>
                    <small class="field-error"><?= e($errors['address']) ?></small>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="form-section">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Controls</span>
                <h2 class="card-title">Stock policy</h2>
            </div>
        </div>

        <div class="checkbox-grid">
            <label class="checkbox-field">
                <input
                    type="checkbox"
                    name="allow_negative_stock"
                    value="1"
                    <?= !empty($old['allow_negative_stock'])
                        ? 'checked'
                        : '' ?>
                >
                <span>Allow negative stock</span>
            </label>

            <label class="checkbox-field">
                <input
                    type="checkbox"
                    name="is_default"
                    value="1"
                    <?= !empty($old['is_default'])
                        ? 'checked'
                        : '' ?>
                >
                <span>Default company warehouse</span>
            </label>

            <label class="checkbox-field">
                <input
                    type="checkbox"
                    name="active"
                    value="1"
                    <?= !array_key_exists('active', $old)
                        || !empty($old['active'])
                            ? 'checked'
                            : '' ?>
                >
                <span>Active</span>
            </label>
        </div>

        <?php if (!empty($errors['is_default'])): ?>
            <small class="field-error"><?= e($errors['is_default']) ?></small>
        <?php endif; ?>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">
            Create warehouse
        </button>
        <a
            href="/office_app/public/inventory/warehouses"
            class="btn btn-secondary"
        >
            Cancel
        </a>
    </div>
</form>
