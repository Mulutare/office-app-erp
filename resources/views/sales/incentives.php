<?php
$incentiveData=$data['incentiveData']??[];
$claims=$incentiveData['claims']??[];$floats=$incentiveData['floats']??[];$reports=$incentiveData['reports']??[];
$users=$incentiveData['users']??[];$managers=$incentiveData['managers']??[];
$notice=$data['notice']??null;$error=$data['error']??null;
$canIssueFloat=!empty($incentiveData['canIssueFloat']);
$floatProduct=$incentiveData['floatProduct']??null;$selectedReport=$incentiveData['selectedReport']??null;
$canSubmitSelected=!empty($data['canSubmitIncentive']) && $selectedReport!==null
    && (int)$selectedReport['dsa_dsp_user_id']===(int)($_SESSION['auth']['user_id']??0);
?>
<div class="module-stack">
<?php if(!empty($notice)):?><div class="notice success"><?=e($notice)?></div><?php endif;?>
<?php if(!empty($error)):?><div class="notice error"><?=e($error)?></div><?php endif;?>
<section class="card"><h2>DSA/DSP Incentives</h2><p>Cash float is money issued by a manager, not stock. Only approved Safaricom incentive reduces unexplained DSA/DSP variance. Safaricom reimbursement settles the external outstanding claim; no Finance journal is posted here.</p></section>
<section class="card finance-filter-panel"><h3>Filter register</h3><form method="get" action="<?=e(appBasePath())?>/sales/incentives" class="finance-filter-form"><label>Status<select name="status"><option value="">All</option><?php foreach(['outstanding'=>'Outstanding','partially_settled'=>'Partially Settled','settled'=>'Settled'] as $key=>$label):?><option value="<?=e($key)?>" <?=($_GET['status']??'')===$key?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label><label>DSA/DSP<select name="dsa_dsp_user_id"><option value="">All</option><?php foreach($users as $user):?><option value="<?=(int)$user['user_id']?>" <?=(int)($_GET['dsa_dsp_user_id']??0)===(int)$user['user_id']?'selected':''?>><?=e($user['display_name'])?></option><?php endforeach;?></select></label><label>Manager<select name="responsible_manager_id"><option value="">All</option><?php foreach($managers as $user):?><option value="<?=(int)$user['user_id']?>" <?=(int)($_GET['responsible_manager_id']??0)===(int)$user['user_id']?'selected':''?>><?=e($user['display_name'])?></option><?php endforeach;?></select></label><label>Date<input type="date" name="date" value="<?=e($_GET['date']??'')?>"></label><label>Safaricom reference<input name="safaricom_reference" value="<?=e($_GET['safaricom_reference']??'')?>"></label><button class="btn btn-primary">Apply filters</button></form></section>
<?php if($floatProduct===null): ?>
<div class="notice error">Active FLOAT product is not configured in Sales Products.</div>
<?php endif; ?>
<section class="card">
    <h3>Confirmed report and issued cash float</h3>
    <form method="get" action="<?=e(appBasePath())?>/sales/incentives" class="finance-filter-form">
        <label>Confirmed DSA/DSP report
            <select name="report_id" required onchange="this.form.requestSubmit()">
                <option value="">Select report</option>
                <?php foreach($reports as $report): ?>
                <option value="<?=(int)$report['report_id']?>" <?=($selectedReport['report_id']??0)==$report['report_id']?'selected':''?>><?=e('Report #'.$report['report_id'].' · '.$report['dsa_name'].' · '.$report['created_at'])?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-secondary">Load issued float</button>
        <?php if($selectedReport!==null): ?><a class="btn btn-secondary" href="<?=e(appBasePath())?>/sales/incentives">Clear report</a><?php endif; ?>
    </form>
    <?php if($reports===[]): ?><p>No unclaimed confirmed reports are available in your scope.</p><?php endif; ?>
    <?php if($selectedReport===null): ?>
        <p>Select a confirmed report to see cash float issued to its DSA/DSP.</p>
    <?php else: ?>
        <p>DSA/DSP: <strong><?=e($selectedReport['dsa_name'])?></strong></p>
        <?php if($floats===[]): ?>
            <p role="status">No issued cash float is available for this DSA/DSP.</p>
            <?php if($canIssueFloat && $floatProduct!==null): ?>
                <p><a class="btn btn-primary" href="#issue-cash-float">Issue cash float</a></p>
            <?php else: ?><p>Your authorized direct manager can issue cash float.</p><?php endif; ?>
        <?php endif; ?>
        <form method="post" action="<?=e(appBasePath())?>/sales/incentives/claims">
            <?=csrfField()?>
            <input type="hidden" name="report_id" value="<?=(int)$selectedReport['report_id']?>">
            <div class="finance-filter-form">
                <label>Issued cash float
                    <select name="float_id" required <?=$floats===[]?'disabled':''?>>
                        <option value="">Select float</option>
                        <?php foreach($floats as $float): ?>
                        <option value="<?=(int)$float['float_id']?>"><?=e($float['currency'].' '.number_format((float)$float['amount'],2).' — issued '.$float['issued_date'].' — '.$float['manager_name'].' · '.$float['reference'])?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if($canSubmitSelected): ?>
                <label>Proposed incentive<input name="proposed_amount" type="number" min="0.01" step="0.01" required></label>
                <label>Safaricom reference<input name="external_reference" maxlength="190"></label>
                <?php endif; ?>
            </div>
            <?php if($canSubmitSelected): ?><button class="btn btn-primary" <?=$floats===[]?'disabled':''?>>Submit for manager approval</button>
            <?php else: ?><p>The reporting DSA/DSP submits the incentive for manager approval.</p><?php endif; ?>
        </form>
    <?php endif; ?>
