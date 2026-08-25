<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class InventoryValuationReconciliationService
{
    /** @return array{company_id:int,currency:string,subledger_value:float,gl_value:float,variance:float,matched:bool,unposted_layers:int} */
    public function reconcile(int $companyId, string $currency): array
    {
        $currency = strtoupper(trim($currency));
        if ($companyId <= 0 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new RuntimeException('A valid company and currency are required.');
        }
        $statement = \db()->prepare(
            "SELECT
                COALESCE((SELECT SUM(total_value) FROM inventory_valuation_layers
                          WHERE company_id=:sub_company AND currency=:sub_currency),0) subledger_value,
                COALESCE((SELECT b.balance_amount FROM finance_account_balances b
                          INNER JOIN finance_accounts a ON a.company_id=b.company_id AND a.account_id=b.account_id
                          WHERE b.company_id=:gl_company AND b.currency=:gl_currency
                            AND a.system_key='inventory_asset' AND a.active=TRUE AND a.deleted_at IS NULL
                          LIMIT 1),0) gl_value,
                (SELECT COUNT(*) FROM inventory_valuation_layers
                 WHERE company_id=:pending_company AND currency=:pending_currency
                   AND journal_batch_id IS NULL) unposted_layers"
        );
        $statement->execute([
            'sub_company' => $companyId,
            'sub_currency' => $currency,
            'gl_company' => $companyId,
            'gl_currency' => $currency,
            'pending_company' => $companyId,
            'pending_currency' => $currency,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Inventory valuation reconciliation could not be calculated.');
        }
        $subledger = round((float) $row['subledger_value'], 2);
        $gl = round((float) $row['gl_value'], 2);
        $variance = round($subledger - $gl, 2);
        $unposted = (int) $row['unposted_layers'];
        return [
            'company_id' => $companyId,
            'currency' => $currency,
            'subledger_value' => $subledger,
            'gl_value' => $gl,
            'variance' => $variance,
            'matched' => abs($variance) < 0.005 && $unposted === 0,
            'unposted_layers' => $unposted,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function exceptions(int $companyId): array
    {
        $company = \db()->prepare('SELECT default_currency FROM companies WHERE company_id=:company_id AND deleted_at IS NULL');
        $company->execute(['company_id' => $companyId]);
        $currency = $company->fetchColumn();
        if ($currency === false) {
            throw new RuntimeException('The company was not found.');
        }
        $result = $this->reconcile($companyId, (string) $currency);
        return $result['matched'] ? [] : [$result];
    }
}
