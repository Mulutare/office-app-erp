<?php
$incentiveData=$data['incentiveData']??[];
$claims=$incentiveData['claims']??[];$positions=$incentiveData['positions']??[];
$eligiblePositions=array_values(array_filter($positions,static fn(array $position): bool=>$position['report_count']>0));
$canSubmit=!empty($data['canSubmitIncentive'])&&($incentiveData['dsaName']??null)!==null;
?>
<div class="module-stack">
<?php if(!empty($data['notice'])): ?><div class="notice success"><?=e($data['notice'])?></div><?php endif; ?>
<?php if(!empty($data['error'])): ?><div class="notice error"><?=e($data['error'])?></div><?php endif; ?>
<section class="card">
    <h2>DSA/DSP Incentives</h2>
    <p>Submit your Safaricom incentive to your direct manager. Approved company-funded incentives create a negative variance; Safaricom repayments settle that balance.</p>
    <?php if(($incentiveData['dsaName']??null)!==null): ?>
        <p>DSA/DSP: <strong><?=e($incentiveData['dsaName'])?></strong><br>Manager: <strong><?=e($incentiveData['managerName'])?></strong></p>
        <?php if($positions===[]): ?><p>No eligible confirmed sales are available yet.</p><?php endif; ?>
        <?php foreach($positions as $position): ?>
            <div class="detail-grid">
                <div><strong>Cumulative confirmed sales</strong><br><?=e($position['currency'].' '.number_format((float)$position['sales_amount'],2))?></div>
                <div><strong>Approved Safaricom incentives</strong><br><?=e($position['currency'].' '.number_format((float)$position['approved_incentives'],2))?></div>
                <div><strong>Current unexplained variance</strong><br><?=e($position['currency'].' '.number_format((float)$position['unexplained_variance'],2))?></div>
            </div>
        <?php endforeach; ?>
        <p>Approved company-funded incentives remain negative until Safaricom settles them. Pending and rejected claims do not change this balance.</p>
    <?php endif; ?>
</section>
<?php if($canSubmit): ?>
<section class="card">
    <h3>Submit Safaricom incentive</h3>
    <form method="post" action="<?=e(appBasePath())?>/sales/incentives/claims">
        <?=csrfField()?>
        <div class="finance-filter-form">
            <?php if(count($eligiblePositions)>1): ?>
            <label>Currency<select name="currency" required><option value="">Select currency</option>
                <?php foreach($eligiblePositions as $position): ?><option value="<?=e($position['currency'])?>"><?=e($position['currency'])?></option><?php endforeach; ?>
            </select></label>
            <?php endif; ?>
            <label>Safaricom incentive amount<input name="proposed_amount" type="number" min="0.01" step="0.01" required></label>
            <label>Safaricom reference<input name="external_reference" maxlength="190" required></label>
        </div>
        <p>The cumulative confirmed-sales amount at submission is saved with your claim.</p>
        <button class="btn btn-primary" <?=$eligiblePositions===[]?'disabled':''?>>Submit to Manager</button>
    </form>
</section>
<?php endif; ?>
<section class="card finance-filter-panel">
    <h3>Filter register</h3>
    <?php view('components.list-filters', ['query'=>$incentiveData['list']['query'],'path'=>appBasePath().'/sales/incentives',
        'sorts'=>['date'=>'Submitted date','dsa'=>'DSA/DSP','manager'=>'Manager','reference'=>'Reference','status'=>'Status','amount'=>'Amount'],
        'filters'=>['status'=>['label'=>'Status','options'=>['submitted'=>'Submitted','approved'=>'Approved','rejected'=>'Rejected','partially_settled'=>'Partially settled','settled'=>'Settled']],
            'dsa_dsp_user_id'=>['label'=>'DSA/DSP','options'=>array_column($incentiveData['users'],'display_name','user_id')],
            'responsible_manager_id'=>['label'=>'Manager','options'=>array_column($incentiveData['managers'],'display_name','user_id')],
            'from'=>['label'=>'From','type'=>'date'],'to'=>['label'=>'To','type'=>'date'],
            'safaricom_reference'=>['label'=>'Safaricom reference','type'=>'text']],
    ]); ?>
    <?php view('components.list-download', ['query'=>$incentiveData['list']['query'],'path'=>appBasePath().'/sales/incentives','allowed'=>$data['canExportList']??false]); ?>
</section>
<section class="card table-card">
    <h3>Incentive register</h3>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th>Submitted</th><th>DSA/DSP</th><th>Manager</th><th>Confirmed-sales snapshot</th><th>Incentive amount</th><th>Safaricom reference</th><th>Approved</th><th>Safaricom settled</th><th>Outstanding</th><th>Variance</th><th>Status</th><th>Review / detail</th></tr></thead>
        <tbody>
        <?php if($claims===[]): ?><tr><td colspan="12">No claims match the filters.</td></tr><?php endif; ?>
        <?php foreach($claims as $claim): ?><tr>
            <td><?=e($claim['submitted_at'])?></td><td><?=e($claim['dsa_name'])?></td><td><?=e($claim['manager_name'])?></td>
            <td><?php if($claim['claim_basis']==='cumulative_sales'): ?><?=e($claim['currency'].' '.number_format((float)$claim['confirmed_sales_snapshot'],2))?><?php else: ?>Historical claim<?php endif; ?></td>
            <td><?=e($claim['currency'].' '.$claim['proposed_amount'])?></td><td><?=e($claim['external_reference']??'—')?></td>
            <td><?=e(number_format((float)($claim['approved_amount']??0),2))?></td>
            <td><?=e(number_format((float)$claim['settled_amount'],2))?></td>
            <td><?=e(number_format((float)$claim['outstanding'],2))?></td>
            <td><strong><?=e(number_format((float)$claim['unexplained_variance'],2))?></strong></td>
            <td><?=e(str_replace('_',' ',$claim['status']))?></td>
            <td><a class="btn btn-secondary btn-compact" href="<?=e(appBasePath())?>/sales/incentives/<?=(int)$claim['incentive_claim_id']?>"><?=$claim['status']==='submitted'?'Review claim':'Open'?></a></td>
        </tr><?php endforeach; ?>
        </tbody>
    </table></div>
</section>
</div>

<?php view('components.list-pagination',['query'=>$incentiveData['list']['query'],'pagination'=>$incentiveData['list']['pagination'],'path'=>appBasePath().'/sales/incentives']); ?>
