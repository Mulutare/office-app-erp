<?php
declare(strict_types=1);
$workspace = $data['workspace'] ?? [];
$filters = $workspace['filters'] ?? [];
$money = ['total_amount','paid_amount','residual_amount','debit_amount','credit_amount','debit','credit','net'];
$render = static function (array $rows, array $columns) use ($money): void { ?>
<div class="table-responsive"><table class="data-table"><thead><tr>
<?php foreach ($columns as $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
</tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr>
<?php foreach ($columns as $field => $label): ?><td<?= in_array($field, $money, true) ? ' class="erp-money-column"' : '' ?>><?= e(in_array($field, $money, true) ? number_format((float)($row[$field] ?? 0), 2) : (string)($row[$field] ?? '')) ?></td><?php endforeach; ?>
</tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="<?= count($columns) ?>">No matching posted records.</td></tr><?php endif; ?>
</tbody></table></div>
<?php }; ?>
<section class="card">
<form method="get" class="form-grid" action="<?= e(appBasePath().'/finance/accounting/'.($workspace['section'] ?? '')) ?>">
<label>Currency <input name="currency" maxlength="3" value="<?= e($filters['currency'] ?? '') ?>" placeholder="All"></label>
<label>From <input name="from" type="date" value="<?= e($filters['from'] ?? '') ?>"></label>
<label>To <input name="to" type="date" value="<?= e($filters['to'] ?? '') ?>"></label>
<button type="submit">Apply filters</button>
</form>
<?php if(($workspace['section']??'')==='receivables'): ?><p><a href="<?= e(appBasePath().'/finance/statements/customer') ?>">Customer statements</a> · <a href="<?= e(appBasePath().'/finance/reconciliation') ?>">AR reconciliation</a></p><?php endif; ?>
<?php if(($workspace['section']??'')==='payables'): ?><p><a href="<?= e(appBasePath().'/finance/statements/supplier') ?>">Supplier statements</a> · <a href="<?= e(appBasePath().'/finance/reconciliation') ?>">AP reconciliation</a></p><?php endif; ?>
<?php if (($workspace['section'] ?? '') === 'reports'): ?>
<p><a href="<?= e(appBasePath().'/finance/accounting/receivables') ?>">AR aging</a> · <a href="<?= e(appBasePath().'/finance/accounting/payables') ?>">AP aging</a> · <a href="<?= e(appBasePath().'/finance/staff-loans') ?>">Staff loans</a> · <a href="<?= e(appBasePath().'/finance/statements/customer') ?>">Customer statements</a> · <a href="<?= e(appBasePath().'/finance/statements/supplier') ?>">Supplier statements</a> · <a href="<?= e(appBasePath().'/finance/reconciliation') ?>">Subledger reconciliation</a></p>
<h2>Trial Balance</h2>
<?php $render($workspace['reports']['trial_balance'] ?? [], ['account_code'=>'Code','account_name'=>'Account','currency'=>'Currency','debit'=>'Debit','credit'=>'Credit','net'=>'Net']); ?>
<?php foreach(($workspace['reports']['trial_totals']??[]) as $currency=>$totals): ?><p><?= e($currency) ?> totals: Debit <?= e(number_format($totals['debit'],2)) ?> · Credit <?= e(number_format($totals['credit'],2)) ?> · Difference <?= e(number_format($totals['debit']-$totals['credit'],2)) ?></p><?php endforeach; ?>
<h2>Profit &amp; Loss</h2>
<?php $render($workspace['reports']['profit_loss'] ?? [], ['account_code'=>'Code','account_name'=>'Account','account_type'=>'Type','currency'=>'Currency','debit'=>'Debit','credit'=>'Credit','net'=>'Net']); ?>
<?php foreach(($workspace['reports']['profit_loss_totals']??[]) as $currency=>$totals): ?><p><?= e($currency) ?> · Revenue <?= e(number_format($totals['revenue']??0,2)) ?> · Expenses <?= e(number_format($totals['expense']??0,2)) ?> · Profit / loss <?= e(number_format(($totals['revenue']??0)-($totals['expense']??0),2)) ?></p><?php endforeach; ?>
<h2>Balance Sheet as of <?= e($filters['to']?:date('Y-m-d')) ?></h2>
<?php $render($workspace['reports']['balance_sheet'] ?? [], ['account_code'=>'Code','account_name'=>'Account','account_type'=>'Type','currency'=>'Currency','debit'=>'Debit','credit'=>'Credit','net'=>'Net']); ?>
<?php foreach(($workspace['reports']['balance_sheet_totals']??[]) as $currency=>$totals): $assets=$totals['asset']??0;$liabilities=$totals['liability']??0;$equity=$totals['equity']??0;$earnings=$totals['current_earnings']??0; ?><p><?= e($currency) ?> · Assets <?= e(number_format($assets,2)) ?> · Liabilities <?= e(number_format($liabilities,2)) ?> · Equity <?= e(number_format($equity,2)) ?> · Current earnings <?= e(number_format($earnings,2)) ?> · Difference <?= e(number_format($assets-$liabilities-$equity-$earnings,2)) ?></p><?php endforeach; ?>
<p>Balance Sheet uses all posted entries through the selected end date. Cash flow classification remains partial pending verified account mapping.</p>
<?php else: ?>
<?php $render($workspace['rows'] ?? [], $workspace['columns'] ?? []); ?>
<?php endif; ?>
<?php if(($workspace['section']??'')!=='reports'): ?><p>Showing up to 500 rows. Use date and currency filters to narrow the register.</p><?php endif; ?>
</section>
