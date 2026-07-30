<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null)
    ? $data
    : [];
$overview = is_array(
    $data['overview'] ?? null
)
    ? $data['overview']
    : [];
$company = is_array(
    $overview['company'] ?? null
)
    ? $overview['company']
    : [];
$metrics = is_array(
    $overview['metrics'] ?? null
)
    ? $overview['metrics']
    : [];
$stages = is_array(
    $overview['stages'] ?? null
)
    ? $overview['stages']
    : [];
$nextAction = is_array(
    $overview['nextAction'] ?? null
)
    ? $overview['nextAction']
    : null;
$capabilities = is_array(
    $data['capabilities'] ?? null
)
    ? $data['capabilities']
    : [];
$progress = max(
    0,
    min(
        100,
        (int) ($overview['progress'] ?? 0)
    )
);

$stageCapability = static function (
    string $key,
    array $available
): bool {
    if ($key === 'localization') {
        return false;
    }

    return !empty($available[$key]);
};
?>

<section class="org-setup-hero">
    <div class="org-setup-hero-copy">
        <span class="section-kicker">
            Organization intelligence
        </span>

        <h2>
            Build a controlled workforce foundation
            that scales internationally.
        </h2>

        <p>
            OfficeApp measures the active company only.
            Complete each stage in sequence to keep
            locations, reporting lines, headcount and
            employee history consistent.
        </p>

        <div class="org-standard-strip">
            <span>
                <small>Country</small>
                <strong>
                    <?= e(
                        $company['countryCode']
                        ?? 'Not set'
                    ) ?>
                </strong>
            </span>
            <span>
                <small>Base currency</small>
                <strong>
                    <?= e(
                        $company['currency']
                        ?? 'Not set'
                    ) ?>
                </strong>
            </span>
            <span>
                <small>Company timezone</small>
                <strong>
                    <?= e(
                        $company['timezone']
                        ?? 'Not set'
                    ) ?>
                </strong>
            </span>
        </div>
    </div>

    <div
        class="org-readiness-ring"
        style="--readiness-progress:
            <?= e($progress) ?>%;"
        aria-label="Organization readiness
            <?= e($progress) ?> percent"
    >
        <div>
            <strong><?= e($progress) ?>%</strong>
            <span>
                <?= e(
                    $overview['readinessLabel']
                    ?? 'Setup required'
                ) ?>
            </span>
            <small>
                <?= e(
                    $overview['completedStages']
                    ?? 0
                ) ?>
                of
                <?= e(
                    $overview['totalStages']
                    ?? count($stages)
                ) ?>
                stages
            </small>
        </div>
    </div>
</section>

<section
    class="org-kpi-grid"
    aria-label="Organization readiness metrics"
>
    <article class="card org-kpi-card">
        <span>Active locations</span>
        <strong>
            <?= e($metrics['branches_active'] ?? 0) ?>
        </strong>
        <small>
            <?= e(
                $metrics[
                    'head_offices_active'
                ] ?? 0
            ) ?>
            head office
        </small>
    </article>

    <article class="card org-kpi-card">
        <span>Departments</span>
        <strong>
            <?= e(
                $metrics[
                    'departments_active'
                ] ?? 0
            ) ?>
        </strong>
        <small>Active organization units</small>
    </article>

    <article class="card org-kpi-card">
        <span>Job architecture</span>
        <strong>
            <?= e(
                $metrics[
                    'job_titles_active'
                ] ?? 0
            ) ?>
        </strong>
        <small>Standardized job titles</small>
    </article>

    <article class="card org-kpi-card">
        <span>Approved headcount</span>
        <strong>
            <?= e(
                $metrics[
                    'approved_headcount'
                ] ?? 0
            ) ?>
        </strong>
        <small>
            <?= e(
                $metrics[
                    'vacant_headcount'
                ] ?? 0
            ) ?>
            available capacity
        </small>
    </article>

    <article class="card org-kpi-card">
        <span>Position coverage</span>
        <strong>
            <?= e(
                $metrics[
                    'placement_coverage'
                ] ?? 0
            ) ?>%
        </strong>
        <small>
            <?= e(
                $metrics[
                    'assigned_employees'
                ] ?? 0
            ) ?>
            of
            <?= e(
                $metrics[
                    'active_employees'
                ] ?? 0
            ) ?>
            employees placed
        </small>
    </article>

    <article class="card org-kpi-card">
        <span>Reporting coverage</span>
        <strong>
            <?= e(
                $metrics[
                    'reporting_coverage'
                ] ?? 0
            ) ?>%
        </strong>
        <small>
            Top-level leader exemption applied
        </small>
    </article>
