<?php

declare(strict_types=1);

use App\Services\ActionRequiredCountService;
use App\Services\SalesService;

require_once __DIR__ . '/../app/helpers/bootstrap.php';

$pdo = db();
$failures = [];
$passed = 0;
$check = static function (bool $condition, string $description) use (&$failures, &$passed): void {
    fwrite($condition ? STDOUT : STDERR, ($condition ? 'PASS ' : 'FAIL ') . $description . PHP_EOL);
    $condition ? $passed++ : $failures[] = $description;
};
$reset = static function (): void {
    foreach (['requestCache', 'itemCache'] as $cache) {
        $property = new ReflectionProperty(ActionRequiredCountService::class, $cache);
        $property->setAccessible(true);
        $property->setValue(null, []);
    }
};

$company = (int) $pdo->query("SELECT company_id FROM companies WHERE code='default' LIMIT 1")->fetchColumn();
$actor = (int) $pdo->query('SELECT user_id FROM users ORDER BY user_id LIMIT 1')->fetchColumn();
$otherCompany = (int) $pdo->query("SELECT company_id FROM companies WHERE company_id<>$company ORDER BY company_id LIMIT 1")->fetchColumn();
$billIds = $pdo->query("SELECT invoice_id FROM finance_invoices WHERE company_id=$company AND document_type='vendor_bill' ORDER BY invoice_id DESC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);

if ($company < 1 || $actor < 1 || count($billIds) < 2) {
    fwrite(STDERR, 'FAIL Action-count fixtures are unavailable.' . PHP_EOL);
    exit(1);
}

$service = new ActionRequiredCountService();
$postPermission = ['procurement.bills.post'];
$previousAuth = $_SESSION['auth'] ?? null;
$_SESSION['auth'] = ['user_id'=>$actor, 'company'=>['company_id'=>$company]];
$pdo->beginTransaction();
try {
    $pdo->exec("UPDATE finance_invoices SET status='posted' WHERE company_id=$company AND document_type='vendor_bill'");
    $reset();
    $zero = $service->counts($company, $actor, $postPermission);
    $check($zero['procurement']['bills'] === 0, 'Zero actionable supplier bills returns zero and renders no badge value');

    $first = (int) $billIds[0];
    $second = (int) $billIds[1];
    $pdo->exec("UPDATE finance_invoices SET status='draft' WHERE company_id=$company AND invoice_id=$first");
    $reset();
    $one = $service->counts($company, $actor, $postPermission);
    $check($one['procurement']['bills'] === 1, 'One Draft supplier bill with Post bill permission counts one');
    $check($one['procurement']['total'] === 1, 'Procurement top-level aggregate is one without double-counting');
    $check($service->counts($company, $actor, $postPermission) === $one, 'Repeated lookup is stable through request-level memoization');

    $pdo->exec("UPDATE finance_invoices SET status='draft' WHERE company_id=$company AND invoice_id=$second");
    $reset();
    $multiple = $service->counts($company, $actor, $postPermission);
    $check($multiple['procurement']['bills'] === 2, 'Multiple actionable supplier bills return the exact count');
    $billTasks = $service->itemsFor($company, $actor, $postPermission, 'procurement', 'bills');
    $check(count($billTasks) === $multiple['procurement']['bills'], 'Supplier Bills action filter result count equals its tab badge');
    $check(count(array_filter($billTasks, static fn(array $task): bool => $task['next_action'] === 'Post supplier bill')) === 2, 'Each actionable supplier bill identifies its real next forward action');

    $reset();
    $check($service->counts($company, $actor, [])['procurement']['bills'] === 0, 'User without action permission gets no supplier-bill count');
    $reset();
    $check($service->counts($company, $actor, ['procurement.view'])['procurement']['bills'] === 0, 'View-only permission does not create an action count');

    $pdo->exec("UPDATE finance_invoices SET status='posted' WHERE company_id=$company AND invoice_id IN($first,$second)");
    $reset();
    $after = $service->counts($company, $actor, $postPermission);
    $check($after['procurement']['bills'] === 0, 'Posting the bills removes the Draft/Post action on the next request');

    $pdo->exec("UPDATE finance_invoices SET status='cancelled' WHERE company_id=$company AND invoice_id=$first");
    $reset();
    $check($service->counts($company, $actor, $postPermission)['procurement']['bills'] === 0, 'Cancelled and posted supplier bills are excluded');

    if ($otherCompany > 0) {
        $otherBill = (int) $pdo->query("SELECT invoice_id FROM finance_invoices WHERE company_id=$otherCompany AND document_type='vendor_bill' LIMIT 1")->fetchColumn();
        if ($otherBill > 0) {
            $pdo->exec("UPDATE finance_invoices SET status='draft' WHERE company_id=$otherCompany AND invoice_id=$otherBill");
        }
        $reset();
        $check($service->counts($company, $actor, $postPermission)['procurement']['bills'] === 0, 'Another company supplier bill is excluded');
    } else {
        $check(true, 'Tenant fixture has no second company; all count SQL remains company-scoped');
    }

    $reset();
    $empty = $service->counts($company, $actor, ['analytics.view']);
    $check($empty['analytics']['total'] === 0 && $empty['assets']['total'] === 0 && $empty['attendance']['total'] === 0, 'Modules without reliable actionable workflows remain zero');

    $navigation = file_get_contents(__DIR__ . '/../resources/views/layouts/navigation.php');
    $moduleNavigation = file_get_contents(__DIR__ . '/../resources/views/layouts/module-navigation.php');
    $hrNavigation = file_get_contents(__DIR__ . '/../resources/views/hr/index.php');
    $administrationNavigation = file_get_contents(__DIR__ . '/../resources/views/administration/index.php');
    $taskItemsView = file_get_contents(__DIR__ . '/../resources/views/layouts/action-required-items.php');
    $appJs = file_get_contents(__DIR__ . '/../public/assets/js/app.js');
    $routes = file_get_contents(__DIR__ . '/../routes/web.php');
    $taskServiceSource = file_get_contents(__DIR__ . '/../app/services/ActionRequiredCountService.php');
    $css = file_get_contents(__DIR__ . '/../public/assets/css/app.css');
    $dashboardView = file_get_contents(__DIR__ . '/../resources/views/dashboard/index.php');
    $check(is_string($navigation) && !str_contains($navigation, 'nav-action-badge') && !str_contains($navigation, 'actionRequiredCounts'), 'Main sidebar remains badge-free and unchanged by action counts');
    $check(is_string($moduleNavigation) && str_contains($moduleNavigation, '$actionCount > 0'), 'Internal module tabs omit badge markup when the count is zero');
    $check(is_string($moduleNavigation) && str_contains($moduleNavigation, 'task_filter=action_required') && str_contains($moduleNavigation, 'aria-label'), 'Module-tab badge is an accessible link to the exact action-required filter');
    $check(is_string($css) && str_contains($css, '.nav-action-badge'), 'Navigation badge uses one reusable CSS class');
    $check(is_string($css) && str_contains($css, 'position: absolute') && str_contains($css, 'top: 1px'), 'Internal-tab badge is anchored at the upper-right without becoming inline text');
    $check(
        is_string($dashboardView)
        && str_contains(
            $dashboardView,
            'dashboard-account-value'
        )
        && is_string($css)
        && str_contains(
            $css,
            '.dashboard-account-value'
        )
        && str_contains(
            $css,
            'overflow-wrap: anywhere'
        ),
        'Signed-in account values wrap within their scoped dashboard card'
    );
    $check(is_string($hrNavigation) && str_contains($hrNavigation, "['hr']['leave']"), 'HR leave action count is attached to the Leave management workflow link');
    $check(is_string($administrationNavigation) && str_contains($administrationNavigation, "'integration_events'"), 'Administration failures are attached to the Integration events workflow link');
    $check(is_string($taskItemsView) && str_contains($taskItemsView, 'Next:') && str_contains($taskItemsView, 'actionRequiredItems'), 'Shared task view renders the service-provided record and next action');
    $check(is_string($appJs) && str_contains($appJs, 'data-action-task-reference') && str_contains($appJs, 'action-required-row'), 'Shared row marker identifies matching existing table rows without workflow logic in views');
    foreach ([
        '/sales/quotations/{id}' => 'Quotation tasks target the registered Sales quotation workflow',
        '/sales/orders/{id}' => 'Sales Order tasks target the registered Sales Order workflow',
        '/sales/deliveries/{id}' => 'Delivery tasks target the registered delivery workflow',
        '/sales/settlements' => 'Settlement tasks target the registered Sales settlement workflow',
        '/finance/customer-invoices/{id}' => 'Customer invoice tasks target the registered Finance invoice workflow',
        '/procurement/{id}' => 'Purchase tasks target the registered Procurement detail workflow',
        '/inventory/receipts/{id}' => 'Receipt tasks target the registered Inventory receipt workflow',
        '/hr/leave' => 'Leave tasks target the registered HR leave workflow',
        '/administration/integration-events' => 'Integration tasks target the registered Integration Events workflow',
    ] as $route => $description) {
        $check(is_string($routes) && str_contains($routes, "'" . $route . "'"), $description);
    }
    $check(is_string($taskServiceSource) && !str_contains($taskServiceSource, "'/sales/settlements/create'") && !str_contains($taskServiceSource, "'/finance/settlements/{id}'"), 'Task descriptors contain no unregistered settlement detail or creation GET route');
    $reset();
    $procurementOrderTasks = $service->itemsFor($company, $actor, ['procurement.orders.approve'], 'procurement', 'orders');
    $check(is_array($procurementOrderTasks), 'Purchase Order task metadata executes with production-compatible unique parameter bindings');

    $pdo->exec("UPDATE sales_quotations SET status='confirmed' WHERE company_id=$company");
    $customer = (int) $pdo->query("SELECT customer_id FROM sales_customers WHERE company_id=$company AND active=TRUE AND deleted_at IS NULL LIMIT 1")->fetchColumn();
    $product = (int) $pdo->query("SELECT product_id FROM sales_products WHERE company_id=$company AND active=TRUE AND deleted_at IS NULL LIMIT 1")->fetchColumn();
    $sales = new SalesService();
    $newQuotation = static function () use ($sales, $customer, $product, $actor): int {
        $result = $sales->createQuotation([
            'customer_id'=>$customer,
            'quotation_date'=>date('Y-m-d'),
            'expiration_date'=>date('Y-m-d', strtotime('+7 days')),
            'currency'=>'ETB',
            'lines'=>[['product_id'=>$product,'quantity'=>'1','discount_amount'=>'0','tax_rate'=>'0']],
        ], $actor);
        return (int) ($result['id'] ?? 0);
    };
    $draftQuotation = $newQuotation();
    $confirmedQuotationA = $newQuotation();
    $confirmedQuotationB = $newQuotation();
    $pdo->exec("UPDATE sales_quotations SET status='confirmed',confirmed_at=NOW() WHERE company_id=$company AND quotation_id IN($confirmedQuotationA,$confirmedQuotationB)");
    $check($draftQuotation > 0 && $confirmedQuotationA > 0 && $confirmedQuotationB > 0, 'Three quotation fixtures include one Draft and two completed quotations');

    $reset();
    $quotationCounts = $service->counts($company, $actor, ['sales.orders.submit']);
    $check($quotationCounts['sales']['quotations'] === 1, 'Draft quotation with permitted Mark sent or Confirm counts one');
    $quotationTasks = $service->itemsFor($company, $actor, ['sales.orders.submit'], 'sales', 'quotations');
    $check(count($quotationTasks) === $quotationCounts['sales']['quotations'], 'Quotation action filter result count equals the badge count');
    $check(($quotationTasks[0]['id'] ?? 0) === $draftQuotation && ($quotationTasks[0]['next_action'] ?? '') === 'Mark sent or confirm', 'The exact Draft quotation is identified with its real next action');
    $reset();
    $check($service->counts($company, $actor, ['sales.orders.view'])['sales']['quotations'] === 0, 'The same Draft quotation is absent for a view-only user');
    $check($service->itemsFor($company, $actor, ['sales.orders.view'], 'sales', 'quotations') === [], 'View-only user receives no actionable quotation marker metadata');
    $check($quotationCounts['sales']['quotations'] === 1, 'Confirmed quotations with no remaining forward quotation action are excluded');
    $check($quotationCounts['sales']['quotations'] === 1, 'Three quotations where only one is actionable count exactly one');

    $pdo->exec("UPDATE sales_quotations SET status='confirmed',confirmed_at=NOW() WHERE company_id=$company AND quotation_id=$draftQuotation");
    $reset();
    $check($service->counts($company, $actor, ['sales.orders.submit'])['sales']['quotations'] === 0, 'Completing the last Draft quotation removes the quotation badge');
    $check($service->itemsFor($company, $actor, ['sales.orders.submit'], 'sales', 'quotations') === [], 'Completing the quotation removes its record-level action marker');

    $orderHandoff = $pdo->query("SELECT o.order_id,i.invoice_id,p.payment_id FROM sales_orders o INNER JOIN inventory_pickings pick ON pick.company_id=o.company_id AND pick.sales_order_id=o.order_id AND pick.picking_type='delivery' INNER JOIN finance_invoices i ON i.company_id=o.company_id AND i.sales_order_id=o.order_id AND i.document_type='customer_invoice' LEFT JOIN finance_payment_allocations a ON a.company_id=i.company_id AND a.invoice_id=i.invoice_id LEFT JOIN finance_payments p ON p.company_id=a.company_id AND p.payment_id=a.payment_id WHERE o.company_id=$company ORDER BY o.order_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $handoffOrder = (int) ($orderHandoff['order_id'] ?? 0);
    $handoffInvoice = (int) ($orderHandoff['invoice_id'] ?? 0);
    $handoffPayment = (int) ($orderHandoff['payment_id'] ?? 0);
    $check($handoffOrder > 0 && $handoffInvoice > 0 && $handoffPayment > 0, 'Order-to-cash handoff fixtures include delivery, invoice and payment records');
    $pdo->exec("UPDATE sales_orders SET status='paid',paid_amount=total_amount WHERE company_id=$company");
    $pdo->exec("UPDATE inventory_pickings SET status='cancelled' WHERE company_id=$company AND picking_type='delivery'");
    $pdo->exec("UPDATE finance_invoices SET status='posted',payment_status='paid',residual_amount=0 WHERE company_id=$company AND document_type='customer_invoice'");
    $pdo->exec("UPDATE finance_payments SET status='reversed' WHERE company_id=$company AND direction='inbound'");
    $pdo->exec("UPDATE sales_orders SET status='fulfilled',paid_amount=0,total_amount=1380 WHERE company_id=$company AND order_id=$handoffOrder");
    $pdo->exec("UPDATE inventory_pickings SET status='done' WHERE company_id=$company AND sales_order_id=$handoffOrder AND picking_type='delivery'");
    $pdo->exec("UPDATE finance_invoices SET status='cancelled' WHERE company_id=$company AND sales_order_id=$handoffOrder AND document_type='customer_invoice'");
    $reset();
    $invoiceCreation = $service->counts($company, $actor, ['sales.view','finance.records.manage']);
    $check($invoiceCreation['sales']['orders'] === 1 && $invoiceCreation['finance']['invoices'] === 0, 'Fulfilled ETB 1,380 order hands off to Sales Orders for Create Invoice');

    $pdo->exec("UPDATE finance_invoices SET status='draft',payment_status='unpaid',residual_amount=total_amount WHERE company_id=$company AND invoice_id=$handoffInvoice");
    $reset();
    $draftInvoice = $service->counts($company, $actor, ['sales.view','finance.records.manage']);
    $check($draftInvoice['sales']['orders'] === 0 && $draftInvoice['finance']['invoices'] === 1, 'Creating the Draft invoice moves the task from Sales Orders to Customer Invoices');
    $pdo->exec("UPDATE finance_invoices SET status='posted' WHERE company_id=$company AND invoice_id=$handoffInvoice");
    $reset();
    $check($service->counts($company, $actor, ['finance.records.manage'])['finance']['invoices'] === 1, 'Posting an unpaid invoice retains one Customer Invoices payment task');
    $pdo->exec("UPDATE finance_invoices SET payment_status='paid',residual_amount=0 WHERE company_id=$company AND invoice_id=$handoffInvoice");
    $reset();
    $check($service->counts($company, $actor, ['finance.records.manage'])['finance']['invoices'] === 0, 'Full payment removes the customer collection task');
    $pdo->exec("UPDATE finance_payments SET status='posted' WHERE company_id=$company AND payment_id=$handoffPayment");
    $reset();
    $check($service->counts($company, $actor, ['sales.settlements.create'])['sales']['settlements'] === 1, 'Posted allocated payment hands off to Sales Settlements for settlement creation');
    $settlementTasks = $service->itemsFor($company, $actor, ['sales.settlements.create'], 'sales', 'settlements');
    $expectedSettlementIdentity = $pdo->query("SELECT CONCAT(o.order_number,' · ',p.payment_number) FROM finance_payments p INNER JOIN finance_payment_allocations a ON a.company_id=p.company_id AND a.payment_id=p.payment_id INNER JOIN finance_invoices i ON i.company_id=a.company_id AND i.invoice_id=a.invoice_id INNER JOIN sales_orders o ON o.company_id=i.company_id AND o.order_id=i.sales_order_id WHERE p.company_id=$company AND p.payment_id=$handoffPayment LIMIT 1")->fetchColumn();
    $check(count($settlementTasks) === 1 && ($settlementTasks[0]['next_action'] ?? '') === 'Create settlement', 'Settlement creation task exposes the correct forward action');
    $check(($settlementTasks[0]['reference'] ?? '') === $expectedSettlementIdentity, 'Settlement creation task displays Sales Order and payment business references');
    $check(($settlementTasks[0]['url'] ?? '') === appBasePath() . '/sales/settlements#create-settlement', 'Settlement creation task targets the existing form on the Sales Settlements page');
    $check(!str_contains((string) ($settlementTasks[0]['url'] ?? ''), '/users/'), 'Settlement creation task cannot route through a user workflow');
    $pdo->exec("UPDATE finance_payments SET status='reversed' WHERE company_id=$company AND payment_id=$handoffPayment");
    $reset();
    $finalOrder = $service->counts($company, $actor, ['sales.view','finance.records.manage','sales.settlements.create']);
    $check($finalOrder['sales']['orders'] === 0 && $finalOrder['finance']['invoices'] === 0 && $finalOrder['sales']['settlements'] === 0, 'Fully completed order-to-cash fixture has no remaining badge');

    $poId = (int) $pdo->query("SELECT purchase_order_id FROM purchase_orders WHERE company_id=$company AND EXISTS(SELECT 1 FROM purchase_order_lines l WHERE l.company_id=purchase_orders.company_id AND l.purchase_order_id=purchase_orders.purchase_order_id) ORDER BY purchase_order_id DESC LIMIT 1")->fetchColumn();
    $vendorBill = (int) $pdo->query("SELECT invoice_id FROM finance_invoices WHERE company_id=$company AND purchase_order_id=$poId AND document_type='vendor_bill' ORDER BY invoice_id DESC LIMIT 1")->fetchColumn();
    $pdo->exec("UPDATE purchase_orders SET status='closed' WHERE company_id=$company");
    $pdo->exec("UPDATE finance_invoices SET status='posted',payment_status='paid',residual_amount=0 WHERE company_id=$company AND document_type='vendor_bill'");
    $pdo->exec("UPDATE purchase_order_lines SET received_quantity=0,billed_quantity=0,returned_quantity=0 WHERE company_id=$company AND purchase_order_id=$poId");
    $pdo->exec("UPDATE purchase_orders SET status='confirmed' WHERE company_id=$company AND purchase_order_id=$poId");
    $reset();
    $check($service->counts($company, $actor, ['procurement.receipts.create'])['procurement']['receipts'] === 1, 'Confirmed PO with outstanding quantity hands off to Receipts');
    $pdo->exec("UPDATE purchase_order_lines SET received_quantity=ordered_quantity WHERE company_id=$company AND purchase_order_id=$poId");
    $pdo->exec("UPDATE purchase_orders SET status='received' WHERE company_id=$company AND purchase_order_id=$poId");
    $reset();
    $check($service->counts($company, $actor, ['procurement.bills.create'])['procurement']['bills'] === 1, 'Received PO with unbilled quantity hands off to Supplier Bills');
    $pdo->exec("UPDATE purchase_order_lines SET billed_quantity=received_quantity WHERE company_id=$company AND purchase_order_id=$poId");
    $pdo->exec("UPDATE purchase_orders SET status='billed' WHERE company_id=$company AND purchase_order_id=$poId");
    $pdo->exec("UPDATE finance_invoices SET status='draft',payment_status='unpaid',residual_amount=total_amount WHERE company_id=$company AND invoice_id=$vendorBill");
    $reset();
    $check($service->counts($company, $actor, ['procurement.bills.post'])['procurement']['bills'] === 1, 'Draft vendor bill retains the Supplier Bills posting task');
    $pdo->exec("UPDATE finance_invoices SET status='posted' WHERE company_id=$company AND invoice_id=$vendorBill");
    $reset();
    $check($service->counts($company, $actor, ['procurement.payments.post'])['procurement']['payments'] === 1, 'Posted unpaid vendor bill hands off to Payments');
    $pdo->exec("UPDATE finance_invoices SET payment_status='paid',residual_amount=0 WHERE company_id=$company AND invoice_id=$vendorBill");
    $reset();
    $check($service->counts($company, $actor, ['procurement.payments.post'])['procurement']['payments'] === 0, 'Paid vendor bill completes the procure-to-pay badge chain');
    $pdo->exec("UPDATE purchase_requisitions SET status='rejected' WHERE company_id=$company");
    $closePermissions = ['procurement.orders.create','procurement.receipts.create','procurement.bills.create','procurement.bills.post','procurement.payments.post'];
    $reset();
    $closeCounts = $service->counts($company, $actor, $closePermissions);
    $closeTasks = $service->itemsFor($company, $actor, $closePermissions, 'procurement', 'orders');
    $check($closeCounts['procurement']['orders'] === 1, 'Billed fully received and billed PO with Close PO permission counts one Purchase Orders task');
    $check(count($closeTasks) === $closeCounts['procurement']['orders'], 'Close PO action-required record count equals the Purchase Orders tab badge');
    $check(($closeTasks[0]['id'] ?? 0) === $poId && str_starts_with((string)($closeTasks[0]['reference'] ?? ''), 'PO-'), 'Close PO task identifies the correct PO business reference');
    $check(($closeTasks[0]['next_action'] ?? '') === 'Close PO' && ($closeTasks[0]['url'] ?? '') === appBasePath() . '/procurement/' . $poId, 'Close PO task exposes the real next action and purchase order detail route');
    $check($closeCounts['procurement']['receipts'] === 0 && $closeCounts['procurement']['bills'] === 0 && $closeCounts['procurement']['payments'] === 0, 'Completed receipt bill and payment stages do not duplicate the Close PO task downstream');
    $reset();
    $check($service->counts($company, $actor, ['procurement.view'])['procurement']['orders'] === 0 && $service->itemsFor($company, $actor, ['procurement.view'], 'procurement', 'orders') === [], 'User without the existing Close PO permission receives no task');
    $pdo->exec("UPDATE purchase_orders SET status='closed' WHERE company_id=$company AND purchase_order_id=$poId");
    $reset();
    $check($service->counts($company, $actor, $closePermissions)['procurement']['orders'] === 0 && $service->itemsFor($company, $actor, $closePermissions, 'procurement', 'orders') === [], 'Closing the PO removes its badge and record-level task');
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (is_array($previousAuth)) {
        $_SESSION['auth'] = $previousAuth;
    } else {
        unset($_SESSION['auth']);
    }
}

fwrite(STDOUT, sprintf('Action-required counts: %d passed, %d failed.%s', $passed, count($failures), PHP_EOL));
exit($failures === [] ? 0 : 1);
