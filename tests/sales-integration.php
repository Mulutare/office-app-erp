<?php

declare(strict_types=1);

use App\Repositories\RepositoryFactory;
use App\Services\SalesService;
use App\Services\IntegrationDispatcherService;
use App\Services\IntegrationEventHandler;
use App\Services\WarehouseManagementService;

require_once __DIR__ . '/../app/helpers/bootstrap.php';

$companyId = (int) db()->query(
    "SELECT company_id FROM companies
     WHERE code = 'default' LIMIT 1"
)->fetchColumn();
$actorId = (int) db()->query(
    'SELECT user_id FROM users ORDER BY user_id LIMIT 1'
)->fetchColumn();
$approverId = (int) db()->query(
    'SELECT user_id FROM users WHERE user_id <> ' . $actorId . ' ORDER BY user_id LIMIT 1'
)->fetchColumn();

if ($companyId < 1 || $actorId < 1 || $approverId < 1) {
    fwrite(STDERR, 'FAIL Sales fixtures are unavailable.' . PHP_EOL);
    exit(1);
}

$_SESSION['auth'] = [
    'user_id' => $actorId,
    'company' => ['company_id' => $companyId],
];

$service = new SalesService();
$repository = RepositoryFactory::sales();
$suffix = strtoupper(bin2hex(random_bytes(3)));
$created = [
    'territory' => 0,
    'agent' => 0,
    'customer' => 0,
    'products' => [],
    'warehouse' => 0,
    'location' => 0,
    'stock_balances' => [],
    'target' => 0,
    'order' => 0,
    'cancelled_order' => 0,
    'reliability_events' => [],
];
$failures = [];
$check = static function (
    bool $condition,
    string $description
) use (&$failures): void {
    fwrite(
        $condition ? STDOUT : STDERR,
        ($condition ? 'PASS ' : 'FAIL ')
        . $description . PHP_EOL
    );
    if (!$condition) {
        $failures[] = $description;
    }
};

