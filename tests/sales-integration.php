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

$check(
    !empty($warehouse['successful'])
    && $created['warehouse'] > 0,
    'Inventory warehouse fixture is created'
);

if (
    empty($warehouse['successful'])
    || $created['warehouse'] < 1
) {
    throw new RuntimeException(
        'Warehouse fixture creation failed: '
        . json_encode(
            $warehouse['errors'] ?? $warehouse,
            JSON_UNESCAPED_SLASHES
        )
    );
}
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


$check(
    $created['location'] > 0,
    'Inventory warehouse STOCK location is provisioned'
);

if ($created['location'] < 1) {
    throw new RuntimeException(
        'Warehouse STOCK location was not provisioned for warehouse '
        . $created['warehouse']
        . ' code '
        . $warehouseCode
    );
}
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

    $approvalKey = 'sales-test-approve-' . $suffix;
    $approval = $service->transitionOrder(
        $created['order'], 'approve', null, $actorId, $approvalKey
    );
    $approvedOrder = $service->orderDetail($created['order']);
    $check(
        !empty($approval['successful'])
        && ($approvedOrder['status'] ?? '') === 'approved',
        'Order creator with approval privilege can manually approve the submitted order'
    );

    $pickingBeforeConfirm=(int)db()->query(
        'SELECT COUNT(*) FROM inventory_pickings WHERE company_id='.(int)$companyId
        .' AND sales_order_id='.(int)$created['order']." AND picking_type='delivery'"
    )->fetchColumn();
    $check(
        $pickingBeforeConfirm===0,
        'Manual approval does not prepare Inventory delivery before confirmation'
    );

    $approvalReplay = $service->transitionOrder(
        $created['order'], 'approve', null, $actorId, $approvalKey
    );
    $check(
        !empty($approvalReplay['successful']) && !empty($approvalReplay['replayed']),
        'Repeated manual approval request is idempotent'
    );

    $confirmKey = 'sales-test-confirm-' . $suffix;
    $confirmation = $service->transitionOrder(
        $created['order'], 'confirm', null, $actorId, $confirmKey
    );
    $confirmedOrder = $service->orderDetail($created['order']);
    $automaticPicking=(int)db()->query(
        'SELECT COUNT(*) FROM inventory_pickings WHERE company_id='.(int)$companyId
        .' AND sales_order_id='.(int)$created['order']." AND picking_type='delivery'"
    )->fetchColumn();
    $check(
        !empty($confirmation['successful'])
        && ($confirmedOrder['status'] ?? '') === 'confirmed'
        && $automaticPicking>0,
        'Manual confirmation confirms the order and prepares authoritative Inventory delivery'
    );

    $confirmationReplay = $service->transitionOrder(
        $created['order'], 'confirm', null, $actorId, $confirmKey
    );
    $check(
        !empty($confirmationReplay['successful']) && !empty($confirmationReplay['replayed']),
        'Repeated manual confirmation request is idempotent'
    );$deliveryId=(int)db()->query(
        'SELECT picking_id FROM inventory_pickings WHERE company_id='.(int)$companyId
        .' AND sales_order_id='.(int)$created['order']." AND picking_type='delivery' ORDER BY picking_id LIMIT 1"
    )->fetchColumn();
    $delivery=$service->delivery($deliveryId);
    $deliveryQuantities = [];
$partialDeliveryQuantities = [];
$partialQuantityAssigned = false;

foreach ((array) ($delivery['lines'] ?? []) as $line) {
    $pickingLineId = (int) ($line['picking_line_id'] ?? 0);
    $remainingQuantity = (float) ($line['remaining_quantity'] ?? 0);

    $deliveryQuantities[$pickingLineId] = $remainingQuantity;
    $partialDeliveryQuantities[$pickingLineId] = 0.0;

    if (
        !$partialQuantityAssigned
        && $pickingLineId > 0
        && $remainingQuantity > 0
    ) {
        $partialDeliveryQuantities[$pickingLineId] =
            min(1.0, $remainingQuantity);
        $partialQuantityAssigned = true;
    }
}

$deliveryCompletion = $service->completeDelivery(
    $deliveryId,
    [
        'completed_quantity' => $partialDeliveryQuantities,
        'create_backorder' => '1',
        'idempotency_key' =>
            'sales-return-delivery-' . $suffix,
    ],
    $approverId
);

