<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogWriter;
use App\Repositories\RepositoryFactory;
use App\Repositories\SalesRepository;
use PDOException;
use Throwable;

final class SalesService
{
    public function __construct(
        private ?SalesRepository $sales = null,
        private ?AuditLogWriter $audit = null,
        private ?TenantContext $tenant = null
    ) {
        $this->sales ??= RepositoryFactory::sales();
        $this->audit ??= RepositoryFactory::auditLogs();
        $this->tenant ??= new TenantContext();
    }

    /** @return array<string, mixed> */
    public function workspace(): array
    {
        $companyId = $this->tenant->companyId();

        return [
            'summary' => $this->sales->dashboard($companyId),
            'orders' => $this->sales->orders($companyId),
            'customers' => $this->sales->customers($companyId),
            'products' => $this->sales->products($companyId),
            'agents' => $this->sales->agents($companyId),
            'territories' => $this->sales->territories($companyId),
            'targets' => $this->sales->targets($companyId),
            'commissions' => $this->sales->commissions($companyId),
            'serialNumbers' => $this->sales->serialNumbers($companyId),
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function createTerritory(array $input, int $actorId): array
    {
        $values = [
            'branch_id' => null,
            'code' => strtoupper(trim((string) ($input['code'] ?? ''))),
            'name' => trim((string) ($input['name'] ?? '')),
        ];
        if ($values['code'] === '' || strlen($values['code']) > 40) {
            return ['successful' => false, 'errors' => ['code' => 'Enter a territory code of up to 40 characters.']];
        }
        if ($values['name'] === '' || strlen($values['name']) > 120) {
            return ['successful' => false, 'errors' => ['name' => 'Enter a territory name of up to 120 characters.']];
        }

        return $this->createSpecialRecord('territory', $values, $actorId);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function createAgent(array $input, int $actorId): array
    {
        $territoryId = $this->optionalId($input['territory_id'] ?? null);
        if ($territoryId !== null && !$this->territoryExists($territoryId)) {
            return ['successful' => false, 'errors' => ['territory_id' => 'Select a territory from the current company.']];
        }
        $values = [
            'territory_id' => $territoryId,
            'employee_id' => null,
            'agent_code' => strtoupper(trim((string) ($input['agent_code'] ?? ''))),
            'name' => trim((string) ($input['name'] ?? '')),
            'agent_type' => strtoupper(trim((string) ($input['agent_type'] ?? 'DSA'))),
            'phone' => $this->nullable($input['phone'] ?? null),
        ];
        $errors = [];
        if ($values['agent_code'] === '' || strlen($values['agent_code']) > 40) {
            $errors['agent_code'] = 'Enter a DSA/DSP code of up to 40 characters.';
        }
        if ($values['name'] === '' || strlen($values['name']) > 160) {
            $errors['name'] = 'Enter a DSA/DSP name of up to 160 characters.';
        }
        if (!in_array($values['agent_type'], ['DSA', 'DSP'], true)) {
            $errors['agent_type'] = 'Select DSA or DSP.';
        }
        if ($errors !== []) {
            return ['successful' => false, 'errors' => $errors];
        }

        return $this->createSpecialRecord('agent', $values, $actorId);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function createTarget(array $input, int $actorId): array
    {
        $territoryId = $this->optionalId($input['territory_id'] ?? null);
        $agentId = $this->optionalId($input['agent_id'] ?? null);
        $start = $this->date($input['period_start'] ?? null);
        $end = $this->date($input['period_end'] ?? null);
        $amount = $this->money($input['target_amount'] ?? 0);
        $quantity = $this->decimal($input['target_quantity'] ?? 0);
        $errors = [];
        if ($territoryId === null && $agentId === null) {
            $errors['scope'] = 'Select a territory, a DSA/DSP, or both.';
        }
        if ($territoryId !== null && !$this->territoryExists($territoryId)) {
            $errors['territory_id'] = 'Select a valid territory.';
        }
        if ($agentId !== null && !$this->agentExists($agentId)) {
            $errors['agent_id'] = 'Select a valid DSA or DSP.';
        }
        if ($start === null || $end === null || $end < $start) {
            $errors['period'] = 'Enter a valid target period.';
        }
        if ($amount < 0 || $quantity < 0 || ($amount === 0.0 && $quantity === 0.0)) {
            $errors['target'] = 'Enter a positive amount or quantity target.';
        }
        if ($errors !== []) {
            return ['successful' => false, 'errors' => $errors];
        }

        return $this->createSpecialRecord('target', [
            'territory_id' => $territoryId,
            'agent_id' => $agentId,
            'period_start' => $start,
            'period_end' => $end,
            'target_amount' => $amount,
            'target_quantity' => $quantity,
        ], $actorId);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function createCustomer(array $input, int $actorId): array
    {
        $creditLimit = $this->money($input['credit_limit'] ?? 0);
        $creditMode = trim((string) ($input['credit_mode'] ?? ''));
        if ($creditMode === '') {
            $creditMode = $creditLimit > 0 ? 'fixed' : 'unlimited';
        }
        $values = [
            'territory_id' => $this->optionalId($input['territory_id'] ?? null),
            'customer_number' => strtoupper(trim((string) ($input['customer_number'] ?? ''))),
            'name' => trim((string) ($input['name'] ?? '')),
            'customer_type' => (string) ($input['customer_type'] ?? 'business'),
            'tax_number' => $this->nullable($input['tax_number'] ?? null),
            'email' => $this->nullable($input['email'] ?? null),
            'phone' => $this->nullable($input['phone'] ?? null),
            'address' => $this->nullable($input['address'] ?? null),
            'preferred_currency' => strtoupper(trim((string) ($input['preferred_currency'] ?? 'ETB'))),
            'credit_mode' => $creditMode,
            'credit_limit' => $creditLimit,
            'credit_status' => 'active',
            'payment_terms_days' => max(0, (int) ($input['payment_terms_days'] ?? 0)),
        ];
        $errors = [];
        if ($values['customer_number'] === '' || strlen($values['customer_number']) > 40) {
            $errors['customer_number'] = 'Enter a customer number of up to 40 characters.';
        }
        if ($values['name'] === '' || strlen($values['name']) > 160) {
            $errors['name'] = 'Enter a customer name of up to 160 characters.';
        }
        if (!in_array($values['customer_type'], ['business', 'individual', 'agent', 'government'], true)) {
            $errors['customer_type'] = 'Select a valid customer type.';
        }
        if ($values['credit_limit'] < 0) {
            $errors['credit_limit'] = 'Credit limit cannot be negative.';
        }
        if (!in_array($values['credit_mode'], ['no_credit', 'unlimited', 'fixed'], true)) {
            $errors['credit_mode'] = 'Select no credit, unlimited credit or a fixed credit limit.';
        }
        if ($values['credit_mode'] === 'fixed' && $values['credit_limit'] <= 0) {
            $errors['credit_limit'] = 'A fixed credit policy requires a positive limit.';
        }
        if (preg_match('/^[A-Z]{3}$/', $values['preferred_currency']) !== 1) {
            $errors['preferred_currency'] = 'Preferred currency must be a three-letter ISO code.';
        }
        if ($values['email'] !== null && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($values['territory_id'] !== null) {
            $territoryIds = array_map(
                static fn (array $territory): int => (int) $territory['territory_id'],
                $this->sales->territories($this->tenant->companyId())
            );
            if (!in_array($values['territory_id'], $territoryIds, true)) {
                $errors['territory_id'] = 'Select a territory from the current company.';
            }
        }
        if ($errors !== []) {
            return ['successful' => false, 'errors' => $errors];
        }

        return $this->createRecord('customer', $values, $actorId);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function createProduct(array $input, int $actorId): array
    {
        $values = [
            'sku' => strtoupper(trim((string) ($input['sku'] ?? ''))),
            'name' => trim((string) ($input['name'] ?? '')),
            'category' => $this->nullable($input['category'] ?? null),
            'product_type' => trim((string) ($input['product_type'] ?? 'telecom_product')),
            'unit_of_measure' => trim((string) ($input['unit_of_measure'] ?? 'unit')),
            'unit_price' => $this->money($input['unit_price'] ?? 0),
            'commission_rate' => $this->money($input['commission_rate'] ?? 0),
            'serial_tracking' => !empty($input['serial_tracking']) ? 1 : 0,
        ];
        $errors = [];
        if ($values['sku'] === '' || strlen($values['sku']) > 60) {
            $errors['sku'] = 'Enter an SKU of up to 60 characters.';
        }
        if ($values['name'] === '' || strlen($values['name']) > 160) {
            $errors['name'] = 'Enter a product name of up to 160 characters.';
        }
        if ($values['unit_price'] < 0) {
            $errors['unit_price'] = 'Unit price cannot be negative.';
        }
        if ($values['commission_rate'] < 0 || $values['commission_rate'] > 100) {
            $errors['commission_rate'] = 'Commission rate must be between 0 and 100.';
        }
        if ($errors !== []) {
            return ['successful' => false, 'errors' => $errors];
        }

        return $this->createRecord('product', $values, $actorId);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function createOrder(array $input, int $actorId): array
    {
        $companyId = $this->tenant->companyId();
        $products = [];
        foreach ($this->sales->products($companyId) as $product) {
            $products[(int) $product['product_id']] = $product;
        }
        $customers = [];
        foreach ($this->sales->customers($companyId) as $customer) {
            $customers[(int) $customer['customer_id']] = $customer;
        }
        $agents = [];
        foreach ($this->sales->agents($companyId) as $agent) {
            $agents[(int) $agent['agent_id']] = true;
        }
        $territories = [];
        foreach ($this->sales->territories($companyId) as $territory) {
            $territories[(int) $territory['territory_id']] = true;
        }
        $customerId = (int) ($input['customer_id'] ?? 0);
        $errors = [];
        if ($customerId < 1 || !isset($customers[$customerId])) {
            $errors['customer_id'] = 'Select a customer.';
        }
        $orderDate = $this->date($input['order_date'] ?? null);
        $dueDate = $this->date($input['due_date'] ?? null);
        if ($orderDate === null || $dueDate === null || $dueDate < $orderDate) {
            $errors['dates'] = 'Enter valid order and due dates; due date cannot precede order date.';
        }
        if ($errors !== []) {
            return ['successful' => false, 'errors' => $errors];
        }

        $agentId = $this->optionalId($input['agent_id'] ?? null);
        $territoryId = $this->optionalId($input['territory_id'] ?? null);
        if ($agentId !== null && !isset($agents[$agentId])) {
            return ['successful' => false, 'errors' => ['agent_id' => 'Select a valid DSA or DSP.']];
        }
        if ($territoryId !== null && !isset($territories[$territoryId])) {
            return ['successful' => false, 'errors' => ['territory_id' => 'Select a valid territory.']];
        }

        $submittedLines = is_array($input['lines'] ?? null) ? $input['lines'] : [];
        $lines = [];
        $subtotal = 0.0;
        $discount = 0.0;
        $tax = 0.0;
        $commissionAmount = 0.0;
        foreach ($submittedLines as $index => $submittedLine) {
            if (!is_array($submittedLine)) {
                continue;
            }
            $productId = (int) ($submittedLine['product_id'] ?? 0);
            $quantity = $this->decimal($submittedLine['quantity'] ?? 0);
            if ($productId === 0 && $quantity === 0.0) {
                continue;
            }
            $product = $products[$productId] ?? null;
            $lineDiscount = $this->money($submittedLine['discount_amount'] ?? 0);
            $taxRate = $this->money($submittedLine['tax_rate'] ?? 0);
            if (!is_array($product) || $quantity <= 0 || $lineDiscount < 0 || $taxRate < 0 || $taxRate > 100) {
                $errors['line_' . ($index + 1)] = 'Line ' . ($index + 1) . ' has an invalid product, quantity, discount or tax rate.';
                continue;
            }
            $unitPrice = (float) $product['unit_price'];
            $lineSubtotal = round($quantity * $unitPrice, 2);
            if ($lineDiscount > $lineSubtotal) {
                $errors['line_' . ($index + 1)] = 'Line ' . ($index + 1) . ' discount exceeds its subtotal.';
                continue;
            }
            $lineTax = round(($lineSubtotal - $lineDiscount) * $taxRate / 100, 2);
            $lineTotal = round($lineSubtotal - $lineDiscount + $lineTax, 2);
            $subtotal += $lineSubtotal;
            $discount += $lineDiscount;
            $tax += $lineTax;
            $commissionAmount += round(($lineSubtotal - $lineDiscount) * (float) $product['commission_rate'] / 100, 2);
            $lines[] = [
                'product_id' => $productId,
                'description' => (string) $product['name'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_amount' => $lineDiscount,
                'tax_rate' => $taxRate,
                'line_total' => $lineTotal,
                'commission_rate' => (float) $product['commission_rate'],
            ];
        }
        if ($lines === []) {
            $errors['lines'] = 'Add at least one valid product line.';
        }
        if ($errors !== []) {
            return ['successful' => false, 'errors' => $errors];
        }
        $subtotal = round($subtotal, 2);
        $discount = round($discount, 2);
        $tax = round($tax, 2);
        $total = round($subtotal - $discount + $tax, 2);
        $commissionAmount = round($commissionAmount, 2);
        $status = !empty($input['confirm']) ? 'submitted' : 'draft';
        $customer = $customers[$customerId];
        $creditLimit = (float) ($customer['credit_limit'] ?? 0);
        $creditMode = (string) ($customer['credit_mode'] ?? ($creditLimit > 0 ? 'fixed' : 'unlimited'));
        $creditStatus = (string) ($customer['credit_status'] ?? 'active');
        if ($status === 'submitted' && $creditStatus !== 'active') {
            return ['successful' => false, 'errors' => ['credit_status' => 'This customer is on credit hold.']];
        }
        if ($status === 'submitted' && $creditMode === 'no_credit') {
            return ['successful' => false, 'errors' => ['credit_limit' => 'This customer is not authorized for credit sales.']];
        }
        if ($status === 'submitted' && $creditMode === 'fixed') {
            $outstanding = $this->sales->customerOutstanding($companyId, $customerId);
            if (round($outstanding + $total, 2) > $creditLimit) {
                return ['successful' => false, 'errors' => [
                    'credit_limit' => sprintf(
                        'This order would exceed the customer credit limit. Available credit: %.2f.',
                        max(0, $creditLimit - $outstanding)
                    ),
                ]];
            }
        }
        try {
            $orderNumber = $this->sales->reserveDocumentNumber($companyId, null, 'order');
        } catch (Throwable $exception) {
            error_log('Sales order numbering failed: ' . $exception->getMessage());
            return ['successful' => false, 'errors' => ['form' => 'A Sales order number could not be reserved. Please retry.']];
        }
        $order = [
            'branch_id' => null,
            'customer_id' => $customerId,
            'territory_id' => $territoryId,
            'agent_id' => $agentId,
            'order_number' => $orderNumber,
            'order_date' => $orderDate,
            'due_date' => $dueDate,
            'status' => $status,
            'currency' => strtoupper(trim((string) ($input['currency'] ?? 'ETB'))),
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'notes' => $this->nullable($input['notes'] ?? null),
            'confirmed_at' => null,
            'commission_amount' => $commissionAmount,
        ];
        if (preg_match('/^[A-Z]{3}$/', $order['currency']) !== 1) {
            return ['successful' => false, 'errors' => ['currency' => 'Currency must be a three-letter ISO code.']];
        }
        try {
            $id = $this->sales->createOrder($companyId, $order, $lines, $actorId);
            $this->audit->record($actorId, 'CREATE_SALES_ORDER', 'sales', 'sales_orders', (string) $id, null, [
                'order_number' => $orderNumber, 'status' => $status, 'total_amount' => $total,
            ], $companyId);
            return ['successful' => true, 'orderId' => $id, 'orderNumber' => $orderNumber];
        } catch (Throwable $exception) {
            error_log(
                'Sales order creation failed [' . date(DATE_ATOM) . ']: '
                . $exception::class . ': ' . $exception->getMessage()
            );
            return ['successful' => false, 'errors' => ['form' => 'The sales order could not be created. Please retry or contact support with the current time.']];
        }
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function registerSerialNumbers(array $input, int $actorId): array
    {
        $companyId = $this->tenant->companyId();
        $productId = (int) ($input['product_id'] ?? 0);
        $product = null;
        foreach ($this->sales->products($companyId) as $candidate) {
            if ((int) $candidate['product_id'] === $productId) {
                $product = $candidate;
                break;
            }
        }
        if (!is_array($product) || empty($product['serial_tracking'])) {
            return ['successful' => false, 'errors' => ['product_id' => 'Select a serial-tracked product.']];
        }
        $serials = preg_split('/[\r\n,]+/', (string) ($input['serial_numbers'] ?? '')) ?: [];
        $serials = array_values(array_unique(array_filter(array_map(
            static fn (string $serial): string => strtoupper(trim($serial)),
            $serials
        ), static fn (string $serial): bool => $serial !== '' && strlen($serial) <= 120)));
        if ($serials === [] || count($serials) > 500) {
            return ['successful' => false, 'errors' => ['serial_numbers' => 'Enter between 1 and 500 serial numbers, one per line.']];
        }
        try {
            $this->sales->registerSerialNumbers($companyId, $productId, $serials, $actorId);
            $this->audit->record($actorId, 'REGISTER_SALES_SERIALS', 'sales', 'sales_serial_numbers', null, null, [
                'product_id' => $productId, 'count' => count($serials),
            ], $companyId);
            return ['successful' => true, 'count' => count($serials)];
        } catch (Throwable $exception) {
            error_log('Sales serial registration failed: ' . $exception->getMessage());
            return ['successful' => false, 'errors' => ['form' => 'Serial numbers could not be registered. Check for duplicates.']];
        }
    }

    /** @return array<string, mixed> */
    public function transitionOrder(
        int $orderId,
        string $action,
        ?string $reason,
        int $actorId,
        ?string $idempotencyKey = null
    ): array
    {
        $companyId = $this->tenant->companyId();
        try {
            $key = trim((string) $idempotencyKey);
            if ($key === '' || strlen($key) > 100) {
                $key = bin2hex(random_bytes(16));
            }
            $transition = $this->sales->transitionOrder(
                $companyId, $orderId, $action, $reason, $actorId, $key
            );
            if (!empty($transition['replayed'])) {
                return ['successful' => true, 'replayed' => true];
            }
            $this->audit->record($actorId, 'TRANSITION_SALES_ORDER', 'sales', 'sales_orders', (string) $orderId, null, [
                'action' => $action, 'reason' => $reason,
            ], $companyId);
            return ['successful' => true];
        } catch (Throwable $exception) {
            if (!$exception instanceof \RuntimeException) {
                error_log('Sales order transition failed: ' . $exception->getMessage());
            }
            return ['successful' => false, 'errors' => ['form' => $exception->getMessage()]];
        }
    }

    /** @return array<string, mixed> */
    public function transitionCommission(int $commissionId, string $action, int $actorId): array
    {
        $companyId = $this->tenant->companyId();
        try {
            $this->sales->transitionCommission($companyId, $commissionId, $action, $actorId);
            $this->audit->record($actorId, 'TRANSITION_SALES_COMMISSION', 'sales', 'sales_commissions', (string) $commissionId, null, [
                'action' => $action,
            ], $companyId);
            return ['successful' => true];
        } catch (Throwable $exception) {
            error_log('Sales commission transition failed: ' . $exception->getMessage());
            return ['successful' => false, 'errors' => ['form' => $exception->getMessage()]];
        }
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function createSpecialRecord(string $type, array $values, int $actorId): array
    {
        $companyId = $this->tenant->companyId();
        try {
            $id = match ($type) {
                'territory' => $this->sales->createTerritory($companyId, $values, $actorId),
                'agent' => $this->sales->createAgent($companyId, $values, $actorId),
                'target' => $this->sales->createTarget($companyId, $values, $actorId),
                default => throw new \LogicException('Unsupported sales record type.'),
            };
            $this->audit->record(
                $actorId,
                'CREATE_SALES_' . strtoupper($type),
                'sales',
                'sales_' . $type . 's',
                (string) $id,
                null,
                $values,
                $companyId
            );
            return ['successful' => true, 'id' => $id];
        } catch (Throwable $exception) {
            error_log('Sales ' . $type . ' creation failed: ' . $exception::class . ': ' . $exception->getMessage());
            return ['successful' => false, 'errors' => ['form' => 'The ' . $type . ' could not be created. Check duplicate codes and related records.']];
        }
    }

    private function territoryExists(int $territoryId): bool
    {
        foreach ($this->sales->territories($this->tenant->companyId()) as $territory) {
            if ((int) $territory['territory_id'] === $territoryId) {
                return true;
            }
        }
        return false;
    }

    private function agentExists(int $agentId): bool
    {
        foreach ($this->sales->agents($this->tenant->companyId()) as $agent) {
            if ((int) $agent['agent_id'] === $agentId) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function recordPayment(int $orderId, array $input, int $actorId): array
    {
        $payment = [
            'receipt_number' => strtoupper(trim((string) ($input['receipt_number'] ?? ''))),
            'payment_date' => $this->date($input['payment_date'] ?? null),
            'amount' => $this->money($input['amount'] ?? 0),
            'payment_method' => trim((string) ($input['payment_method'] ?? 'bank_transfer')),
            'reference_number' => $this->nullable($input['reference_number'] ?? null),
            'notes' => $this->nullable($input['notes'] ?? null),
        ];
        if ($orderId < 1 || $payment['receipt_number'] === '' || $payment['payment_date'] === null || $payment['amount'] <= 0) {
            return ['successful' => false, 'errors' => ['form' => 'Order, receipt number, payment date and a positive amount are required.']];
        }
        try {
            $companyId = $this->tenant->companyId();
            $this->sales->recordPayment($companyId, $orderId, $payment, $actorId);
            $this->audit->record($actorId, 'RECORD_SALES_PAYMENT', 'sales', 'sales_payments', null, null, [
                'order_id' => $orderId, 'receipt_number' => $payment['receipt_number'], 'amount' => $payment['amount'],
            ], $companyId);
            return ['successful' => true];
        } catch (Throwable $exception) {
            error_log('Sales payment failed: ' . $exception::class . ': ' . $exception->getMessage());
            $message = $exception instanceof \RuntimeException
                ? $exception->getMessage()
                : 'The payment could not be recorded. Check the receipt number and retry.';
            return ['successful' => false, 'errors' => ['form' => $message]];
        }
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function createRecord(string $type, array $values, int $actorId): array
    {
        $companyId = $this->tenant->companyId();
        try {
            $id = $type === 'customer'
                ? $this->sales->createCustomer($companyId, $values, $actorId)
                : $this->sales->createProduct($companyId, $values, $actorId);
            $this->audit->record($actorId, 'CREATE_SALES_' . strtoupper($type), 'sales', 'sales_' . $type . 's', (string) $id, null, $values, $companyId);
            return ['successful' => true, 'id' => $id];
        } catch (PDOException $exception) {
            error_log('Sales catalogue write failed: ' . $exception::class);
            return ['successful' => false, 'errors' => ['form' => 'That code is already in use or the selected related record is invalid.']];
        }
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function optionalId(mixed $value): ?int
    {
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function money(mixed $value): float
    {
        return is_numeric($value) ? round((float) $value, 2) : -1;
    }

    private function decimal(mixed $value): float
    {
        return is_numeric($value) ? round((float) $value, 3) : 0;
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }
}
