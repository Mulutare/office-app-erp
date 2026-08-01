<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\SalesService;

final class SalesController
{
    private AuthorizationService $authorization;
    private SalesService $sales;

    public function __construct()
    {
        $this->authorization = new AuthorizationService();
        $this->sales = new SalesService();
    }

    public function index(): void
    {
        $this->authorize('sales.view');
        $workspace = $this->sales->workspace();

        \view('layouts.app', [
            'applicationName' => \config('name', 'OfficeApp ERP'),
            'environment' => \config('environment', 'unknown'),
            'pageTitle' => 'Sales',
            'pageDescription' => 'Customer orders, telecom products, commissions and receivables.',
            'contentView' => 'sales.index',
            'user' => $_SESSION['auth'],
            'notice' => \getFlash('sales_notice'),
            'errors' => \getFlash('sales_errors', []),
            'old' => \getFlash('sales_old', []),
            'canManageCatalogue' => $this->can('sales.catalogue.manage'),
            'canCreateOrders' => $this->can('sales.orders.create'),
            'canRecordPayments' => $this->can('sales.payments.record'),
            'canManageTargets' => $this->can('sales.targets.manage'),
            'canSubmitOrders' => $this->can('sales.orders.submit'),
            'canApproveOrders' => $this->can('sales.orders.approve'),
            'canFulfillOrders' => $this->can('sales.orders.confirm'),
            'canCancelOrders' => $this->can('sales.orders.cancel'),
            'canManageSerials' => $this->can('sales.serials.manage'),
            'canManageCommissions' => $this->can('sales.commissions.manage'),
            'canExportReports' => $this->can('sales.reports.export'),
        ] + $workspace);
    }

    public function storeTerritory(): void
    {
        $this->authorize('sales.catalogue.manage');
        $this->requireCsrf('territory');
        $input = $this->input(['code', 'name']);
        $this->finish(
            $this->sales->createTerritory($input, $this->actorId()),
            'territory',
            'Territory created successfully.',
            $input
        );
    }

    public function storeAgent(): void
    {
        $this->authorize('sales.catalogue.manage');
        $this->requireCsrf('agent');
        $input = $this->input([
            'agent_code', 'name', 'agent_type', 'territory_id', 'phone',
        ]);
        $this->finish(
            $this->sales->createAgent($input, $this->actorId()),
            'agent',
            'DSA/DSP created successfully.',
            $input
        );
    }

    public function storeTarget(): void
    {
        $this->authorize('sales.targets.manage');
        $this->requireCsrf('target');
        $input = $this->input([
            'territory_id', 'agent_id', 'period_start', 'period_end',
            'target_amount', 'target_quantity',
        ]);
        $this->finish(
            $this->sales->createTarget($input, $this->actorId()),
            'target',
            'Sales target created successfully.',
            $input
        );
    }

    public function storeCustomer(): void
    {
        $this->authorize('sales.catalogue.manage');
        $this->requireCsrf('customer');
        $input = $this->input([
            'customer_number', 'name', 'customer_type', 'territory_id',
            'tax_number', 'email', 'phone', 'address', 'credit_limit',
            'payment_terms_days', 'preferred_currency', 'credit_mode',
        ]);
        $this->finish($this->sales->createCustomer($input, $this->actorId()), 'customer', 'Customer created successfully.', $input);
    }

    public function storeProduct(): void
    {
        $this->authorize('sales.catalogue.manage');
        $this->requireCsrf('product');
        $input = $this->input([
            'sku', 'name', 'category', 'product_type', 'unit_of_measure',
            'unit_price', 'commission_rate',
        ]) + ['serial_tracking' => isset($_POST['serial_tracking'])];
        $this->finish($this->sales->createProduct($input, $this->actorId()), 'product', 'Product created successfully.', $input);
    }

    public function storeOrder(): void
    {
        $this->authorize('sales.orders.create');
        $this->requireCsrf('order');
        $input = $this->input([
            'customer_id', 'order_date', 'due_date', 'currency', 'territory_id',
            'agent_id', 'branch_id', 'notes',
        ]) + [
            'confirm' => isset($_POST['confirm']),
            'lines' => $this->orderLines(),
        ];
        $result = $this->sales->createOrder($input, $this->actorId());
        $message = !empty($result['orderNumber'])
            ? 'Sales order ' . $result['orderNumber'] . ' created successfully.'
            : 'Sales order created successfully.';
        $this->finish($result, 'order', $message, $input);
    }

    public function recordPayment(): void
    {
        $this->authorize('sales.payments.record');
        $this->requireCsrf('payment');
        $input = $this->input([
            'order_id', 'receipt_number', 'payment_date', 'amount',
            'payment_method', 'reference_number', 'notes',
        ]);
        $this->finish(
            $this->sales->recordPayment((int) $input['order_id'], $input, $this->actorId()),
            'payment', 'Payment recorded and receivable updated.', $input
        );
    }

