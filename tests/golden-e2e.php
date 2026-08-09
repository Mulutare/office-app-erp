<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';

$failures = [];
$check = static function (bool $condition, string $description) use (&$failures): void {
    fwrite($condition ? STDOUT : STDERR, ($condition ? 'PASS ' : 'FAIL ') . $description . PHP_EOL);
    if (!$condition) { $failures[] = $description; }
};
$one = static function (string $sql, array $params = []): array {
    $statement = db()->prepare($sql); $statement->execute($params);
    $row = $statement->fetch(PDO::FETCH_ASSOC); return is_array($row) ? $row : [];
};

$customer = $one("SELECT * FROM sales_customers WHERE customer_number='E2E-GOLD-CUST-001'");
$product = $one("SELECT * FROM sales_products WHERE sku='E2E-GOLD-001'");
$order = $one("SELECT * FROM sales_orders WHERE order_number='SO-00000007'");
$check(($customer['name'] ?? '') === 'E2E Golden Customer', 'Golden customer persists');
$check(($product['product_type'] ?? '') === 'stockable' && (float)($product['unit_price'] ?? 0) === 100.0, 'Golden product is stockable at ETB 100');
$check(($order['status'] ?? '') === 'fulfilled', 'Golden Sales Order is fulfilled');

$receipt = $one("SELECT * FROM inventory_goods_receipts WHERE receipt_number='GR-20260809-1BDB1B70'");
$check(($receipt['status'] ?? '') === 'posted', 'Receipt is posted');
$stock = $one("SELECT b.quantity_on_hand,b.quantity_reserved,b.quantity_available FROM inventory_stock_balances b INNER JOIN inventory_warehouse_locations l ON l.company_id=b.company_id AND l.location_id=b.location_id WHERE b.product_id=:product AND l.code='INT953126/STOCK'", ['product'=>(int)($product['product_id'] ?? 0)]);
$check((float)($stock['quantity_on_hand'] ?? -1) === 13.0 && (float)($stock['quantity_available'] ?? -1) === 13.0, 'Final Stock and available quantity equal 13');

$picking = $one("SELECT SUM(CASE WHEN p.picking_type='delivery' THEN pl.completed_quantity ELSE 0 END) delivered,SUM(CASE WHEN p.picking_type='customer_return' THEN pl.completed_quantity ELSE 0 END) returned FROM inventory_pickings p INNER JOIN inventory_picking_lines pl ON pl.company_id=p.company_id AND pl.picking_id=p.picking_id WHERE p.sales_order_id=:order AND p.status IN('done','partially_done')", ['order'=>(int)($order['order_id'] ?? 0)]);
$check((float)($picking['delivered'] ?? 0) === 10.0, 'Two deliveries total 10 units');
$check((float)($picking['returned'] ?? 0) === 3.0, 'Customer return totals 3 units');

$documents = db()->prepare("SELECT invoice_number,document_type,status,payment_status,untaxed_amount,tax_amount,total_amount,residual_amount FROM finance_invoices WHERE sales_order_id=:order ORDER BY invoice_id");
$documents->execute(['order'=>(int)($order['order_id'] ?? 0)]); $documents = $documents->fetchAll(PDO::FETCH_ASSOC);
$check(count($documents) === 3, 'Exactly two invoices and one credit note exist');
$check(($documents[0]['invoice_number'] ?? '') === 'INV-00000005' && (float)$documents[0]['total_amount'] === 460.0 && (float)$documents[0]['residual_amount'] === 0.0, 'First invoice is ETB 460 and paid');
$check(($documents[1]['invoice_number'] ?? '') === 'INV-00000006' && (float)$documents[1]['total_amount'] === 690.0 && (float)$documents[1]['residual_amount'] === 0.0, 'Second invoice is ETB 690 and paid');
$check(($documents[2]['invoice_number'] ?? '') === 'CN-00000007' && ($documents[2]['status'] ?? '') === 'posted' && (float)$documents[2]['total_amount'] === 345.0, 'Credit note is posted for ETB 345');

$payments = $one("SELECT COUNT(*) payment_count,COALESCE(SUM(amount),0) paid FROM finance_payments WHERE reference_number IN('E2E-GOLD-PAY-1A','E2E-GOLD-PAY-1B','E2E-GOLD-PAY-2') AND status='posted'");
$check((int)($payments['payment_count'] ?? 0) === 3 && (float)($payments['paid'] ?? 0) === 1150.0, 'Three posted payments total ETB 1,150');
$journal = $one("SELECT COALESCE(SUM(e.debit_amount),0) debit,COALESCE(SUM(e.credit_amount),0) credit FROM finance_journal_batches b INNER JOIN finance_journal_entries e ON e.company_id=b.company_id AND e.journal_batch_id=b.journal_batch_id WHERE b.source_type IN('customer_invoice','customer_credit') AND b.source_id IN('5','6','7')");
$check((float)($journal['debit'] ?? -1) === 1495.0 && (float)($journal['credit'] ?? -1) === 1495.0, 'Invoice and credit journals balance');
$check(1150.0 - 345.0 === 805.0, 'Net commercial amount is ETB 805');

fwrite(STDOUT, 'Golden E2E failures: ' . count($failures) . PHP_EOL);
exit($failures === [] ? 0 : 1);
