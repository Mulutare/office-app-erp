<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$branches = is_array($data['branches'] ?? null)
    ? $data['branches']
    : [];
$summary = is_array($data['summary'] ?? null)
    ? $data['summary']
    : [];
$notice = is_array($data['notice'] ?? null)
    ? $data['notice']
    : null;
$canManage = !empty($data['canManage']);

$formatDate = static function (mixed $value): string {
    if (!is_string($value) || trim($value) === '') {
        return 'Not recorded';
    }

    $timestamp = strtotime($value);

    return $timestamp === false
        ? $value
        : date('M j, Y', $timestamp);
};
?>

<?php if ($notice !== null): ?>
    <div class="alert alert-success" role="status">
        <?= e($notice['message'] ?? '') ?>
    </div>
<?php endif; ?>

<section class="branch-overview">
    <div class="branch-overview-heading">
        <div>
            <span class="eyebrow">Organization</span>
            <h2>Branch network</h2>
            <p>
                A shared location directory for future HR,
                inventory, sales and operational assignments.
            </p>
        </div>

        <?php if ($canManage): ?>
            <a
                href="/office_app/public/organization/branches/create"
                class="btn btn-primary"
            >
                Create branch
            </a>
        <?php endif; ?>
    </div>

    <div class="branch-metrics" aria-label="Branch summary">
        <article class="card">
            <span>Total locations</span>
            <strong><?= e((int) (
                $summary['total'] ?? 0
            )) ?></strong>
        </article>
        <article class="card">
            <span>Active branches</span>
            <strong><?= e((int) (
                $summary['active'] ?? 0
            )) ?></strong>
        </article>
        <article class="card">
            <span>Head offices</span>
            <strong><?= e((int) (
                $summary['headOffices'] ?? 0
            )) ?></strong>
        </article>
    </div>
</section>

<section class="card table-card">
    <div class="table-summary">
        <div>
            <strong>
                <?= e(count($branches)) ?>
                registered branches
            </strong>
            <span class="table-summary-note">
                Results are restricted to the current company.
            </span>
        </div>
    </div>

    <div class="table-responsive">
        <table class="data-table branch-table">
            <thead>
                <tr>
                    <th>Branch</th>
                    <th>Location</th>
                    <th>Contact</th>
                    <th>Timezone</th>
                    <th>Status</th>
                    <th>Updated</th>
                    <?php if ($canManage): ?>
                        <th class="table-actions-column">
                            Actions
                        </th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if ($branches === []): ?>
                <tr>
                    <td
                        colspan="<?= $canManage ? '7' : '6' ?>"
                        class="empty-state"
                    >
                        No branches have been created for this
                        company.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($branches as $branch): ?>
                    <tr>
                        <td>
                            <div class="branch-name-line">
                                <strong>
                                    <?= e($branch['name'] ?? '') ?>
                                </strong>
                                <?php if (!empty(
                                    $branch['is_head_office']
                                )): ?>
                                    <span class="badge badge-role">
                                        Head office
                                    </span>
                                <?php endif; ?>
                            </div>
                            <small>
                                <?= e($branch['code'] ?? '') ?>
                            </small>
                        </td>
                        <td>
                            <strong>
                                <?= e(
                                    $branch['city']
                                    ?? 'Location not specified'
                                ) ?>
                            </strong>
                            <small>
                                <?= e(
                                    $branch['country_code']
                                    ?? ''
                                ) ?>
                            </small>
                        </td>
                        <td>
                            <?= e(
                                $branch['contact_email']
                                ?? 'Not provided'
                            ) ?>
                            <small>
                                <?= e(
                                    $branch['contact_phone']
                                    ?? ''
                                ) ?>
                            </small>
                        </td>
                        <td>
                            <?= e(
                                $branch['timezone'] ?? ''
                            ) ?>
                        </td>
                        <td>
                            <span class="badge <?= !empty(
                                $branch['active']
                            )
                                ? 'badge-success'
                                : 'badge-muted' ?>">
                                <?= !empty($branch['active'])
                                    ? 'Active'
                                    : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <?= e($formatDate(
                                $branch['updated_at'] ?? null
                            )) ?>
                        </td>
                        <?php if ($canManage): ?>
                            <td>
                                <a
                                    href="/office_app/public/organization/branches/edit?id=<?= e(
                                        $branch['branch_id'] ?? 0
                                    ) ?>"
                                    class="table-link"
                                >
                                    Edit
                                </a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
