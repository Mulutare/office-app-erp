<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/** Protects only posted periods of explicitly mapped bank GL accounts. */
final class FinanceBankReconciliationPostingGuard
{
    /** @param list<int> $accountIds */
    public function assertPostingAllowed(PDO $connection, int $companyId, array $accountIds, string $postingDate): void
    {
        if ($companyId < 1 || $accountIds === []) return;

        // A locking read sees a reconciliation completed after this posting
        // transaction's earlier ordinary reads, including period validation.
        // The caller already holds FOR UPDATE locks on these Finance accounts.
        $statement = $connection->prepare(
            "SELECT r.reconciliation_id
             FROM finance_bank_account_gl_mappings m
             JOIN finance_bank_reconciliations r
               ON r.company_id=m.company_id AND r.mapping_id=m.mapping_id
             JOIN finance_bank_statements s
               ON s.company_id=r.company_id AND s.statement_id=r.statement_id
             WHERE m.company_id=?
               AND m.finance_account_id=?
               AND r.status='completed'
               AND ? BETWEEN m.effective_from AND s.period_end
             LIMIT 1 FOR UPDATE"
        );
        foreach ($accountIds as $accountId) {
            $statement->execute([$companyId, $accountId, $postingDate]);
            if ($statement->fetchColumn() !== false) {
                throw new RuntimeException(
                    'Posting date is within a completed bank reconciliation period for this bank account. Post in an open subsequent period or use an approved reconciliation correction process.'
                );
            }
        }
    }
}
