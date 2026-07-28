<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$positions = is_array($data['positions'] ?? null)
    ? $data['positions']
    : [];
$summary = is_array($data['summary'] ?? null)
    ? $data['summary']
    : [];
$notice = is_array($data['notice'] ?? null)
    ? $data['notice']
    : null;
$canManage = !empty($data['canManage']);
$statusPresentation = [
    'planned' => ['Planned', 'badge-role'],
    'open' => ['Open', 'badge-success'],
    'frozen' => ['Frozen', 'badge-warning'],
    'closed' => ['Closed', 'badge-muted'],
];

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

<section class="position-overview">
    <div class="position-overview-heading">
        <div>
            <span class="eyebrow">Workforce design</span>
            <h2>Approved organization positions</h2>
            <p>
                Connect job architecture to departments,
                locations and approved staffing capacity.
            </p>
        </div>

        <?php if ($canManage): ?>
            <a
                href="/office_app/public/organization/positions/create"
                class="btn btn-primary"
            >
                Create position
            </a>
        <?php endif; ?>
    </div>

    <div
        class="position-metrics"
        aria-label="Position summary"
    >
        <article class="card">
            <span>Position definitions</span>
            <strong><?= e((int) (
                $summary['total'] ?? 0
            )) ?></strong>
        </article>
        <article class="card">
            <span>Open positions</span>
            <strong><?= e((int) (
                $summary['open'] ?? 0
            )) ?></strong>
        </article>
        <article class="card">
            <span>Planned positions</span>
            <strong><?= e((int) (
                $summary['planned'] ?? 0
            )) ?></strong>
        </article>
        <article class="card">
            <span>Approved headcount</span>
            <strong><?= e((int) (
                $summary['approvedHeadcount'] ?? 0
            )) ?></strong>
        </article>
    </div>
</section>

<section class="card table-card">
    <div class="table-summary">
        <div>
            <strong>
                <?= e(count($positions)) ?>
                registered positions
            </strong>
            <span class="table-summary-note">
                Employee assignment and payroll remain
                independent of this planning catalogue.
            </span>
        </div>
    </div>

    <div class="table-responsive">
        <table class="data-table position-table">
            <thead>
                <tr>
                    <th>Position</th>
                    <th>Organization</th>
                    <th>Job title</th>
                    <th>Headcount</th>
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
            <?php if ($positions === []): ?>
                <tr>
                    <td
                        colspan="<?= $canManage ? '7' : '6' ?>"
                        class="empty-state"
                    >
                        No positions have been defined for
                        this company.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach (
                    $positions as $position
                ): ?>
                    <?php
                    $status = (string) (
                        $position['status'] ?? ''
                    );
                    $statusData =
                        $statusPresentation[$status]
                        ?? [
                            ucfirst($status),
                            'badge-muted',
                        ];
                    ?>
                    <tr>
                        <td>
                            <strong>
                                <?= e(
                                    $position['name'] ?? ''
                                ) ?>
                            </strong>
                            <small>
                                <?= e(
                                    $position['code'] ?? ''
                                ) ?>
                            </small>
                        </td>
                        <td>
                            <strong class="table-primary">
                                <?= e(
                                    $position[
                                        'department_name'
                                    ] ?? ''
                                ) ?>
                            </strong>
                            <small>
                                <?= e(
                                    $position['branch_name']
                                    ?? 'Company-wide'
                                ) ?>
                            </small>
                        </td>
                        <td>
                            <?= e(
                                $position['job_title_name']
                                ?? ''
                            ) ?>
                            <?php if (!empty(
                                $position['grade_level']
                            )): ?>
                                <small>
                                    Grade
                                    <?= e(
                                        $position[
                                            'grade_level'
                                        ]
                                    ) ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="headcount-value">
                                <?= e((int) (
                                    $position[
                                        'approved_headcount'
                                    ] ?? 0
                                )) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= e(
                                $statusData[1]
                            ) ?>">
                                <?= e($statusData[0]) ?>
                            </span>
                        </td>
                        <td>
                            <?= e($formatDate(
                                $position['updated_at']
                                ?? null
                            )) ?>
                        </td>
                        <?php if ($canManage): ?>
                            <td>
                                <a
                                    href="/office_app/public/organization/positions/edit?id=<?= e(
                                        $position[
                                            'position_id'
                                        ] ?? 0
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
