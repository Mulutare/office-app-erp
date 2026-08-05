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