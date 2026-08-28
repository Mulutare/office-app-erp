<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';

use App\Services\SalesWorkflowTraceService;

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$stage = static function (array $trace, string $code): array {
    foreach ($trace['stages'] as $item) {
        if ($item['code'] === $code) return $item;
    }
    throw new RuntimeException('Missing workflow stage: ' . $code);
};

$pdo = db();
$pdo->beginTransaction();
try {
    $context = $pdo->query(
        "SELECT c.company_id,u.user_id,sp.product_id,
                iw.warehouse_id,iot.operation_type_id,
                iot.default_source_location_id source_location_id,
                iot.default_destination_location_id destination_location_id
         FROM companies c
         INNER JOIN users u ON 1=1
         INNER JOIN sales_products sp ON sp.company_id=c.company_id
         INNER JOIN inventory_warehouses iw ON iw.company_id=c.company_id
         INNER JOIN inventory_operation_types iot
            ON iot.company_id=c.company_id AND iot.warehouse_id=iw.warehouse_id
           AND iot.operation_kind='delivery'
         WHERE iot.default_source_location_id<>iot.default_destination_location_id
         ORDER BY c.company_id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!is_array($context)) {
        throw new RuntimeException('Required seeded Sales/Finance/Inventory fixture data is unavailable.');
    }
    $company = (int) $context['company_id'];
    $otherCompany = (int) ($pdo->query(
        'SELECT company_id FROM companies WHERE company_id<>' . $company . ' ORDER BY company_id LIMIT 1'
    )->fetchColumn() ?: 0);
    $suffix = strtoupper(bin2hex(random_bytes(4)));
    $insert = $pdo->prepare(
        'INSERT INTO sales_customers(company_id,customer_number,name,created_by) VALUES(?,?,?,?)'
    );
    $insert->execute([$company,'CUS-TRACE-'.$suffix,'Workflow Trace Customer',$context['user_id']]);
    $context['customer_id'] = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare(
        "INSERT INTO finance_journals(company_id,journal_code,journal_name,journal_type,created_by)
         VALUES(?,?,?,'sales',?)"
    );
    $insert->execute([$company,'TR'.$suffix,'Workflow Trace Sales',$context['user_id']]);
    $context['journal_id'] = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare(
        "INSERT INTO company_bank_accounts
         (company_id,bank_name,account_name,account_number,currency,created_by)
         VALUES(?,?,?,?,'ETB',?)"
    );
    $insert->execute([$company,'Workflow Trace Bank','Workflow Trace','TRACE-'.$suffix,$context['user_id']]);
    $context['bank_account_id'] = (int) $pdo->lastInsertId();

    $insert = $pdo->prepare(
        "INSERT INTO sales_orders
         (company_id,customer_id,order_number,order_date,due_date,status,currency,
          subtotal,total_amount,created_by)
         VALUES(:company,:customer,:number,CURDATE(),CURDATE(),'confirmed','ETB',100,100,:user)"
    );
    $insert->execute(['company'=>$company,'customer'=>$context['customer_id'],'number'=>'SO-TRACE-'.$suffix,'user'=>$context['user_id']]);
    $order = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare(
        'INSERT INTO sales_order_lines
         (company_id,order_id,product_id,description,quantity,unit_price,line_total)
         VALUES(?,?,?,?,10,10,100)'
    );
    $insert->execute([$company,$order,$context['product_id'],'Workflow trace fixture']);

    $insert = $pdo->prepare(
        "INSERT INTO sales_quotations
         (company_id,quotation_number,customer_id,sales_order_id,quotation_date,
          currency,status,untaxed_amount,tax_amount,total_amount,created_by)
         VALUES(?,?,?,?,CURDATE(),'ETB','confirmed',100,0,100,?)"
    );
    $insert->execute([$company,'QT-TRACE-'.$suffix,$context['customer_id'],$order,$context['user_id']]);
    $quotation = (int) $pdo->lastInsertId();

    $pickingInsert = $pdo->prepare(
        "INSERT INTO inventory_pickings
         (company_id,warehouse_id,operation_type_id,sales_order_id,picking_type,
          picking_number,source_location_id,destination_location_id,status,created_by)
         VALUES(?,?,?,? ,?,?,?,? ,?,?)"
    );
    $lineInsert = $pdo->prepare(
        "INSERT INTO inventory_picking_lines
         (company_id,picking_id,product_id,source_location_id,destination_location_id,
          requested_quantity,reserved_quantity,completed_quantity,returned_quantity,status)
         VALUES(?,?,?,?,?,?,?,?,0,'done')"
    );
    $deliveries = [];
    foreach ([4, 6] as $index => $quantity) {
        $pickingInsert->execute([$company,$context['warehouse_id'],$context['operation_type_id'],$order,
            'delivery','DEL-TRACE-'.$suffix.'-'.($index+1),$context['source_location_id'],
            $context['destination_location_id'],'done',$context['user_id']]);
        $deliveries[] = (int) $pdo->lastInsertId();
        $lineInsert->execute([$company,end($deliveries),$context['product_id'],$context['source_location_id'],
            $context['destination_location_id'],$quantity,$quantity,$quantity]);
    }
    $pickingInsert->execute([$company,$context['warehouse_id'],$context['operation_type_id'],$order,
        'customer_return','RET-TRACE-'.$suffix,$context['destination_location_id'],
        $context['source_location_id'],'done',$context['user_id']]);
    $return = (int) $pdo->lastInsertId();

    $invoiceInsert = $pdo->prepare(
        "INSERT INTO finance_invoices
         (company_id,journal_id,customer_id,sales_order_id,document_type,invoice_number,
          invoice_date,due_date,currency,status,payment_status,untaxed_amount,total_amount,
          residual_amount,created_by)
         VALUES(?,?,?,?,?,?,CURDATE(),CURDATE(),'ETB',?,?,?,?,?,?)"
    );
    $invoiceInsert->execute([$company,$context['journal_id'],$context['customer_id'],$order,
        'customer_invoice','INV-TRACE-'.$suffix,'posted','partially_paid',100,100,40,$context['user_id']]);
    $invoice = (int) $pdo->lastInsertId();
    $invoiceInsert->execute([$company,$context['journal_id'],$context['customer_id'],$order,
        'customer_credit','CRN-TRACE-'.$suffix,'posted','credit',10,10,0,$context['user_id']]);
    $credit = (int) $pdo->lastInsertId();

    $insert = $pdo->prepare(
        "INSERT INTO finance_payments
         (company_id,journal_id,customer_id,payment_number,direction,payment_date,currency,
          amount,allocated_amount,unallocated_amount,method,status,created_by)
         VALUES(?,?,?,?,'inbound',CURDATE(),'ETB',60,60,0,'bank','posted',?)"
    );
    $insert->execute([$company,$context['journal_id'],$context['customer_id'],'PAY-TRACE-'.$suffix,$context['user_id']]);
    $payment = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare(
        'INSERT INTO finance_payment_allocations(company_id,payment_id,invoice_id,amount,allocated_by,allocated_at)
         VALUES(?,?,?,?,?,NOW())'
    );
    $insert->execute([$company,$payment,$invoice,60,$context['user_id']]);
    $allocation = (int) $pdo->lastInsertId();

    $insert = $pdo->prepare(
        "INSERT INTO sales_settlements
         (company_id,settlement_number,bank_account_id,currency,expected_amount,
          confirmed_amount,variance_amount,remaining_amount,reconciliation_status,
          workflow_status,created_by)
         VALUES(?,? ,?,'ETB',60,0,-60,60,'awaiting_confirmation','draft',?)"
    );
    $insert->execute([$company,'SET-TRACE-'.$suffix,$context['bank_account_id'],$context['user_id']]);
    $settlement = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare(
        'INSERT INTO sales_settlement_lines
         (company_id,settlement_id,sales_order_id,finance_payment_id,amount) VALUES(?,?,?,?,60)'
    );
    $insert->execute([$company,$settlement,$order,$payment]);

    $auth = [
        'permissions'=>['sales.view','finance.records.view','sales.settlements.view'],
        'modules'=>['sales','finance'],
    ];
    $service = new SalesWorkflowTraceService();
    $origins = [
        'quotation'=>$quotation,'order'=>$order,'delivery'=>$deliveries[0],
        'invoice'=>$invoice,'payment'=>$payment,'settlement'=>$settlement,
    ];
    foreach ($origins as $type => $id) {
        $trace = $service->trace($company,$type,$id,$auth);
        $check(is_array($trace) && $trace['chain_reference']==='SO-TRACE-'.$suffix,
            ucfirst($type).' resolves to the same authoritative Sales chain');
        $check(($trace['current_stage'] ?? null)===$type,
            ucfirst($type).' is marked as the current stage');
    }

    $trace = $service->trace($company,'order',$order,$auth);
    $check(count($stage($trace,'delivery')['records'])===2
        && $stage($trace,'delivery')['status']==='completed',
        'Multiple deliveries and backorder-style fulfillment remain visible and complete');
    $check(count($trace['related_records'])===2
        && str_contains((string)$trace['related_records'][0]['url'],'/sales/deliveries/'.$return),
        'Customer returns and credit notes are related branches, not forward stages');
    $check($stage($trace,'payment')['status']==='partial'
        && $trace['next_pending_stage']==='payment'
        && $trace['next_action']==='Record customer payment',
        'Partial payment keeps Payment as the Action Required-aligned next step');
    $check($stage($trace,'quotation')['clickable'] && $stage($trace,'order')['clickable']
        && $stage($trace,'invoice')['clickable'] && $stage($trace,'settlement')['clickable'],
        'Existing backward and forward documents are clickable');
    $restricted = $service->trace($company,'order',$order,['permissions'=>[],'modules'=>[]]);
    $check(!$stage($restricted,'order')['clickable'] && !$stage($restricted,'invoice')['clickable'],
        'Permission scope removes document links without hiding workflow state');
    if ($otherCompany > 0) {
        $check($service->trace($otherCompany,'order',$order,$auth)===null,
            'Tenant scope prevents cross-company workflow resolution');
    }

    $pdo->prepare("UPDATE finance_invoices SET payment_status='paid',residual_amount=0 WHERE invoice_id=?")
        ->execute([$invoice]);
    $pdo->prepare('UPDATE finance_payments SET amount=100,allocated_amount=100 WHERE payment_id=?')
        ->execute([$payment]);
    $pdo->prepare('UPDATE finance_payment_allocations SET amount=100 WHERE allocation_id=?')
        ->execute([$allocation]);
    $pdo->prepare("UPDATE sales_settlements SET workflow_status='closed',reconciliation_status='matched',
                  confirmed_amount=60,variance_amount=0,remaining_amount=0,closed_at=NOW()
                  WHERE settlement_id=?")->execute([$settlement]);
    $trace = $service->trace($company,'settlement',$settlement,$auth);
    $check($trace['complete']===true && $stage($trace,'complete')['status']==='completed'
        && $trace['next_action']===null,
        'Paid, delivered, posted, closed workflow reaches Complete with no forward action');
} catch (Throwable $exception) {
    $failed++;
    echo 'FAIL unexpected: ' . $exception->getMessage() . PHP_EOL;
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

echo PHP_EOL . ($passed + $failed) . ' Sales workflow trace checks, ' . $failed . ' failures' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