try {
    $territory = $service->createTerritory([
        'code' => 'T-' . $suffix,
        'name' => 'Integration Territory ' . $suffix,
    ], $actorId);
    $created['territory'] = (int) ($territory['id'] ?? 0);
    $check(!empty($territory['successful']), 'Territory is created');

    $agent = $service->createAgent([
        'agent_code' => 'A-' . $suffix,
        'name' => 'Integration DSA ' . $suffix,
        'agent_type' => 'DSA',
        'territory_id' => $created['territory'],
        'phone' => '+251900000000',
    ], $actorId);
    $created['agent'] = (int) ($agent['id'] ?? 0);
    $check(!empty($agent['successful']), 'DSA is created in the territory');

    $customer = $service->createCustomer([
        'customer_number' => 'C-' . $suffix,
        'name' => 'Integration Customer ' . $suffix,
        'customer_type' => 'business',
        'territory_id' => $created['territory'],
        'credit_limit' => '10000',
        'payment_terms_days' => '30',
    ], $actorId);
    $created['customer'] = (int) ($customer['id'] ?? 0);
    $check(!empty($customer['successful']), 'Customer is created');

    foreach ([['SIM', 100, 5], ['ROUTER', 500, 2]] as $index => $fixture) {
        $product = $service->createProduct([
            'sku' => $fixture[0] . '-' . $suffix,
            'name' => 'Integration Product ' . ($index + 1),
            'category' => 'Telecom',
            'product_type' => 'telecom_product',
            'unit_of_measure' => 'unit',
            'unit_price' => (string) $fixture[1],
            'commission_rate' => (string) $fixture[2],
            'serial_tracking' => $index === 0,
        ], $actorId);
        $created['products'][] = (int) ($product['id'] ?? 0);
        $check(!empty($product['successful']), 'Product line ' . ($index + 1) . ' is created');
    }

    $warehouseCode = 'TEST-WH-' . $suffix;
    $warehouse = (new WarehouseManagementService())->create([
        'code' => $warehouseCode,
        'name' => 'Integration Warehouse ' . $suffix,
        'warehouse_type' => 'standard',
        'branch_id' => null,
        'manager_user_id' => null,
        'address' => null,
        'phone' => null,
        'email' => null,
        'allow_negative_stock' => false,
        'is_default' => true,
        'active' => true,
    ], $actorId);
    $created['warehouse'] = (int) ($warehouse['warehouseId'] ?? 0);
    $locationStatement = db()->prepare(
        "SELECT location_id FROM inventory_warehouse_locations
         WHERE company_id = :company_id AND warehouse_id = :warehouse_id
           AND code = :code AND location_usage = 'internal'"
    );
    $locationStatement->execute([
        'company_id' => $companyId,
        'warehouse_id' => $created['warehouse'],
        'code' => $warehouseCode . '/STOCK',
    ]);
    $created['location'] = (int) $locationStatement->fetchColumn();

    $stockStatement = db()->prepare(
        "INSERT INTO inventory_stock_balances (
            company_id,
            warehouse_id,
            location_id,
            product_id,
            quantity_on_hand,
            quantity_reserved,
            average_unit_cost
        ) VALUES (
            :company_id,
            :warehouse_id,
            :location_id,
            :product_id,
            100,
            0,
            10
        )"
    );

    foreach ($created['products'] as $productId) {
        $stockStatement->execute([
            'company_id' => $companyId,
            'warehouse_id' => $created['warehouse'],
            'location_id' => $created['location'],
            'product_id' => $productId,
        ]);

        $created['stock_balances'][] = (int)
            db()->lastInsertId();
    }

    $check(
        $created['warehouse'] > 0
        && $created['location'] > 0
        && count($created['stock_balances']) === 2,
        'Inventory picking fixtures are created'
    );
    $target = $service->createTarget([
        'territory_id' => $created['territory'],
        'agent_id' => $created['agent'],
        'period_start' => date('Y-m-01'),
        'period_end' => date('Y-m-t'),
        'target_amount' => '5000',
        'target_quantity' => '20',
    ], $actorId);
    $created['target'] = (int) ($target['id'] ?? 0);
    $check(!empty($target['successful']), 'Territory and DSA target is created');

    $order = $service->createOrder([
        'customer_id' => $created['customer'],
        'territory_id' => $created['territory'],
        'agent_id' => $created['agent'],
        'order_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d', strtotime('+30 days')),
        'currency' => 'ETB',
        'confirm' => true,
        'lines' => [
            ['product_id' => $created['products'][0], 'quantity' => 2, 'discount_amount' => 0, 'tax_rate' => 15],
            ['product_id' => $created['products'][1], 'quantity' => 1, 'discount_amount' => 20, 'tax_rate' => 15],
        ],
    ], $actorId);
    $created['order'] = (int) ($order['orderId'] ?? 0);
    $check(!empty($order['successful']), 'Multi-line order is submitted for approval');
    $check(
        preg_match('/^SO-[0-9]{8}$/', (string) ($order['orderNumber'] ?? '')) === 1,
        'Order uses the controlled tenant document sequence'
    );

    $selfApproval = $service->transitionOrder(
        $created['order'], 'approve', null, $actorId, 'sales-test-self-' . $suffix
    );
    $check(empty($selfApproval['successful']), 'Order creator cannot approve the same order');

    $approvalKey = 'sales-test-approve-' . $suffix;
    $approval = $service->transitionOrder(
        $created['order'], 'approve', null, $approverId, $approvalKey
    );
    $check(!empty($approval['successful']), 'Submitted order is approved');
    $automaticPicking=(int)db()->query('SELECT COUNT(*) FROM inventory_pickings WHERE company_id='.(int)$companyId.' AND sales_order_id='.(int)$created['order']." AND picking_type='delivery'")->fetchColumn();
    $check($automaticPicking>0,'Order approval automatically prepares an authoritative Inventory delivery picking');
    $approvalReplay = $service->transitionOrder(
        $created['order'], 'approve', null, $approverId, $approvalKey
    );
    $check(!empty($approvalReplay['successful']), 'Repeated approval request is idempotent');

    $deliveryId=(int)db()->query(
        'SELECT picking_id FROM inventory_pickings WHERE company_id='.(int)$companyId
        .' AND sales_order_id='.(int)$created['order']." AND picking_type='delivery' ORDER BY picking_id LIMIT 1"
    )->fetchColumn();
    $delivery=$service->delivery($deliveryId);
    $deliveryQuantities=[];
    foreach((array)($delivery['lines']??[]) as $line){
        $deliveryQuantities[(int)$line['picking_line_id']] = (float)$line['remaining_quantity'];
    }
    $deliveryCompletion=$service->completeDelivery($deliveryId,[
        'completed_quantity'=>$deliveryQuantities,
        'create_backorder'=>'',
        'idempotency_key'=>'sales-return-delivery-'.$suffix,
    ],$approverId);
    $check(!empty($deliveryCompletion['successful']),'Authoritative delivery is completed before return');
    $stableCompletedDelivery=$service->delivery($deliveryId);
    $stableCompletedOrder=$service->orderDetail($created['order']);
    $stableCompletedLine=(array)(($stableCompletedOrder['lines']??[])[0]??[]);
    $check(
        ($stableCompletedDelivery['status']??'')==='done'
        && (float)($stableCompletedLine['delivered_quantity']??0)===2.0
        && (float)($stableCompletedLine['returned_quantity']??0)===0.0
        && (float)($stableCompletedOrder['credit_note_eligible_quantity']??-1)===0.0,
        'Completed delivery remains authoritative on repeated delivery and Sales Order reloads'
    );

    $completedDelivery=$service->delivery($deliveryId);
    $firstDeliveryLine=(array)(($completedDelivery['lines']??[])[0]??[]);
    $returnCreation=$service->createReturn($deliveryId,[
        'return_quantity'=>[(int)($firstDeliveryLine['picking_line_id']??0)=>1],
    ],$approverId);
    $returnId=(int)($returnCreation['returnPickingId']??0);
    $returnPicking=$service->delivery($returnId);
    $returnQuantities=[];
    foreach((array)($returnPicking['lines']??[]) as $line){
        $returnQuantities[(int)$line['picking_line_id']] = (float)$line['remaining_quantity'];
    }
    $returnCompletion=$service->completeDelivery($returnId,[
        'completed_quantity'=>$returnQuantities,
        'create_backorder'=>'',
        'idempotency_key'=>'sales-return-complete-'.$suffix,
    ],$approverId);
    $returnedOrder=$service->orderDetail($created['order']);
    $returnedLine=(array)(($returnedOrder['lines']??[])[0]??[]);
    $check(
        !empty($returnCreation['successful']) && !empty($returnCompletion['successful'])
        && (float)($returnedLine['delivered_quantity']??0)===2.0
        && (float)($returnedLine['returned_quantity']??0)===1.0
        && (float)($returnedLine['net_delivered_quantity']??0)===1.0,
        'Customer return preserves delivered quantity and recalculates returned and net delivered quantities'
    );

    $historyStatement = db()->prepare(
        'SELECT COUNT(*) FROM sales_order_status_history
         WHERE company_id = :company_id AND order_id = :order_id'
    );
    $historyStatement->execute(['company_id' => $companyId, 'order_id' => $created['order']]);
    $check((int) $historyStatement->fetchColumn() === 2, 'Order creation and approval have immutable transition history');

    $serials = $service->registerSerialNumbers([
        'product_id' => $created['products'][0],
        'serial_numbers' => 'IMEI-' . $suffix . "\nICCID-" . $suffix,
    ], $actorId);
    $check(!empty($serials['successful']) && ($serials['count'] ?? 0) === 2, 'Telecom serial numbers are registered');

    $lineCount = 0;
    if ($created['order'] > 0) {
        $lineStatement = db()->prepare(
            'SELECT COUNT(*) FROM sales_order_lines
             WHERE company_id = :company_id AND order_id = :order_id'
        );
        $lineStatement->execute([
            'company_id' => $companyId,
            'order_id' => $created['order'],
        ]);
        $lineCount = (int) $lineStatement->fetchColumn();
    }
    $check($lineCount === 2, 'Order stores both product lines');

    $commissionId = (int) db()->query(
        'SELECT commission_id FROM sales_commissions WHERE order_id = ' . $created['order'] . ' LIMIT 1'
    )->fetchColumn();
    $commissionApproval = $service->transitionCommission($commissionId, 'approve', $actorId);
    $check(!empty($commissionApproval['successful']), 'Accrued DSA commission is approved');

    $payment = $service->recordPayment($created['order'], [
        'receipt_number' => 'R-' . $suffix,
        'payment_date' => date('Y-m-d'),
        'amount' => '300',
        'payment_method' => 'bank_transfer',
        'reference_number' => 'BANK-' . $suffix,
    ], $actorId);
    $check(!empty($payment['successful']), 'Partial customer payment is recorded');

    $dispatch = (new IntegrationDispatcherService())->dispatch(50);
    if ($dispatch['failed'] > 0) {
        $diagnostic = db()->prepare(
            "SELECT event_type, last_error
             FROM integration_outbox
             WHERE company_id = :company_id
               AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.order_id'))
                    = :order_id
             ORDER BY created_at"
        );
        $diagnostic->execute([
            'company_id' => $companyId,
            'order_id' => (string) $created['order'],
        ]);
        foreach ($diagnostic->fetchAll(PDO::FETCH_ASSOC) as $failure) {
            fwrite(
                STDERR,
                'INTEGRATION ' . $failure['event_type'] . ': '
                . $failure['last_error'] . PHP_EOL
            );
        }
    }
    $check(
        $dispatch['processed'] >= 2 && $dispatch['failed'] === 0,
        'Order and payment integration events are dispatched'
    );
    $webhookEventDispatch = (new IntegrationDispatcherService())->dispatch(50);
    $check(
        $webhookEventDispatch['processed'] === 1
        && $webhookEventDispatch['failed'] === 0,
        'Ordered Sales webhook event is released after its internal predecessor'
    );

    $receivableStatement = db()->prepare(
        'SELECT paid_amount, balance_amount, status
         FROM finance_sales_receivables
         WHERE company_id = :company_id AND order_id = :order_id'
    );
    $receivableStatement->execute([
        'company_id' => $companyId,
        'order_id' => $created['order'],
    ]);
    $receivable = $receivableStatement->fetch(PDO::FETCH_ASSOC);
    $check(
        is_array($receivable)
        && (float) $receivable['paid_amount'] === 300.0
        && $receivable['status'] === 'partially_paid',
        'Finance projection receives the order and payment'
    );

    $commitmentStatement = db()->prepare(
        'SELECT COUNT(*) FROM inventory_sales_commitments
         WHERE company_id = :company_id AND order_id = :order_id
           AND status = \'reserved\''
    );
    $commitmentStatement->execute([
        'company_id' => $companyId,
        'order_id' => $created['order'],
    ]);
    $check(
        (int) $commitmentStatement->fetchColumn() === 2,
        'Inventory projection reserves both product lines'
    );

    $workspace = $service->workspace();
    $targetRow = array_values(array_filter(
        $workspace['targets'],
        static fn (array $row): bool =>
            (int) $row['target_id'] === $created['target']
    ))[0] ?? null;
    $check(
        is_array($targetRow)
        && (float) $targetRow['achieved_amount'] > 0,
        'Target achievement includes the confirmed order'
    );
    $check(
        (float) $workspace['summary']['receivableTotal'] > 0
        && (float) $workspace['summary']['commissionTotal'] > 0,
        'Dashboard reports receivable and commission balances'
    );

    $eventInsert = db()->prepare(
        "INSERT INTO integration_outbox
            (event_id, company_id, event_type, aggregate_type, aggregate_id,
             payload_json, status, attempts, available_at)
         VALUES
            (:event_id, :company_id, 'sales.test.failure', 'sales_order',
             :aggregate_id, '{}', 'pending', 0, NOW())"
    );
    foreach (['1', '2'] as $position) {
        $eventId = strtolower(sprintf(
            '%s-%s000-4000-8000-%012d',
            substr($suffix, 0, 6), $position, (int) $position
        ));
        $created['reliability_events'][] = $eventId;
        $eventInsert->execute([
            'event_id' => $eventId,
            'company_id' => $companyId,
            'aggregate_id' => 'ordering-' . $suffix,
        ]);
    }
    $failingHandler = new class implements IntegrationEventHandler {
        public function supports(string $eventType): bool
        {
            return $eventType === 'sales.test.failure';
        }

        public function handle(array $event): void
        {
            throw new RuntimeException('Intentional ordering fixture failure.');
        }
    };
    $failureDispatch = (new IntegrationDispatcherService(null, [$failingHandler]))->dispatch(10);
    $eventStates = db()->prepare(
        'SELECT status, attempts FROM integration_outbox
         WHERE event_id IN (:first_event, :second_event)
         ORDER BY outbox_sequence'
    );
    $eventStates->execute([
        'first_event' => $created['reliability_events'][0],
        'second_event' => $created['reliability_events'][1],
    ]);
    $states = $eventStates->fetchAll(PDO::FETCH_ASSOC);
    $check(
        $failureDispatch['failed'] === 1
        && ($states[0]['status'] ?? null) === 'failed'
        && (int) ($states[0]['attempts'] ?? 0) === 1
        && ($states[1]['status'] ?? null) === 'pending',
        'Failed predecessor is retried and blocks later aggregate events'
    );
} catch (Throwable $exception) {
    $failures[] = $exception::class . ': ' . $exception->getMessage();
    fwrite(STDERR, 'FAIL ' . end($failures) . PHP_EOL);
} finally {
    if ($created['order'] > 0) {
        $pickingIds = db()->prepare(
            'SELECT picking_id FROM inventory_pickings WHERE company_id=:company_id AND sales_order_id=:order_id'
        );
        $pickingIds->execute(['company_id'=>$companyId,'order_id'=>$created['order']]);
        $pickingIds = array_map('intval',$pickingIds->fetchAll(PDO::FETCH_COLUMN));
        foreach (array_reverse($pickingIds) as $pickingId) {
            db()->prepare("DELETE FROM inventory_stock_movements WHERE company_id=:company_id AND reference_type='inventory_picking' AND reference_id=:id")
                ->execute(['company_id'=>$companyId,'id'=>$pickingId]);
            db()->prepare('DELETE FROM inventory_picking_completions WHERE company_id=:company_id AND picking_id=:id')
                ->execute(['company_id'=>$companyId,'id'=>$pickingId]);
            db()->prepare('DELETE FROM inventory_picking_lines WHERE company_id=:company_id AND picking_id=:id')
                ->execute(['company_id'=>$companyId,'id'=>$pickingId]);
            db()->prepare('DELETE FROM inventory_pickings WHERE company_id=:company_id AND picking_id=:id')
                ->execute(['company_id'=>$companyId,'id'=>$pickingId]);
        }
        db()->prepare('DELETE FROM inventory_sales_reservation_allocations WHERE company_id=:company_id AND order_id=:order_id')
            ->execute(['company_id'=>$companyId,'order_id'=>$created['order']]);

        foreach($created['products'] as $productId){
            db()->prepare('DELETE FROM inventory_stock_balances WHERE company_id=:company_id AND warehouse_id=:warehouse_id AND product_id=:product_id')
                ->execute(['company_id'=>$companyId,'warehouse_id'=>$created['warehouse'],'product_id'=>$productId]);
        }

        $batchIds = db()->prepare(
            "SELECT journal_batch_id FROM finance_invoices WHERE company_id=:invoice_company AND sales_order_id=:order_id
             UNION SELECT journal_batch_id FROM finance_payments WHERE company_id=:payment_company AND customer_id=:customer_id"
        );
        $batchIds->execute(['invoice_company'=>$companyId,'payment_company'=>$companyId,
            'order_id'=>$created['order'],'customer_id'=>$created['customer']]);
        $batchIds = array_values(array_filter(array_map('intval',$batchIds->fetchAll(PDO::FETCH_COLUMN))));
        db()->prepare(
            'DELETE FROM finance_payment_allocations WHERE company_id=:company_id AND invoice_id IN
             (SELECT invoice_id FROM finance_invoices WHERE company_id=:invoice_company AND sales_order_id=:order_id)'
        )->execute(['company_id'=>$companyId,'invoice_company'=>$companyId,'order_id'=>$created['order']]);
        db()->prepare('DELETE FROM finance_payments WHERE company_id=:company_id AND customer_id=:customer_id')
            ->execute(['company_id'=>$companyId,'customer_id'=>$created['customer']]);
        db()->prepare('DELETE FROM finance_invoices WHERE company_id=:company_id AND sales_order_id=:order_id')
            ->execute(['company_id'=>$companyId,'order_id'=>$created['order']]);
        foreach ($batchIds as $batchId) {
            db()->prepare('DELETE FROM finance_journal_batches WHERE company_id=:company_id AND journal_batch_id=:batch_id')
                ->execute(['company_id'=>$companyId,'batch_id'=>$batchId]);
        }
    }
    $deletions = [
        ['inventory_sales_commitments', 'order_id', $created['order']],
        ['finance_sales_receipts', 'order_id', $created['order']],
        ['finance_sales_receivables', 'order_id', $created['order']],
        ['sales_commissions', 'order_id', $created['order']],
        ['sales_order_status_history', 'order_id', $created['order']],
        ['sales_serial_numbers', 'product_id', $created['products'][0] ?? 0],
        ['sales_payments', 'order_id', $created['order']],
        ['sales_order_lines', 'order_id', $created['order']],
        ['sales_orders', 'order_id', $created['order']],
        ['sales_targets', 'target_id', $created['target']],
        ['sales_agents', 'agent_id', $created['agent']],
        ['sales_customers', 'customer_id', $created['customer']],
    ];
    foreach (
        array_reverse($created['stock_balances'])
        as $stockBalanceId
    ) {
        $deletions[] = [
            'inventory_stock_balances',
            'stock_balance_id',
            $stockBalanceId,
        ];
    }

    foreach (array_reverse($created['products']) as $productId) {
        $deletions[] = ['sales_products', 'product_id', $productId];
    }
    $deletions[] = ['sales_territories', 'territory_id', $created['territory']];
    foreach ($deletions as [$table, $column, $id]) {
        if ((int) $id < 1) {
            continue;
        }
        $statement = db()->prepare(
            "DELETE FROM {$table}
             WHERE company_id = :company_id
               AND {$column} = :record_id"
        );
        try {
            $statement->execute([
                'company_id' => $companyId,
                'record_id' => $id,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'Sales integration cleanup failed for '.$table.'.'.$column.': '.$exception->getMessage(),
                0,
                $exception
            );
        }
    }
    if ($created['warehouse'] > 0) {
        db()->prepare('DELETE FROM inventory_operation_types WHERE company_id=:company_id AND warehouse_id=:warehouse_id')
            ->execute(['company_id'=>$companyId,'warehouse_id'=>$created['warehouse']]);
        db()->prepare('DELETE FROM inventory_warehouse_locations WHERE company_id=:company_id AND warehouse_id=:warehouse_id AND parent_location_id IS NOT NULL')
            ->execute(['company_id'=>$companyId,'warehouse_id'=>$created['warehouse']]);
        db()->prepare('DELETE FROM inventory_warehouse_locations WHERE company_id=:company_id AND warehouse_id=:warehouse_id AND parent_location_id IS NULL')
            ->execute(['company_id'=>$companyId,'warehouse_id'=>$created['warehouse']]);
        db()->prepare('DELETE FROM inventory_warehouses WHERE company_id=:company_id AND warehouse_id=:warehouse_id')
            ->execute(['company_id'=>$companyId,'warehouse_id'=>$created['warehouse']]);
    }
    db()->prepare(
        "DELETE FROM integration_outbox
         WHERE company_id = :company_id
           AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.order_id'))
                = :order_id"
    )->execute([
        'company_id' => $companyId,
        'order_id' => (string) $created['order'],
    ]);
    foreach ($created['reliability_events'] as $eventId) {
        db()->prepare(
            'DELETE FROM integration_outbox WHERE event_id = :event_id'
        )->execute(['event_id' => $eventId]);
    }
}

fwrite(STDOUT, PHP_EOL . sprintf(
    'Sales integration: %d failure(s)%s',
    count($failures),
    PHP_EOL
));
exit($failures === [] ? 0 : 1);