    public function transitionOrder(): void
    {
        $action = \postString('action');
        $permission = match ($action) {
            'submit' => 'sales.orders.submit',
            'approve' => 'sales.orders.approve',
            'fulfill' => 'sales.orders.confirm',
            'cancel' => 'sales.orders.cancel',
            default => 'sales.orders.approve',
        };
        $this->authorize($permission);
        $this->requireCsrf('order_action');
        $input = $this->input(['order_id', 'action', 'reason', 'idempotency_key']);
        $this->finish(
            $this->sales->transitionOrder(
                (int) $input['order_id'], $input['action'],
                $input['reason'] ?: null, $this->actorId(), $input['idempotency_key']
            ),
            'order_action', 'Order status updated successfully.', $input
        );
    }

    public function storeSerialNumbers(): void
    {
        $this->authorize('sales.serials.manage');
        $this->requireCsrf('serials');
        $input = $this->input(['product_id', 'serial_numbers']);
        $this->finish(
            $this->sales->registerSerialNumbers($input, $this->actorId()),
            'serials', 'Serial numbers registered successfully.', $input
        );
    }

    public function transitionCommission(): void
    {
        $this->authorize('sales.commissions.manage');
        $this->requireCsrf('commission_action');
        $input = $this->input(['commission_id', 'action']);
        $this->finish(
            $this->sales->transitionCommission((int) $input['commission_id'], $input['action'], $this->actorId()),
            'commission_action', 'Commission status updated successfully.', $input
        );
    }

    public function export(): void
    {
        $this->authorize('sales.reports.export');
        $workspace = $this->sales->workspace();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="sales-orders-' . date('Y-m-d') . '.csv"');
        $stream = fopen('php://output', 'wb');
        if ($stream === false) {
            throw new \RuntimeException('The sales export could not be opened.');
        }
        fputcsv($stream, [
            'Order number', 'Customer', 'Order date', 'Due date',
            'Currency', 'Total', 'Paid', 'Balance', 'Status', 'DSA/DSP',
        ]);
        foreach ($workspace['orders'] as $order) {
            fputcsv($stream, [
                $order['order_number'], $order['customer_name'],
                $order['order_date'], $order['due_date'], $order['currency'],
                $order['total_amount'], $order['paid_amount'],
                $order['balance_due'], $order['status'], $order['agent_name'] ?? '',
            ]);
        }
        fclose($stream);
        exit;
    }

    private function authorize(string $permission): void
    {
        $this->authorization->requireModule('sales');
        $this->authorization->requireTenantPermission($permission);
    }

    private function requireCsrf(string $form): void
    {
        if (!\verifyCsrfToken(\postString('_token'))) {
            \flash('sales_errors', ['form' => 'The form session expired. Please try again.']);
            \flash('sales_old', ['form' => $form]);
            \redirect('/sales');
        }
    }

    /** @param list<string> $keys @return array<string, string> */
    private function input(array $keys): array
    {
        $input = [];
        foreach ($keys as $key) {
            $input[$key] = \postString($key);
        }
        return $input;
    }

    /** @return list<array<string, string>> */
    private function orderLines(): array
    {
        $productIds = is_array($_POST['product_id'] ?? null)
            ? $_POST['product_id'] : [];
        $quantities = is_array($_POST['quantity'] ?? null)
            ? $_POST['quantity'] : [];
        $discounts = is_array($_POST['discount_amount'] ?? null)
            ? $_POST['discount_amount'] : [];
        $taxRates = is_array($_POST['tax_rate'] ?? null)
            ? $_POST['tax_rate'] : [];
        $count = min(max(count($productIds), count($quantities)), 20);
        $lines = [];
        for ($index = 0; $index < $count; $index++) {
            $lines[] = [
                'product_id' => $this->scalar($productIds[$index] ?? ''),
                'quantity' => $this->scalar($quantities[$index] ?? ''),
                'discount_amount' => $this->scalar($discounts[$index] ?? '0'),
                'tax_rate' => $this->scalar($taxRates[$index] ?? '0'),
            ];
        }
        return $lines;
    }

    private function scalar(mixed $value): string
    {
        return is_string($value) || is_numeric($value)
            ? trim((string) $value)
            : '';
    }

    /** @param array<string, mixed> $result @param array<string, mixed> $old */
    private function finish(array $result, string $form, string $message, array $old): never
    {
        if (empty($result['successful'])) {
            \flash('sales_errors', $result['errors'] ?? ['form' => 'The request could not be completed.']);
            \flash('sales_old', $old + ['form' => $form]);
        } else {
            \flash('sales_notice', ['message' => $message]);
        }
        \redirect('/sales');
    }

    private function actorId(): int
    {
        return (int) ($_SESSION['auth']['user_id'] ?? 0);
    }

    private function can(string $permission): bool
    {
        return in_array($permission, $_SESSION['auth']['permissions'] ?? [], true);
    }
}
