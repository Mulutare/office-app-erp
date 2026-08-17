<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$logs = is_array($data['logs'] ?? null)
    ? $data['logs']
    : [];
$options = is_array($data['options'] ?? null)
    ? $data['options']
    : [];
$filters = is_array($data['filters'] ?? null)
    ? $data['filters']
    : [];
$pagination = is_array(
    $data['pagination'] ?? null
)
    ? $data['pagination']
    : [];
$modules = is_array($options['modules'] ?? null)
    ? $options['modules']
    : [];
$actions = is_array($options['actions'] ?? null)
    ? $options['actions']
    : [];
$actors = is_array($options['actors'] ?? null)
    ? $options['actors']
    : [];

$formatDate = static function (mixed $value): string {
    if (!is_string($value) || trim($value) === '') {
        return 'Unknown time';
    }

    $timestamp = strtotime($value);

    return $timestamp === false
        ? $value
        : date('M j, Y g:i A', $timestamp);
};

function auditLogListUrl(
    array $filters,
    array $overrides = []
): string {
    $query = array_merge(
        $filters,
        $overrides
    );

    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }

    return appBasePath() . '/administration/audit-logs'
        . ($query === []
            ? ''
            : '?' . http_build_query($query));
}
?>

<section class="audit-filter-panel card">
    <form
        method="get"
        action="<?= e(appBasePath()) ?>/administration/audit-logs"
        class="audit-filter-form"
    >
        <div class="form-field audit-search-field">
            <label for="audit-search">
                Search audit records
            </label>
            <input
                id="audit-search"
                name="search"
                type="search"
                value="<?= e(
                    $filters['search'] ?? ''
                ) ?>"
                placeholder="Action, target, actor or IP address"
                maxlength="100"
            >
        </div>

        <div class="form-field">
            <label for="audit-module">Module</label>
            <select id="audit-module" name="module">
                <option value="">All modules</option>
                <?php foreach ($modules as $module): ?>
                    <option
                        value="<?= e($module) ?>"
                        <?= (
                            $filters['module'] ?? ''
                        ) === $module
                            ? 'selected'
                            : '' ?>
                    >
                        <?= e(ucwords($module)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-field">
            <label for="audit-action">Action</label>
            <select id="audit-action" name="action">
                <option value="">All actions</option>
                <?php foreach ($actions as $action): ?>
                    <option
                        value="<?= e($action) ?>"
                        <?= (
                            $filters['action'] ?? ''
                        ) === $action
                            ? 'selected'
                            : '' ?>
                    >
                        <?= e(ucwords(strtolower(
                            str_replace('_', ' ', $action)
                        ))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-field">
            <label for="audit-actor">Actor</label>
            <select id="audit-actor" name="actor">
                <option value="">All actors</option>
                <option
                    value="system"
                    <?= (
                        $filters['actor'] ?? ''
                    ) === 'system'
                        ? 'selected'
                        : '' ?>
                >
                    System / deleted actor
                </option>
                <?php foreach ($actors as $actor): ?>
                    <?php
                    $actorId = (string) (
                        $actor['user_id'] ?? ''
                    );
                    ?>
                    <option
                        value="<?= e($actorId) ?>"
                        <?= (
                            $filters['actor'] ?? ''
                        ) === $actorId
                            ? 'selected'
                            : '' ?>
                    >
                        <?= e(
                            $actor['display_name']
                            ?? $actor['username']
                            ?? 'User'
                        ) ?>
                        (@<?= e(
                            $actor['username'] ?? ''
                        ) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-field">
            <label for="audit-date-from">
                From date
            </label>
            <input
                id="audit-date-from"
                name="date_from"
                type="date"
                value="<?= e(
                    $filters['date_from'] ?? ''
                ) ?>"
            >
        </div>

        <div class="form-field">
            <label for="audit-date-to">
                To date
            </label>
            <input
                id="audit-date-to"
                name="date_to"
                type="date"
                value="<?= e(
                    $filters['date_to'] ?? ''
                ) ?>"
            >
        </div>

        <div class="filter-actions audit-filter-actions">
            <button
                type="submit"
                class="btn btn-primary"
            >
                Apply filters
            </button>
            <a
                href="<?= e(appBasePath()) ?>/administration/audit-logs"
                class="btn btn-secondary"
            >
                Reset
            </a>
        </div>
    </form>
</section>

<section class="card table-card">
    <div class="table-summary">
        <strong>
            <?= e($pagination['total'] ?? 0) ?>
            audit events
        </strong>
        <span>
            Showing
            <?= e($pagination['from'] ?? 0) ?>
            â€“
            <?= e($pagination['to'] ?? 0) ?>
        </span>
    </div>

    <div class="table-responsive">
        <table class="data-table audit-table">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Module</th>
                    <th>Actor</th>
                    <th>Target</th>
                    <th>IP address</th>
                    <th>Time</th>
                    <th class="table-actions-column">
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody>
            <?php if ($logs === []): ?>
                <tr>
                    <td
                        colspan="7"
                        class="empty-state"
                    >
                        No audit events matched the filters.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td>
                            <strong>
                                <?= e(
                                    $log['actionLabel']
                                    ?? 'Recorded event'
                                ) ?>
                            </strong>
                            <small>
                                #<?= e(
                                    $log['audit_log_id']
                                    ?? ''
                                ) ?>
                                Â·
                                <?= e(
                                    $log['action'] ?? ''
                                ) ?>
                            </small>
                        </td>
                        <td>
                            <span class="badge badge-information">
                                <?= e(ucwords((string) (
                                    $log['module'] ?? ''
                                ))) ?>
                            </span>
                        </td>
                        <td>
                            <strong>
                                <?= e(
                                    $log['actorLabel']
                                    ?? 'System'
                                ) ?>
                            </strong>
                            <?php if (!empty(
                                $log['actor_username']
                            )): ?>
                                <small>
                                    @<?= e(
                                        $log['actor_username']
                                    ) ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e(
                                $log['targetLabel']
                                ?? 'No record target'
                            ) ?>
                        </td>
                        <td>
                            <?= e(
                                $log['ip_address']
                                ?? 'Not recorded'
                            ) ?>
                        </td>
                        <td>
                            <?= e($formatDate(
                                $log['created_at'] ?? null
                            )) ?>
                        </td>
                        <td>
                            <a
                                href="<?= e(appBasePath()) ?>/administration/audit-logs/view?id=<?= e(
                                    $log['audit_log_id']
                                    ?? ''
                                ) ?>"
                                class="table-link"
                            >
                                View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

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
            aria-label="Audit log pagination"
        >
            <?php if ($page > 1): ?>
                <a
                    class="pagination-link"
                    href="<?= e(auditLogListUrl(
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
                    href="<?= e(auditLogListUrl(
                        $filters,
                        ['page' => $page + 1]
                    )) ?>"
                >
                    Next
                </a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
