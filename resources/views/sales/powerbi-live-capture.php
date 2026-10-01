<?php

declare(strict_types=1);

$warehouses = is_array($data['warehouses'] ?? null) ? $data['warehouses'] : [];
$metric = is_array($data['metric'] ?? null) ? $data['metric'] : [];
$recent = is_array($data['recentMetrics'] ?? null) ? $data['recentMetrics'] : [];
$control = is_array($data['reportingControl'] ?? null) ? $data['reportingControl'] : [];
$selectedWarehouseId = (int)($data['selectedWarehouseId'] ?? 0);
$selectedDate = (string)($data['selectedDate'] ?? date('Y-m-d'));
$notice = is_array($data['notice'] ?? null) ? $data['notice'] : null;
$error = (string)($data['error'] ?? '');
$value = static fn(string $field): string => isset($metric[$field]) && $metric[$field] !== null ? (string)$metric[$field] : '';
$mode = (string)($control['reporting_mode'] ?? 'UNKNOWN');
?>

<div class="sales-workspace">
<header class="page-header">
    <div>
        <p class="eyebrow">Sales reporting</p>
        <h1>Daily Shop Metrics</h1>
        <p>Capture only the shop-level facts that are not already derived from stock, quick sales, GRVs, or bank-confirmed ERP data.</p>
    </div>
</header>

<?php if ($notice !== null): ?>
    <div class="alert alert-success" role="status"><?= e($notice['message'] ?? '') ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<section class="card erp-section-card">
    <header class="erp-section-header">
        <div><p class="erp-eyebrow">Reporting guard</p><h2>Current Power BI Mode</h2></div>
        <span class="badge badge-muted"><?= e($mode) ?></span>
    </header>
    <p>These entries are stored in the ERP database immediately. They will not replace historical Power BI data until an approved live cutover is configured.</p>
</section>

