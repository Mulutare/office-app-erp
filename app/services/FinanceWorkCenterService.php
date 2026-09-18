<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/** Read-only dashboard indicators from the existing posted documents and controls. */
final class FinanceWorkCenterService
{
    private function rows(string $sql, int $company): array
    {
        $query = \db()->prepare($sql);
        $query->execute(['company' => $company]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function summary(): array
    {
        $company = (new TenantContext())->companyId();
        $reconciliation = (new FinanceReconciliationService())->summary();
        return [
            'cash' => $this->rows("SELECT b.currency,ROUND(SUM(e.debit_amount-e.credit_amount),2) amount FROM finance_journal_entries e JOIN finance_journal_batches b ON b.company_id=e.company_id AND b.journal_batch_id=e.journal_batch_id JOIN finance_accounts a ON a.company_id=e.company_id AND a.account_id=e.account_id WHERE b.company_id=:company AND b.status='posted' AND a.system_key='cash' GROUP BY b.currency", $company),
            'balances' => $reconciliation,
            'overdue' => $this->rows("SELECT document_type,currency,COUNT(*) records,ROUND(SUM(residual_amount),2) amount FROM finance_invoices WHERE company_id=:company AND status='posted' AND document_type IN('customer_invoice','vendor_bill') AND residual_amount>0 AND due_date<CURRENT_DATE GROUP BY document_type,currency", $company),
            'expenses' => $this->rows("SELECT status,COUNT(*) records FROM finance_expense_requests WHERE company_id=:company AND deleted_at IS NULL AND status IN('submitted','approved') GROUP BY status", $company),
            'loans' => $this->rows("SELECT currency,COUNT(*) records,ROUND(SUM(outstanding_principal+outstanding_interest),2) amount FROM finance_staff_loans WHERE company_id=:company AND status IN('disbursed','active') GROUP BY currency", $company),
            'settlements' => $this->rows("SELECT COUNT(*) records FROM sales_settlements WHERE company_id=:company AND workflow_status IN('submitted','supervisor_reviewed') AND reconciliation_status IN('awaiting_confirmation','partial','mismatch','review_required')", $company),
            'periods' => $this->rows("SELECT period_name,status FROM finance_accounting_periods WHERE company_id=:company AND CURRENT_DATE BETWEEN date_from AND date_to ORDER BY period_id DESC", $company),
        ];
    }
}
