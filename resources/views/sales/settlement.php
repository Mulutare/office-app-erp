<?php

declare(strict_types=1);

$settlement = $data['settlement'];
$permissions = $data['user']['permissions'] ?? [];
$can = static fn (string $permission): bool => in_array($permission, $permissions, true);
$money = static fn (mixed $value): string => $settlement['currency'] . ' ' . number_format((float) $value, 2);
$humanize = static fn (mixed $value): string => ucwords(str_replace('_', ' ', (string) $value));
$statusTone = static fn (string $status): string => match ($status) {
    'matched', 'closed', 'approved' => 'success',
    'partial', 'awaiting_confirmation', 'submitted' => 'warning',
    'mismatch', 'review_required', 'returned', 'cancelled' => 'danger',
    default => 'neutral',
};
$maskAccount = static fn (string $account): string => strlen($account) <= 4 ? $account : '••••' . substr($account, -4);
$workflow = (string) $settlement['workflow_status'];
$reconciliation = (string) $settlement['reconciliation_status'];
$hasWorkflowAction =
    ($workflow === 'draft' && $can('sales.settlements.submit'))
    || ($workflow === 'submitted' && $can('sales.settlements.review'))
    || ($workflow === 'supervisor_reviewed' && $can('finance.settlements.reconcile'))
    || ($workflow === 'finance_reconciled' && $can('finance.settlements.approve'));