<?php if ($warehouses !== []): ?>
<section class="card erp-section-card">
    <header class="erp-section-header"><div><p class="erp-eyebrow">Shop/day facts</p><h2>Capture Daily Metrics</h2></div></header>

    <form method="get" action="<?= e(appBasePath() . '/sales/daily-shop-metrics') ?>" class="finance-filter-form">
        <div class="form-field"><label>Shop</label><select name="warehouse_id"><?php foreach ($warehouses as $warehouse): ?><option value="<?= e($warehouse['warehouse_id']) ?>"<?= (int)$warehouse['warehouse_id'] === $selectedWarehouseId ? ' selected' : '' ?>><?= e($warehouse['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-field"><label>Report date</label><input type="date" name="date" value="<?= e($selectedDate) ?>" max="<?= e(date('Y-m-d')) ?>"></div>
        <button class="btn btn-secondary">Load</button>
    </form>

    <form method="post" action="<?= e(appBasePath() . '/sales/daily-shop-metrics') ?>">
        <?= csrfField() ?>
        <input type="hidden" name="warehouse_id" value="<?= e($selectedWarehouseId) ?>">
        <input type="hidden" name="report_date" value="<?= e($selectedDate) ?>">

        <div class="erp-form-grid erp-form-grid-three">
            <label class="form-field">Manager reported deposit (Birr)<input type="number" min="0" step="0.01" name="manager_reported_deposit_birr" value="<?= e($value('manager_reported_deposit_birr')) ?>"></label>
            <label class="form-field">Reward SIM cards (pieces)<input type="number" min="0" step="1" name="reward_sim_cards_pieces" value="<?= e($value('reward_sim_cards_pieces')) ?>"></label>
            <label class="form-field">Incentive SIM cards (Birr)<input type="number" min="0" step="0.01" name="incentive_sim_cards_birr" value="<?= e($value('incentive_sim_cards_birr')) ?>"></label>
            <label class="form-field">Float airtime incentive (Birr)<input type="number" min="0" step="0.01" name="float_airtime_incentive_birr" value="<?= e($value('float_airtime_incentive_birr')) ?>"></label>
            <label class="form-field">Total float returned (Birr)<input type="number" min="0" step="0.01" name="total_float_returned_birr" value="<?= e($value('total_float_returned_birr')) ?>"></label>
            <label class="form-field">Safaricom SIM value borrowed (Birr)<input type="number" min="0" step="0.01" name="safaricom_sim_value_borrowed_birr" value="<?= e($value('safaricom_sim_value_borrowed_birr')) ?>"></label>
            <label class="form-field">Safaricom MiFi borrowed (pieces)<input type="number" min="0" step="1" name="safaricom_mifi_value_borrowed_pcs" value="<?= e($value('safaricom_mifi_value_borrowed_pcs')) ?>"></label>
            <label class="form-field">Returned Birr 10 (pieces)<input type="number" min="0" step="1" name="qty_returned_birr_10_pieces" value="<?= e($value('qty_returned_birr_10_pieces')) ?>"></label>
            <label class="form-field">Returned Birr 15 (pieces)<input type="number" min="0" step="1" name="qty_returned_birr_15_pieces" value="<?= e($value('qty_returned_birr_15_pieces')) ?>"></label>
            <label class="form-field">Returned Birr 20 (pieces)<input type="number" min="0" step="1" name="qty_returned_birr_20_pieces" value="<?= e($value('qty_returned_birr_20_pieces')) ?>"></label>
            <label class="form-field">Returned Birr 25 (pieces)<input type="number" min="0" step="1" name="qty_returned_birr_25_pieces" value="<?= e($value('qty_returned_birr_25_pieces')) ?>"></label>
            <label class="form-field">Returned Birr 50 (pieces)<input type="number" min="0" step="1" name="qty_returned_birr_50_pieces" value="<?= e($value('qty_returned_birr_50_pieces')) ?>"></label>
            <label class="form-field">Returned Birr 100 (pieces)<input type="number" min="0" step="1" name="qty_returned_birr_100_pieces" value="<?= e($value('qty_returned_birr_100_pieces')) ?>"></label>
            <label class="form-field erp-field-wide">Notes<textarea name="notes" maxlength="1000" rows="3"><?= e((string)($metric['notes'] ?? '')) ?></textarea></label>
        </div>

        <div class="alert alert-warning" role="note">
            <strong>Safaricom incentive fields remain locked.</strong>
            “Float Incentive Airtime To Safaricom” and “Float Incentive Refund From Safaricom” are not captured here until their exact legacy meaning is confirmed.
        </div>

        <div class="erp-form-actions"><button class="btn btn-primary">Save Daily Metrics</button></div>
    </form>
</section>
<?php endif; ?>

<section class="card table-card erp-section-card">
    <header class="erp-section-header"><div><p class="erp-eyebrow">Recent capture</p><h2>Recent Daily Metrics</h2></div><span><?= e(count($recent)) ?> row(s)</span></header>
    <div class="table-responsive"><table class="data-table erp-data-table"><thead><tr><th>Date</th><th>Shop</th><th>Manager deposit</th><th>Reward SIM</th><th>SIM incentive</th><th>Float incentive</th><th>Float returned</th><th>Updated</th></tr></thead><tbody>
    <?php if ($recent === []): ?><tr><td colspan="8" class="empty-state">No daily shop metrics have been captured yet.</td></tr><?php endif; ?>
    <?php foreach ($recent as $row): ?><tr>
        <td><?= e($row['report_date']) ?></td><td><?= e($row['shop_name']) ?></td>
        <td><?= e($row['manager_reported_deposit_birr'] ?? '-') ?></td><td><?= e($row['reward_sim_cards_pieces'] ?? '-') ?></td>
        <td><?= e($row['incentive_sim_cards_birr'] ?? '-') ?></td><td><?= e($row['float_airtime_incentive_birr'] ?? '-') ?></td>
        <td><?= e($row['total_float_returned_birr'] ?? '-') ?></td><td><?= e($row['updated_at']) ?></td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
</section>
</div>
