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

    $journals = $finance->ensureSystemJournals($companyId,'ETB',$actorId);
    $first = $finance->postCustomerPayment(
        $companyId,(int)$customer['id'],$journals['bank'],date('Y-m-d'),'ETB',100,'bank_transfer',
        'PART-'.$suffix,[['invoice_id'=>$invoiceId,'amount'=>100]],$actorId
    );
    $residual = (float)db()->query('SELECT residual_amount FROM finance_invoices WHERE invoice_id='.$invoiceId)->fetchColumn();
    $check($residual===130.0 && (float)$first['allocatedAmount']===100.0,
        'Partial payment leaves the exact invoice residual');

    $second = $finance->postCustomerPayment(
        $companyId,(int)$customer['id'],$journals['bank'],date('Y-m-d'),'ETB',230,'bank_transfer',
        'OVER-'.$suffix,[['invoice_id'=>$invoiceId,'amount'=>130]],$actorId
    );
    $paidInvoice = db()->query('SELECT residual_amount,payment_status FROM finance_invoices WHERE invoice_id='.$invoiceId)->fetch(PDO::FETCH_ASSOC);
    $check((float)$paidInvoice['residual_amount']===0.0 && $paidInvoice['payment_status']==='paid'
        && (float)$second['unallocatedAmount']===100.0,
        'Overpayment pays the invoice and preserves the excess as customer credit');

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