?>
<div class="module-stack settlement-workspace">
    <?php if (is_array($data['notice'] ?? null)): ?><div class="alert alert-success"><?= e($data['notice']['message'] ?? '') ?></div><?php endif; ?>
    <?php foreach ((array) ($data['errors'] ?? []) as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>

    <section class="card erp-record-header">
        <div><a class="erp-back-link" href="<?= e(appBasePath() . '/sales/settlements') ?>">← Settlement history</a><p class="erp-eyebrow">Sales settlement</p><h2><?= e($settlement['settlement_number']) ?></h2><p>Settlement evidence, bank reconciliation and approval history</p></div>
        <div class="erp-record-statuses" aria-label="Settlement status"><span class="erp-status-badge erp-status-<?= e($statusTone($workflow)) ?>"><?= e($humanize($workflow)) ?></span><span class="erp-status-badge erp-status-<?= e($statusTone($reconciliation)) ?>"><?= e($humanize($reconciliation)) ?></span></div>
    </section>

    <div class="erp-document-actions" aria-label="Settlement documents"><a class="btn btn-secondary btn-compact" href="<?= e(appBasePath() . '/sales/settlements/' . $settlement['settlement_id'] . '/deposit-advice.pdf') ?>">Download Deposit Advice PDF</a><?php if (in_array($workflow, ['finance_reconciled', 'approved', 'closed'], true)): ?><a class="btn btn-secondary btn-compact" href="<?= e(appBasePath() . '/sales/settlements/' . $settlement['settlement_id'] . '/reconciliation.pdf') ?>">Download Reconciliation PDF</a><?php endif; ?></div>

    <section class="erp-metric-grid" aria-label="Financial summary">
        <?php foreach ([['Expected Deposit', $settlement['expected_amount'], 'neutral'], ['Bank Confirmed', $settlement['confirmed_amount'], $statusTone($reconciliation)], ['Variance', $settlement['variance_amount'], abs((float) $settlement['variance_amount']) < 0.005 ? 'success' : 'danger'], ['Remaining', $settlement['remaining_amount'], (float) $settlement['remaining_amount'] === 0.0 ? 'success' : 'warning']] as [$label, $value, $tone]): ?>
            <article class="card erp-metric-card erp-metric-<?= e($tone) ?>"><span><?= e($label) ?></span><strong class="erp-money"><?= e($money($value)) ?></strong></article>
        <?php endforeach; ?>
    </section>

    <?php if ($reconciliation !== 'matched' && (float) $settlement['confirmed_amount'] > 0): ?><div class="alert alert-danger" role="alert"><strong>Review required.</strong> A partial or mismatched bank amount cannot close without resolution.</div><?php endif; ?>

    <section class="card erp-section-card"><header class="erp-section-header"><div><p class="erp-eyebrow">Record details</p><h2>Settlement Metadata</h2></div></header><dl class="erp-info-grid">
        <div><dt>Settlement</dt><dd><?= e($settlement['settlement_number']) ?></dd></div><div><dt>Bank</dt><dd><?= e($settlement['bank_name']) ?></dd></div><div><dt>Account</dt><dd><?= e($settlement['account_name'] . ' · ' . $maskAccount((string) $settlement['account_number'])) ?></dd></div><div><dt>Currency</dt><dd><?= e($settlement['currency']) ?></dd></div><div><dt>Workflow</dt><dd><?= e($humanize($workflow)) ?></dd></div><div><dt>Reconciliation</dt><dd><?= e($humanize($reconciliation)) ?></dd></div>
        <?php foreach (['created_at' => 'Created', 'submitted_at' => 'Submitted', 'supervisor_reviewed_at' => 'Supervisor Reviewed', 'finance_reconciled_at' => 'Finance Reconciled', 'approved_at' => 'Approved', 'closed_at' => 'Closed'] as $field => $label): ?><div><dt><?= e($label) ?></dt><dd><?= e($settlement[$field] ?? '—') ?></dd></div><?php endforeach; ?>
    </dl></section>

    <section class="card table-card erp-section-card"><header class="erp-section-header"><div><p class="erp-eyebrow">Deposit basis</p><h2>Included Sales Payments</h2></div><span><?= e(count($settlement['lines'])) ?> payment(s)</span></header><div class="table-responsive"><table class="data-table erp-data-table"><thead><tr><th>Order</th><th>Customer</th><th>Payment</th><th>Date</th><th>Reference</th><th class="erp-money-column">Amount</th></tr></thead><tbody><?php foreach ($settlement['lines'] as $line): ?><tr><td><strong><?= e($line['order_number']) ?></strong></td><td><?= e($line['customer_name']) ?></td><td><?= e($line['payment_number']) ?></td><td><?= e($line['payment_date']) ?></td><td><?= e($line['reference_number'] ?? '—') ?></td><td class="erp-money erp-money-column"><?= e($money($line['amount'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section>

    <?php if ($hasWorkflowAction): ?><section class="card erp-action-bar"><div><p class="erp-eyebrow">Next step</p><h2>Workflow Action</h2></div><div class="erp-action-group">
        <?php if ($workflow === 'draft' && $can('sales.settlements.submit')): ?><form method="post" action="<?= e(appBasePath() . '/sales/settlements/' . $settlement['settlement_id'] . '/submit') ?>"><?= csrfField() ?><button class="btn btn-primary">Submit Settlement</button></form><?php endif; ?>
        <?php if ($workflow === 'submitted' && $can('sales.settlements.review')): ?><form method="post" action="<?= e(appBasePath() . '/sales/settlements/' . $settlement['settlement_id'] . '/review') ?>"><?= csrfField() ?><button class="btn btn-primary">Review Settlement</button></form><?php endif; ?>
        <?php if ($workflow === 'supervisor_reviewed' && $can('finance.settlements.reconcile')): ?><form method="post" action="<?= e(appBasePath() . '/sales/settlements/' . $settlement['settlement_id'] . '/reconcile') ?>"><?= csrfField() ?><button class="btn btn-primary">Reconcile</button></form><?php endif; ?>
        <?php if ($workflow === 'finance_reconciled' && $can('finance.settlements.approve')): ?><form method="post" action="<?= e(appBasePath() . '/sales/settlements/' . $settlement['settlement_id'] . '/approve') ?>"><?= csrfField() ?><button class="btn btn-primary">Approve</button></form><?php endif; ?>
    </div></section><?php endif; ?>

    <?php if (in_array($workflow, ['submitted', 'supervisor_reviewed'], true) && $can('finance.bank_confirmations.create')): ?><section class="card erp-section-card"><header class="erp-section-header"><div><p class="erp-eyebrow">Financial evidence</p><h2>Add Bank Confirmation</h2><p>Upload original bank or mobile-wallet evidence (PDF, JPEG or PNG).</p></div></header><form method="post" enctype="multipart/form-data" action="<?= e(appBasePath() . '/sales/settlements/' . $settlement['settlement_id'] . '/confirmations') ?>"><?= csrfField() ?><div class="erp-form-grid erp-form-grid-three"><label class="form-field">Bank Reference<input name="bank_reference" maxlength="190" autocomplete="off" required></label><label class="form-field">Transaction Date<input type="date" name="transaction_date" required></label><label class="form-field">Confirmed Amount<input type="number" name="confirmed_amount" min="0.01" step="0.01" inputmode="decimal" required></label><label class="form-field erp-field-wide">Original Bank Receipt<input type="file" name="evidence" accept="application/pdf,image/png,image/jpeg" required><small>Maximum size and file-type validation remain enforced by the server.</small></label></div><div class="erp-form-actions"><button class="btn btn-primary">Add Bank Confirmation</button></div></form></section><?php endif; ?>

    <section class="card table-card erp-section-card"><header class="erp-section-header"><div><p class="erp-eyebrow">Evidence register</p><h2>Bank Confirmations</h2></div><span><?= e(count($settlement['confirmations'])) ?> confirmation(s)</span></header><div class="table-responsive"><table class="data-table erp-data-table"><thead><tr><th>Reference</th><th>Transaction Date</th><th class="erp-money-column">Confirmed Amount</th><th>Evidence</th><th>Added By</th></tr></thead><tbody><?php if ($settlement['confirmations'] === []): ?><tr><td colspan="5" class="empty-state">No bank confirmation has been added.</td></tr><?php endif; ?><?php foreach ($settlement['confirmations'] as $confirmation): ?><tr><td><strong><?= e($confirmation['bank_reference']) ?></strong></td><td><?= e($confirmation['transaction_date']) ?></td><td class="erp-money erp-money-column"><?= e($money($confirmation['confirmed_amount'])) ?></td><td><a class="btn btn-secondary btn-compact" href="<?= e(appBasePath() . '/sales/settlements/' . $settlement['settlement_id'] . '/confirmations/' . $confirmation['confirmation_id'] . '/evidence') ?>">View Receipt</a></td><td><?= e($confirmation['creator_name']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>

    <section class="card table-card erp-section-card"><header class="erp-section-header"><div><p class="erp-eyebrow">Audit history</p><h2>Activity Timeline</h2></div></header><div class="table-responsive"><table class="data-table erp-data-table erp-timeline-table"><thead><tr><th>Date / Time</th><th>Action</th><th>Actor</th><th>Transition</th><th>Reason</th></tr></thead><tbody><?php foreach ($settlement['events'] as $event): ?><tr><td><?= e($event['created_at']) ?></td><td><span class="erp-status-badge erp-status-neutral"><?= e($humanize($event['action'])) ?></span></td><td><?= e($event['actor_name'] ?? 'System') ?></td><td><span class="erp-transition"><?= e($humanize($event['from_status'] ?? '—')) ?> <span aria-hidden="true">→</span> <?= e($humanize($event['to_status'] ?? '—')) ?></span></td><td class="erp-reason-cell"><?= e($event['reason'] ?: '—') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</div>
