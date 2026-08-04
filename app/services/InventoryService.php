<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\InventoryRepository;
use App\Repositories\RepositoryFactory;
use RuntimeException;
use Throwable;

final class InventoryService
{
    public function __construct(
        private ?InventoryRepository $inventory = null,
        private ?TenantContext $tenant = null
    ) {
        $this->inventory ??= RepositoryFactory::inventory();
        $this->tenant ??= new TenantContext();
    }

    /** @return array<string, mixed> */
    public function postGoodsReceipt(
        int $goodsReceiptId,
        int $actorId
    ): array {
        if ($goodsReceiptId <= 0) {
            return [
                'successful' => false,
                'errors' => [
                    'goods_receipt_id' =>
                        'Select a valid goods receipt.',
                ],
            ];
        }

        if ($actorId <= 0) {
            return [
                'successful' => false,
                'errors' => [
                    'actor' => 'A valid posting user is required.',
                ],
            ];
        }

        try {
            $result = $this->inventory->postGoodsReceipt(
                $this->tenant->companyId(),
                $goodsReceiptId,
                $actorId,
                date('Y-m-d H:i:s')
            );

            return [
                'successful' => true,
                'result' => $result,
            ];
        } catch (Throwable $exception) {
            return [
                'successful' => false,
                'errors' => [
                    'form' => $exception->getMessage(),
                ],
            ];
        }
    }
}