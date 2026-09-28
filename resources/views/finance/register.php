<?php
declare(strict_types=1);

$list=is_array($data['register']??null)?$data['register']:[];
$rows=is_array($list['rows']??null)?$list['rows']:[];
$entity=(string)($data['registerEntity']??'');
$controls=is_array($data['registerControls']??null)?$data['registerControls']:[];
$canExport=!empty($data['canExport']);

$money=static fn(mixed $amount,string $currency):string =>
    strtoupper($currency).' '.number_format((float)$amount,2);

$status=static fn(mixed $value):string =>
    ucwords(str_replace('_',' ',(string)$value));
?>

<div class="module-stack">

<?php
view('finance.list-controls',[
    'list'=>$list,
    'controls'=>$controls,
    'path'=>appBasePath().'/finance',
    'canExport'=>$canExport,
]);
?>

<section class="card table-card">

<?php if($entity==='receivables'): ?>

    <div class="table-summary">
        <div>
            <strong>Sales Receivables</strong>
            <small class="table-summary-note">
                Posted customer balances and collection status.
            </small>
        </div>
    </div>

    <div class="table-responsive">
    <table class="data-table">
        <thead>
        <tr>
            <th>Order</th>
            <th>Customer</th>
            <th>Original</th>
            <th>Paid</th>
            <th>Outstanding</th>
            <th>Due</th>
            <th>Status</th>
        </tr>
        </thead>
        <tbody>

        <?php if($rows===[]): ?>
        <tr>
            <td colspan="7" class="empty-state">
                No receivables matched the selected filters.
            </td>
        </tr>
        <?php endif; ?>

        <?php foreach($rows as $row): ?>
        <tr>
            <td>
                <strong><?=e((string)$row['order_number'])?></strong>
            </td>

            <td>
                <strong><?=e((string)($row['customer_name']??''))?></strong>
                <?php if(!empty($row['customer_number'])): ?>
                    <small><?=e((string)$row['customer_number'])?></small>
                <?php endif; ?>
            </td>

            <td>
                <?=e($money(
                    $row['original_amount']??0,
                    (string)($row['currency']??'')
                ))?>
            </td>

            <td>
                <?=e($money(
                    $row['paid_amount']??0,
                    (string)($row['currency']??'')
                ))?>
            </td>

            <td>
                <strong>
                    <?=e($money(
                        $row['balance_amount']??0,
                        (string)($row['currency']??'')
                    ))?>
                </strong>
            </td>

            <td><?=e((string)($row['due_date']??''))?></td>

            <td>
                <span class="status-badge">
                    <?=e($status($row['list_status']??$row['status']??''))?>
                </span>
            </td>
        </tr>
        <?php endforeach; ?>

        </tbody>
    </table>
    </div>


<?php elseif($entity==='receipts'): ?>

    <div class="table-summary">
        <div>
            <strong>Customer Receipts</strong>
            <small class="table-summary-note">
                Posted customer payments and references.
            </small>
        </div>
    </div>

    <div class="table-responsive">
    <table class="data-table">
        <thead>
        <tr>
            <th>Receipt</th>
            <th>Order</th>
            <th>Customer</th>
            <th>Date</th>
            <th>Method</th>
            <th>Reference</th>
            <th>Amount</th>
        </tr>
        </thead>
        <tbody>

        <?php if($rows===[]): ?>
        <tr>
            <td colspan="7" class="empty-state">
                No receipts matched the selected filters.
            </td>
        </tr>
        <?php endif; ?>

        <?php foreach($rows as $row): ?>
        <tr>
            <td>
                <strong><?=e((string)$row['receipt_number'])?></strong>
            </td>

            <td><?=e((string)($row['order_number']??''))?></td>

            <td>
                <?=e((string)($row['customer_name']??''))?>
            </td>

            <td><?=e((string)$row['payment_date'])?></td>

            <td>
                <?=e($status($row['payment_method']??''))?>
            </td>

            <td>
                <?=e((string)($row['reference_number']??''))?>
            </td>

            <td>
                <strong>
                    <?=e($money(
                        $row['amount']??0,
                        (string)($row['currency']??'')
                    ))?>
                </strong>
            </td>
        </tr>
        <?php endforeach; ?>

        </tbody>
    </table>
    </div>


<?php elseif($entity==='journals'): ?>

    <div class="table-summary">
        <div>
            <strong>Journal Postings</strong>
            <small class="table-summary-note">
                Finance journal batches and posting totals.
            </small>
        </div>
    </div>

    <div class="table-responsive">
    <table class="data-table">
        <thead>
        <tr>
            <th>Batch</th>
            <th>Source</th>
            <th>Description</th>
            <th>Date</th>
            <th>Debit</th>
            <th>Credit</th>
            <th>Status</th>
        </tr>
        </thead>
        <tbody>

        <?php if($rows===[]): ?>
        <tr>
            <td colspan="7" class="empty-state">
                No journal postings matched the selected filters.
            </td>
        </tr>
        <?php endif; ?>

        <?php foreach($rows as $row): ?>
        <tr>
            <td>
                <strong><?=e((string)$row['batch_number'])?></strong>
            </td>

            <td>
                <?=e(
                    (string)(
                        $row['source_number']
                        ?? $row['source_type']
                        ?? ''
                    )
                )?>
                <small>
                    <?=e($status($row['source_type']??''))?>
                </small>
            </td>

            <td><?=e((string)($row['description']??''))?></td>

            <td><?=e((string)$row['posting_date'])?></td>

            <td>
                <?=e($money(
                    $row['total_debit']??0,
                    (string)($row['currency']??'')
                ))?>
            </td>

            <td>
                <?=e($money(
                    $row['total_credit']??0,
                    (string)($row['currency']??'')
                ))?>
            </td>

            <td>
                <span class="status-badge">
                    <?=e($status($row['status']??''))?>
                </span>
            </td>
        </tr>
        <?php endforeach; ?>

        </tbody>
    </table>
    </div>

<?php endif; ?>

</section>
</div>
