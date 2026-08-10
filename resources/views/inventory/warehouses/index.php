<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$warehouses = is_array($data['warehouses'] ?? null)
    ? $data['warehouses']
    : [];
$summary = is_array($data['summary'] ?? null)
    ? $data['summary']
    : [];
$notice = is_array($data['notice'] ?? null)
    ? $data['notice']
    : null;
$canManage = !empty($data['canManage']);
?>

<?php if ($notice !== null): ?>
    <div class="alert alert-success" role="status">
        <?= e($notice['message'] ?? '') ?>
    </div>
<?php endif; ?>

<section class="branch-overview">
    <div class="branch-overview-heading">
        <div>
            <span class="eyebrow">Inventory</span>
            <h2>Warehouse network</h2>
            <p>
                Every warehouse is tenant-scoped and should have
                six operational locations plus four mapped operation types.
            </p>
        </div>

        <div class="details-toolbar">
            <a href="/office_app/public/data-exchange/warehouses/export/configure" class="btn btn-secondary">Export</a>
            <a
                href="/office_app/public/inventory"
                class="btn btn-secondary"
            >
                Inventory dashboard
            </a>
            <a
                href="/office_app/public/inventory/locations"
                class="btn btn-secondary"
            >
                Warehouse locations
            </a>
            <?php if ($canManage): ?>
                <a
                    href="/office_app/public/inventory/warehouses/create"
                    class="btn btn-primary"
                >
                    Create warehouse
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="branch-metrics" aria-label="Warehouse summary">
        <article class="card">
            <span>Total warehouses</span>
            <strong><?= e((int) ($summary['total'] ?? 0)) ?></strong>
        </article>
        <article class="card">
            <span>Active</span>
            <strong><?= e((int) ($summary['active'] ?? 0)) ?></strong>
        </article>
        <article class="card">
            <span>Default</span>
            <strong><?= e((int) ($summary['defaults'] ?? 0)) ?></strong>
        </article>
        <article class="card">
            <span>Operation-ready</span>
            <strong><?= e((int) ($summary['ready'] ?? 0)) ?></strong>
        </article>
    </div>
</section>

<section class="card table-card">
    <div class="table-summary">
        <div>
            <strong><?= e(count($warehouses)) ?> registered warehouses</strong>
            <span class="table-summary-note">
                Results are restricted to the active company.
            </span>
        </div>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Warehouse</th>
                    <th>Type</th>
                    <th>Branch</th>
                    <th>Manager</th>
                    <th>Default</th>
                    <th>Negative stock</th>
                    <th>Status</th>
                    <th>Operations</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($warehouses === []): ?>
                <tr>
                    <td colspan="8" class="empty-state">
                        No warehouses have been created for this company.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($warehouses as $warehouse): ?>
                    <?php
                    $ready = !empty(
                        $warehouse['operational_ready']
                    );
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($warehouse['name'] ?? '') ?></strong>
                            <small><?= e($warehouse['code'] ?? '') ?></small>
                        </td>
                        <td><?= e(ucwords(str_replace(
                            '_',
                            ' ',
                            (string) ($warehouse['warehouse_type'] ?? '')
                        ))) ?></td>
                        <td><?= e(
                            $warehouse['branch_name']
                            ?? 'Not assigned'
                        ) ?></td>
                        <td><?= e(
                            $warehouse['manager_name']
                            ?? 'Not assigned'
                        ) ?></td>
                        <td>
                            <span class="badge <?= !empty(
                                $warehouse['is_default']
                            ) ? 'badge-success' : 'badge-muted' ?>">
                                <?= !empty($warehouse['is_default'])
                                    ? 'Default'
                                    : 'No' ?>
                            </span>
                        </td>
                        <td>
                            <?= !empty($warehouse['allow_negative_stock'])
                                ? 'Allowed'
                                : 'Blocked' ?>
                        </td>
                        <td>
                            <span class="badge <?= !empty(
                                $warehouse['active']
                            ) ? 'badge-success' : 'badge-muted' ?>">
                                <?= !empty($warehouse['active'])
                                    ? 'Active'
                                    : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $ready
                                ? 'badge-success'
                                : 'badge-muted' ?>">
                                <?= e((int) (
                                    $warehouse[
                                        'operational_location_count'
                                    ] ?? 0
                                )) ?>/6 locations
                                &middot;
                                <?= e((int) (
                                    $warehouse[
                                        'mapped_operation_type_count'
                                    ] ?? 0
                                )) ?>/4 routes
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
