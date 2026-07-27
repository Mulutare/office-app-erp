<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$employee = is_array(
    $data['employee'] ?? null
)
    ? $data['employee']
    : [];
$events = is_array($data['events'] ?? null)
    ? $data['events']
    : [];
$pagination = is_array(
    $data['pagination'] ?? null
)
    ? $data['pagination']
    : [];
$canManage = !empty($data['canManage']);
$employeeId = (int) (
    $employee['employee_id'] ?? 0
);

$formatDate = static function (mixed $value): string {
    if (!is_string($value) || trim($value) === '') {
        return 'Unknown time';
    }

    $timestamp = strtotime($value);

    return $timestamp === false
        ? $value
        : date('M j, Y g:i A', $timestamp);
};

function employeeActivityUrl(
    int $employeeId,
    int $page
): string {
    return '/office_app/public/hr/employees/activity?'
        . http_build_query([
            'id' => $employeeId,
            'page' => max(1, $page),
        ]);
}

$initial = strtoupper(substr(
    (string) (
        $employee['displayName'] ?? 'E'
    ),
    0,
    1
));
?>

<div class="details-toolbar">
    <a
        href="/office_app/public/hr/employees/view?id=<?= e(
            $employeeId
        ) ?>"
        class="btn btn-secondary"
    >
        Back to employee
    </a>

    <?php if ($canManage): ?>
        <a
            href="/office_app/public/hr/employees/edit?id=<?= e(
                $employeeId
            ) ?>"
            class="btn btn-primary"
        >
            Edit employee
        </a>
    <?php endif; ?>
</div>

<section class="card activity-profile-banner">
    <div class="profile-identity">
        <div class="profile-avatar" aria-hidden="true">
            <?= e($initial) ?>
        </div>

        <div>
            <h2 class="card-title">
                <?= e(
                    $employee['displayName'] ?? ''
                ) ?>
            </h2>
            <p class="profile-username">
                <?= e(
                    $employee['employee_number'] ?? ''
                ) ?>
                &middot;
                <?= e(
                    $employee['job_title'] ?? ''
                ) ?>
            </p>
        </div>
    </div>

    <div class="activity-total">
        <strong>
            <?= e($pagination['total'] ?? 0) ?>
        </strong>
        <span>recorded events</span>
    </div>
</section>

<div class="activity-toolbar">
    <p class="activity-range">
        Showing
        <?= e($pagination['from'] ?? 0) ?>
        &ndash;
        <?= e($pagination['to'] ?? 0) ?>
        of
        <?= e($pagination['total'] ?? 0) ?>
    </p>
</div>

<?php if ($events === []): ?>
    <section
        class="card details-empty activity-empty"
    >
        No audited activity has been recorded for this
        employee.
    </section>
<?php else: ?>
    <ol class="user-timeline">
        <?php foreach ($events as $event): ?>
            <?php
            $tone = (string) (
                $event['tone'] ?? 'information'
            );
            $changes = is_array(
                $event['changes'] ?? null
            )
                ? $event['changes']
                : [];
            ?>

            <li class="user-timeline-event">
                <span
                    class="timeline-marker timeline-marker-<?= e(
                        $tone
                    ) ?>"
                    aria-hidden="true"
                ></span>

                <article class="card timeline-card">
                    <div class="timeline-heading">
                        <div>
                            <span class="timeline-category">
                                Human Resources
                            </span>
                            <h2>
                                <?= e(
                                    $event['label']
                                    ?? 'Recorded activity'
                                ) ?>
                            </h2>
                        </div>

                        <span class="badge badge-<?= e(
                            $tone
                        ) ?>">
                            Audit
                        </span>
                    </div>

                    <p class="timeline-description">
                        <?= e(
                            $event['description'] ?? ''
                        ) ?>
                    </p>

                    <dl class="timeline-metadata">
                        <div>
                            <dt>Time</dt>
                            <dd>
                                <time datetime="<?= e(
                                    $event['created_at'] ?? ''
                                ) ?>">
                                    <?= e($formatDate(
                                        $event['created_at']
                                        ?? null
                                    )) ?>
                                </time>
                            </dd>
                        </div>
                        <div>
                            <dt>Actor</dt>
                            <dd>
                                <?= e(
                                    $event['actor_label']
                                    ?? 'System'
                                ) ?>
                            </dd>
                        </div>
                        <div>
                            <dt>IP address</dt>
                            <dd>
                                <?= e(
                                    $event['ip_address']
                                    ?? 'Not recorded'
                                ) ?>
                            </dd>
                        </div>
                    </dl>

                    <?php if ($changes !== []): ?>
                        <details class="activity-changes">
                            <summary>
                                View <?= e(count($changes)) ?>
                                recorded
                                <?= count($changes) === 1
                                    ? 'change'
                                    : 'changes' ?>
                            </summary>

                            <dl class="activity-change-list">
                                <?php foreach (
                                    $changes as $change
                                ): ?>
                                    <div>
                                        <dt>
                                            <?= e(
                                                $change['field']
                                                ?? ''
                                            ) ?>
                                        </dt>
                                        <dd>
                                            <span>
                                                <?= e(
                                                    $change['old']
                                                    ?? 'Not set'
                                                ) ?>
                                            </span>
                                            <span aria-hidden="true">
                                                &rarr;
                                            </span>
                                            <strong>
                                                <?= e(
                                                    $change['new']
                                                    ?? 'Not set'
                                                ) ?>
                                            </strong>
                                        </dd>
                                    </div>
                                <?php endforeach; ?>
                            </dl>
                        </details>
                    <?php endif; ?>

                    <?php if (!empty(
                        $event['user_agent']
                    )): ?>
                        <details class="activity-context">
                            <summary>Request context</summary>
                            <p>
                                <?= e(
                                    $event['user_agent']
                                ) ?>
                            </p>
                        </details>
                    <?php endif; ?>
                </article>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php if (
    ($pagination['lastPage'] ?? 1) > 1
): ?>
    <?php
    $page = (int) ($pagination['page'] ?? 1);
    $lastPage = (int) (
        $pagination['lastPage'] ?? 1
    );
    ?>

    <nav
        class="pagination"
        aria-label="Employee activity pagination"
    >
        <?php if ($page > 1): ?>
            <a
                class="pagination-link"
                href="<?= e(employeeActivityUrl(
                    $employeeId,
                    $page - 1
                )) ?>"
            >
                Previous
            </a>
        <?php endif; ?>

        <span class="pagination-status">
            Page <?= e($page) ?>
            of <?= e($lastPage) ?>
        </span>

        <?php if ($page < $lastPage): ?>
            <a
                class="pagination-link"
                href="<?= e(employeeActivityUrl(
                    $employeeId,
                    $page + 1
                )) ?>"
            >
                Next
            </a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
