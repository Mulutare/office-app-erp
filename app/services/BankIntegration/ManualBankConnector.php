<?php
declare(strict_types=1);
namespace App\Services\BankIntegration;

final class ManualBankConnector implements BankConnectorInterface
{
    public function providerCode(): string{return 'manual';}
    public function testConnection(): array{return ['available'=>true,'message'=>'Manual evidence workflow is available; no live bank connection is configured.'];}
    public function fetchTransactions(array $criteria=[]): array{return [];}
    public function normalizeTransaction(array $transaction): array
    {
        foreach(['external_transaction_id','booking_date','amount','currency','debit_credit'] as $key)if(!isset($transaction[$key]))throw new \InvalidArgumentException('Missing normalized bank field: '.$key);
        return $transaction+['provider'=>'manual','source'=>'manual_import'];
    }
}
