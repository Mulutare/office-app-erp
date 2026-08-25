<?php
declare(strict_types=1);
$tasks = is_array($data['actionRequiredItems'] ?? null) ? $data['actionRequiredItems'] : [];
$filtered = !empty($data['actionRequiredFilter']);
$module = (string) ($data['actionRequiredModule'] ?? '');
$section = (string) ($data['actionRequiredSection'] ?? '');
$count = (int) ($data['actionRequiredCounts'][$module][$section] ?? 0);
if ($tasks === [] && !$filtered) return;
?>
<section class="action-task-panel<?= $filtered ? ' is-filtered' : '' ?>" aria-label="Action required records">
    <div class="action-task-panel-heading">
        <strong>Action required</strong>
        <span><?= e(count($tasks)) ?> <?= count($tasks) === 1 ? 'record' : 'records' ?></span>
        <?php if ($filtered): ?><a class="btn btn-secondary btn-compact" href="<?= e(preg_replace('/([?&])task_filter=action_required(&|$)/', '$1', (string) ($_SERVER['REQUEST_URI'] ?? '')) ?? '') ?>">Show all</a><?php endif; ?>
    </div>
    <?php if ($filtered && count($tasks) !== $count): ?><p class="task-integrity-warning" role="alert">The task list could not be reconciled with its badge. Please refresh before acting.</p><?php endif; ?>
    <?php if ($tasks === []): ?><p class="empty-state">No records currently require an action you are authorized to perform.</p><?php endif; ?>
    <ul class="action-task-list">
        <?php foreach ($tasks as $task): ?>
            <li data-action-task-reference="<?= e((string) $task['reference']) ?>" data-action-task-next="<?= e((string) $task['next_action']) ?>"><a href="<?= e((string) $task['url']) ?>"><strong><?= e((string) $task['reference']) ?></strong><span class="record-action-chip">Action required</span><small>Next: <?= e((string) $task['next_action']) ?></small></a></li>
        <?php endforeach; ?>
    </ul>
</section>
