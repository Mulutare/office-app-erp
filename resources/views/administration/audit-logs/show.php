<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$log = is_array($data['log'] ?? null)
    ? $data['log']
    : [];
$changes = is_array($log['changes'] ?? null)
    ? $log['changes']
    : [];
$oldSnapshot = is_array(
    $log['oldSnapshot'] ?? null
)
    ? $log['oldSnapshot']
    : [];
$newSnapshot = is_array(
    $log['newSnapshot'] ?? null
)
    ? $log['newSnapshot']
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
        : date('M j, Y g:i:s A', $timestamp);
};

$actorUserId = (int) (
    $log['user_id'] ?? 0
);
$targetIsUser = (
    $log['table_name'] ?? ''
) === 'users'
    && ctype_digit((string) (
        $log['record_id'] ?? ''
    ));
$targetUserId = $targetIsUser
    ? (int) $log['record_id']
    : 0;
?>

<div class="details-toolbar">
    <a
        href="/office_app/public/administration/audit-logs"
        class="btn btn-secondary"
    >
        Back to audit logs
    </a>
</div>

<section class="card audit-event-summary">
    <div>
        <span class="timeline-category">
            <?= e(ucwords((string) (
                $log['module'] ?? 'audit'
            ))) ?>
        </span>
        <h2 class="card-title">
            <?= e(
                $log['actionLabel']
                ?? 'Recorded audit event'
            ) ?>
        </h2>
        <code>
            <?= e($log['action'] ?? '') ?>
        </code>
    </div>

    <span class="badge badge-<?= e(
        $log['tone'] ?? 'information'
    ) ?>">
        Event #<?= e(
            $log['audit_log_id'] ?? ''
        ) ?>
    </span>
</section>

<div class="audit-metadata-grid">
    <section class="card details-card">
        <h2 class="card-title">Event metadata</h2>
        <dl class="metadata-list">
            <div>
                <dt>Recorded</dt>
                <dd>
                    <?= e($formatDate(
                        $log['created_at'] ?? null
                    )) ?>
                </dd>
            </div>
            <div>
                <dt>Module</dt>
                <dd>
                    <?= e(
                        $log['module']
                        ?? 'Not recorded'
                    ) ?>
                </dd>
            </div>
            <div>
                <dt>Target</dt>
                <dd>
                    <?php if (
                        $targetIsUser
                        && $canManageUsers
                    ): ?>
                        <a
                            href="/office_app/public/administration/users/view?id=<?= e(
                                $targetUserId
                            ) ?>"
                            class="table-link"
                        >
                            <?= e(
                                $log['targetLabel']
                                ?? 'User record'
                            ) ?>
                        </a>
                    <?php else: ?>
                        <?= e(
                            $log['targetLabel']
                            ?? 'No record target'
                        ) ?>
                    <?php endif; ?>
                </dd>
            </div>
        </dl>
    </section>

    <section class="card details-card">
        <h2 class="card-title">Actor</h2>
        <dl class="metadata-list">
            <div>
                <dt>Name</dt>
                <dd>
                    <?php if (
                        $actorUserId > 0
                        && $canManageUsers
                    ): ?>
                        <a
                            href="/office_app/public/administration/users/view?id=<?= e(
                                $actorUserId
                            ) ?>"
                            class="table-link"
                        >
                            <?= e(
                                $log['actorLabel']
                                ?? 'User'
                            ) ?>
                        </a>
                    <?php else: ?>
                        <?= e(
                            $log['actorLabel']
                            ?? 'System'
                        ) ?>
                    <?php endif; ?>
                </dd>
            </div>
            <div>
                <dt>Username</dt>
                <dd>
                    <?= !empty($log['actor_username'])
                        ? '@' . e(
                            $log['actor_username']
                        )
                        : 'Not available' ?>
                </dd>
            </div>
            <div>
                <dt>Email</dt>
                <dd>
                    <?= e(
                        $log['actor_email']
                        ?? 'Not available'
                    ) ?>
                </dd>
            </div>
        </dl>
    </section>

    <section class="card details-card">
        <h2 class="card-title">Request context</h2>
        <dl class="metadata-list">
            <div>
                <dt>IP address</dt>
                <dd>
                    <?= e(
                        $log['ip_address']
                        ?? 'Not recorded'
                    ) ?>
                </dd>
            </div>
            <div>
                <dt>User agent</dt>
                <dd class="audit-user-agent">
                    <?= e(
                        $log['user_agent']
                        ?? 'Not recorded'
                    ) ?>
                </dd>
            </div>
        </dl>
    </section>
</div>

<section class="card details-section">
    <div class="section-heading">
        <div>
            <h2 class="card-title">
                Recorded changes
            </h2>
            <p>
                Values captured when the event was written.
            </p>
        </div>
        <span class="count-pill">
            <?= e(count($changes)) ?>
        </span>
    </div>

    <?php if ($changes === []): ?>
        <p class="details-empty">
            This event did not record field-level changes.
        </p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table audit-change-table">
                <thead>
                    <tr>
                        <th>Field</th>
                        <th>Previous value</th>
                        <th>New value</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($changes as $change): ?>
                    <tr>
                        <td>
                            <strong>
                                <?= e(
                                    $change['field'] ?? ''
                                ) ?>
                            </strong>
                        </td>
                        <td class="audit-old-value">
                            <?= e(
                                $change['old']
                                ?? 'Not set'
                            ) ?>
                        </td>
                        <td>
                            <?= e(
                                $change['new']
                                ?? 'Not set'
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (
        $oldSnapshot !== []
        || $newSnapshot !== []
    ): ?>
        <div class="audit-snapshot-grid">
            <details class="audit-snapshot">
                <summary>Previous snapshot</summary>
                <?php if ($oldSnapshot === []): ?>
                    <p>No previous values were recorded.</p>
                <?php else: ?>
                    <dl>
                        <?php foreach (
                            $oldSnapshot as $item
                        ): ?>
                            <div>
                                <dt>
                                    <?= e(
                                        $item['field'] ?? ''
                                    ) ?>
                                </dt>
                                <dd>
                                    <?= e(
                                        $item['value']
                                        ?? 'Not set'
                                    ) ?>
                                </dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
            </details>

            <details class="audit-snapshot">
                <summary>New snapshot</summary>
                <?php if ($newSnapshot === []): ?>
                    <p>No new values were recorded.</p>
                <?php else: ?>
                    <dl>
                        <?php foreach (
                            $newSnapshot as $item
                        ): ?>
                            <div>
                                <dt>
                                    <?= e(
                                        $item['field'] ?? ''
                                    ) ?>
                                </dt>
                                <dd>
                                    <?= e(
                                        $item['value']
                                        ?? 'Not set'
                                    ) ?>
                                </dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
            </details>
        </div>
    <?php endif; ?>
</section>
