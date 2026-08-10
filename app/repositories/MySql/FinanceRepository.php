<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\FinanceRepository
    as FinanceRepositoryContract;
use PDO;
use RuntimeException;
use Throwable;

final class FinanceRepository extends MySqlRepository
    implements FinanceRepositoryContract
{
    public function customerInvoices(int $companyId): array
    {
        $statement = $this->connection()->prepare(
            "SELECT i.invoice_id,i.invoice_number,i.invoice_date,i.due_date,i.currency,
                    i.status,i.payment_status,i.total_amount,i.residual_amount,
                    c.name customer_name,o.order_number
             FROM finance_invoices i
             INNER JOIN sales_customers c
                ON c.company_id=i.company_id AND c.customer_id=i.customer_id
             LEFT JOIN sales_orders o
                ON o.company_id=i.company_id AND o.order_id=i.sales_order_id
             WHERE i.company_id=:company_id
               AND i.document_type IN ('customer_invoice','customer_credit')
               AND i.status<>'cancelled'
             ORDER BY i.invoice_date DESC,i.invoice_id DESC"
        );
        $statement->execute(['company_id' => $companyId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function customerInvoice(int $companyId, int $invoiceId): ?array
    {
        $statement = $this->connection()->prepare(
            "SELECT i.*,c.name customer_name,o.order_number,
                    j.journal_code,j.journal_name,b.batch_number posting_reference
             FROM finance_invoices i
             INNER JOIN sales_customers c
                ON c.company_id=i.company_id AND c.customer_id=i.customer_id
             LEFT JOIN sales_orders o
                ON o.company_id=i.company_id AND o.order_id=i.sales_order_id
             INNER JOIN finance_journals j
                ON j.company_id=i.company_id AND j.journal_id=i.journal_id
             LEFT JOIN finance_journal_batches b
                ON b.company_id=i.company_id AND b.journal_batch_id=i.journal_batch_id
             WHERE i.company_id=:company_id
               AND i.invoice_id=:invoice_id
               AND i.document_type IN ('customer_invoice','customer_credit')"
        );
        $statement->execute(['company_id' => $companyId, 'invoice_id' => $invoiceId]);
        $invoice = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($invoice)) {
            return null;
        }

        $lines = $this->connection()->prepare(
            "SELECT il.*,p.sku,p.name product_name
             FROM finance_invoice_lines il
             LEFT JOIN sales_products p
                ON p.company_id=il.company_id AND p.product_id=il.product_id
             WHERE il.company_id=:company_id AND il.invoice_id=:invoice_id
             ORDER BY il.invoice_line_id"
        );
        $lines->execute(['company_id' => $companyId, 'invoice_id' => $invoiceId]);
        $invoice['lines'] = $lines->fetchAll(PDO::FETCH_ASSOC);

        $payments = $this->connection()->prepare(
            "SELECT p.payment_id,p.payment_number,p.payment_date,p.currency,p.amount,
                    a.amount allocated_amount,p.method,p.reference_number,p.status,
                    b.batch_number posting_reference
             FROM finance_payment_allocations a
             INNER JOIN finance_payments p
                ON p.company_id=a.company_id AND p.payment_id=a.payment_id
             LEFT JOIN finance_journal_batches b
                ON b.company_id=p.company_id AND b.journal_batch_id=p.journal_batch_id
             WHERE a.company_id=:company_id AND a.invoice_id=:invoice_id
             ORDER BY p.payment_id"
        );
        $payments->execute(['company_id' => $companyId, 'invoice_id' => $invoiceId]);
        $invoice['payments'] = $payments->fetchAll(PDO::FETCH_ASSOC);
        return $invoice;
    }

    public function customerPaymentJournals(int $companyId): array
    {
        $statement = $this->connection()->prepare(
            "SELECT journal_id,journal_code,journal_name,journal_type
             FROM finance_journals
             WHERE company_id=:company_id
               AND journal_type IN ('bank','cash') AND active=TRUE
             ORDER BY journal_type,journal_name"
        );
        $statement->execute(['company_id' => $companyId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @var list<array{
     *     code:string,
     *     name:string,
     *     type:string,
     *     normal:string,
     *     key:string
     * }>
     */
    private const SYSTEM_ACCOUNTS = [
        [
            'code' => '1000',
            'name' => 'Cash and Bank',
            'type' => 'asset',
            'normal' => 'debit',
            'key' => 'cash',
        ],
        [
            'code' => '1100',
            'name' => 'Accounts Receivable',
            'type' => 'asset',
            'normal' => 'debit',
            'key' => 'accounts_receivable',
        ],
        [
            'code' => '1200',
            'name' => 'Inventory Asset',
            'type' => 'asset',
            'normal' => 'debit',
            'key' => 'inventory_asset',
        ],
        [
            'code' => '2000',
            'name' => 'Accounts Payable',
            'type' => 'liability',
            'normal' => 'credit',
            'key' => 'accounts_payable',
        ],
        [
            'code' => '2100',
            'name' => 'Sales Tax Payable',
            'type' => 'liability',
            'normal' => 'credit',
            'key' => 'sales_tax_payable',
        ],
        [
            'code' => '2200',
            'name' => 'Customer Credits',
            'type' => 'liability',
            'normal' => 'credit',
            'key' => 'customer_credits',
        ],
        [
            'code' => '3000',
            'name' => 'Owner Equity',
            'type' => 'equity',
            'normal' => 'credit',
            'key' => 'owner_equity',
        ],
        [
            'code' => '3200',
            'name' => 'Retained Earnings',
            'type' => 'equity',
            'normal' => 'credit',
            'key' => 'retained_earnings',
        ],
        [
            'code' => '4000',
            'name' => 'Sales Revenue',
            'type' => 'revenue',
            'normal' => 'credit',
            'key' => 'sales_revenue',
        ],
        [
            'code' => '5000',
            'name' => 'Cost of Goods Sold',
            'type' => 'expense',
            'normal' => 'debit',
            'key' => 'cost_of_goods_sold',
        ],
    ];

    public function ensureSystemAccounts(
        int $companyId,
        string $currency,
        ?int $actorId
    ): array {
        if ($companyId <= 0) {
            throw new RuntimeException(
                'A valid company is required.'
            );
        }

        $currency = strtoupper(trim($currency));

        if (
            preg_match('/^[A-Z]{3}$/', $currency)
            !== 1
        ) {
            throw new RuntimeException(
                'A valid three-letter currency is required.'
            );
        }

        $connection = $this->connection();
        $ownsTransaction = !$connection->inTransaction();

        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            $company = $connection->prepare(
                'SELECT company_id
                 FROM companies
                 WHERE company_id = :company_id
                   AND deleted_at IS NULL
                 FOR UPDATE'
            );
            $company->execute([
                'company_id' => $companyId,
            ]);

            if ($company->fetchColumn() === false) {
                throw new RuntimeException(
                    'The finance company was not found.'
                );
            }

            $insert = $connection->prepare(
                "INSERT INTO finance_accounts (
                    company_id,
                    account_code,
                    account_name,
                    account_type,
                    normal_balance,
                    system_key,
                    currency,
                    description,
                    active,
                    allow_manual_posting,
                    created_by,
                    updated_by
                 ) VALUES (
                    :company_id,
                    :account_code,
                    :account_name,
                    :account_type,
                    :normal_balance,
                    :system_key,
                    :currency,
                    :description,
                    TRUE,
                    FALSE,
                    :created_by,
                    :updated_by
                 )
                 ON DUPLICATE KEY UPDATE
                    account_name = VALUES(account_name),
                    account_type = VALUES(account_type),
                    normal_balance = VALUES(normal_balance),
                    currency = VALUES(currency),
                    active = TRUE,
                    allow_manual_posting = FALSE,
                    updated_by = VALUES(updated_by),
                    deleted_at = NULL"
            );

            foreach (self::SYSTEM_ACCOUNTS as $account) {
                $insert->execute([
                    'company_id' => $companyId,
                    'account_code' => $account['code'],
                    'account_name' => $account['name'],
                    'account_type' => $account['type'],
                    'normal_balance' =>
                        $account['normal'],
                    'system_key' => $account['key'],
                    'currency' => $currency,
                    'description' =>
                        'System-managed account',
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);
            }

            $statement = $connection->prepare(
                'SELECT account_id, system_key
                 FROM finance_accounts
                 WHERE company_id = :company_id
                   AND system_key IS NOT NULL
                   AND active = TRUE
                   AND deleted_at IS NULL'
            );
            $statement->execute([
                'company_id' => $companyId,
            ]);

            $accounts = [];

            foreach (
                $statement->fetchAll(PDO::FETCH_ASSOC)
                as $account
            ) {
                $accounts[
                    (string) $account['system_key']
                ] = (int) $account['account_id'];
            }

            foreach (self::SYSTEM_ACCOUNTS as $required) {
                if (
                    !isset($accounts[$required['key']])
                ) {
                    throw new RuntimeException(
                        'A required system account was not created: '
                        . $required['key']
                    );
                }
            }

            if ($ownsTransaction) {
                $connection->commit();
            }

            return $accounts;
        } catch (Throwable $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    public function accountBySystemKey(
        int $companyId,
        string $systemKey
    ): ?array {
        $systemKey = trim($systemKey);

        $statement = $this->connection()->prepare(
            'SELECT
                account_id,
                company_id,
                account_code,
                account_name,
                account_type,
                normal_balance,
                system_key,
                currency,
                active,
                allow_manual_posting
             FROM finance_accounts
             WHERE company_id = :company_id
               AND system_key = :system_key
               AND active = TRUE
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'system_key' => $systemKey,
        ]);

        $account = $statement->fetch(PDO::FETCH_ASSOC);

        return $account === false ? null : $account;
    }

    public function postBalancedJournal(
        int $companyId,
        string $batchNumber,
        string $sourceType,
        ?string $sourceId,
        ?string $sourceNumber,
        string $postingDate,
        string $currency,
        string $description,
        string $idempotencyKey,
        array $lines,
        ?int $actorId
    ): array {
        $batchNumber = trim($batchNumber);
        $sourceType = trim($sourceType);
        $postingDate = trim($postingDate);
        $currency = strtoupper(trim($currency));
        $description = trim($description);
        $idempotencyKey = trim($idempotencyKey);

        if (
            $companyId <= 0
            || $batchNumber === ''
            || $sourceType === ''
            || $description === ''
            || $idempotencyKey === ''
        ) {
            throw new RuntimeException(
                'The journal header is incomplete.'
            );
        }

        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $postingDate
            ) !== 1
        ) {
            throw new RuntimeException(
                'The journal posting date is invalid.'
            );
        }

        if (
            preg_match('/^[A-Z]{3}$/', $currency)
            !== 1
        ) {
            throw new RuntimeException(
                'The journal currency is invalid.'
            );
        }

        if (count($lines) < 2) {
            throw new RuntimeException(
                'A journal requires at least two lines.'
            );
        }

        $normalized = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($lines as $index => $line) {
            if (!is_array($line)) {
                throw new RuntimeException(
                    'A journal line is invalid.'
                );
            }

            $accountId = (int) (
                $line['account_id'] ?? 0
            );
            $branchId = isset($line['branch_id'])
                && (int) $line['branch_id'] > 0
                    ? (int) $line['branch_id']
                    : null;
            $debit = round(
                (float) ($line['debit'] ?? 0),
                2
            );
            $credit = round(
                (float) ($line['credit'] ?? 0),
                2
            );

            if ($accountId <= 0) {
                throw new RuntimeException(
                    'Every journal line requires an account.'
                );
            }

            if (
                ($debit > 0 && $credit > 0)
                || ($debit <= 0 && $credit <= 0)
            ) {
                throw new RuntimeException(
                    'Each journal line must contain exactly one positive debit or credit.'
                );
            }

            $normalized[] = [
                'line_number' => $index + 1,
                'account_id' => $accountId,
                'branch_id' => $branchId,
                'debit' => $debit,
                'credit' => $credit,
                'description' => isset(
                    $line['description']
                )
                    ? trim((string)
                        $line['description'])
                    : null,
            ];

            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        $totalDebit = round($totalDebit, 2);
        $totalCredit = round($totalCredit, 2);

        if (
            $totalDebit <= 0
            || abs($totalDebit - $totalCredit) > 0.005
        ) {
            throw new RuntimeException(
                'Journal debits and credits must balance.'
            );
        }

        $connection = $this->connection();
        $ownsTransaction = !$connection->inTransaction();

        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            $existing = $connection->prepare(
                'SELECT
                    journal_batch_id,
                    batch_number,
                    status,
                    total_debit,
                    total_credit
                 FROM finance_journal_batches
                 WHERE company_id = :company_id
                   AND idempotency_key =
                        :idempotency_key
                 FOR UPDATE'
            );
            $existing->execute([
                'company_id' => $companyId,
                'idempotency_key' => $idempotencyKey,
            ]);
            $existingBatch = $existing->fetch(
                PDO::FETCH_ASSOC
            );

            if (is_array($existingBatch)) {
                if ($ownsTransaction) {
                    $connection->commit();
                }

                return [
                    'journalBatchId' => (int)
                        $existingBatch[
                            'journal_batch_id'
                        ],
                    'batchNumber' => (string)
                        $existingBatch['batch_number'],
                    'status' => (string)
                        $existingBatch['status'],
                    'replayed' => true,
                    'totalDebit' => (float)
                        $existingBatch['total_debit'],
                    'totalCredit' => (float)
                        $existingBatch['total_credit'],
                ];
            }

            $this->assertOpenPostingDate(
                $companyId,
                $postingDate
            );

            $accountIds = array_values(array_unique(
                array_map(
                    static fn (array $line): int =>
                        (int) $line['account_id'],
                    $normalized
                )
            ));

            $placeholders = implode(
                ',',
                array_fill(0, count($accountIds), '?')
            );

            $accountStatement = $connection->prepare(
                "SELECT account_id
                 FROM finance_accounts
                 WHERE company_id = ?
                   AND account_id IN ($placeholders)
                   AND active = TRUE
                   AND deleted_at IS NULL
                 FOR UPDATE"
            );
            $accountStatement->execute([
                $companyId,
                ...$accountIds,
            ]);
            $validAccounts = array_map(
                'intval',
                $accountStatement->fetchAll(
                    PDO::FETCH_COLUMN
                )
            );

            sort($validAccounts);
            $expectedAccounts = $accountIds;
            sort($expectedAccounts);

            if ($validAccounts !== $expectedAccounts) {
                throw new RuntimeException(
                    'A journal account is inactive, missing or belongs to another company.'
                );
            }

            foreach ($normalized as $line) {
                if ($line['branch_id'] === null) {
                    continue;
                }

                $branch = $connection->prepare(
                    'SELECT branch_id
                     FROM organization_branches
                     WHERE company_id = :company_id
                       AND branch_id = :branch_id
                       AND active = TRUE
                       AND deleted_at IS NULL'
                );
                $branch->execute([
                    'company_id' => $companyId,
                    'branch_id' => $line['branch_id'],
                ]);

                if ($branch->fetchColumn() === false) {
                    throw new RuntimeException(
                        'A journal branch is invalid.'
                    );
                }
            }

            $batch = $connection->prepare(
                "INSERT INTO finance_journal_batches (
                    company_id,
                    batch_number,
                    source_type,
                    source_id,
                    source_number,
                    posting_date,
                    currency,
                    description,
                    status,
                    total_debit,
                    total_credit,
                    idempotency_key,
                    posted_by,
                    posted_at,
                    created_by
                 ) VALUES (
                    :company_id,
                    :batch_number,
                    :source_type,
                    :source_id,
                    :source_number,
                    :posting_date,
                    :currency,
                    :description,
                    'posted',
                    :total_debit,
                    :total_credit,
                    :idempotency_key,
                    :posted_by,
                    NOW(),
                    :created_by
                 )"
            );
            $batch->execute([
                'company_id' => $companyId,
                'batch_number' => $batchNumber,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_number' => $sourceNumber,
                'posting_date' => $postingDate,
                'currency' => $currency,
                'description' => $description,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'idempotency_key' => $idempotencyKey,
                'posted_by' => $actorId,
                'created_by' => $actorId,
            ]);

            $journalBatchId = (int)
                $connection->lastInsertId();

            $entry = $connection->prepare(
                'INSERT INTO finance_journal_entries (
                    company_id,
                    journal_batch_id,
                    line_number,
                    account_id,
                    branch_id,
                    debit_amount,
                    credit_amount,
                    currency,
                    description
                 ) VALUES (
                    :company_id,
                    :journal_batch_id,
                    :line_number,
                    :account_id,
                    :branch_id,
                    :debit_amount,
                    :credit_amount,
                    :currency,
                    :description
                 )'
            );

            $balance = $connection->prepare(
                "INSERT INTO finance_account_balances (
                    company_id,
                    account_id,
                    currency,
                    debit_total,
                    credit_total,
                    balance_amount,
                    version_number,
                    last_posted_at
                 ) VALUES (
                    :company_id,
                    :account_id,
                    :currency,
                    :debit_total,
                    :credit_total,
                    :balance_amount,
                    1,
                    NOW()
                 )
                 ON DUPLICATE KEY UPDATE
                    debit_total =
                        debit_total
                        + VALUES(debit_total),
                    credit_total =
                        credit_total
                        + VALUES(credit_total),
                    balance_amount =
                        balance_amount
                        + VALUES(balance_amount),
                    version_number =
                        version_number + 1,
                    last_posted_at = NOW()"
            );

            foreach ($normalized as $line) {
                $entry->execute([
                    'company_id' => $companyId,
                    'journal_batch_id' =>
                        $journalBatchId,
                    'line_number' =>
                        $line['line_number'],
                    'account_id' =>
                        $line['account_id'],
                    'branch_id' =>
                        $line['branch_id'],
                    'debit_amount' =>
                        $line['debit'],
                    'credit_amount' =>
                        $line['credit'],
                    'currency' => $currency,
                    'description' =>
                        $line['description'],
                ]);

                $balance->execute([
                    'company_id' => $companyId,
                    'account_id' =>
                        $line['account_id'],
                    'currency' => $currency,
                    'debit_total' =>
                        $line['debit'],
                    'credit_total' =>
                        $line['credit'],
                    'balance_amount' =>
                        $line['debit']
                        - $line['credit'],
                ]);
            }

            if ($ownsTransaction) {
                $connection->commit();
            }

            return [
                'journalBatchId' => $journalBatchId,
                'batchNumber' => $batchNumber,
                'status' => 'posted',
                'replayed' => false,
                'totalDebit' => $totalDebit,
                'totalCredit' => $totalCredit,
            ];
        } catch (Throwable $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    public function ensureSystemJournals(int $companyId, string $currency, ?int $actorId): array
    {
        $accounts = $this->ensureSystemAccounts($companyId, $currency, $actorId);
        $definitions = [
            'sales' => ['SAL', 'Sales Journal', null, 'accounts_receivable'],
            'purchase' => ['PUR', 'Purchase Journal', 'accounts_payable', null],
            'bank' => ['BNK', 'Bank Journal', 'cash', null],
            'cash' => ['CSH', 'Cash Journal', 'cash', null],
            'general' => ['GEN', 'General Journal', null, null],
        ];
        $connection = $this->connection();
        $statement = $connection->prepare(
            "INSERT INTO finance_journals
                (company_id,journal_code,journal_name,journal_type,
                 default_debit_account_id,default_credit_account_id,
                 active,system_required,created_by)
             VALUES (:company_id,:code,:name,:type,:debit,:credit,TRUE,TRUE,:actor)
             ON DUPLICATE KEY UPDATE journal_id = LAST_INSERT_ID(journal_id),
                 journal_name = VALUES(journal_name), active = TRUE"
        );
        $ids = [];
        foreach ($definitions as $type => [$code, $name, $debitKey, $creditKey]) {
            $statement->execute([
                'company_id' => $companyId, 'code' => $code, 'name' => $name,
                'type' => $type,
                'debit' => $debitKey === null ? null : $accounts[$debitKey],
                'credit' => $creditKey === null ? null : $accounts[$creditKey],
                'actor' => $actorId,
            ]);
            $ids[$type] = (int) $connection->lastInsertId();
        }
        return $ids;
    }

    public function createCustomerInvoiceFromOrder(
        int $companyId,
        int $orderId,
        string $invoicePolicy,
        int $actorId
    ): int {
        if (!in_array($invoicePolicy, ['ordered', 'delivered'], true)) {
            throw new RuntimeException('Invoice policy must be ordered or delivered.');
        }
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $orderStatement = $connection->prepare(
                "SELECT * FROM sales_orders WHERE company_id=:company_id AND order_id=:order_id
                 AND deleted_at IS NULL AND status NOT IN ('draft','submitted','cancelled') FOR UPDATE"
            );
            $orderStatement->execute(['company_id' => $companyId, 'order_id' => $orderId]);
            $order = $orderStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order)) {
                throw new RuntimeException('Only an active confirmed sales order can be invoiced.');
            }
            if ($invoicePolicy === 'ordered') {
                $existing = $connection->prepare(
                    "SELECT invoice_id FROM finance_invoices
                     WHERE company_id=:company_id AND sales_order_id=:order_id
                       AND document_type='customer_invoice' AND invoice_policy='ordered'
                       AND status<>'cancelled' ORDER BY invoice_id LIMIT 1 FOR UPDATE"
                );
                $existing->execute(['company_id'=>$companyId,'order_id'=>$orderId]);
                $existingId = $existing->fetchColumn();
                if ($existingId !== false) {
                    $connection->commit();
                    return (int)$existingId;
                }
            }
            $journals = $this->ensureSystemJournals($companyId, (string) $order['currency'], $actorId);
            $linesStatement = $connection->prepare(
                "SELECT order_lines.*,
                    CASE WHEN :policy = 'ordered' THEN order_lines.quantity ELSE
                        COALESCE((SELECT SUM(pl.completed_quantity - pl.returned_quantity)
                          FROM inventory_picking_lines pl
                          INNER JOIN inventory_pickings p ON p.company_id=pl.company_id AND p.picking_id=pl.picking_id
                          WHERE p.company_id=order_lines.company_id AND p.sales_order_id=order_lines.order_id
                            AND p.picking_type='delivery' AND p.status IN ('done','partially_done')
                            AND pl.product_id=order_lines.product_id),0) END AS eligible_quantity,
                    COALESCE((SELECT SUM(il.quantity)
                      FROM finance_invoice_lines il INNER JOIN finance_invoices i
                        ON i.company_id=il.company_id AND i.invoice_id=il.invoice_id
                      WHERE i.company_id=order_lines.company_id AND i.sales_order_id=order_lines.order_id
                        AND i.document_type='customer_invoice' AND i.status <> 'cancelled'
                        AND il.sales_order_line_id=order_lines.order_line_id),0) AS invoiced_quantity
                 FROM sales_order_lines order_lines
                 WHERE order_lines.company_id=:company_id AND order_lines.order_id=:order_id
                 ORDER BY order_lines.order_line_id FOR UPDATE"
            );
            $linesStatement->execute(['policy' => $invoicePolicy, 'company_id' => $companyId, 'order_id' => $orderId]);
            $invoiceLines = [];
            $untaxed = $discount = $tax = $total = 0.0;
            foreach ($linesStatement->fetchAll(PDO::FETCH_ASSOC) as $line) {
                $quantity = round((float) $line['eligible_quantity'] - (float) $line['invoiced_quantity'], 3);
                if ($quantity <= 0.0005) { continue; }
                $unitPrice = round((float) $line['unit_price'], 4);
                $gross = round($quantity * $unitPrice, 2);
                $ratio = $quantity / (float) $line['quantity'];
                $lineDiscount = round((float) $line['discount_amount'] * $ratio, 2);
                $lineUntaxed = round($gross - $lineDiscount, 2);
                $lineTax = round($lineUntaxed * (float) $line['tax_rate'] / 100, 2);
                $invoiceLines[] = [$line, $quantity, $unitPrice, $lineDiscount, $lineUntaxed, $lineTax];
                $untaxed += $lineUntaxed; $discount += $lineDiscount; $tax += $lineTax;
                $total += $lineUntaxed + $lineTax;
            }
            if ($invoiceLines === []) {
                throw new RuntimeException('The sales order has no remaining invoiceable quantity.');
            }
            $journal = $connection->prepare(
                'SELECT next_number FROM finance_journals WHERE company_id=:company_id AND journal_id=:journal_id FOR UPDATE'
            );
            $journal->execute(['company_id' => $companyId, 'journal_id' => $journals['sales']]);
            $next = (int) $journal->fetchColumn();
            $number = sprintf('INV-%08d', $next);
            $connection->prepare(
                'UPDATE finance_journals SET next_number=next_number+1 WHERE company_id=:company_id AND journal_id=:journal_id'
            )->execute(['company_id' => $companyId, 'journal_id' => $journals['sales']]);
            $invoice = $connection->prepare(
                "INSERT INTO finance_invoices
                 (company_id,journal_id,customer_id,sales_order_id,document_type,invoice_number,
                  invoice_date,due_date,currency,payment_terms_days,invoice_policy,status,payment_status,
                  untaxed_amount,discount_amount,tax_amount,total_amount,residual_amount,notes,created_by)
                 VALUES (:company_id,:journal_id,:customer_id,:order_id,'customer_invoice',:number,
                  CURRENT_DATE,:due_date,:currency,:terms,:policy,'draft','unpaid',
                  :untaxed,:discount,:tax,:total,:residual,:notes,:actor)"
            );
            $invoice->execute([
                'company_id'=>$companyId,'journal_id'=>$journals['sales'],'customer_id'=>(int)$order['customer_id'],
                'order_id'=>$orderId,'number'=>$number,'due_date'=>$order['due_date'],'currency'=>$order['currency'],
                'terms'=>(int)((strtotime((string)$order['due_date'])-strtotime((string)$order['order_date']))/86400),
                'policy'=>$invoicePolicy,'untaxed'=>round($untaxed,2),'discount'=>round($discount,2),
                'tax'=>round($tax,2),'total'=>round($total,2),'residual'=>round($total,2),
                'notes'=>$order['notes'],'actor'=>$actorId,
            ]);
            $invoiceId = (int) $connection->lastInsertId();
            $insertLine = $connection->prepare(
                "INSERT INTO finance_invoice_lines
                 (company_id,invoice_id,sales_order_line_id,product_id,description,quantity,unit_price,
                  discount_amount,tax_rate,untaxed_amount,tax_amount,total_amount)
                 VALUES (:company_id,:invoice_id,:sales_line,:product,:description,:quantity,:unit_price,
                  :discount,:tax_rate,:untaxed,:tax,:total)"
            );
            foreach ($invoiceLines as [$line,$quantity,$unitPrice,$lineDiscount,$lineUntaxed,$lineTax]) {
                $insertLine->execute([
                    'company_id'=>$companyId,'invoice_id'=>$invoiceId,'sales_line'=>(int)$line['order_line_id'],
                    'product'=>(int)$line['product_id'],'description'=>$line['description'],'quantity'=>$quantity,
                    'unit_price'=>$unitPrice,'discount'=>$lineDiscount,'tax_rate'=>$line['tax_rate'],
                    'untaxed'=>$lineUntaxed,'tax'=>$lineTax,'total'=>round($lineUntaxed+$lineTax,2),
                ]);
            }
            $connection->commit();
            return $invoiceId;
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) { $connection->rollBack(); }
            throw $exception;
        }
    }

    public function createCustomerCreditFromOrder(int $companyId, int $orderId, int $actorId): int
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $existing = $connection->prepare("SELECT invoice_id FROM finance_invoices WHERE company_id=:company_id AND sales_order_id=:order_id AND document_type='customer_credit' AND status<>'cancelled' ORDER BY invoice_id DESC LIMIT 1 FOR UPDATE");
            $existing->execute(['company_id'=>$companyId,'order_id'=>$orderId]);
            $existingId = $existing->fetchColumn();
            if ($existingId !== false) { $connection->commit(); return (int)$existingId; }
            $orderStatement = $connection->prepare("SELECT * FROM sales_orders WHERE company_id=:company_id AND order_id=:order_id AND deleted_at IS NULL FOR UPDATE");
            $orderStatement->execute(['company_id'=>$companyId,'order_id'=>$orderId]);
            $order = $orderStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order)) { throw new RuntimeException('Sales Order was not found.'); }
            $linesStatement = $connection->prepare("SELECT l.*,COALESCE((SELECT SUM(pl.completed_quantity) FROM inventory_picking_lines pl INNER JOIN inventory_pickings p ON p.company_id=pl.company_id AND p.picking_id=pl.picking_id WHERE p.company_id=l.company_id AND p.sales_order_id=l.order_id AND p.picking_type='customer_return' AND p.status='done' AND pl.product_id=l.product_id),0) returned_quantity FROM sales_order_lines l WHERE l.company_id=:company_id AND l.order_id=:order_id ORDER BY l.order_line_id FOR UPDATE");
            $linesStatement->execute(['company_id'=>$companyId,'order_id'=>$orderId]);
            $creditLines=[];$untaxed=$discount=$tax=$total=0.0;
            foreach($linesStatement->fetchAll(PDO::FETCH_ASSOC) as $line){
                $quantity=round((float)$line['returned_quantity'],3);if($quantity<=0.0005)continue;
                $unitPrice=round((float)$line['unit_price'],4);$gross=round($quantity*$unitPrice,2);
                $ratio=$quantity/(float)$line['quantity'];$lineDiscount=round((float)$line['discount_amount']*$ratio,2);
                $lineUntaxed=round($gross-$lineDiscount,2);$lineTax=round($lineUntaxed*(float)$line['tax_rate']/100,2);
                $creditLines[]=[$line,$quantity,$unitPrice,$lineDiscount,$lineUntaxed,$lineTax];
                $untaxed+=$lineUntaxed;$discount+=$lineDiscount;$tax+=$lineTax;$total+=$lineUntaxed+$lineTax;
            }
            if($creditLines===[]){throw new RuntimeException('No completed returned quantity is eligible for credit.');}
            $original=$connection->prepare("SELECT invoice_id,journal_id FROM finance_invoices WHERE company_id=:company_id AND sales_order_id=:order_id AND document_type='customer_invoice' AND status='posted' ORDER BY invoice_id DESC LIMIT 1 FOR UPDATE");
            $original->execute(['company_id'=>$companyId,'order_id'=>$orderId]);$source=$original->fetch(PDO::FETCH_ASSOC);
            if(!is_array($source)){throw new RuntimeException('A posted customer invoice is required before creating a credit note.');}
            $journal=$connection->prepare('SELECT next_number FROM finance_journals WHERE company_id=:company_id AND journal_id=:journal_id FOR UPDATE');
            $journal->execute(['company_id'=>$companyId,'journal_id'=>$source['journal_id']]);$next=(int)$journal->fetchColumn();
            $number=sprintf('CN-%08d',$next);$connection->prepare('UPDATE finance_journals SET next_number=next_number+1 WHERE company_id=:company_id AND journal_id=:journal_id')->execute(['company_id'=>$companyId,'journal_id'=>$source['journal_id']]);
            $insert=$connection->prepare("INSERT INTO finance_invoices(company_id,journal_id,customer_id,sales_order_id,original_invoice_id,document_type,invoice_number,invoice_date,due_date,currency,payment_terms_days,invoice_policy,status,payment_status,untaxed_amount,discount_amount,tax_amount,total_amount,residual_amount,notes,created_by) VALUES(:company_id,:journal_id,:customer_id,:order_id,:original,'customer_credit',:number,CURRENT_DATE,CURRENT_DATE,:currency,0,'delivered','draft','credit',:untaxed,:discount,:tax,:total,:residual,:notes,:actor)");
            $insert->execute(['company_id'=>$companyId,'journal_id'=>$source['journal_id'],'customer_id'=>$order['customer_id'],'order_id'=>$orderId,'original'=>$source['invoice_id'],'number'=>$number,'currency'=>$order['currency'],'untaxed'=>round($untaxed,2),'discount'=>round($discount,2),'tax'=>round($tax,2),'total'=>round($total,2),'residual'=>round($total,2),'notes'=>'Customer return credit for '.$order['order_number'],'actor'=>$actorId]);
            $creditId=(int)$connection->lastInsertId();$lineInsert=$connection->prepare("INSERT INTO finance_invoice_lines(company_id,invoice_id,sales_order_line_id,product_id,description,quantity,unit_price,discount_amount,tax_rate,untaxed_amount,tax_amount,total_amount) VALUES(:company_id,:invoice_id,:sales_line,:product,:description,:quantity,:unit_price,:discount,:tax_rate,:untaxed,:tax,:total)");
            foreach($creditLines as [$line,$quantity,$unitPrice,$lineDiscount,$lineUntaxed,$lineTax]){$lineInsert->execute(['company_id'=>$companyId,'invoice_id'=>$creditId,'sales_line'=>$line['order_line_id'],'product'=>$line['product_id'],'description'=>$line['description'],'quantity'=>$quantity,'unit_price'=>$unitPrice,'discount'=>$lineDiscount,'tax_rate'=>$line['tax_rate'],'untaxed'=>$lineUntaxed,'tax'=>$lineTax,'total'=>round($lineUntaxed+$lineTax,2)]);}
            $connection->commit();return $creditId;
        } catch(Throwable $exception){if($connection->inTransaction())$connection->rollBack();throw $exception;}
    }

    public function postInvoice(int $companyId, int $invoiceId, int $actorId): array
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $statement = $connection->prepare(
                "SELECT * FROM finance_invoices WHERE company_id=:company_id AND invoice_id=:invoice_id FOR UPDATE"
            );
            $statement->execute(['company_id'=>$companyId,'invoice_id'=>$invoiceId]);
            $invoice = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($invoice)) { throw new RuntimeException('Invoice was not found.'); }
            if ($invoice['status'] === 'posted') {
                /*
                 * A replay also repairs the order-level receivable
                 * projection if an older posting predates this rule.
                 */
                $this->syncSalesReceivableFromPostedInvoices(
                    $connection,
                    $invoice
                );
                $connection->commit();
                return ['invoiceId'=>$invoiceId,'status'=>'posted','replayed'=>true];
            }
            if ($invoice['status'] !== 'draft') { throw new RuntimeException('Only a draft invoice can be posted.'); }
            $this->assertOpenPostingDate($companyId, (string)$invoice['invoice_date']);
            $accounts = $this->ensureSystemAccounts($companyId, (string)$invoice['currency'], $actorId);
            $isCredit = (string)$invoice['document_type'] === 'customer_credit';
            $journal = $this->postBalancedJournal(
                $companyId, ($isCredit?'CN-':'INV-').$invoiceId, $isCredit?'customer_credit':'customer_invoice', (string)$invoiceId,
                (string)$invoice['invoice_number'], (string)$invoice['invoice_date'], (string)$invoice['currency'],
                ($isCredit?'Customer credit ':'Customer invoice ').$invoice['invoice_number'], 'finance-invoice-'.$companyId.'-'.$invoiceId,
                $isCredit ? [
                    ['account_id'=>$accounts['sales_revenue'],'debit'=>$invoice['untaxed_amount'],'credit'=>0,'description'=>'Sales return'],
                    ...((float)$invoice['tax_amount'] > 0 ? [['account_id'=>$accounts['sales_tax_payable'],'debit'=>$invoice['tax_amount'],'credit'=>0,'description'=>'Sales tax reversal']] : []),
                    ['account_id'=>$accounts['accounts_receivable'],'debit'=>0,'credit'=>$invoice['total_amount'],'description'=>'Customer credit'],
                ] : [
                    ['account_id'=>$accounts['accounts_receivable'],'debit'=>$invoice['total_amount'],'credit'=>0,'description'=>'Customer receivable'],
                    ['account_id'=>$accounts['sales_revenue'],'debit'=>0,'credit'=>$invoice['untaxed_amount'],'description'=>'Sales revenue'],
                    ...((float)$invoice['tax_amount'] > 0 ? [[
                        'account_id'=>$accounts['sales_tax_payable'],'debit'=>0,'credit'=>$invoice['tax_amount'],'description'=>'Sales tax payable'
                    ]] : []),
                ], $actorId
            );
            $connection->prepare(
                "UPDATE finance_invoices SET status='posted',journal_batch_id=:batch,posted_by=:actor,posted_at=NOW()
                 WHERE company_id=:company_id AND invoice_id=:invoice_id"
            )->execute(['batch'=>$journal['journalBatchId'],'actor'=>$actorId,'company_id'=>$companyId,'invoice_id'=>$invoiceId]);
            /*
             * Invoice posting, not Sales Order confirmation, owns the
             * order-level Finance receivable projection.
             */
            $this->syncSalesReceivableFromPostedInvoices(
                $connection,
                $invoice
            );
            $connection->commit();
            return ['invoiceId'=>$invoiceId,'status'=>'posted','replayed'=>false,'journalBatchId'=>$journal['journalBatchId']];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) { $connection->rollBack(); }
            throw $exception;
        }
    }


    /**
     * Rebuild the compatibility order-level receivable from authoritative
     * posted customer invoices. Recalculation makes posting/replay idempotent.
     *
     * @param array<string, mixed> $invoice
     */
    private function syncSalesReceivableFromPostedInvoices(
        PDO $connection,
        array $invoice
    ): void {
        if (
            (string) ($invoice['document_type'] ?? '') !==
                'customer_invoice'
            || (int) ($invoice['sales_order_id'] ?? 0) <= 0
        ) {
            return;
        }

        $companyId = (int) $invoice['company_id'];
        $orderId = (int) $invoice['sales_order_id'];

        $totalsStatement = $connection->prepare(
            "SELECT
                COALESCE(SUM(total_amount), 0) AS original_amount,
                COALESCE(
                    SUM(total_amount - residual_amount),
                    0
                ) AS paid_amount,
                COALESCE(
                    MIN(
                        CASE
                            WHEN residual_amount > 0
                            THEN due_date
                            ELSE NULL
                        END
                    ),
                    MAX(due_date)
                ) AS due_date
             FROM finance_invoices
             WHERE company_id = :company_id
               AND sales_order_id = :order_id
               AND document_type = 'customer_invoice'
               AND status = 'posted'"
        );
        $totalsStatement->execute([
            'company_id' => $companyId,
            'order_id' => $orderId,
        ]);

        $totals = $totalsStatement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($totals)) {
            throw new RuntimeException(
                'Posted customer invoice totals could not be reconciled.'
            );
        }

        $orderStatement = $connection->prepare(
            "SELECT order_number
             FROM sales_orders
             WHERE company_id = :company_id
               AND order_id = :order_id
               AND deleted_at IS NULL
             LIMIT 1"
        );
        $orderStatement->execute([
            'company_id' => $companyId,
            'order_id' => $orderId,
        ]);

        $orderNumber = $orderStatement->fetchColumn();

        if ($orderNumber === false) {
            throw new RuntimeException(
                'The sales order for the posted invoice was not found.'
            );
        }

        $originalAmount = round(
            (float) $totals['original_amount'],
            2
        );
        $paidAmount = min(
            $originalAmount,
            round((float) $totals['paid_amount'], 2)
        );
        $balanceAmount = max(
            0,
            round($originalAmount - $paidAmount, 2)
        );

        $status = $balanceAmount <= 0
            ? 'paid'
            : ($paidAmount > 0 ? 'partially_paid' : 'open');

        $receivable = $connection->prepare(
            "INSERT INTO finance_sales_receivables
                (
                    company_id,
                    order_id,
                    customer_id,
                    order_number,
                    currency,
                    original_amount,
                    paid_amount,
                    balance_amount,
                    due_date,
                    status
                )
             VALUES
                (
                    :company_id,
                    :order_id,
                    :customer_id,
                    :order_number,
                    :currency,
                    :original_amount,
                    :paid_amount,
                    :balance_amount,
                    :due_date,
                    :status
                )
             ON DUPLICATE KEY UPDATE
                customer_id = VALUES(customer_id),
                order_number = VALUES(order_number),
                currency = VALUES(currency),
                original_amount = VALUES(original_amount),
                paid_amount = VALUES(paid_amount),
                balance_amount = VALUES(balance_amount),
                due_date = VALUES(due_date),
                status = VALUES(status)"
        );

        $receivable->execute([
            'company_id' => $companyId,
            'order_id' => $orderId,
            'customer_id' => (int) $invoice['customer_id'],
            'order_number' => (string) $orderNumber,
            'currency' => (string) $invoice['currency'],
            'original_amount' => $originalAmount,
            'paid_amount' => $paidAmount,
            'balance_amount' => $balanceAmount,
            'due_date' =>
                (string) (
                    $totals['due_date']
                    ?? $invoice['due_date']
                ),
            'status' => $status,
        ]);
    }
    public function postCustomerPayment(
        int $companyId, int $customerId, int $journalId, string $paymentDate,
        string $currency, mixed $amount, string $method, ?string $reference,
        array $allocations, int $actorId
    ): array {
        $amountValue = round((float)$amount, 2);
        if ($amountValue <= 0) { throw new RuntimeException('Payment amount must be positive.'); }
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $this->assertOpenPostingDate($companyId, $paymentDate);
            $journal = $connection->prepare(
                "SELECT next_number FROM finance_journals WHERE company_id=:company_id AND journal_id=:journal_id
                 AND journal_type IN ('bank','cash') AND active=TRUE FOR UPDATE"
            );
            $journal->execute(['company_id'=>$companyId,'journal_id'=>$journalId]);
            $next = $journal->fetchColumn();
            if ($next === false) { throw new RuntimeException('Select an active bank or cash journal.'); }
            $normalized = []; $allocated = 0.0;
            foreach ($allocations as $allocation) {
                $invoiceId = (int)($allocation['invoice_id'] ?? 0);
                $allocationAmount = round((float)($allocation['amount'] ?? 0), 2);
                if ($invoiceId < 1 || $allocationAmount <= 0) { throw new RuntimeException('Payment allocations must be positive.'); }
                $invoice = $connection->prepare(
                    "SELECT residual_amount FROM finance_invoices WHERE company_id=:company_id AND invoice_id=:invoice_id
                     AND customer_id=:customer_id AND status='posted' AND payment_status<>'paid' FOR UPDATE"
                );
                $invoice->execute(['company_id'=>$companyId,'invoice_id'=>$invoiceId,'customer_id'=>$customerId]);
                $residual = $invoice->fetchColumn();
                if ($residual === false || $allocationAmount > round((float)$residual,2)) {
                    throw new RuntimeException('A payment allocation exceeds the invoice residual.');
                }
                $normalized[$invoiceId] = ($normalized[$invoiceId] ?? 0) + $allocationAmount;
                $allocated += $allocationAmount;
            }
            $allocated = round($allocated,2);
            if ($allocated > $amountValue) { throw new RuntimeException('Payment allocations exceed the payment amount.'); }
            $unallocated = round($amountValue-$allocated,2);
            $number = sprintf('PAY-%08d',(int)$next);
            $connection->prepare('UPDATE finance_journals SET next_number=next_number+1 WHERE company_id=:company_id AND journal_id=:journal_id')
                ->execute(['company_id'=>$companyId,'journal_id'=>$journalId]);
            $payment = $connection->prepare(
                "INSERT INTO finance_payments
                 (company_id,journal_id,customer_id,payment_number,direction,payment_date,currency,amount,
                  allocated_amount,unallocated_amount,method,reference_number,status,posted_by,posted_at,created_by)
                 VALUES (:company_id,:journal_id,:customer_id,:number,'inbound',:payment_date,:currency,:amount,
                  :allocated,:unallocated,:method,:reference,'draft',:posted_by,NOW(),:created_by)"
            );
            $payment->execute(['company_id'=>$companyId,'journal_id'=>$journalId,'customer_id'=>$customerId,
                'number'=>$number,'payment_date'=>$paymentDate,'currency'=>strtoupper($currency),'amount'=>$amountValue,
                'allocated'=>$allocated,'unallocated'=>$unallocated,'method'=>$method,'reference'=>$reference,
                'posted_by'=>$actorId,'created_by'=>$actorId]);
            $paymentId = (int)$connection->lastInsertId();
            $accounts = $this->ensureSystemAccounts($companyId, $currency, $actorId);
            $journalResult = $this->postBalancedJournal(
                $companyId,$number,'customer_payment',(string)$paymentId,$number,$paymentDate,strtoupper($currency),
                'Customer payment '.$number,'finance-payment-'.$companyId.'-'.$paymentId,
                [
                    ['account_id'=>$accounts['cash'],'debit'=>$amountValue,'credit'=>0,'description'=>'Bank or cash received'],
                    ...($allocated > 0 ? [['account_id'=>$accounts['accounts_receivable'],'debit'=>0,'credit'=>$allocated,'description'=>'Receivable settlement']] : []),
                    ...($unallocated > 0 ? [['account_id'=>$accounts['customer_credits'],'debit'=>0,'credit'=>$unallocated,'description'=>'Unallocated customer credit']] : []),
                ],$actorId
            );
            $allocationInsert = $connection->prepare(
                'INSERT INTO finance_payment_allocations (company_id,payment_id,invoice_id,amount,allocated_by,allocated_at)
                 VALUES (:company_id,:payment_id,:invoice_id,:amount,:actor,NOW())'
            );
            foreach ($normalized as $invoiceId=>$allocationAmount) {
                $allocationInsert->execute(['company_id'=>$companyId,'payment_id'=>$paymentId,'invoice_id'=>$invoiceId,'amount'=>$allocationAmount,'actor'=>$actorId]);
                $connection->prepare(
                    "UPDATE finance_invoices SET residual_amount=residual_amount-:amount,
                     payment_status=CASE WHEN residual_amount-:status_amount<=0 THEN 'paid' ELSE 'partially_paid' END
                     WHERE company_id=:company_id AND invoice_id=:invoice_id"
                )->execute(['amount'=>$allocationAmount,'status_amount'=>$allocationAmount,'company_id'=>$companyId,'invoice_id'=>$invoiceId]);
            }
            /*
             * Synchronize the Sales receivable projection from Finance
             * payment allocations. The Finance payment and this projection
             * update remain inside the same database transaction.
             */
            $salesAllocationStatement = $connection->prepare(
                "SELECT
                    invoices.sales_order_id AS order_id,
                    SUM(allocations.amount) AS allocated_amount
                 FROM finance_payment_allocations allocations
                 INNER JOIN finance_invoices invoices
                    ON invoices.company_id =
                       allocations.company_id
                   AND invoices.invoice_id =
                       allocations.invoice_id
                 WHERE allocations.company_id =
                       :company_id
                   AND allocations.payment_id =
                       :payment_id
                   AND invoices.document_type =
                       'customer_invoice'
                   AND invoices.sales_order_id IS NOT NULL
                 GROUP BY invoices.sales_order_id"
            );

            $salesAllocationStatement->execute([
                'company_id' => $companyId,
                'payment_id' => $paymentId,
            ]);

            foreach (
                $salesAllocationStatement->fetchAll(PDO::FETCH_ASSOC)
                as $salesAllocation
            ) {
                $orderId =
                    (int) $salesAllocation['order_id'];

                $paidIncrement =
                    round(
                        (float) $salesAllocation['allocated_amount'],
                        2
                    );

                if (
                    $orderId <= 0
                    || $paidIncrement <= 0
                ) {
                    continue;
                }

                $receivableStatement =
                    $connection->prepare(
                        "SELECT
                            original_amount,
                            paid_amount
                         FROM finance_sales_receivables
                         WHERE company_id = :company_id
                           AND order_id = :order_id
                         FOR UPDATE"
                    );

                $receivableStatement->execute([
                    'company_id' => $companyId,
                    'order_id' => $orderId,
                ]);

                $receivable =
                    $receivableStatement->fetch(
                        PDO::FETCH_ASSOC
                    );

                if (!is_array($receivable)) {
                    continue;
                }

                $originalAmount =
                    round(
                        (float) $receivable['original_amount'],
                        2
                    );

                $newPaid =
                    min(
                        $originalAmount,
                        round(
                            (float) $receivable['paid_amount']
                            + $paidIncrement,
                            2
                        )
                    );

                $newBalance =
                    max(
                        0.0,
                        round(
                            $originalAmount - $newPaid,
                            2
                        )
                    );

                $newStatus =
                    $newBalance <= 0.0001
                        ? 'paid'
                        : (
                            $newPaid > 0.0001
                                ? 'partially_paid'
                                : 'open'
                        );

                $updateReceivable =
                    $connection->prepare(
                        "UPDATE finance_sales_receivables
                         SET paid_amount = :paid_amount,
                             balance_amount = :balance_amount,
                             status = :status
                         WHERE company_id = :company_id
                           AND order_id = :order_id"
                    );

                $updateReceivable->execute([
                    'paid_amount' => $newPaid,
                    'balance_amount' => $newBalance,
                    'status' => $newStatus,
                    'company_id' => $companyId,
                    'order_id' => $orderId,
                ]);
            }
            $connection->prepare("UPDATE finance_payments SET status='posted',journal_batch_id=:batch WHERE company_id=:company_id AND payment_id=:payment_id")
                ->execute(['batch'=>$journalResult['journalBatchId'],'company_id'=>$companyId,'payment_id'=>$paymentId]);
            $connection->commit();
            return ['paymentId'=>$paymentId,'paymentNumber'=>$number,'allocatedAmount'=>$allocated,'unallocatedAmount'=>$unallocated,'status'=>'posted'];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) { $connection->rollBack(); }
            throw $exception;
        }
    }

    private function assertOpenPostingDate(int $companyId, string $postingDate): void
    {
        $statement = $this->connection()->prepare(
            "SELECT COUNT(*) FROM finance_accounting_periods WHERE company_id=:company_id
             AND :posting_date BETWEEN date_from AND date_to AND status IN ('closed','locked')"
        );
        $statement->execute(['company_id'=>$companyId,'posting_date'=>$postingDate]);
        if ((int)$statement->fetchColumn() > 0) {
            throw new RuntimeException('The accounting period is closed or locked.');
        }
    }

    public function salesReceivableSummary(
        int $companyId
    ): array {
        if ($companyId <= 0) {
            throw new RuntimeException(
                'A valid company is required.'
            );
        }

        $statement = $this->connection()->prepare(
            "SELECT
                currency,
                COUNT(*) AS total_count,
                SUM(
                    CASE
                        WHEN status = 'open'
                        THEN 1
                        ELSE 0
                    END
                ) AS open_count,
                SUM(
                    CASE
                        WHEN status = 'paid'
                        THEN 1
                        ELSE 0
                    END
                ) AS paid_count,
                SUM(
                    CASE
                        WHEN balance_amount > 0
                         AND due_date < CURRENT_DATE
                        THEN 1
                        ELSE 0
                    END
                ) AS overdue_count,
                COALESCE(
                    SUM(original_amount),
                    0
                ) AS total_original,
                COALESCE(
                    SUM(paid_amount),
                    0
                ) AS total_paid,
                COALESCE(
                    SUM(balance_amount),
                    0
                ) AS total_outstanding
             FROM finance_sales_receivables
             WHERE company_id = :company_id
             GROUP BY currency
             ORDER BY currency"
        );

        $statement->execute([
            'company_id' => $companyId,
        ]);

        $rows = $statement->fetchAll(
            PDO::FETCH_ASSOC
        );

        return is_array($rows) ? $rows : [];
    }
    public function countSalesReceivables(
        int $companyId,
        array $filters
    ): int {
        if ($companyId <= 0) {
            throw new RuntimeException(
                'A valid company is required.'
            );
        }

        $search = mb_substr(
            trim((string) (
                $filters['search'] ?? ''
            )),
            0,
            100
        );
        $status = trim((string) (
            $filters['status'] ?? ''
        ));

        $where = [
            'r.company_id = :company_id',
        ];
        $parameters = [
            'company_id' => $companyId,
        ];

        if ($search !== '') {
            $where[] = '(
                r.order_number LIKE :search_order
                OR c.customer_number
                    LIKE :search_customer_number
                OR c.name LIKE :search_customer_name
            )';

            $like = '%' . $search . '%';
            $parameters['search_order'] = $like;
            $parameters['search_customer_number'] =
                $like;
            $parameters['search_customer_name'] =
                $like;
        }

        if ($status === 'overdue') {
            $where[] = 'r.balance_amount > 0
                AND r.due_date < CURRENT_DATE';
        } elseif (in_array(
            $status,
            ['open', 'paid'],
            true
        )) {
            $where[] = 'r.status = :status';
            $parameters['status'] = $status;
        }

        $statement = $this->connection()->prepare(
            'SELECT COUNT(*)
             FROM finance_sales_receivables r
             LEFT JOIN sales_customers c
               ON c.customer_id = r.customer_id
              AND c.company_id = r.company_id
              AND c.deleted_at IS NULL
             WHERE ' . implode(
                ' AND ',
                $where
            )
        );

        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }
    public function salesReceivables(
        int $companyId,
        array $filters,
        int $limit,
        int $offset
    ): array {
        if ($companyId <= 0) {
            throw new RuntimeException(
                'A valid company is required.'
            );
        }

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $search = mb_substr(
            trim((string) (
                $filters['search'] ?? ''
            )),
            0,
            100
        );
        $status = trim((string) (
            $filters['status'] ?? ''
        ));

        $where = [
            'r.company_id = :company_id',
        ];
        $parameters = [
            'company_id' => $companyId,
        ];

        if ($search !== '') {
            $where[] = '(
                r.order_number LIKE :search_order
                OR c.customer_number
                    LIKE :search_customer_number
                OR c.name LIKE :search_customer_name
            )';

            $like = '%' . $search . '%';
            $parameters['search_order'] = $like;
            $parameters['search_customer_number'] =
                $like;
            $parameters['search_customer_name'] =
                $like;
        }

        if ($status === 'overdue') {
            $where[] = 'r.balance_amount > 0
                AND r.due_date < CURRENT_DATE';
        } elseif (in_array(
            $status,
            ['open', 'paid'],
            true
        )) {
            $where[] = 'r.status = :status';
            $parameters['status'] = $status;
        }

        $statement = $this->connection()->prepare(
            'SELECT
                r.receivable_id,
                r.order_id,
                r.customer_id,
                r.order_number,
                r.currency,
                r.original_amount,
                r.paid_amount,
                r.balance_amount,
                r.due_date,
                r.status,
                r.created_at,
                r.updated_at,
                c.customer_number,
                c.name AS customer_name,
                CASE
                    WHEN r.balance_amount > 0
                     AND r.due_date < CURRENT_DATE
                    THEN 1
                    ELSE 0
                END AS is_overdue
             FROM finance_sales_receivables r
             LEFT JOIN sales_customers c
               ON c.customer_id = r.customer_id
              AND c.company_id = r.company_id
              AND c.deleted_at IS NULL
             WHERE ' . implode(
                ' AND ',
                $where
            ) . '
             ORDER BY
                is_overdue DESC,
                r.due_date ASC,
                r.receivable_id DESC
             LIMIT ' . $limit . '
             OFFSET ' . $offset
        );

        $statement->execute($parameters);

        $rows = $statement->fetchAll(
            PDO::FETCH_ASSOC
        );

        return is_array($rows) ? $rows : [];
    }
    public function recentSalesReceipts(
        int $companyId,
        int $limit
    ): array {
        if ($companyId <= 0) {
            throw new RuntimeException(
                'A valid company is required.'
            );
        }

        $limit = max(1, min(100, $limit));

        $statement = $this->connection()->prepare(
            'SELECT
                sr.posting_id,
                sr.payment_id,
                sr.order_id,
                sr.receipt_number,
                sr.amount,
                sr.payment_date,
                sr.payment_method,
                sr.reference_number,
                sr.posted_at,
                r.order_number,
                r.currency
             FROM finance_sales_receipts sr
             LEFT JOIN finance_sales_receivables r
               ON r.company_id = sr.company_id
              AND r.order_id = sr.order_id
             WHERE sr.company_id = :company_id
             ORDER BY
                sr.payment_date DESC,
                sr.posting_id DESC
             LIMIT ' . $limit
        );

        $statement->execute([
            'company_id' => $companyId,
        ]);

        $rows = $statement->fetchAll(
            PDO::FETCH_ASSOC
        );

        return is_array($rows) ? $rows : [];
    }
    public function recentJournalBatches(
        int $companyId,
        int $limit
    ): array {
        if ($companyId <= 0) {
            throw new RuntimeException(
                'A valid company is required.'
            );
        }

        $limit = max(1, min(100, $limit));

        $statement = $this->connection()->prepare(
            'SELECT
                journal_batch_id,
                batch_number,
                source_type,
                source_id,
                source_number,
                posting_date,
                currency,
                description,
                status,
                total_debit,
                total_credit,
                posted_at,
                created_at
             FROM finance_journal_batches
             WHERE company_id = :company_id
             ORDER BY
                posting_date DESC,
                journal_batch_id DESC
             LIMIT ' . $limit
        );

        $statement->execute([
            'company_id' => $companyId,
        ]);

        $rows = $statement->fetchAll(
            PDO::FETCH_ASSOC
        );

        return is_array($rows) ? $rows : [];
    }
    public function trialBalance(
        int $companyId,
        string $currency
    ): array {
        $currency = strtoupper(trim($currency));

        $statement = $this->connection()->prepare(
            'SELECT
                accounts.account_id,
                accounts.account_code,
                accounts.account_name,
                accounts.account_type,
                accounts.normal_balance,
                balances.currency,
                COALESCE(
                    balances.debit_total,
                    0
                ) AS debit_total,
                COALESCE(
                    balances.credit_total,
                    0
                ) AS credit_total,
                COALESCE(
                    balances.balance_amount,
                    0
                ) AS balance_amount
             FROM finance_accounts accounts
             LEFT JOIN finance_account_balances balances
                ON balances.company_id =
                    accounts.company_id
               AND balances.account_id =
                    accounts.account_id
               AND balances.currency = :currency
             WHERE accounts.company_id = :company_id
               AND accounts.active = TRUE
               AND accounts.deleted_at IS NULL
             ORDER BY accounts.account_code'
        );
        $statement->execute([
            'currency' => $currency,
            'company_id' => $companyId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
