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
$warehouses = is_array($data['warehouses'] ?? null)
    ? $data['warehouses']
    : [];
$parents = is_array($data['parents'] ?? null)
    ? $data['parents']
    : [];
$locationTypes = is_array($data['locationTypes'] ?? null)
    ? $data['locationTypes']
    : [];
?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<div class="details-toolbar">
    <a
        href="/office_app/public/inventory/locations"
        class="btn btn-secondary"
    >
        Back to locations
    </a>
</div>

<form
    method="post"
    action="/office_app/public/inventory/locations"
    class="card enterprise-form"
>
    <?= csrfField() ?>

    <section class="form-section">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Identity</span>
                <h2 class="card-title">Location information</h2>
                <p>
                    A location belongs to one tenant warehouse and may be
                    nested under another active location in that warehouse.
                </p>
            </div>
        </div>

        <div class="form-grid">
            <div class="form-field">
                <label for="location-warehouse">Warehouse</label>
                <select
                    id="location-warehouse"
                    name="warehouse_id"
                    required
                >
                    <option value="">Select warehouse</option>
                    <?php foreach ($warehouses as $warehouse): ?>
                        <option
                            value="<?= e(
                                $warehouse['warehouse_id'] ?? ''
                            ) ?>"
                            <?= (string) ($old['warehouse_id'] ?? '')
                                === (string) (
                                    $warehouse['warehouse_id'] ?? ''
                                )
                                    ? 'selected'
                                    : '' ?>
                        >
                            <?= e(
                                ($warehouse['code'] ?? '')
                                . ' - '
                                . ($warehouse['name'] ?? '')
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['warehouse_id'])): ?>
                    <small class="field-error">
                        <?= e($errors['warehouse_id']) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="location-parent">
                    Parent location
                </label>
                <select
                    id="location-parent"
                    name="parent_location_id"
                >
                    <option value="">Top-level location</option>
                    <?php foreach ($parents as $parent): ?>
                        <option
                            value="<?= e(
                                $parent['location_id'] ?? ''
                            ) ?>"
                            data-warehouse-id="<?= e(
                                $parent['warehouse_id'] ?? ''
                            ) ?>"
                            <?= (string) (
                                $old['parent_location_id'] ?? ''
                            ) === (string) (
                                $parent['location_id'] ?? ''
                            )
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e(
                                ($parent['warehouse_code'] ?? '')
                                . ' / '
                                . ($parent['code'] ?? '')
                                . ' - '
                                . ($parent['name'] ?? '')
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty(
                    $errors['parent_location_id']
                )): ?>
                    <small class="field-error">
                        <?= e($errors['parent_location_id']) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="location-code">Location code</label>
                <input
                    id="location-code"
                    name="code"
                    type="text"
                    value="<?= e($old['code'] ?? '') ?>"
                    maxlength="60"
                    placeholder="MAIN/STOCK/A01"
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
                <label for="location-name">Location name</label>
                <input
                    id="location-name"
                    name="name"
                    type="text"
                    value="<?= e($old['name'] ?? '') ?>"
                    maxlength="160"
                    placeholder="Aisle A - Rack 01"
                    required
                >
                <?php if (!empty($errors['name'])): ?>
                    <small class="field-error">
                        <?= e($errors['name']) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="location-type">Location type</label>
                <select
                    id="location-type"
                    name="location_type"
                    required
                >
                    <?php foreach (
                        $locationTypes as $value => $label
                    ): ?>
                        <option
                            value="<?= e($value) ?>"
                            <?= ($old['location_type'] ?? 'bin')
                                === $value
                                    ? 'selected'
                                    : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['location_type'])): ?>
                    <small class="field-error">
                        <?= e($errors['location_type']) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="location-barcode">Barcode</label>
                <input
                    id="location-barcode"
                    name="barcode"
                    type="text"
                    value="<?= e($old['barcode'] ?? '') ?>"
                    maxlength="120"
                    placeholder="Optional unique barcode"
                >
                <?php if (!empty($errors['barcode'])): ?>
                    <small class="field-error">
                        <?= e($errors['barcode']) ?>
                    </small>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="form-section">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Physical coordinates</span>
                <h2 class="card-title">Storage position</h2>
            </div>
        </div>

        <div class="form-grid">
            <?php foreach (
                [
                    'aisle' => 'Aisle',
                    'rack' => 'Rack',
                    'shelf' => 'Shelf',
                    'bin' => 'Bin',
                ]
                as $field => $label
            ): ?>
                <div class="form-field">
                    <label for="location-<?= e($field) ?>">
                        <?= e($label) ?>
                    </label>
                    <input
                        id="location-<?= e($field) ?>"
                        name="<?= e($field) ?>"
                        type="text"
                        value="<?= e($old[$field] ?? '') ?>"
                        maxlength="40"
                    >
                    <?php if (!empty($errors[$field])): ?>
                        <small class="field-error">
                            <?= e($errors[$field]) ?>
                        </small>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="form-field">
                <label for="location-priority">
                    Pick priority
                </label>
                <input
                    id="location-priority"
                    name="pick_priority"
                    type="number"
                    min="1"
                    max="65535"
                    value="<?= e(
                        $old['pick_priority'] ?? 100
                    ) ?>"
                    required
                >
                <?php if (!empty($errors['pick_priority'])): ?>
                    <small class="field-error">
                        <?= e($errors['pick_priority']) ?>
                    </small>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="form-section">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Controls</span>
                <h2 class="card-title">Operational permissions</h2>
            </div>
        </div>

        <div class="checkbox-grid">
            <label class="checkbox-field">
                <input
                    type="checkbox"
                    name="receiving_allowed"
                    value="1"
                    <?= !array_key_exists(
                        'receiving_allowed',
                        $old
                    ) || !empty($old['receiving_allowed'])
                        ? 'checked'
                        : '' ?>
                >
                <span>Receiving allowed</span>
            </label>

            <label class="checkbox-field">
                <input
                    type="checkbox"
                    name="picking_allowed"
                    value="1"
                    <?= !array_key_exists(
                        'picking_allowed',
                        $old
                    ) || !empty($old['picking_allowed'])
                        ? 'checked'
                        : '' ?>
                >
                <span>Picking allowed</span>
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
    </section>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">
            Create location
        </button>
        <a
            href="/office_app/public/inventory/locations"
            class="btn btn-secondary"
        >
            Cancel
        </a>
    </div>
</form>
