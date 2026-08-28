<?php

declare(strict_types=1);

$trace = is_array($data['workflowTrace'] ?? null)
    ? $data['workflowTrace']
    : null;

if ($trace === null) {
    return;
}

$icons = [
    'completed' => '✓',
    'in_progress' => '●',
    'partial' => '◐',
    'not_started' => '○',
    'blocked' => '!',
];
?>
<section class="card sales-workflow-trace" aria-labelledby="sales-workflow-title">
    <header class="sales-workflow-heading">
        <div>
            <p class="erp-eyebrow">Sales progress</p>
            <h2 id="sales-workflow-title"><?= e($trace['chain_reference'] ?? 'Sales workflow') ?></h2>
        </div>
        <?php if (!empty($trace['next_action'])): ?>
            <p class="sales-workflow-next"><strong>Next:</strong> <?= e($trace['next_action']) ?></p>
        <?php endif; ?>
    </header>

    <ol class="sales-workflow-stages">
        <?php foreach ((array) ($trace['stages'] ?? []) as $stage): ?>
            <?php
            $status = (string) ($stage['status'] ?? 'not_started');
            $records = is_array($stage['records'] ?? null) ? $stage['records'] : [];
            $current = !empty($stage['current']);
            ?>
            <li class="sales-workflow-stage sales-workflow-stage-<?= e($status) ?><?= $current ? ' is-current' : '' ?>">
                <div class="sales-workflow-node">
                    <?php if (!empty($stage['clickable']) && is_string($stage['url'] ?? null)): ?>
                        <a href="<?= e($stage['url']) ?>" class="sales-workflow-stage-link">
                    <?php else: ?>
                        <span class="sales-workflow-stage-link">
                    <?php endif; ?>
                        <span class="sales-workflow-icon" aria-hidden="true"><?= e($icons[$status] ?? '○') ?></span>
                        <span class="sales-workflow-label"><?= e($stage['label'] ?? '') ?></span>
                        <?php if ($current): ?><span class="sales-workflow-current">Current</span><?php endif; ?>
                        <small><?= e($stage['reference'] ?? '') ?></small>
                    <?php if (!empty($stage['clickable']) && is_string($stage['url'] ?? null)): ?>
                        </a>
                    <?php else: ?>
                        </span>
                    <?php endif; ?>

                    <?php if (count($records) > 1): ?>
                        <details class="sales-workflow-records">
                            <summary>View <?= e(count($records)) ?> documents</summary>
                            <ul>
                                <?php foreach ($records as $record): ?>
                                    <li>
                                        <?php if (!empty($record['clickable']) && is_string($record['url'] ?? null)): ?>
                                            <a href="<?= e($record['url']) ?>"><?= e($record['reference'] ?? '') ?></a>
                                        <?php else: ?>
                                            <span><?= e($record['reference'] ?? '') ?></span>
                                        <?php endif; ?>
                                        <small><?= e(str_replace('_', ' ', (string) ($record['status'] ?? ''))) ?></small>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>

    <?php if (!empty($trace['related_records'])): ?>
        <div class="sales-workflow-related">
            <strong>Related records</strong>
            <?php foreach ((array) $trace['related_records'] as $record): ?>
                <?php if (!empty($record['clickable']) && is_string($record['url'] ?? null)): ?>
                    <a href="<?= e($record['url']) ?>">↳ <?= e($record['reference'] ?? '') ?></a>
                <?php else: ?>
                    <span>↳ <?= e($record['reference'] ?? '') ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
