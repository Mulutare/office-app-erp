<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$profile = is_array($data['profile'] ?? null)
    ? $data['profile']
    : [];
$events = is_array($data['events'] ?? null)
    ? $data['events']
    : [];
$filters = is_array($data['filters'] ?? null)
    ? $data['filters']
    : [];
$pagination = is_array(
    $data['pagination'] ?? null
)
    ? $data['pagination']
    : [];
$canManageUsers = !empty(
    $data['canManageUsers']
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

function userActivityUrl(
    int $userId,
    array $filters,
    array $overrides = []
): string {
    $query = array_merge(
        [
            'id' => $userId,
        ],
        $filters,
        $overrides
    );

    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }

    return '/office_app/public/administration/users/activity?'
        . http_build_query($query);
}

$userId = (int) ($profile['user_id'] ?? 0);
$typeOptions = [
    'all' => 'All activity',
    'authentication' => 'Authentication',
    'administration' => 'Administration',
];
?>

<div class="details-toolbar">
    <a
        href="<?= $canManageUsers
            ? '/office_app/public/administration/users/view?id='
                . e($userId)
            : '/office_app/public/administration' ?>"
        class="btn btn-secondary"
    >
        <?= $canManageUsers
            ? 'Back to user'
            : 'Back to administration' ?>
    </a>
</div>

<section class="card activity-profile-banner">
    <div class="profile-identity">
        <div class="profile-avatar" aria-hidden="true">
            <?= e(strtoupper(substr(
                (string) (
                    $profile['display_name'] ?? 'U'
                ),
                0,
                1
            ))) ?>
        </div>

        <div>
            <h2 class="card-title">
                <?= e($profile['display_name'] ?? '') ?>
            </h2>
            <p class="profile-username">
                @<?= e($profile['username'] ?? '') ?>
            </p>
        </div>
    </div>

    <div class="activity-total">
        <strong><?= e(
            $pagination['total'] ?? 0
        ) ?></strong>
        <span>recorded events</span>
    </div>
</section>

<section class="activity-toolbar">
    <form
        method="get"
        action="/office_app/public/administration/users/activity"
        class="filter-form"
    >
        <input
            type="hidden"
            name="id"
            value="<?= e($userId) ?>"
        >

        <div class="form-field">
            <label for="activity-type">
                Activity type
            </label>

            <select
                id="activity-type"
                name="type"
            >
                <?php foreach (
                    $typeOptions as $value => $label
                ): ?>
                    <option
                        value="<?= e($value) ?>"
                        <?= (
                            $filters['type'] ?? 'all'
                        ) === $value
                            ? 'selected'
                            : '' ?>
                    >
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-actions">
            <button
                type="submit"
                class="btn btn-primary"
            >
                Apply filter
            </button>

            <a
                href="<?= e(userActivityUrl(
                    $userId,
                    ['type' => 'all']
                )) ?>"
                class="btn btn-secondary"
            >
                Reset
            </a>
        </div>
    </form>

    <p class="activity-range">
        Showing
        <?= e($pagination['from'] ?? 0) ?>
        –
        <?= e($pagination['to'] ?? 0) ?>
        of
        <?= e($pagination['total'] ?? 0) ?>
    </p>
</section>

<?php if ($events === []): ?>
    <section class="card details-empty activity-empty">
        No activity matches the selected filter.
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
                                <?= e(ucwords((string) (
                                    $event['category']
                                    ?? 'activity'
                                ))) ?>
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
                            <?= e(
                                ($event['source'] ?? '')
                                === 'login_attempt'
                                    ? 'Authentication'
                                    : 'Audit'
                            ) ?>
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
                                    $event['occurred_at'] ?? ''
                                ) ?>">
                                    <?= e($formatDate(
                                        $event['occurred_at']
                                        ?? null
                                    )) ?>
                                </time>
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
                        <div>
                            <dt>Actor</dt>
                            <dd>
                                <?= e(
                                    $event['actor_label']
                                    ?? 'System'
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
                                            <span
                                                aria-hidden="true"
                                            >
                                                →
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
        aria-label="User activity pagination"
    >
        <?php if ($page > 1): ?>
            <a
                class="pagination-link"
                href="<?= e(userActivityUrl(
                    $userId,
                    $filters,
                    ['page' => $page - 1]
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
                href="<?= e(userActivityUrl(
                    $userId,
                    $filters,
                    ['page' => $page + 1]
                )) ?>"
            >
                Next
            </a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
