<?php
declare(strict_types=1);
$workspace = $data['workspace'] ?? [];
$filters = $workspace['filters'] ?? [];
$money = ['total_amount','paid_amount','residual_amount','debit_amount','credit_amount','debit','credit','net'];
$render = static function (array $rows, array $columns) use ($money, $workspace, $data): void { ?>
<div class="table-responsive"><table class="data-table"><thead><tr>
<?php foreach ($columns as $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
</tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr>
<?php foreach ($columns as $field => $label): ?><td<?= in_array($field, $money, true) ? ' class="erp-money-column"' : '' ?>><?php
    $source = '';
    if ($field === 'invoice_number' && ($workspace['section'] ?? '') === 'receivables' && (int)($row['invoice_id'] ?? 0) > 0) $source = '/finance/customer-invoices/'.(int)$row['invoice_id'];
    if ($field === 'source_number' && ($workspace['section'] ?? '') === 'ledger' && ctype_digit((string)($row['source_id'] ?? ''))) {
        $sourceId = (int)$row['source_id'];
        if (str_starts_with((string)($row['source_type'] ?? ''), 'expense') && $sourceId > 0) $source = '/finance/expenses#expense-'.$sourceId;
        elseif (str_starts_with((string)($row['source_type'] ?? ''), 'staff_loan') && $sourceId > 0) $source = '/finance/staff-loans/'.$sourceId;
    }
    if ($source !== ''): ?><a class="finance-record-link" href="<?= e(appBasePath().$source) ?>"><?= e((string)$row[$field]) ?> <span aria-hidden="true">↗</span></a><?php else: ?><?= e(in_array($field, $money, true) ? number_format((float)($row[$field] ?? 0), 2) : (string)($row[$field] ?? '')) ?><?php endif; ?></td><?php endforeach; ?>
</tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="<?= count($columns) ?>">No matching posted records.</td></tr><?php endif; ?>
</tbody></table></div>
<?php }; ?>
<?php $section = (string)($workspace['section'] ?? ''); $report = (string)($workspace['selected_report'] ?? ''); ?>
<?php if ($section === 'reports'): ?>
<section class="finance-report-center" aria-label="Report center">
    <h2>Choose a report</h2>
    <div class="finance-report-grid">
        <?php foreach (['Financial statements'=>['trial-balance'=>'Trial Balance','profit-loss'=>'Profit & Loss','balance-sheet'=>'Balance Sheet'], 'Receivables'=>['/finance/accounting/receivables'=>'AR Aging','/finance/statements/customer'=>'Customer Statement'], 'Payables'=>['/finance/accounting/payables'=>'AP Aging','/finance/statements/supplier'=>'Supplier Statement'], 'Banking & accounting'=>['/finance/accounting/cash-bank'=>'Cash / Bank Activity','/finance/accounting/ledger'=>'General Ledger','/finance?section=journals'=>'Journals'], 'Staff loans & reconciliation'=>['/finance/staff-loans'=>'Staff Loan Register','/finance/reconciliation'=>'AR / AP / Loan Reconciliation']] as $group=>$links): ?>
        <div class="finance-report-group"><h3><?= e($group) ?></h3>
            <?php foreach ($links as $target=>$label): $url = str_starts_with($target, '/') ? $target : '/finance/accounting/reports?report='.$target; ?>
            <a href="<?= e(appBasePath().$url) ?>"<?= $report === $target ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span aria-hidden="true">→</span></a>
            <?php endforeach; ?>
        </div><?php endforeach; ?>
    </div>
    <p class="finance-warning">Cash Flow requires verified company account classifications. Unmapped accounts are not classified or reported as operating, investing, or financing activity.</p>
</section>
<?php endif; ?>
<?php if ($section !== 'reports' || $report !== ''): ?>
<section class="card finance-register">
<form method="get" class="finance-filter-bar" action="<?= e(appBasePath().'/finance/accounting/'.$section) ?>">
<?php if ($section === 'reports'): ?><input type="hidden" name="report" value="<?= e($report) ?>"><?php endif; ?>
<label>Currency <input name="currency" maxlength="3" value="<?= e($filters['currency'] ?? '') ?>" placeholder="All"></label>
<label>From <input name="from" type="date" value="<?= e($filters['from'] ?? '') ?>"></label>
<label>To <input name="to" type="date" value="<?= e($filters['to'] ?? '') ?>"></label>
<button class="btn btn-primary" type="submit">Apply filters</button>
</form>
<?php if($section==='receivables'): ?><p class="finance-action-bar"><a class="btn btn-secondary" href="<?= e(appBasePath().'/finance/customer-invoices') ?>">Customer invoices</a><a class="btn btn-secondary" href="<?= e(appBasePath().'/finance/statements/customer') ?>">Customer statements</a><a class="btn btn-secondary" href="<?= e(appBasePath().'/finance/reconciliation') ?>">AR reconciliation</a></p><?php endif; ?>
<?php if($section==='payables'): ?><p class="finance-action-bar"><?php if(in_array('procurement.view',$data['user']['permissions']??[],true)): ?><a class="btn btn-secondary" href="<?= e(appBasePath().'/procurement?section=bills') ?>">Procurement supplier bills</a><?php endif; ?><a class="btn btn-secondary" href="<?= e(appBasePath().'/finance/statements/supplier') ?>">Supplier statements</a><a class="btn btn-secondary" href="<?= e(appBasePath().'/finance/reconciliation?focus=ap') ?>">AP reconciliation</a></p><?php endif; ?>
<?php if ($section === 'reports' && $report === 'trial-balance'): ?>
<h2>Trial Balance</h2>
<?php $render($workspace['reports']['trial_balance'] ?? [], ['account_code'=>'Code','account_name'=>'Account','currency'=>'Currency','debit'=>'Debit','credit'=>'Credit','net'=>'Net']); ?>
<?php foreach(($workspace['reports']['trial_totals']??[]) as $currency=>$totals): ?><p><?= e($currency) ?> totals: Debit <?= e(number_format($totals['debit'],2)) ?> · Credit <?= e(number_format($totals['credit'],2)) ?> · Difference <?= e(number_format($totals['debit']-$totals['credit'],2)) ?></p><?php endforeach; ?>
<?php elseif ($section === 'reports' && $report === 'profit-loss'): ?>
<h2>Profit &amp; Loss</h2>
<?php $render($workspace['reports']['profit_loss'] ?? [], ['account_code'=>'Code','account_name'=>'Account','account_type'=>'Type','currency'=>'Currency','debit'=>'Debit','credit'=>'Credit','net'=>'Net']); ?>
<?php foreach(($workspace['reports']['profit_loss_totals']??[]) as $currency=>$totals): ?><p><?= e($currency) ?> · Revenue <?= e(number_format($totals['revenue']??0,2)) ?> · Expenses <?= e(number_format($totals['expense']??0,2)) ?> · Profit / loss <?= e(number_format(($totals['revenue']??0)-($totals['expense']??0),2)) ?></p><?php endforeach; ?>
<?php elseif ($section === 'reports' && $report === 'balance-sheet'): ?>
<h2>Balance Sheet as of <?= e($filters['to']?:date('Y-m-d')) ?></h2>
<?php $render($workspace['reports']['balance_sheet'] ?? [], ['account_code'=>'Code','account_name'=>'Account','account_type'=>'Type','currency'=>'Currency','debit'=>'Debit','credit'=>'Credit','net'=>'Net']); ?>
<?php foreach(($workspace['reports']['balance_sheet_totals']??[]) as $currency=>$totals): $assets=$totals['asset']??0;$liabilities=$totals['liability']??0;$equity=$totals['equity']??0;$earnings=$totals['current_earnings']??0; ?><p><?= e($currency) ?> · Assets <?= e(number_format($assets,2)) ?> · Liabilities <?= e(number_format($liabilities,2)) ?> · Equity <?= e(number_format($equity,2)) ?> · Current earnings <?= e(number_format($earnings,2)) ?> · Difference <?= e(number_format($assets-$liabilities-$equity-$earnings,2)) ?></p><?php endforeach; ?>
<p>Balance Sheet uses all posted entries through the selected end date.</p>
<?php else: ?>
<?php $render($workspace['rows'] ?? [], $workspace['columns'] ?? []); ?>
<?php endif; ?>
<?php if($section!=='reports'): ?><p class="finance-muted">Showing up to 500 rows. Use date and currency filters to narrow the register.</p><?php endif; ?>
</section>
<?php endif; ?>
