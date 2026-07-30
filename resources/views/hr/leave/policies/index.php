<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$policies = is_array($data['policies'] ?? null)
    ? $data['policies']
    : [];
$summary = is_array($data['summary'] ?? null)
    ? $data['summary']
    : [];
$notice = is_array($data['notice'] ?? null)
    ? $data['notice']
    : null;

$formatDate = static function (mixed $value): string {
    if (!is_string($value) || trim($value) === '') {
        return 'Not recorded';
    }

    $timestamp = strtotime($value);

    return $timestamp === false
        ? $value
        : date('d M Y, H:i', $timestamp);
};
?>

<nav class="workspace-breadcrumb" aria-label="Breadcrumb">
    <a href="/office_app/public/hr">HR</a>
    <span aria-hidden="true">/</span>
    <a href="/office_app/public/hr/leave">Leave</a>
    <span aria-hidden="true">/</span>
    <strong>Policies</strong>
</nav>

<?php if ($notice !== null): ?>
    <div
        class="alert alert-<?= e(
            $notice['type'] ?? 'success'
        ) ?>"
        role="status"
    >
        <?= e($notice['message'] ?? '') ?>
    </div>
<?php endif; ?>

<section class="policy-hero">
    <div>
        <span class="section-kicker">
            Company leave configuration
        </span>
        <h2>Design leave rules for this workspace.</h2>
        <p>
            Control entitlement, approval routing and which
            leave types employees can select. Every rule is
            isolated to the active company.
        </p>
    </div>
    <a
        href="/office_app/public/hr/leave/policies/create"
        class="btn btn-primary"
    >
        Create leave policy
    </a>
</section>

<section class="operations-summary-grid">
    <article class="operations-summary-card is-primary">
        <span>Total policies</span>
        <strong><?= e($summary['total'] ?? 0) ?></strong>
        <small>Configured for this company</small>
    </article>
    <article class="operations-summary-card">
        <span>Available</span>
        <strong><?= e($summary['active'] ?? 0) ?></strong>
        <small>Visible on new requests</small>
    </article>
    <article class="operations-summary-card">
        <span>Approval required</span>
        <strong>
            <?= e(
                $summary['approvalRequired'] ?? 0
            ) ?>
        </strong>
        <small>Routed for a decision</small>
    </article>
    <article class="operations-summary-card">
        <span>Recorded requests</span>
        <strong><?= e($summary['requests'] ?? 0) ?></strong>
        <small>Historical policy usage</small>
    </article>
</section>

<section class="card table-card policy-table-card">
    <div class="table-summary">
        <div>
            <strong>Leave policy catalogue</strong>
            <span>
                Inactive policies remain in history but cannot
                be selected for new requests.
            </span>
        </div>
        <span><?= e(count($policies)) ?> policies</span>
    </div>

    <div class="table-responsive">
        <table class="data-table policy-table">
            <thead>
                <tr>
                    <th>Policy</th>
                    <th>Entitlement</th>
                    <th>Approval</th>
                    <th>Usage</th>
                    <th>Status</th>
                    <th>Updated</th>
                    <th class="table-actions-column">
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody>
            <?php if ($policies === []): ?>
                <tr>
                    <td colspan="7" class="empty-state">
                        No leave policies are configured yet.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($policies as $policy): ?>
                    <tr>
                        <td>
                            <strong>
                                <?= e($policy['name'] ?? '') ?>
                            </strong>
                            <small class="policy-code">
                                <?= e($policy['code'] ?? '') ?>
                            </small>
                        </td>
                        <td>
                            <strong>
                                <?= e(
                                    $policy[
                                        'annual_entitlement'
                                    ] ?? '0.00'
                                ) ?>
                            </strong>
                            <small>days per year</small>
                        </td>
                        <td>
                            <?php if (
                                (
                                    $policy[
                                        'approval_workflow'
                                    ] ?? 'manager'
                                ) !== 'none'
                            ): ?>
                                <span class="badge badge-warning">
                                    <?= e(
                                        $policy[
                                            'approvalWorkflowLabel'
                                        ] ?? 'Manager only'
                                    ) ?>
                                </span>
                                <?php if (!empty(
                                    $policy[
                                        'hr_approver_name'
                                    ]
                                )): ?>
                                    <small>
                                        HR:
                                        <?= e(
                                            $policy[
                                                'hr_approver_name'
                                            ]
                                        ) ?>
                                    </small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge badge-info">
                                    Automatic
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong>
                                <?= e(
                                    $policy[
                                        'request_count'
                                    ] ?? 0
                                ) ?>
                                requests
                            </strong>
                            <small>
                                <?= e(
                                    $policy[
                                        'pending_request_count'
                                    ] ?? 0
                                ) ?>
                                pending
                            </small>
                        </td>
                        <td>
                            <span class="badge <?= !empty(
                                $policy['active']
                            )
                                ? 'badge-success'
                                : 'badge-muted' ?>">
                                <?= !empty($policy['active'])
                                    ? 'Active'
                                    : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <?= e(
                                $formatDate(
                                    $policy['updated_at']
                                        ?? null
                                )
                            ) ?>
                        </td>
                        <td>
                            <a
                                class="table-link"
                                href="/office_app/public/hr/leave/policies/edit?id=<?= e(
                                    $policy[
                                        'leave_type_id'
                                    ] ?? 0
                                ) ?>"
                            >
                                Edit
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="policy-guidance-grid">
    <article class="card">
        <span class="section-kicker">Approval behavior</span>
        <h3>Choose the right control level.</h3>
        <p>
            Approval-required policies create pending requests.
            Policies without approval automatically approve valid
            requests and record that decision in the audit trail.
        </p>
    </article>
    <article class="card">
        <span class="section-kicker">Safe history</span>
        <h3>Deactivate instead of deleting.</h3>
        <p>
            Used policies remain available for reporting.
            A policy with pending requests cannot be deactivated
            until those requests are resolved.
        </p>
    </article>
</section>
