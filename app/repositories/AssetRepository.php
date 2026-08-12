<?php

declare(strict_types=1);

namespace App\Repositories;

interface AssetRepository
{
    public function workspace(int $companyId): array;
    public function asset(int $companyId,int $assetId,bool $forUpdate=false): ?array;
    public function category(int $companyId,int $categoryId): ?array;
    public function createCategory(int $companyId,array $values,int $actorId): int;
    public function createAsset(int $companyId,array $values,int $actorId): int;
    public function activate(int $companyId,int $assetId,string $inServiceDate,array $schedule,int $actorId): void;
    public function depreciationLine(int $companyId,int $lineId,bool $forUpdate=false): ?array;
    public function markDepreciationPosted(int $companyId,int $lineId,int $journalBatchId,float $accumulated,float $bookValue,string $assetStatus,int $actorId): void;
    public function transfer(int $companyId,int $assetId,array $values,int $actorId): int;
    public function addMaintenance(int $companyId,int $assetId,array $values,int $actorId): int;
    public function dispose(int $companyId,int $assetId,array $values,int $actorId): int;
    public function linkInventorySource(int $companyId,int $assetId,int $movementId,int $warehouseId,int $locationId,int $productId,float $quantity,float $cost): void;
    public function history(int $companyId,int $assetId,string $action,?string $from,?string $to,array $details,int $actorId): void;
}
