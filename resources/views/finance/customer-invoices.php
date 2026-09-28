<?php
declare(strict_types=1);

$list=is_array($data['invoiceList']??null)?$data['invoiceList']:[];
$invoices=is_array($list['rows']??null)?$list['rows']:[];
$controls=is_array($data['invoiceControls']??null)?$data['invoiceControls']:[];
$canExport=!empty($data['canExport']);

$money=static fn(mixed $amount,string $currency):string =>
    strtoupper($currency).' '.number_format((float)$amount,2);
?>

<div class="module-stack">

<?php require __DIR__.'/quick-sale-queue.php'; ?>

<?php
view('finance.list-controls',[
    'list'=>$list,
    'controls'=>$controls,
    'path'=>appBasePath().'/finance/customer-invoices',
    'canExport'=>$canExport,
    'actions'=>['register'=>'invoices'],
]);
?>

<section class="card table-card">

    <div class="table-summary">
        <div>
            <strong>Customer Invoices</strong>
            <small class="table-summary-note">
                Open an invoice to review posting and payment allocation history.
            </small>
        </div>
    </div>

    <div class="table-responsive">
    <table class="data-table">
        <thead>
        <tr>
            <th>Invoice</th>
            <th>Customer</th>
            <th>Sales Order</th>
            <th>Date</th>
            <th>Due</th>
            <th>Total</th>
            <th>Residual</th>
            <th>State</th>
            <th>Payment</th>
        </tr>
        </thead>

        <tbody>

        <?php if($invoices===[]): ?>
        <tr>
            <td colspan="9" class="empty-state">
                No customer invoices matched the selected filters.
            </td>
        </tr>
        <?php endif; ?>

        <?php foreach($invoices as $invoice): ?>
        <tr>

            <td>
                <strong>
                    <a href="<?=e(appBasePath())?>/finance/customer-invoices/<?=e((string)$invoice['invoice_id'])?>">
                        <?=e((string)$invoice['invoice_number'])?>
                    </a>
                </strong>
            </td>

            <td>
                <?=e((string)$invoice['customer_name'])?>
                <?php if(!empty($invoice['customer_number'])): ?>
                    <small>
                        <?=e((string)$invoice['customer_number'])?>
                    </small>
                <?php endif; ?>
            </td>

            <td>
                <?php if(!empty($invoice['order_number'])): ?>
                    <?=e((string)$invoice['order_number'])?>
                <?php else: ?>
                    &mdash;
                <?php endif; ?>
            </td>

            <td><?=e((string)$invoice['invoice_date'])?></td>

            <td><?=e((string)$invoice['due_date'])?></td>

            <td>
                <?=e($money(
                    $invoice['total_amount'],
                    (string)$invoice['currency']
                ))?>
            </td>

            <td>
                <strong>
                    <?=e($money(
                        $invoice['residual_amount'],
                        (string)$invoice['currency']
                    ))?>
                </strong>
            </td>

            <td>
                <?=e(ucwords(str_replace(
                    '_',
                    ' ',
                    (string)$invoice['status']
                )))?>
            </td>

            <td>
                <?=e(ucwords(str_replace(
                    '_',
                    ' ',
                    (string)$invoice['payment_status']
                )))?>
            </td>

        </tr>
        <?php endforeach; ?>

        </tbody>
    </table>
    </div>

</section>
</div>
