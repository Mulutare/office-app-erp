<?php $incentiveDetail=$data['incentiveDetail']??[];$claim=$incentiveDetail['claim'];$settlements=$incentiveDetail['settlements'];$events=$incentiveDetail['events'];$notice=$data['notice']??null;$error=$data['error']??null;$canApproveIncentive=$data['canApproveIncentive']??false;$canSettleIncentive=$data['canSettleIncentive']??false; ?>
<div class="module-stack">
<div class="page-actions"><a class="btn btn-secondary" href="<?=e(appBasePath())?>/sales/incentives">Back to Incentives</a></div>
<?php if(!empty($notice)):?><div class="notice success"><?=e($notice)?></div><?php endif;?>
<?php if(!empty($error)):?><div class="notice error"><?=e($error)?></div><?php endif;?>
<section class="finance-summary-grid" aria-label="Claim financial summary">
    <article class="card"><span>Approved incentive</span><strong><?=e($claim['currency'].' '.number_format((float)($claim['approved_amount']??0),2))?></strong></article>
    <article class="card"><span>Safaricom settled</span><strong><?=e($claim['currency'].' '.number_format((float)$claim['settled_amount'],2))?></strong></article>
    <article class="card"><span>Outstanding</span><strong><?=e($claim['currency'].' '.number_format((float)$claim['outstanding'],2))?></strong></article>
    <article class="card"><h2><?=$claim['claim_basis']==='cumulative_sales'?'Current variance':'Historical unexplained variance'?></h2><strong><?=e($claim['currency'].' '.number_format((float)$claim['unexplained_variance'],2))?></strong></article>
</section>
<?php if(in_array($claim['status'],['approved','partially_settled'],true)&&(float)$claim['outstanding']>0&&!empty($canSettleIncentive)): ?>
<section class="card" id="record-safaricom-repayment">
    <h2>Record Safaricom repayment</h2>
    <?php if($claim['claim_basis']==='cumulative_sales'): ?><p>Recording a repayment increases Safaricom settled, reduces outstanding, and moves current variance toward zero.</p><?php endif; ?>
    <form method="post" action="<?=e(appBasePath())?>/sales/incentives/<?=(int)$claim['incentive_claim_id']?>/settlements">
        <?=csrfField()?>
        <input type="hidden" name="currency" value="<?=e($claim['currency'])?>">
        <div class="finance-filter-form">
            <label>Payment date<input type="date" name="settlement_date" value="<?=e(date('Y-m-d'))?>" required></label>
            <label>Amount received (<?=e($claim['currency'])?>)<input type="number" name="amount" min="0.01" max="<?=e($claim['outstanding'])?>" step="0.01" required></label>
            <label>Safaricom payment reference<input name="external_payment_reference" maxlength="190" required></label>
            <label>Evidence/reference (optional)<input name="evidence_reference" maxlength="500"></label>
        </div>
        <button class="btn btn-primary">Record Safaricom repayment</button>
    </form>
</section>
<?php endif; ?>
<section class="card">
    <h2>Safaricom incentive #<?=(int)$claim['incentive_claim_id']?></h2>
    <p>Status: <?=e(str_replace('_',' ',$claim['status']))?></p>
    <div class="detail-grid">
        <div><strong>DSA/DSP</strong><br><?=e($claim['dsa_name'])?></div>
        <div><strong>Responsible manager at submission</strong><br><?=e($claim['manager_name'])?></div>
        <div><strong>Submitted</strong><br><?=e($claim['submitted_at'])?></div>
        <?php if($claim['claim_basis']==='cumulative_sales'): ?>
        <div><strong>Cumulative confirmed-sales snapshot</strong><br><?=e($claim['confirmed_sales_snapshot'].' '.$claim['currency'])?></div>
        <?php else: ?>
        <div><strong>Historical report</strong><br><?=e('#'.$claim['originating_report_id'])?></div>
        <div><strong>Historical cash float</strong><br><?=e($claim['float_reference'].' · '.$claim['float_amount'].' '.$claim['currency'])?></div>
        <?php endif; ?>
        <div><strong>Safaricom incentive amount</strong><br><?=e($claim['proposed_amount'].' '.$claim['currency'])?></div>
        <div><strong>Safaricom reference</strong><br><?=e($claim['external_reference']??'—')?></div>
    </div>
    <?php if(!empty($claim['rejection_reason'])): ?><p>Rejection reason: <?=e($claim['rejection_reason'])?></p><?php endif; ?>
</section>
<?php if($claim['status']==='submitted'&&!empty($canApproveIncentive)&&(int)$claim['responsible_manager_id']===(int)($_SESSION['auth']['user_id']??0)):?><section class="card"><h3>Manager decision</h3><form method="post" action="<?=e(appBasePath())?>/sales/incentives/<?=(int)$claim['incentive_claim_id']?>/decision"><?=csrfField()?><div class="finance-filter-form"><label>Approved amount<input name="approved_amount" type="number" min="0.01" max="<?=e($claim['proposed_amount'])?>" step="0.01" value="<?=e($claim['proposed_amount'])?>"></label><label>Decision reason<input name="reason" maxlength="1000"></label></div><button class="btn btn-primary" name="decision" value="approve">Approve incentive</button> <button class="btn btn-secondary" name="decision" value="reject">Reject claim</button></form></section><?php endif;?>
<section class="card table-card"><h3>Safaricom settlement history</h3><div class="table-responsive"><table class="data-table"><thead><tr><th>Date</th><th>Amount</th><th>Currency</th><th>Payment reference</th><th>Evidence/reference</th><th>Entered by</th></tr></thead><tbody><?php if($settlements===[]):?><tr><td colspan="6">No settlement recorded.</td></tr><?php endif;?><?php foreach($settlements as $settlement):?><tr><td><?=e($settlement['settlement_date'])?></td><td><?=e($settlement['amount'])?></td><td><?=e($settlement['currency'])?></td><td><?=e($settlement['external_payment_reference'])?></td><td><?=e($settlement['evidence_reference']??'—')?></td><td><?=e($settlement['entered_by'])?></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="card table-card"><h3>Immutable event history</h3><div class="table-responsive"><table class="data-table"><thead><tr><th>When</th><th>Event</th><th>From</th><th>To</th><th>Actor</th><th>Reason/reference</th></tr></thead><tbody><?php foreach($events as $event):?><tr><td><?=e($event['occurred_at'])?></td><td><?=e(str_replace('_',' ',$event['event_type']))?></td><td><?=e($event['from_status']??'—')?></td><td><?=e($event['to_status'])?></td><td><?=e($event['actor_id'])?></td><td><?=e($event['reason_reference']??'—')?></td></tr><?php endforeach;?></tbody></table></div></section>
</div>