</section>
<?php if($canIssueFloat): ?>
<section class="card" id="issue-cash-float">
    <h3>Issue cash float</h3>
    <?php if($floatProduct!==null): ?>
    <form method="post" action="<?=e(appBasePath())?>/sales/incentives/floats">
        <?=csrfField()?>
        <input type="hidden" name="report_id" value="<?=(int)($selectedReport['report_id']??0)?>">
        <div class="finance-filter-form">
            <label>DSA/DSP<select name="dsa_dsp_user_id" required><option value="">Select your direct report</option>
                <?php foreach($incentiveData['issuableUsers'] as $user): ?>
                <option value="<?=(int)$user['user_id']?>" <?=($selectedReport['dsa_dsp_user_id']??0)==$user['user_id']?'selected':''?>><?=e($user['display_name'])?></option>
                <?php endforeach; ?>
            </select></label>
            <label>Sales product<select name="product_id" required><option value="<?=(int)$floatProduct['product_id']?>"><?=e($floatProduct['sku'].' — '.$floatProduct['name'])?></option></select></label>
            <label>Amount<input type="number" name="amount" min="0.01" step="0.01" required></label>
            <label>Issued date<input type="date" name="issued_date" value="<?=e(date('Y-m-d'))?>" required></label>
            <label>Unique reference<input name="reference" maxlength="120" required></label>
        </div>
        <p>Record the actual money issued. The product identifies cash float; its sales price is not the issued amount.</p>
        <button class="btn btn-primary">Record cash float issuance</button>
    </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card table-card"><h3>Incentive register</h3><div class="table-responsive"><table class="data-table"><thead><tr><th>Date</th><th>DSA/DSP</th><th>Manager</th><th>Float</th><th>Sold</th><th>Approved incentive</th><th>Safaricom settled</th><th>Outstanding</th><th>Unexplained variance</th><th>Status</th><th>Detail</th></tr></thead><tbody><?php if($claims===[]):?><tr><td colspan="11">No claims match the filters.</td></tr><?php endif;?><?php foreach($claims as $claim):?><tr><td><?=e($claim['submitted_at'])?></td><td><?=e($claim['dsa_name'])?></td><td><?=e($claim['manager_name'])?></td><td><?=e($claim['float_amount'].' '.$claim['currency'])?></td><td><?=e($claim['sold_amount'])?></td><td><?=e($claim['approved_amount']??'0.00')?></td><td><?=e($claim['settled_amount'])?></td><td><?=e($claim['outstanding'])?></td><td><?=e($claim['unexplained_variance'])?></td><td><span class="status-badge"><?=e(str_replace('_',' ',$claim['status']))?></span></td><td><a class="btn btn-secondary btn-compact" href="<?=e(appBasePath())?>/sales/incentives/<?=(int)$claim['incentive_claim_id']?>">Open</a></td></tr><?php endforeach;?></tbody></table></div></section>
</div>
