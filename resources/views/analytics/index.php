<?php

declare(strict_types=1);

$configuration = is_array($data['configuration'] ?? null)
    ? $data['configuration']
    : null;
$canConfigure = in_array(
    'analytics.configure',
    $data['user']['permissions'] ?? [],
    true
);
?>
<div class="module-stack analytics-workspace">
    <section class="card erp-record-header">
        <div>
            <p class="erp-eyebrow">Company analytics</p>
            <h2><?= e($configuration['report_name'] ?? 'Power BI Analytics') ?></h2>
            <p>Reporting is resolved from the active company's configured workspace.</p>
        </div>
        <?php if ($canConfigure): ?>
            <a
                class="btn btn-secondary btn-compact"
                href="<?= e(appBasePath() . '/administration/analytics') ?>"
            >Configure Analytics</a>
        <?php endif; ?>
    </section>

    <?php if (($data['state'] ?? '') !== 'ready'): ?>
        <section class="card analytics-setup-state">
            <span class="erp-status-badge erp-status-warning">
                Configuration required
            </span>
            <h2>Power BI is not ready for this company</h2>
            <p>
                Analytics is enabled, but its company-specific report
                configuration is incomplete or invalid. Contact your company
                administrator.
            </p>
            <?php if ($canConfigure): ?>
                <a
                    class="btn btn-primary btn-compact"
                    href="<?= e(appBasePath() . '/administration/analytics') ?>"
                >Open Configuration</a>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="card analytics-report-card">
            <header class="erp-section-header">
                <div>
                    <p class="erp-eyebrow">Power BI report</p>
                    <h2><?= e($configuration['report_name']) ?></h2>
                </div>
                <span class="erp-status-badge erp-status-success">Ready</span>
            </header>
            <div class="analytics-report-frame">
                <iframe
                    title="<?= e($configuration['report_name']) ?>"
                    src="<?= e($data['embedUrl']) ?>"
                    loading="lazy"
                    referrerpolicy="strict-origin-when-cross-origin"
                    allowfullscreen
                ></iframe>
            </div>
        </section>
    <?php endif; ?>
</div>