$backorderDeliveryId = (int) (
    $deliveryCompletion['backorderPickingId'] ?? 0
);

$backorderDelivery = $service->delivery($backorderDeliveryId);
$backorderDeliveryQuantities = [];

foreach ((array) ($backorderDelivery['lines'] ?? []) as $line) {
    $backorderDeliveryQuantities[
        (int) ($line['picking_line_id'] ?? 0)
    ] = (float) ($line['remaining_quantity'] ?? 0);
}

$backorderDeliveryCompletion = $service->completeDelivery(
    $backorderDeliveryId,
    [
        'completed_quantity' => $backorderDeliveryQuantities,
        'create_backorder' => '',
        'idempotency_key' =>
            'sales-return-delivery-backorder-' . $suffix,
    ],
    $approverId
);

$check(
    $partialQuantityAssigned
    && !empty($deliveryCompletion['successful'])
    && $backorderDeliveryId > 0
    && is_array($backorderDelivery)
    && !empty($backorderDeliveryCompletion['successful']),
    'Partial delivery creates and completes an authoritative backorder'
);

$stableCompletedDelivery = $service->delivery($deliveryId);
$stableCompletedBackorder =
    $service->delivery($backorderDeliveryId);
$stableCompletedOrder =
    $service->orderDetail($created['order']);
$stableCompletedLine = (array) (
    ($stableCompletedOrder['lines'] ?? [])[0] ?? []
);

