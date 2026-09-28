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
<?php view('administration.list-controls',['listing'=>$data['listing'],'entity'=>'audit','path'=>appBasePath().'/administration/audit-logs']); ?>
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
            –
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
                                ·
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

    <?php view('components.list-pagination',['query'=>$data['listing']['list']['query'],'pagination'=>$pagination,'path'=>appBasePath().'/administration/audit-logs']); ?>
</section>
