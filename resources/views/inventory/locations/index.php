<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$locations = is_array($data['locations'] ?? null)
    ? $data['locations']
    : [];
$warehouses = is_array($data['warehouses'] ?? null)
    ? $data['warehouses']
    : [];
$summary = is_array($data['summary'] ?? null)
    ? $data['summary']
    : [];
$notice = is_array($data['notice'] ?? null)
    ? $data['notice']
    : null;
$errors = is_array($data['errors'] ?? null)
    ? $data['errors']
    : [];
$canManage = !empty($data['canManage']);
?>

<?php if ($notice !== null): ?>
    <div class="alert alert-success" role="status">
        <?= e($notice['message'] ?? '') ?>
    </div>
<?php endif; ?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<section class="branch-overview">
    <div class="branch-overview-heading">
        <div>
            <span class="eyebrow">Inventory</span>
            <h2>Warehouse location hierarchy</h2>
            <p>
                Use receiving, stock, dispatch, returns and quarantine
                locations to control how products move through each warehouse.
            </p>
        </div>

        <div class="details-toolbar">
            <a href="/office_app/public/data-exchange/locations/export/configure" class="btn btn-secondary">Export</a>
            <a
                href="/office_app/public/inventory"
                class="btn btn-secondary"
            >
                Inventory dashboard
            </a>
            <a
                href="/office_app/public/inventory/warehouses"
                class="btn btn-secondary"
            >
                Warehouses
            </a>
            <?php if ($canManage): ?>
                <a
                    href="/office_app/public/inventory/locations/create"
                    class="btn btn-primary"
                >
                    Create location
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="branch-metrics" aria-label="Location summary">
        <article class="card">
            <span>Total locations</span>
            <strong><?= e((int) ($summary['total'] ?? 0)) ?></strong>
        </article>
        <article class="card">
            <span>Active</span>
            <strong><?= e((int) ($summary['active'] ?? 0)) ?></strong>
        </article>
        <article class="card">
            <span>Receiving enabled</span>
            <strong><?= e((int) ($summary['receiving'] ?? 0)) ?></strong>
        </article>
        <article class="card">
            <span>Picking enabled</span>
            <strong><?= e((int) ($summary['picking'] ?? 0)) ?></strong>
        </article>
    </div>
</section>

<?php if ($canManage && $warehouses !== []): ?>
    <section class="card">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Operational defaults</span>
                <h2 class="card-title">
                    Provision or repair the standard warehouse route
                </h2>
                <p>
                    Creates the warehouse root plus INPUT, STOCK, OUTPUT,
                    RETURNS and QUARANTINE, then maps RCPT, INT, DLV and ADJ.
                </p>
            </div>
        </div>

        <form
            method="post"
            action="/office_app/public/inventory/locations/provision"
            class="enterprise-form"
        >
            <?= csrfField() ?>

            <div class="form-grid">
                <div class="form-field">
                    <label for="provision-warehouse">
                        Warehouse
                    </label>
                    <select
                        id="provision-warehouse"
                        name="warehouse_id"
                        required
                    >
                        <option value="">Select warehouse</option>
                        <?php foreach ($warehouses as $warehouse): ?>
                            <option
                                value="<?= e(
                                    $warehouse['warehouse_id'] ?? ''
                                ) ?>"
                            >
                                <?= e(
                                    ($warehouse['code'] ?? '')
                                    . ' - '
                                    . ($warehouse['name'] ?? '')
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-secondary">
                    Provision operational locations
                </button>
            </div>
        </form>
    </section>
<?php endif; ?>

<section class="card table-card">
    <div class="table-summary">
        <div>
            <strong><?= e(count($locations)) ?> registered locations</strong>
            <span class="table-summary-note">
                Results are restricted to the active company.
            </span>
        </div>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Location</th>
                    <th>Warehouse</th>
                    <th>Parent</th>
                    <th>Type</th>
                    <th>Coordinates</th>
                    <th>Receiving</th>
                    <th>Picking</th>
                    <th>Priority</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($locations === []): ?>
                <tr>
                    <td colspan="9" class="empty-state">
                        No locations have been configured.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($locations as $location): ?>
                    <?php
                    $coordinates = array_filter([
                        $location['aisle'] ?? null,
                        $location['rack'] ?? null,
                        $location['shelf'] ?? null,
                        $location['bin'] ?? null,
                    ], static fn (mixed $value): bool =>
                        is_string($value) && $value !== '');
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($location['name'] ?? '') ?></strong>
                            <small><?= e($location['code'] ?? '') ?></small>
                        </td>
                        <td>
                            <?= e($location['warehouse_name'] ?? '') ?>
                            <small>
                                <?= e($location['warehouse_code'] ?? '') ?>
                            </small>
                        </td>
                        <td>
                            <?= e(
                                $location['parent_name']
                                ?? 'Top level'
                            ) ?>
                        </td>
                        <td>
                            <?= e(ucwords(str_replace(
                                '_',
                                ' ',
                                (string) (
                                    $location['location_type'] ?? ''
                                )
                            ))) ?>
                        </td>
                        <td>
                            <?= $coordinates === []
                                ? 'Not specified'
                                : e(implode(' / ', $coordinates)) ?>
                        </td>
                        <td>
                            <span class="badge <?= !empty(
                                $location['receiving_allowed']
                            ) ? 'badge-success' : 'badge-muted' ?>">
                                <?= !empty($location['receiving_allowed'])
                                    ? 'Allowed'
                                    : 'Blocked' ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= !empty(
                                $location['picking_allowed']
                            ) ? 'badge-success' : 'badge-muted' ?>">
                                <?= !empty($location['picking_allowed'])
                                    ? 'Allowed'
                                    : 'Blocked' ?>
                            </span>
                        </td>
                        <td>
                            <?= e((int) (
                                $location['pick_priority'] ?? 100
                            )) ?>
                        </td>
                        <td>
                            <span class="badge <?= !empty(
                                $location['active']
                            ) ? 'badge-success' : 'badge-muted' ?>">
                                <?= !empty($location['active'])
                                    ? 'Active'
                                    : 'Inactive' ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
