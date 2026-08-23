<?php
declare(strict_types=1);
namespace App\Services\BankIntegration;

interface BankConnectorInterface
{
    public function providerCode(): string;
    /** @return array{available:bool,message:string} */
    public function testConnection(): array;
    /** @return list<array<string,mixed>> */
    public function fetchTransactions(array $criteria=[]): array;
    /** @return array<string,mixed> */
    public function normalizeTransaction(array $transaction): array;
}