</section>

<?php if ($nextAction !== null): ?>
    <section class="card org-next-action">
        <div class="org-next-action-icon" aria-hidden="true">
            NX
        </div>

        <div>
            <span class="section-kicker">
                Recommended next action
            </span>
            <h2>
                <?= e($nextAction['title'] ?? '') ?>
            </h2>
            <p>
                <?= e(
                    $nextAction['description']
                    ?? ''
                ) ?>
            </p>
        </div>

        <?php
        $nextKey = (string) (
            $nextAction['key'] ?? ''
        );
        $nextPath = (string) (
            $nextAction['path'] ?? ''
        );
        ?>

        <?php if (
            $nextPath !== ''
            && $stageCapability(
                $nextKey,
                $capabilities
            )
        ): ?>
            <a
                href="<?= e($nextPath) ?>"
                class="btn btn-primary"
            >
                <?= e(
                    $nextAction['actionLabel']
                    ?? 'Continue setup'
                ) ?>
            </a>
        <?php else: ?>
            <span class="org-permission-note">
                Authorized administrator required
            </span>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="card org-next-action is-complete">
        <div class="org-next-action-icon" aria-hidden="true">
            OK
        </div>

        <div>
            <span class="section-kicker">
                Readiness achieved
            </span>
            <h2>
                Organization foundation is complete.
            </h2>
            <p>
                Continue monitoring headcount, reporting
                lines and employee placement as the company
                grows or enters new countries.
            </p>
        </div>
    </section>
<?php endif; ?>

<section class="org-stage-section">
    <div class="section-heading">
        <div>
            <span class="section-kicker">
                Guided implementation
            </span>
            <h2>Organization readiness stages</h2>
        </div>

        <p>
            Each stage uses current tenant data and is
            recalculated whenever this page opens.
        </p>
    </div>

    <ol class="org-stage-list">
        <?php foreach (
            $stages as $index => $stage
        ): ?>
            <?php
            if (!is_array($stage)) {
                continue;
            }

            $stageKey = (string) (
                $stage['key'] ?? ''
            );
            $stagePath = (string) (
                $stage['path'] ?? ''
            );
            $complete = !empty(
                $stage['complete']
            );
            ?>

            <li
                class="card org-stage-card
                    <?= $complete
                        ? 'is-ready'
                        : 'needs-action' ?>"
            >
                <div class="org-stage-number">
                    <?= e(
                        str_pad(
                            (string) ($index + 1),
                            2,
                            '0',
                            STR_PAD_LEFT
                        )
                    ) ?>
                </div>

                <div class="org-stage-content">
                    <div class="org-stage-heading">
                        <h3>
                            <?= e(
                                $stage['title'] ?? ''
                            ) ?>
                        </h3>

                        <span
                            class="org-stage-status
                                <?= $complete
                                    ? 'is-ready'
                                    : 'needs-action' ?>"
                        >
                            <?= e(
                                $stage[
                                    'statusLabel'
                                ] ?? ''
                            ) ?>
                        </span>
                    </div>

                    <p>
                        <?= e(
                            $stage['description']
                            ?? ''
                        ) ?>
                    </p>

                    <strong class="org-stage-metric">
                        <?= e(
                            $stage['metric'] ?? ''
                        ) ?>
                    </strong>
                </div>

                <div class="org-stage-action">
                    <?php if (
                        $stagePath !== ''
                        && $stageCapability(
                            $stageKey,
                            $capabilities
                        )
                    ): ?>
                        <a
                            href="<?= e($stagePath) ?>"
                            class="workspace-link"
                        >
                            <?= e(
                                $stage[
                                    'actionLabel'
                                ] ?? 'Open'
                            ) ?>
                        </a>
                    <?php elseif (
                        !$complete
                    ): ?>
                        <span class="org-permission-note">
                            Administrator required
                        </span>
                    <?php else: ?>
                        <span class="org-stage-verified">
                            Verified
                        </span>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
</section>

<section class="org-governance-note">
    <strong>Governance rule</strong>
    <p>
        Positions remain controlled master data. Employee
        assignments cannot use free-text positions because
        effective-dated history, approved headcount,
        tenant isolation and audit evidence depend on
        consistent organization records.
    </p>
</section>
