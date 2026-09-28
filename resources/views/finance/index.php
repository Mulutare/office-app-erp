<?php
declare(strict_types=1);
$receivableSummary=$data['receivableSummary']??[];
$financeSection=(string)($_GET['section']??'dashboard');
?>
<?php if (!isset($_GET['section']) && in_array('finance.records.view', $data['user']['permissions'] ?? [], true)):
    $workCenter = $data['workCenter'] ?? [];
    $financeTasks = array_sum($data['actionRequiredCounts']['finance'] ?? []);
?>
<section class="finance-work-center" aria-label="Finance work center">
    <div class="finance-toolbar"><div><h2>Finance work center</h2><p>Current tasks and balances from posted company records. Amounts stay separated by currency.</p></div></div>
    <div class="finance-kpi-grid">
        <?php foreach (['invoices'=>['Customer invoices','/finance/customer-invoices'],'expenses'=>['Expenses','/finance/expenses'],'staff-loans'=>['Staff loans','/finance/staff-loans'],'settlements'=>['Settlements','/finance/settlements']] as $taskKey=>[$taskLabel,$taskPath]): $taskCount=(int)($data['actionRequiredCounts']['finance'][$taskKey]??0); if ($taskCount<1) continue; ?><a class="finance-kpi finance-kpi-link" href="<?= e(appBasePath().$taskPath.'?task_filter=action_required') ?>"><strong>Action Required · <?= e($taskLabel) ?></strong><b><?= $taskCount ?> tasks</b><small>Open authorized pending records</small></a><?php endforeach; ?>
        <?php if ($financeTasks===0): ?><div class="finance-kpi"><strong>Action Required</strong><b>0 Finance tasks</b><small>No authorized Finance actions pending</small></div><?php endif; ?>
        <?php foreach (($workCenter['cash'] ?? []) as $row): ?><a class="finance-kpi finance-kpi-link" href="<?= e(appBasePath().'/finance/accounting/cash-bank?currency='.rawurlencode($row['currency'])) ?>"><strong>Mapped cash control · <?= e($row['currency']) ?></strong><b><?= e(number_format((float)$row['amount'],2)) ?></b><small>Posted cash control entries</small></a><?php endforeach; ?>
        <?php foreach (($workCenter['balances'] ?? []) as $row): $path=match($row['subledger']){'AR'=>'/finance/accounting/receivables','AP'=>'/finance/accounting/payables',default=>'/finance/staff-loans'}; ?><a class="finance-kpi finance-kpi-link" href="<?= e(appBasePath().$path) ?>"><strong><?= e($row['subledger'].' · '.$row['currency']) ?></strong><b><?= e(number_format((float)$row['subledger_amount'],2)) ?></b><small><?= $row['status']==='DIFFERENCE'?'GL difference '.e(number_format((float)$row['difference'],2)):'Matches GL control' ?></small></a><?php endforeach; ?>
        <?php foreach (($workCenter['overdue'] ?? []) as $row): $path=$row['document_type']==='customer_invoice'?'/finance/accounting/receivables':'/finance/accounting/payables'; ?><a class="finance-kpi finance-kpi-link" href="<?= e(appBasePath().$path.'?currency='.rawurlencode($row['currency'])) ?>"><strong>Overdue <?= $row['document_type']==='customer_invoice'?'AR':'AP' ?> · <?= e($row['currency']) ?></strong><b><?= e(number_format((float)$row['amount'],2)) ?></b><small><?= (int)$row['records'] ?> posted documents</small></a><?php endforeach; ?>
        <?php foreach (($workCenter['loans'] ?? []) as $row): ?><a class="finance-kpi finance-kpi-link" href="<?= e(appBasePath().'/finance/staff-loans?status=active') ?>"><strong>Outstanding staff loans · <?= e($row['currency']) ?></strong><b><?= e(number_format((float)$row['amount'],2)) ?></b><small><?= (int)$row['records'] ?> active loans, principal and interest</small></a><?php endforeach; ?>
        <?php foreach (($workCenter['expenses'] ?? []) as $row): ?><a class="finance-kpi finance-kpi-link" href="<?= e(appBasePath().'/finance/expenses?status='.rawurlencode($row['status'])) ?>"><strong>Expenses · <?= e($row['status']) ?></strong><b><?= (int)$row['records'] ?></b><small>Open the expense register</small></a><?php endforeach; ?>
        <?php if (in_array('finance.settlements.view', $data['user']['permissions'] ?? [], true) && (int)($workCenter['settlements'][0]['records'] ?? 0) > 0): ?><a class="finance-kpi finance-kpi-link" href="<?= e(appBasePath().'/finance/settlements') ?>"><strong>Unreconciled settlements</strong><b><?= (int)$workCenter['settlements'][0]['records'] ?></b><small>Review bank confirmations and variances</small></a><?php endif; ?>
        <?php if (in_array('finance.period.view', $data['user']['permissions'] ?? [], true)): foreach (($workCenter['periods'] ?? []) as $row): ?><a class="finance-kpi finance-kpi-link" href="<?= e(appBasePath().'/finance/accounting-periods') ?>"><strong>Current accounting period</strong><b><?= e($row['period_name']) ?></b><small><?= e(ucfirst($row['status'])) ?></small></a><?php endforeach; endif; ?>
    </div>
    <div class="finance-action-bar"><a class="btn btn-secondary" href="<?= e(appBasePath().'/finance/reconciliation') ?>">Review control differences</a><a class="btn btn-secondary" href="<?= e(appBasePath().'/finance/accounting/reports') ?>">Open report center</a></div>
