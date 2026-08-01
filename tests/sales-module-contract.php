<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$expectContains = static function (
    string $file,
    array $needles
) use (&$failures, $root): void {
    $contents = file_get_contents($root . '/' . $file);

    if (!is_string($contents)) {
        $failures[] = 'Cannot read ' . $file;
        return;
    }

    foreach ($needles as $needle) {
        if (!str_contains($contents, $needle)) {
            $failures[] = $file . ' is missing ' . $needle;
        }
    }
};

$expectContains('routes/web.php', [
    "'/sales'",
    "'/sales/customers'",
    "'/sales/products'",
    "'/sales/territories'",
    "'/sales/agents'",
    "'/sales/targets'",
    "'/sales/orders'",
    "'/sales/payments'",
    "'/sales/export'",
]);

$expectContains(
    'database/migrations/mysql/026_create_sales_core.php',
    [
        'CREATE TABLE sales_territories',
        'CREATE TABLE sales_customers',
        'CREATE TABLE sales_products',
        'CREATE TABLE sales_agents',
        'CREATE TABLE sales_targets',
        'CREATE TABLE sales_orders',
        'CREATE TABLE sales_order_lines',
        'CREATE TABLE sales_payments',
        'CREATE TABLE sales_commissions',
    ]
);

$expectContains(
    'database/migrations/mysql/027_create_module_integration_core.php',
    [
        'CREATE TABLE integration_outbox',
        'CREATE TABLE finance_sales_receivables',
        'CREATE TABLE finance_sales_receipts',
        'CREATE TABLE inventory_sales_commitments',
    ]
);

$expectContains('bin/dispatch-integration-events.php', [
    'IntegrationDispatcherService',
]);

$expectContains(
    'database/migrations/mysql/028_add_integration_event_sequence.php',
    ['outbox_sequence']
);

$expectContains('database/seeds/018_enable_sales_module.sql', [
    "'sales.view'",
    "'sales.catalogue.manage'",
    "'sales.orders.create'",
    "'sales.payments.record'",
    "'sales.targets.manage'",
]);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, 'PASS Sales module contract' . PHP_EOL);
