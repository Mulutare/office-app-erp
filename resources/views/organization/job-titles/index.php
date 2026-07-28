<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$jobTitles = is_array($data['jobTitles'] ?? null)
    ? $data['jobTitles']
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

<section class="job-title-overview">
    <div class="job-title-overview-heading">
        <div>
            <span class="eyebrow">Organization</span>
            <h2>Workforce terminology</h2>
            <p>
                Standard titles shared by HR, recruitment,
                payroll, projects and reporting.
            </p>
        </div>

        <?php if ($canManage): ?>
            <a
                href="/office_app/public/organization/job-titles/create"
                class="btn btn-primary"
            >
                Create job title
            </a>
        <?php endif; ?>
    </div>

    <div
        class="job-title-metrics"
        aria-label="Job-title summary"
    >
        <article class="card">
            <span>Catalogue size</span>
            <strong><?= e((int) (
                $summary['total'] ?? 0
            )) ?></strong>
        </article>
        <article class="card">
            <span>Active titles</span>
            <strong><?= e((int) (
                $summary['active'] ?? 0
            )) ?></strong>
        </article>
        <article class="card">
            <span>Job families</span>
            <strong><?= e((int) (
                $summary['families'] ?? 0
            )) ?></strong>
        </article>
    </div>
</section>

<section class="card table-card">
    <div class="table-summary">
        <div>
            <strong>
                <?= e(count($jobTitles)) ?>
                registered job titles
            </strong>
            <span class="table-summary-note">
                Results are restricted to the current company.
            </span>
        </div>
    </div>

    <div class="table-responsive">
        <table class="data-table job-title-table">
            <thead>
                <tr>
                    <th>Job title</th>
                    <th>Family</th>
                    <th>Grade</th>
                    <th>Description</th>
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
            <?php if ($jobTitles === []): ?>
                <tr>
                    <td
                        colspan="<?= $canManage ? '7' : '6' ?>"
                        class="empty-state"
                    >
                        No job titles have been created for
                        this company.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach (
                    $jobTitles as $jobTitle
                ): ?>
                    <tr>
                        <td>
                            <strong>
                                <?= e(
                                    $jobTitle['name'] ?? ''
                                ) ?>
                            </strong>
                            <small>
                                <?= e(
                                    $jobTitle['code'] ?? ''
                                ) ?>
                            </small>
                        </td>
                        <td>
                            <?= e(
                                $jobTitle['job_family']
                                ?? 'Unclassified'
                            ) ?>
                        </td>
                        <td>
                            <?php if (
                                !empty($jobTitle['grade_level'])
                            ): ?>
                                <span class="badge badge-role">
                                    <?= e(
                                        $jobTitle['grade_level']
                                    ) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">
                                    Not assigned
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="job-title-description">
                            <?= e(
                                $jobTitle['description']
                                ?? 'No description'
                            ) ?>
                        </td>
                        <td>
                            <span class="badge <?= !empty(
                                $jobTitle['active']
                            )
                                ? 'badge-success'
                                : 'badge-muted' ?>">
                                <?= !empty($jobTitle['active'])
                                    ? 'Active'
                                    : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <?= e($formatDate(
                                $jobTitle['updated_at']
                                ?? null
                            )) ?>
                        </td>
                        <?php if ($canManage): ?>
                            <td>
                                <a
                                    href="/office_app/public/organization/job-titles/edit?id=<?= e(
                                        $jobTitle[
                                            'job_title_id'
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