</section>
<?php endif; ?>

<?php if ($financeSection === 'dashboard'): ?>
<section
    class="finance-overview"
    aria-labelledby="finance-overview-title"
>
    <div class="section-heading">
        <div>
            <h2 id="finance-overview-title">
                Financial Overview
            </h2>
            <p>
                Sales receivables and collections,
                separated by currency.
            </p>
        </div>
    </div>

    <?php if ($receivableSummary === []): ?>
        <article class="card empty-state">
            No Sales receivables have been posted yet.
        </article>
    <?php else: ?>
        <?php foreach (
            $receivableSummary as $currencySummary
        ): ?>
            <?php
            $currency = strtoupper((string) (
                $currencySummary['currency'] ?? ''
            ));
            ?>

            <div class="section-heading">
                <strong><?= e($currency) ?></strong>
            </div>

            <div class="finance-summary-grid">
                <article class="card finance-summary-card">
                    <span>Outstanding</span>
                    <strong>
                        <?= e(
                            $currency . ' ' .
                            number_format(
                                (float) (
                                    $currencySummary[
                                        'total_outstanding'
                                    ] ?? 0
                                ),
                                2
                            )
                        ) ?>
                    </strong>
                </article>

                <article class="card finance-summary-card">
                    <span>Collected</span>
                    <strong>
                        <?= e(
                            $currency . ' ' .
                            number_format(
                                (float) (
                                    $currencySummary[
                                        'total_paid'
                                    ] ?? 0
                                ),
                                2
                            )
                        ) ?>
                    </strong>
                </article>

                <article class="card finance-summary-card">
                    <span>Open receivables</span>
                    <strong>
                        <?= e(
                            $currencySummary[
                                'open_count'
                            ] ?? 0
                        ) ?>
                    </strong>
                </article>

                <article class="card finance-summary-card">
                    <span>Overdue</span>
                    <strong>
                        <?= e(
                            $currencySummary[
                                'overdue_count'
                            ] ?? 0
                        ) ?>
                    </strong>
                </article>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php view('components.document-list',[
    'workspace'=>$data['overviewRegister'],'entity'=>$data['overviewEntity'],
    'title'=>$data['overviewEntity']==='expenses'?'Expense requests':'Receivables',
]); ?>
