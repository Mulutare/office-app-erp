<?php
declare(strict_types=1);
$records=$data['expenseData']['expenses']??[];
$employees=$data['expenseData']['employees']??[];
$categories=$data['expenseData']['categories']??[];
$accounts=$data['expenseData']['accounts']??[];
$journals=$data['expenseData']['journals']??[];
$history=[];foreach(($data['expenseData']['history']??[]) as $event){$history[(int)$event['expense_request_id']][]=$event;}
$permissions=$data['user']['permissions']??[];
$canManage=in_array('finance.records.manage',$permissions,true);
$canApprove=in_array('finance.requests.approve',$permissions,true);
$actor=(int)($data['user']['user_id']??0);
$token=csrfToken();
?>
<?php if(!empty($data['expenseError'])): ?><p class="alert alert-danger"><?= e($data['expenseError']) ?></p><?php endif; ?>
<?php if(!empty($data['notice'])): ?><p class="alert alert-success"><?= e($data['notice']) ?></p><?php endif; ?>
<?php if($canManage): ?>
<details class="card finance-composer"><summary>New expense draft</summary>
<form method="post" action="<?= e(appBasePath().'/finance/expenses') ?>" class="form-grid">
<input type="hidden" name="_token" value="<?= e($token) ?>">
<label>Employee <select name="employee_id" required><option value="">Select</option><?php foreach($employees as $employee): ?><option value="<?= (int)$employee['employee_id'] ?>"><?= e($employee['employee_number'].' '.$employee['first_name'].' '.$employee['last_name']) ?></option><?php endforeach; ?></select></label>
<label>Type <select name="expense_kind"><option value="company_paid">Company paid</option><option value="reimbursement">Employee reimbursement</option><option value="petty_cash">Petty cash</option></select></label>
<label>Category <select name="category_id"><option value="">None</option><?php foreach($categories as $category): ?><option value="<?= (int)$category['category_id'] ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
<label>Expense account <select name="expense_account_id" required><option value="">Select</option><?php foreach($accounts as $account): if($account['account_type']!=='expense')continue; ?><option value="<?= (int)$account['account_id'] ?>"><?= e($account['account_code'].' '.$account['account_name']) ?></option><?php endforeach; ?></select></label>
<label>Title <input name="title" required maxlength="150"></label>
<label>Expense date <input type="date" name="expense_date" required value="<?= e(date('Y-m-d')) ?>"></label>
<label>Currency <input name="currency" required maxlength="3" value="ETB"></label>
<label>Net amount <input type="number" name="net_amount" step="0.01" min="0.01" required></label>
<label>Tax amount <input type="number" name="tax_amount" step="0.01" min="0" value="0"></label>
<label>Recoverable tax account <select name="tax_account_id"><option value="">None</option><?php foreach($accounts as $account): if($account['account_type']!=='asset')continue; ?><option value="<?= (int)$account['account_id'] ?>"><?= e($account['account_code'].' '.$account['account_name']) ?></option><?php endforeach; ?></select></label>
<label>Business purpose <input name="description" maxlength="500"></label>
<label>Receipt / evidence reference <input name="evidence_reference" maxlength="500"></label>
<button type="submit">Save draft</button>
</form></details>
<?php endif; ?>
<section class="card"><h2>Expense register</h2><div class="table-responsive"><table class="data-table"><thead><tr><th>Number</th><th>Employee</th><th>Type</th><th>Category / account</th><th>Date</th><th>Amount</th><th>Status</th><th>Journal</th><th>Action</th></tr></thead><tbody>
<?php foreach($records as $row): $id=(int)$row['expense_request_id'];$status=(string)$row['status'];$own=(int)$row['created_by']===$actor; ?>
<tr id="expense-<?= $id ?>"><td><?= e($row['request_number']) ?></td><td><?= e($row['employee_name']) ?></td><td><?= e(str_replace('_',' ',$row['expense_kind'])) ?></td><td><?= e(($row['category_name']??'').' / '.($row['account_code']??'')) ?></td><td><?= e($row['expense_date']) ?></td><td class="erp-money-column"><?= e($row['currency'].' '.number_format((float)$row['amount'],2)) ?></td><td><span class="status"><?= e($status) ?></span></td><td><?= e($row['batch_number']??'') ?></td><td>
<details><summary>History</summary><?php foreach(($history[$id]??[]) as $event): ?><p><?= e($event['occurred_at'].' '.$event['action'].' by user #'.$event['actor_id'].($event['reason']?' — '.$event['reason']:'')) ?></p><?php endforeach; ?></details>
<?php if($canManage&&$own&&$status==='draft'): ?>
<details><summary>Edit draft</summary><form method="post" action="<?= e(appBasePath().'/finance/expenses/'.$id.'/edit') ?>">
<input type="hidden" name="_token" value="<?= e($token) ?>">
<label>Employee <select name="employee_id" required><?php foreach($employees as $employee): ?><option value="<?= (int)$employee['employee_id'] ?>"<?= (int)$row['requested_by_employee_id']===(int)$employee['employee_id']?' selected':'' ?>><?= e($employee['employee_number'].' '.$employee['first_name'].' '.$employee['last_name']) ?></option><?php endforeach; ?></select></label>
<label>Type <select name="expense_kind"><?php foreach(['company_paid'=>'Company paid','reimbursement'=>'Employee reimbursement','petty_cash'=>'Petty cash'] as $value=>$label): ?><option value="<?= e($value) ?>"<?= $row['expense_kind']===$value?' selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<label>Category <select name="category_id"><option value="">None</option><?php foreach($categories as $category): ?><option value="<?= (int)$category['category_id'] ?>"<?= (int)$row['category_id']===(int)$category['category_id']?' selected':'' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
<label>Expense account <select name="expense_account_id" required><?php foreach($accounts as $account): if($account['account_type']!=='expense')continue; ?><option value="<?= (int)$account['account_id'] ?>"<?= (int)$row['expense_account_id']===(int)$account['account_id']?' selected':'' ?>><?= e($account['account_code'].' '.$account['account_name']) ?></option><?php endforeach; ?></select></label>
<label>Title <input name="title" value="<?= e($row['title']) ?>" required></label>
<label>Date <input type="date" name="expense_date" value="<?= e($row['expense_date']) ?>" required></label>
<label>Currency <input name="currency" maxlength="3" value="<?= e($row['currency']) ?>" required></label>
<label>Net <input type="number" step="0.01" min="0.01" name="net_amount" value="<?= e($row['net_amount']??$row['amount']) ?>" required></label>
<label>Tax <input type="number" step="0.01" min="0" name="tax_amount" value="<?= e($row['tax_amount']??0) ?>"></label>
<label>Tax account <select name="tax_account_id"><option value="">None</option><?php foreach($accounts as $account): if($account['account_type']!=='asset')continue; ?><option value="<?= (int)$account['account_id'] ?>"<?= (int)$row['tax_account_id']===(int)$account['account_id']?' selected':'' ?>><?= e($account['account_code'].' '.$account['account_name']) ?></option><?php endforeach; ?></select></label>
<label>Purpose <input name="description" value="<?= e($row['description']??'') ?>"></label>
<label>Evidence reference <input name="evidence_reference" value="<?= e($row['evidence_reference']??'') ?>"></label>
<button type="submit">Save changes</button></form></details>
<form method="post" action="<?= e(appBasePath().'/finance/expenses/'.$id.'/submit') ?>"><input type="hidden" name="_token" value="<?= e($token) ?>"><button type="submit">Submit</button></form>
<form method="post" action="<?= e(appBasePath().'/finance/expenses/'.$id.'/cancel') ?>"><input type="hidden" name="_token" value="<?= e($token) ?>"><button type="submit">Cancel</button></form>
<?php elseif($canApprove&&!$own&&$status==='submitted'): ?>
<form method="post" action="<?= e(appBasePath().'/finance/expenses/'.$id.'/review') ?>"><input type="hidden" name="_token" value="<?= e($token) ?>"><button name="action" value="approve">Approve</button><input name="reason" placeholder="Rejection reason"><button name="action" value="reject">Reject</button></form>
<?php elseif($canManage&&$status==='approved'): ?>
<?php if($row['expense_kind']==='reimbursement'&&empty($row['recognition_batch_id'])): ?><form method="post" action="<?= e(appBasePath().'/finance/expenses/'.$id.'/recognize') ?>"><input type="hidden" name="_token" value="<?= e($token) ?>"><input type="date" name="recognition_date" value="<?= e(date('Y-m-d')) ?>" required><button type="submit">Recognize employee payable</button></form><?php endif; ?>
<?php if($row['expense_kind']!=='reimbursement'||!empty($row['recognition_batch_id'])): ?>
<form method="post" action="<?= e(appBasePath().'/finance/expenses/'.$id.'/pay') ?>"><input type="hidden" name="_token" value="<?= e($token) ?>"><input type="date" name="payment_date" value="<?= e(date('Y-m-d')) ?>" required><select name="journal_id" required><option value="">Journal</option><?php foreach($journals as $journal): ?><option value="<?= (int)$journal['journal_id'] ?>"><?= e($journal['journal_name']) ?></option><?php endforeach; ?></select><button type="submit">Pay &amp; post</button></form>
<?php endif; ?>
<?php elseif($canApprove&&$status==='paid'&&(int)$row['paid_by']!==$actor): ?>
<details><summary>Reverse</summary><form method="post" action="<?= e(appBasePath().'/finance/expenses/'.$id.'/reverse') ?>"><input type="hidden" name="_token" value="<?= e($token) ?>"><input type="date" name="reversal_date" value="<?= e(date('Y-m-d')) ?>" required><input name="reason" maxlength="500" required placeholder="Reversal reason"><button type="submit">Reverse posted expense</button></form></details>
<?php endif; ?>
</td></tr>
<?php endforeach; ?>
<?php if(!$records): ?><tr><td colspan="9">No expenses recorded.</td></tr><?php endif; ?>
</tbody></table></div></section>
