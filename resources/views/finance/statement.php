<?php
declare(strict_types=1);
$statement=$data['statement']??[];$kind=$statement['kind']??'customer';
?>
<section class="card"><form method="get" action="<?= e(appBasePath().'/finance/statements/'.$kind) ?>" class="form-grid">
<label><?= $kind==='customer'?'Customer':'Supplier' ?> <select name="party_id" required><option value="">Select</option><?php foreach(($statement['parties']??[]) as $party): ?><option value="<?= (int)$party['id'] ?>"<?= (int)$party['id']===(int)($statement['party_id']??0)?' selected':'' ?>><?= e($party['label']) ?></option><?php endforeach; ?></select></label>
<button type="submit">Show statement</button></form></section>
<?php if(!empty($statement['party_name'])): ?><section class="card"><h2><?= e($statement['party_name']) ?></h2>
<?php foreach(($statement['opening']??[]) as $currency=>$amount): ?><p>Opening balance: <?= e($currency.' '.number_format((float)$amount,2)) ?></p><?php endforeach; ?>
<?php view('finance.list-controls',['list'=>$statement['list'],'controls'=>$statement['controls'],
    'path'=>appBasePath().'/finance/statements/'.$kind,'canExport'=>in_array('finance.export',$_SESSION['auth']['permissions']??[],true)]); ?>
<p class="form-help">Running balances follow posting order. Opening and closing balances cover the selected dates and currency; searching or sorting rows does not change those balances.</p>
<div class="table-responsive"><table class="data-table"><thead><tr><th>Date</th><th>Document</th><th>Reference</th><th>Invoice</th><th>Bill</th><th>Credit / reversal</th><th>Payment</th><th>Debit</th><th>Credit</th><th>Currency</th><th>Running balance</th></tr></thead><tbody>
<?php foreach(($statement['lines']??[]) as $line): ?><tr><td><?= e($line['date']) ?></td><td><?= e($line['document']) ?></td><td><?= e($line['reference']) ?></td><?php foreach(['invoice','bill','credit_note','payment','debit','credit'] as $field): ?><td class="erp-money-column"><?= e(number_format((float)$line[$field],2)) ?></td><?php endforeach; ?><td><?= e($line['currency']) ?></td><td class="erp-money-column"><?= e(number_format((float)$line['balance'],2)) ?></td></tr><?php endforeach; ?>
<?php if(empty($statement['lines'])): ?><tr><td colspan="11">No posted activity in the selected range.</td></tr><?php endif; ?></tbody></table></div>
<?php foreach(($statement['totals']??[]) as $currency=>$amount): ?><p>Closing balance: <?= e($currency.' '.number_format((float)$amount,2)) ?></p><?php endforeach; ?>
</section><?php endif; ?>
