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
    "'/sales/orders/action'",
    "'/sales/serials'",
    "'/sales/commissions/action'",
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

$expectContains('database/migrations/mysql/029_complete_sales_controls.php', [
    'CREATE TABLE sales_serial_numbers',
    'CREATE TABLE sales_order_line_serials',
    "'submitted'", "'approved'", "'fulfilled'", "'cancelled'",
]);

$expectContains('database/seeds/019_assign_sales_control_permissions.sql', [
    "'sales.orders.approve'",
    "'sales.serials.manage'",
    "'sales.commissions.manage'",
]);

$expectContains('database/migrations/mysql/030_harden_sales_enterprise_controls.php', [
    'CREATE TABLE sales_document_sequences',
    'CREATE TABLE sales_order_status_history',
    "'credit_mode'", "'claimed_by'", "'dead_lettered_at'",
]);

$expectContains('database/seeds/020_expand_sales_enterprise_permissions.sql', [
    "'sales.orders.submit'", "'sales.orders.confirm'", "'sales.orders.cancel'",
    "'sales.credit.manage'", "'sales.credit.release'",
    "'sales.reports.export'", "'sales.integrations.replay'",
]);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, 'PASS Sales module contract' . PHP_EOL);