$check(
    in_array(
        (string) ($stableCompletedDelivery['status'] ?? ''),
        ['done', 'partially_done'],
        true
    )
    && ($stableCompletedBackorder['status'] ?? '') === 'done'
    && (float) ($stableCompletedLine['delivered_quantity'] ?? 0) === 2.0
    && (float) ($stableCompletedLine['returned_quantity'] ?? 0) === 0.0
    && (float) (
        $stableCompletedOrder['credit_note_eligible_quantity'] ?? -1
    ) === 0.0,
    'Partial delivery and backorder remain authoritative on Sales Order reloads'
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
    $returnValuationStatement = db()->prepare(
        "SELECT
            movement_id,
            product_id,
            unit_cost,
            related_movement_id
         FROM inventory_stock_movements
         WHERE company_id = :company_id
           AND reference_type = 'inventory_picking'
           AND reference_id = :reference_id
           AND movement_type = 'return_in'
           AND status = 'completed'
         ORDER BY movement_id DESC
         LIMIT 1"
    );

    $returnValuationStatement->execute([
        'company_id' => $companyId,
        'reference_id' => $returnId,
    ]);

    $returnValuation =
        $returnValuationStatement->fetch(PDO::FETCH_ASSOC);

    $relatedDelivery = false;

    if (
        is_array($returnValuation)
        && (int) ($returnValuation['related_movement_id'] ?? 0) > 0
    ) {
        $relatedDeliveryStatement = db()->prepare(
            "SELECT
                movement_id,
                reference_id,
                product_id,
                movement_type,
                unit_cost
             FROM inventory_stock_movements
             WHERE company_id = :company_id
               AND movement_id = :movement_id
             LIMIT 1"
        );

        $relatedDeliveryStatement->execute([
            'company_id' => $companyId,
            'movement_id' =>
                (int) $returnValuation['related_movement_id'],
        ]);

        $relatedDelivery =
            $relatedDeliveryStatement->fetch(PDO::FETCH_ASSOC);
    }

    $check(
        is_array($returnValuation)
        && is_array($relatedDelivery)
        && (int) $relatedDelivery['movement_id']
            === (int) $returnValuation['related_movement_id']
        && (int) $relatedDelivery['reference_id']
            === $deliveryId
        && (string) $relatedDelivery['movement_type']
            === 'fulfilment'
        && (int) $relatedDelivery['product_id']
            === (int) $returnValuation['product_id']
        && abs(
            (float) $returnValuation['unit_cost']
            - (float) $relatedDelivery['unit_cost']
        ) < 0.000001,
        'Customer return restores original delivery valuation and movement linkage'
    );

    $historyStatement = db()->prepare(
        'SELECT COUNT(*) FROM sales_order_status_history
         WHERE company_id = :company_id AND order_id = :order_id'
    );
    $historyStatement->execute(['company_id' => $companyId, 'order_id' => $created['order']]);
    $check((int) $historyStatement->fetchColumn() === 3, 'Order submission, approval and confirmation have immutable transition history');

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

    $paymentFinance = new \App\Services\FinancePostingService();

$paymentInvoiceId =
    $paymentFinance->createCustomerInvoiceFromOrder(
        $companyId,
        (int) $created['order'],
        'delivered',
        $actorId
    );

$paymentInvoicePosting =
    $paymentFinance->postInvoice(
        $companyId,
        $paymentInvoiceId,
        $actorId
    );

$check(
    ($paymentInvoicePosting['status'] ?? '') === 'posted',
    'Posted customer invoice establishes the receivable before payment'
);

$payment = $service->recordPayment($created['order'], [
        'receipt_number' => 'R-' . $suffix,
        'payment_date' => date('Y-m-d'),
        'amount' => '300',
        'payment_method' => 'bank_transfer',
        'reference_number' => 'BANK-' . $suffix,
    ], $actorId);
    $check(!empty($payment['successful']), 'Partial customer payment is recorded');

    $dispatch = ['processed' => 0, 'failed' => 0];
    for ($dispatchPass = 0; $dispatchPass < 6; $dispatchPass++) {
        $batch = (new IntegrationDispatcherService())->dispatch(50);
        $dispatch['processed'] += (int) $batch['processed'];
        $dispatch['failed'] += (int) $batch['failed'];
        if ($batch['failed'] > 0 || ($batch['processed'] === 0 && $batch['failed'] === 0)) {
            break;
        }
    }
    if ($dispatch['failed'] > 0) {
        $diagnostic = db()->prepare(
            "SELECT event_type, last_error
             FROM integration_outbox
             WHERE company_id = :company_id
               AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.order_id'))
                    = :order_id
             ORDER BY outbox_sequence"
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
        $dispatch['processed'] >= 3 && $dispatch['failed'] === 0,
        'Approval, confirmation and payment integration events are dispatched'
    );

    $orderedEventStatement = db()->prepare(
        "SELECT event_type, status, outbox_sequence
         FROM integration_outbox
         WHERE company_id = :company_id
           AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.order_id')) = :order_id
           AND event_type IN ('sales.order.approved','sales.order.confirmed','sales.payment.recorded')
         ORDER BY outbox_sequence"
    );
    $orderedEventStatement->execute([
        'company_id' => $companyId,
        'order_id' => (string) $created['order'],
    ]);
    $orderedEvents = $orderedEventStatement->fetchAll(PDO::FETCH_ASSOC);
    $eventPositions = [];
    $allOrderedEventsProcessed = true;
    foreach ($orderedEvents as $position => $orderedEvent) {
        $eventPositions[(string) $orderedEvent['event_type']] ??= $position;
        $allOrderedEventsProcessed = $allOrderedEventsProcessed
            && ($orderedEvent['status'] ?? '') === 'processed';
    }
    $check(
        isset(
            $eventPositions['sales.order.approved'],
            $eventPositions['sales.order.confirmed'],
            $eventPositions['sales.payment.recorded']
        )
        && $eventPositions['sales.order.approved'] < $eventPositions['sales.order.confirmed']
        && $eventPositions['sales.order.confirmed'] < $eventPositions['sales.payment.recorded']
        && $allOrderedEventsProcessed,
        'Approval, confirmation and payment preserve order-level outbox causality'
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

    $inventoryMovementCost = static function (
        int $pickingId,
        string $movementType
    ) use ($companyId): float {
        $statement = db()->prepare(
            "SELECT COALESCE(
                SUM(completed_quantity * unit_cost),
                0
             )
             FROM inventory_stock_movements
             WHERE company_id = :company_id
               AND reference_type = 'inventory_picking'
               AND reference_id = :picking_id
               AND movement_type = :movement_type
               AND status = 'completed'"
        );

        $statement->execute([
            'company_id' => $companyId,
            'picking_id' => $pickingId,
            'movement_type' => $movementType,
        ]);

        return round(
            (float) $statement->fetchColumn(),
            2
        );
    };

    $inventoryCostJournals = static function (
        string $sourceType,
        int $sourceId
    ) use ($companyId): array {
        $statement = db()->prepare(
            "SELECT
                batches.journal_batch_id,
                batches.total_debit,
                batches.total_credit,
                COALESCE(
                    SUM(
                        CASE
                            WHEN accounts.account_name = 'Cost of Goods Sold'
                            THEN entries.debit_amount
                            ELSE 0
                        END
                    ),
                    0
                ) AS cogs_debit,
                COALESCE(
                    SUM(
                        CASE
                            WHEN accounts.account_name = 'Cost of Goods Sold'
                            THEN entries.credit_amount
                            ELSE 0
                        END
                    ),
                    0
                ) AS cogs_credit,
                COALESCE(
                    SUM(
                        CASE
                            WHEN accounts.account_name = 'Inventory Asset'
                            THEN entries.debit_amount
                            ELSE 0
                        END
                    ),
                    0
                ) AS inventory_debit,
                COALESCE(
                    SUM(
                        CASE
                            WHEN accounts.account_name = 'Inventory Asset'
                            THEN entries.credit_amount
                            ELSE 0
                        END
                    ),
                    0
                ) AS inventory_credit
             FROM finance_journal_batches batches
             INNER JOIN finance_journal_entries entries
                ON entries.company_id = batches.company_id
               AND entries.journal_batch_id =
                   batches.journal_batch_id
             INNER JOIN finance_accounts accounts
                ON accounts.company_id = entries.company_id
               AND accounts.account_id = entries.account_id
             WHERE batches.company_id = :company_id
               AND batches.source_type = :source_type
               AND batches.source_id = :source_id
             GROUP BY
                batches.journal_batch_id,
                batches.total_debit,
                batches.total_credit
             ORDER BY batches.journal_batch_id"
        );

        $statement->execute([
            'company_id' => $companyId,
            'source_type' => $sourceType,
            'source_id' => (string) $sourceId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    };

    $moneyMatches = static function (
        float $left,
        float $right
    ): bool {
        return abs($left - $right) < 0.005;
    };

    $deliveryMovementCost = $inventoryMovementCost(
    $deliveryId,
    'fulfilment'
);

$backorderMovementCost = $inventoryMovementCost(
    $backorderDeliveryId,
    'fulfilment'
);

$returnMovementCost = $inventoryMovementCost(
    $returnId,
    'return_in'
);

$deliveryCostJournals = $inventoryCostJournals(
    'inventory_fulfilment',
    $deliveryId
);

$backorderCostJournals = $inventoryCostJournals(
    'inventory_fulfilment',
    $backorderDeliveryId
);

$returnCostJournals = $inventoryCostJournals(
    'inventory_return',
    $returnId
);

$deliveryJournal =
    count($deliveryCostJournals) === 1
        ? $deliveryCostJournals[0]
        : null;

$backorderJournal =
    count($backorderCostJournals) === 1
        ? $backorderCostJournals[0]
        : null;

$returnJournal =
    count($returnCostJournals) === 1
        ? $returnCostJournals[0]
        : null;

$valuationForPicking = static function (int $pickingId) use ($companyId): array {
    $statement = db()->prepare(
        "SELECT COUNT(*) layer_count,COALESCE(SUM(total_value),0) total_value,
                SUM(journal_batch_id IS NULL) unlinked_count
         FROM inventory_valuation_layers
         WHERE company_id=:company_id AND source_document_type='inventory_picking'
           AND source_document_id=:picking_id"
    );
    $statement->execute(['company_id' => $companyId, 'picking_id' => $pickingId]);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
};
$deliveryValuation = $valuationForPicking($deliveryId);
$backorderValuation = $valuationForPicking($backorderDeliveryId);
$returnValuation = $valuationForPicking($returnId);

$check(
    $deliveryMovementCost > 0
    && $backorderMovementCost > 0
    && $returnMovementCost > 0
    && is_array($deliveryJournal)
    && is_array($backorderJournal)
    && is_array($returnJournal)

    && $moneyMatches(
        (float) $deliveryJournal['cogs_debit'],
        $deliveryMovementCost
    )
    && $moneyMatches(
        (float) $deliveryJournal['inventory_credit'],
        $deliveryMovementCost
    )
    && $moneyMatches(
        (float) $deliveryJournal['total_debit'],
        $deliveryMovementCost
    )
    && $moneyMatches(
        (float) $deliveryJournal['total_credit'],
        $deliveryMovementCost
    )

    && $moneyMatches(
        (float) $backorderJournal['cogs_debit'],
        $backorderMovementCost
    )
    && $moneyMatches(
        (float) $backorderJournal['inventory_credit'],
        $backorderMovementCost
    )
    && $moneyMatches(
        (float) $backorderJournal['total_debit'],
        $backorderMovementCost
    )
    && $moneyMatches(
        (float) $backorderJournal['total_credit'],
        $backorderMovementCost
    )

    && $moneyMatches(
        (float) $returnJournal['inventory_debit'],
        $returnMovementCost
    )
    && $moneyMatches(
        (float) $returnJournal['cogs_credit'],
        $returnMovementCost
    )
    && $moneyMatches(
        (float) $returnJournal['total_debit'],
        $returnMovementCost
    )
    && $moneyMatches(
        (float) $returnJournal['total_credit'],
        $returnMovementCost
    ),
    'Partial delivery, backorder, and return post balanced cost journals at persisted movement valuation'
);
$check(
    (int)($deliveryValuation['layer_count']??0)>0
    && (int)($backorderValuation['layer_count']??0)>0
    && (int)($returnValuation['layer_count']??0)>0
    && (int)($deliveryValuation['unlinked_count']??1)===0
    && (int)($backorderValuation['unlinked_count']??1)===0
    && (int)($returnValuation['unlinked_count']??1)===0
    && $moneyMatches(abs((float)$deliveryValuation['total_value']),$deliveryMovementCost)
    && $moneyMatches(abs((float)$backorderValuation['total_value']),$backorderMovementCost)
    && $moneyMatches((float)$returnValuation['total_value'],$returnMovementCost),
    'Delivery, backorder, and customer return valuation layers match and link to their existing cost journals'
);

    $deliveryReplay = $service->completeDelivery(
    $deliveryId,
    [
        'completed_quantity' => $partialDeliveryQuantities,
        'create_backorder' => '1',
        'idempotency_key' =>
            'sales-return-delivery-' . $suffix,
    ],
    $approverId
);

$backorderDeliveryReplay = $service->completeDelivery(
    $backorderDeliveryId,
    [
        'completed_quantity' =>
            $backorderDeliveryQuantities,
        'create_backorder' => '',
        'idempotency_key' =>
            'sales-return-delivery-backorder-' . $suffix,
    ],
    $approverId
);

$returnReplay = $service->completeDelivery(
    $returnId,
    [
        'completed_quantity' => $returnQuantities,
        'create_backorder' => '',
        'idempotency_key' =>
            'sales-return-complete-' . $suffix,
    ],
    $approverId
);

$replayDispatch =
    (new IntegrationDispatcherService())->dispatch(50);

$costBatchCountStatement = db()->prepare(
    "SELECT COUNT(*)
     FROM finance_journal_batches
     WHERE company_id = :company_id
       AND (
            (
                source_type = 'inventory_fulfilment'
                AND source_id IN (
                    :delivery_id,
                    :backorder_delivery_id
                )
            )
            OR
            (
                source_type = 'inventory_return'
                AND source_id = :return_id
            )
       )"
);

$costBatchCountStatement->execute([
    'company_id' => $companyId,
    'delivery_id' => (string) $deliveryId,
    'backorder_delivery_id' =>
        (string) $backorderDeliveryId,
    'return_id' => (string) $returnId,
]);

$check(
    !empty($deliveryReplay['successful'])
    && !empty($backorderDeliveryReplay['successful'])
    && !empty($returnReplay['successful'])
    && $replayDispatch['failed'] === 0
    && (int) $costBatchCountStatement->fetchColumn() === 3,
    'Partial picking, backorder, and return replay do not duplicate inventory cost journals'
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

        if ($pickingIds !== []) {
            $pickingPlaceholders = implode(
                ',',
                array_fill(0, count($pickingIds), '?')
            );

            $deleteCompletionReferences = db()->prepare(
                'DELETE FROM inventory_picking_completions
                 WHERE company_id = ?
                   AND (
                        picking_id IN (' . $pickingPlaceholders . ')
                        OR backorder_picking_id IN (' . $pickingPlaceholders . ')
                   )'
            );

            $deleteCompletionReferences->execute(
                array_merge(
                    [$companyId],
                    $pickingIds,
                    $pickingIds
                )
            );

            $detachPickingReferences = db()->prepare(
                'UPDATE inventory_pickings
                 SET backorder_of_id = NULL,
                     original_picking_id = NULL
                 WHERE company_id = ?
                   AND picking_id IN (' . $pickingPlaceholders . ')'
            );

            $detachPickingReferences->execute(
                array_merge(
                    [$companyId],
                    $pickingIds
                )
            );
        }
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
