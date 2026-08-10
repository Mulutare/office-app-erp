<?php

declare(strict_types=1);

use App\Services\FinancePostingService;
use App\Services\SalesService;

require_once __DIR__ . '/../app/helpers/bootstrap.php';

$companyId = (int) db()->query("SELECT company_id FROM companies WHERE code='default' LIMIT 1")->fetchColumn();
$users = db()->query('SELECT user_id FROM users ORDER BY user_id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
$actorId = (int)($users[0] ?? 0);
$approverId = (int)($users[1] ?? 0);
if ($companyId < 1 || $actorId < 1 || $approverId < 1) {
    fwrite(STDERR, "FAIL Finance fixtures unavailable.\n"); exit(1);
}
$_SESSION['auth'] = ['user_id'=>$actorId,'company'=>['company_id'=>$companyId]];
$sales = new SalesService();
$finance = new FinancePostingService();
$suffix = strtoupper(bin2hex(random_bytes(3)));
$failures = [];
$check = static function (bool $condition, string $description) use (&$failures): void {
    fwrite($condition ? STDOUT : STDERR, ($condition ? 'PASS ' : 'FAIL ').$description.PHP_EOL);
    if (!$condition) { $failures[] = $description; }
};

try {
    $customer = $sales->createCustomer([
        'customer_number'=>'FIN-'.$suffix,'name'=>'Finance Customer '.$suffix,'customer_type'=>'business',
        'credit_mode'=>'unlimited','credit_limit'=>0,'payment_terms_days'=>30,'preferred_currency'=>'ETB',
    ], $actorId);
    $product = $sales->createProduct([
        'sku'=>'FIN-'.$suffix,'name'=>'Finance Product '.$suffix,'product_type'=>'service',
        'unit_of_measure'=>'unit','unit_price'=>'100.00','commission_rate'=>0,
    ], $actorId);
    $order = $sales->createOrder([
        'customer_id'=>$customer['id'] ?? 0,'order_date'=>date('Y-m-d'),'due_date'=>date('Y-m-d',strtotime('+30 days')),
        'currency'=>'ETB','confirm'=>true,'lines'=>[[
            'product_id'=>$product['id'] ?? 0,'quantity'=>2,'discount_amount'=>0,'tax_rate'=>15,
        ]],
    ], $actorId);
    $approval = $sales->transitionOrder((int)($order['orderId'] ?? 0),'approve',null,$approverId,'fin-'.$suffix);
    $check(!empty($customer['successful']) && !empty($product['successful']) && !empty($approval['successful']),
        'Finance integration fixtures create an approved sales order');

    $invoiceId = $finance->createCustomerInvoiceFromOrder($companyId,(int)$order['orderId'],'ordered',$actorId);
    $invoice = db()->query('SELECT * FROM finance_invoices WHERE invoice_id='.$invoiceId)->fetch(PDO::FETCH_ASSOC);
    $check(is_array($invoice) && (float)$invoice['untaxed_amount']===200.0
        && (float)$invoice['tax_amount']===30.0 && (float)$invoice['total_amount']===230.0,
        'Invoice totals are derived server-side from authoritative sales lines');

    $posted = $finance->postInvoice($companyId,$invoiceId,$actorId);
    $replayed = $finance->postInvoice($companyId,$invoiceId,$actorId);
    $balance = db()->prepare(
        'SELECT total_debit,total_credit FROM finance_journal_batches WHERE company_id=:company_id AND journal_batch_id=:batch'
    );
    $balance->execute(['company_id'=>$companyId,'batch'=>$posted['journalBatchId'] ?? 0]);
    $batch = $balance->fetch(PDO::FETCH_ASSOC);
    $check(!empty($posted['journalBatchId']) && !empty($replayed['replayed'])
        && (float)($batch['total_debit'] ?? 0)===(float)($batch['total_credit'] ?? -1),
        'Invoice posting is balanced and idempotent');

    /*
     * Finance-origin payments must keep the Sales receivable projection
     * synchronized with authoritative Finance allocations.
     */
    $receivableFixture = db()->prepare(
        "INSERT INTO finance_sales_receivables
            (
                company_id,
                order_id,
                customer_id,
                order_number,
                currency,
                original_amount,
                paid_amount,
                balance_amount,
                due_date,
                status
            )
         SELECT
            company_id,
            order_id,
            customer_id,
            order_number,
            currency,
            total_amount,
            0,
            total_amount,
            due_date,
            'open'
         FROM sales_orders
         WHERE company_id = :company_id
           AND order_id = :order_id
         ON DUPLICATE KEY UPDATE
            customer_id = VALUES(customer_id),
            order_number = VALUES(order_number),
            currency = VALUES(currency),
            original_amount = VALUES(original_amount),
            paid_amount = 0,
            balance_amount = VALUES(original_amount),
            due_date = VALUES(due_date),
            status = 'open'"
    );

    $receivableFixture->execute([
        'company_id' => $companyId,
        'order_id' => (int) $order['orderId'],
    ]);
    $journals = $finance->ensureSystemJournals($companyId,'ETB',$actorId);
    $first = $finance->postCustomerPayment(
        $companyId,(int)$customer['id'],$journals['bank'],date('Y-m-d'),'ETB',100,'bank_transfer',
        'PART-'.$suffix,[['invoice_id'=>$invoiceId,'amount'=>100]],$actorId
    );
    $residual = (float)db()->query('SELECT residual_amount FROM finance_invoices WHERE invoice_id='.$invoiceId)->fetchColumn();
    $check($residual===130.0 && (float)$first['allocatedAmount']===100.0,
        'Partial payment leaves the exact invoice residual');

    $partialReceivable = db()->prepare(
        'SELECT paid_amount, balance_amount, status
         FROM finance_sales_receivables
         WHERE company_id = :company_id
           AND order_id = :order_id'
    );

    $partialReceivable->execute([
        'company_id' => $companyId,
        'order_id' => (int) $order['orderId'],
    ]);

    $partialReceivableRow =
        $partialReceivable->fetch(PDO::FETCH_ASSOC);

    $check(
        is_array($partialReceivableRow)
        && (float) $partialReceivableRow['paid_amount'] === 100.0
        && (float) $partialReceivableRow['balance_amount'] === 130.0
        && $partialReceivableRow['status'] === 'partially_paid',
        'Finance-origin partial payment updates the Sales receivable projection'
    );
    $second = $finance->postCustomerPayment(
        $companyId,(int)$customer['id'],$journals['bank'],date('Y-m-d'),'ETB',230,'bank_transfer',
        'OVER-'.$suffix,[['invoice_id'=>$invoiceId,'amount'=>130]],$actorId
    );
    $paidInvoice = db()->query('SELECT residual_amount,payment_status FROM finance_invoices WHERE invoice_id='.$invoiceId)->fetch(PDO::FETCH_ASSOC);
    $check((float)$paidInvoice['residual_amount']===0.0 && $paidInvoice['payment_status']==='paid'
        && (float)$second['unallocatedAmount']===100.0,
        'Overpayment pays the invoice and preserves the excess as customer credit');
    $finalReceivable = db()->prepare(
        'SELECT paid_amount, balance_amount, status
         FROM finance_sales_receivables
         WHERE company_id = :company_id
           AND order_id = :order_id'
    );

    $finalReceivable->execute([
        'company_id' => $companyId,
        'order_id' => (int) $order['orderId'],
    ]);

    $finalReceivableRow =
        $finalReceivable->fetch(PDO::FETCH_ASSOC);

    $check(
        is_array($finalReceivableRow)
        && (float) $finalReceivableRow['paid_amount'] === 230.0
        && (float) $finalReceivableRow['balance_amount'] === 0.0
        && $finalReceivableRow['status'] === 'paid',
        'Finance-origin final payment closes the Sales receivable projection'
    );
    $paidOrder=$sales->orderDetail((int)$order['orderId']);
    $check(
        ($paidOrder['payment_state']??'')==='paid'
        && (float)($paidOrder['credit_note_eligible_quantity']??-1)===0.0,
        'Final payment reload does not invent credit eligibility when no completed return exists'
    );

    $unbalancedRejected = false;
    try {
        $accounts = $finance->ensureSystemAccounts($companyId,'ETB',$actorId);
        $finance->postBalancedJournal($companyId,'BAD-'.$suffix,'test',null,null,date('Y-m-d'),'ETB',
            'Unbalanced fixture','bad-'.$suffix,[
                ['account_id'=>$accounts['cash'],'debit'=>10,'credit'=>0],
                ['account_id'=>$accounts['sales_revenue'],'debit'=>0,'credit'=>9],
            ],$actorId);
    } catch (RuntimeException) { $unbalancedRejected = true; }
    $check($unbalancedRejected,'Unbalanced journal posting is rejected atomically');

    $crossTenantRejected = false;
    $otherCompany = (int)db()->query('SELECT company_id FROM companies WHERE company_id<>'.$companyId.' ORDER BY company_id LIMIT 1')->fetchColumn();
    if ($otherCompany > 0) {
        try { $finance->postInvoice($otherCompany,$invoiceId,$actorId); }
        catch (RuntimeException) { $crossTenantRejected = true; }
    } else { $crossTenantRejected = true; }
    $check($crossTenantRejected,'Cross-company invoice access is rejected');
} catch (Throwable $exception) {
    $failures[] = $exception::class.': '.$exception->getMessage();
    fwrite(STDERR,'FAIL '.end($failures).PHP_EOL);
}

fwrite(STDOUT,sprintf("\nFinance accounting integration: %d failure(s)\n",count($failures)));
exit($failures === [] ? 0 : 1);
